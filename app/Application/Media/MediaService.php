<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Agent\{ActorContext, OperationException};
use App\Application\Shared\Access;
use App\Engine\{Database, ImageUpload, Ulid, DemoMode, Outbox};

final class MediaService
{
    public const PURPOSES = ['logo', 'service', 'staff', 'resource', 'event'];

    public static function prepare(ActorContext $actor, string $businessId, string $purpose): array
    {
        Access::tenant($actor, $businessId, 'owner', scope: 'media:manage');
        if (DemoMode::isActive()) {
            throw new OperationException('demo_restricted', 403);
        }

        if (!in_array($purpose, self::PURPOSES, true)) {
            throw new OperationException('validation_failed', 422);
        }

        $id = Ulid::generate();
        $token = bin2hex(random_bytes(32));
        $table = $actor->principalType === 'operator' ? 'operators' : 'business_users';
        $email = Database::query('SELECT email FROM ' . $table . ' WHERE id = ?', [$actor->principalId])[0]['email'];
        Database::execute(
            'INSERT INTO agent_media_grants (id,tenant_id,principal_type,principal_id,connection_id,credential_id,identity_hash,purpose,token_hash,expires_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [
                $id,
                $businessId,
                $actor->principalType,
                $actor->principalId,
                $actor->connectionId,
                $actor->credentialId,
                \App\Agent\PrincipalResolver::identityHash($email),
                $purpose,
                hash('sha256', $token),
                gmdate('Y-m-d H:i:s', time() + 600),
                gmdate('Y-m-d H:i:s')
            ]
        );
        return [
            'media_id' => $id,
            'upload_url' => \App\Agent\Endpoints::resource() . '/uploads/' . $id,
            'upload_token' => $token,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', time() + 600),
            'max_bytes' => 2097152,
            'field_name' => 'image',
            'allowed_types' => ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
        ];
    }

    public static function receive(ActorContext $actor, string $id, string $token, array $file): array
    {
        $journal = null;
        try {
            $response = Access::transaction(static function () use ($actor, $id, $token, $file, &$journal): array {
                $source = Database::query('SELECT tenant_id FROM agent_media_grants WHERE id = ?', [$id])[0]
                    ?? throw new OperationException('not_found', 404);
                $tenant = Access::tenant($actor, $source['tenant_id'], 'owner', true);
                $row = Database::query('SELECT * FROM agent_media_grants WHERE id = ? FOR UPDATE', [$id])[0] ?? null;
                self::authorize($actor, $row);
                if (!hash_equals($row['token_hash'], hash('sha256', $token))) {
                    throw new OperationException('upload_grant_invalid', 403);
                }

                self::validateImage($file);
                $digest = hash_file('sha256', $file['tmp_name']);
                if ($row['path'] !== null) {
                    if (!hash_equals($row['content_hash'] ?? '', $digest)) {
                        throw new OperationException('idempotency_conflict', 409);
                    }

                    return ['media_id' => $id, 'state' => 'ready', 'purpose' => $row['purpose']];
                }

                $type = match ($row['purpose']) {
                    'staff' => 'avatar',
                    'logo' => 'logo',
                    default => 'cover'
                };
                $fileId = Ulid::generate();
                $extension = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
                if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp', 'gif'], true)) {
                    throw new OperationException('upload_invalid', 422);
                }
                $path = 'uploads/' . preg_replace('/[^a-z0-9\-]/', '', strtolower($tenant['slug'])) . '/' . $type . '/'
                    . $fileId . '.' . $extension;
                // Persist before moving bytes. A crash or ambiguous commit is
                // reconciled against database references; it never guesses rollback.
                $journal = UploadJournal::begin($tenant['id'], $id, $path);
                $result = ImageUpload::store($type, $file, $tenant['slug'], fileId: $fileId);
                if ($result['error'] !== null || $result['path'] === null) {
                    throw new OperationException('upload_invalid', 422, ['image' => [$result['error'] ?? 'invalid']]);
                }

                try {
                    self::sanitize($result['path']);
                    Database::execute(
                        'UPDATE agent_media_grants SET path = ?, content_hash = ? WHERE id = ?',
                        [$result['path'], $digest, $id]
                    );
                } catch (\Throwable $error) {
                    ImageUpload::delete($result['path']);
                    throw $error;
                }

                return ['media_id' => $id, 'state' => 'ready', 'purpose' => $row['purpose']];
            });
            if (!Database::pdo()->inTransaction()) {
                $journal?->finish();
            }
            return $response;
        } finally {
            $journal?->release();
        }
    }

    /** The upload secret authorizes only this one image, with the source grant rechecked. */
    public static function receiveGrant(string $id, string $token, array $file): array
    {
        $row = Database::query('SELECT * FROM agent_media_grants WHERE id = ?', [$id])[0] ?? null;
        if (!$row || !hash_equals($row['token_hash'], hash('sha256', $token))) {
            throw new OperationException('upload_grant_invalid', 403);
        }

        $actor = self::sourceActor($row);
        return self::receive($actor, $id, $token, $file);
    }

    public static function cleanup(): array
    {
        $journal = UploadJournal::reconcile();
        $rows = Database::query(
            'SELECT id,tenant_id,path FROM agent_media_grants WHERE expires_at < ? AND consumed_at IS NULL LIMIT 100',
            [gmdate('Y-m-d H:i:s')]
        );
        $expired = 0;
        foreach ($rows as $candidate) {
            $expired += Access::transaction(static function () use ($candidate): int {
                Database::query('SELECT id FROM tenants WHERE id=? FOR UPDATE', [$candidate['tenant_id']]);
                $row = Database::query('SELECT * FROM agent_media_grants WHERE id=? FOR UPDATE', [$candidate['id']])[0]
                    ?? null;
                if (!$row || $row['consumed_at'] !== null || strtotime($row['expires_at'] . ' UTC') > time()) {
                    return 0;
                }

                if ($row['path'] !== null) {
                    self::scheduleDeletion($row['path'], $row['tenant_id'], 'expired-upload:' . $row['id']);
                }

                Database::execute('DELETE FROM agent_media_grants WHERE id = ?', [$row['id']]);
                return 1;
            });
        }

        Database::execute(
            'DELETE FROM agent_media_grants WHERE consumed_at < ?',
            [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]
        );
        return [
            'expired' => $expired,
            'reconciled_uploads' => $journal['preserved'] + $journal['removed'],
            'removed_uncommitted_uploads' => $journal['removed'],
            'deferred_uploads' => $journal['deferred']
        ];
    }

    public static function consume(ActorContext $actor, string $businessId, string $mediaId, string $purpose): string
    {
        return Access::transaction(static function () use ($actor, $businessId, $mediaId, $purpose): string {
            Access::tenant($actor, $businessId, 'owner', true);
            $row = Database::query('SELECT * FROM agent_media_grants WHERE id = ? FOR UPDATE', [$mediaId])[0] ?? null;
            self::authorize($actor, $row);
            if (
                $row['tenant_id'] !== $businessId
                || $row['purpose'] !== $purpose
                || $row['path'] === null
                || $row['consumed_at'] !== null
            ) {
                throw new OperationException('upload_grant_invalid', 422);
            }

            Database::execute(
                'UPDATE agent_media_grants SET consumed_at = ? WHERE id = ?',
                [gmdate('Y-m-d H:i:s'), $mediaId]
            );
            return $row['path'];
        });
    }

    public static function storeBrowser(ActorContext $actor, string $businessId, string $purpose, array $file): string
    {
        $grant = self::prepare($actor, $businessId, $purpose);
        self::receive($actor, $grant['media_id'], $grant['upload_token'], $file);
        return $grant['media_id'];
    }

    public static function scheduleDeletion(string $path, string $businessId, string $dedupeKey): void
    {
        if (!preg_match(
            '#\Auploads/[a-z0-9-]+/(?:logo|avatar|cover|event|resource|service-cover)/[A-Za-z0-9]+\.(?:png|jpe?g|webp|gif)\z#D',
            $path
        )) {
            throw new OperationException('upload_grant_invalid', 422);
        }

        Outbox::enqueue('media.delete', ['path' => $path], $businessId, $dedupeKey);
    }

    public static function deliverDeletion(array $payload): array
    {
        $path = $payload['path'] ?? '';
        if (!preg_match(
            '#\Auploads/[a-z0-9-]+/(?:logo|avatar|cover|event|resource|service-cover)/[A-Za-z0-9]+\.(?:png|jpe?g|webp|gif)\z#D',
            $path
        )) {
            throw new \RuntimeException('invalid_media_path');
        }

        ImageUpload::delete($path);
        return ['deleted' => true];
    }

    public static function validateImage(array $file): void
    {
        $path = $file['tmp_name'] ?? '';
        if (
            ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK
            || !is_file($path)
            || filesize($path) > 2097152
            || filesize($path) < 1
        ) {
            throw new OperationException('upload_invalid', 422);
        }

        $info = @getimagesize($path);
        if (
            $info === false
            || $info[0] > 8000
            || $info[1] > 8000
            || $info[0] * $info[1] > 24000000
            || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)
        ) {
            throw new OperationException('upload_invalid', 422);
        }

        if (!extension_loaded('gd')) {
            throw new OperationException('image_processing_unavailable', 503);
        }
    }

    private static function sanitize(string $relativePath): void
    {
        $path = rtrim(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 3) . '/public', '/') . '/' . $relativePath;
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if ($image === false) {
            throw new OperationException('upload_invalid', 422);
        }

        imagesavealpha($image, true);
        $success = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'jpg',
            'jpeg' => imagejpeg($image, $path, 90),
            'png' => imagepng($image, $path),
            'webp' => imagewebp($image, $path, 90),
            'gif' => imagegif($image, $path),
            default => false
        };
        if (!$success) {
            throw new OperationException('upload_invalid', 422);
        }
    }

    private static function authorize(ActorContext $actor, ?array $row): void
    {
        if (
            !$row
            || $row['principal_type'] !== $actor->principalType
            || $row['principal_id'] !== $actor->principalId
            || $row['connection_id'] !== $actor->connectionId
        ) {
            throw new OperationException('not_found', 404);
        }

        $actor->business($row['tenant_id']);
        self::sourceActor($row);
        if (strtotime($row['expires_at'] . ' UTC') <= time()) {
            throw new OperationException('upload_expired', 410);
        }
    }

    private static function sourceActor(array $row): ActorContext
    {
        $actor = $row['connection_id'] !== null
            ? (new \App\Agent\ConnectionAuthenticator())->refresh(new ActorContext(
                $row['principal_type'],
                $row['principal_id'],
                '',
                [],
                [],
                connectionId: $row['connection_id'],
                credentialId: $row['credential_id']
            ))
            : (new \App\Agent\PrincipalResolver())->resolve(
                $row['principal_type'],
                $row['principal_id'],
                [$row['tenant_id']],
                ['media:manage'],
                $row['identity_hash'],
                authMode: 'session'
            );
        if (!in_array('media:manage', $actor->scopes, true)) {
            throw new OperationException('forbidden', 403);
        }

        Access::tenant($actor, $row['tenant_id'], 'owner');
        return $actor;
    }
}
