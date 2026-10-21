<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Agent\{ActorContext, CanonicalJson, ConnectionAuthenticator, Endpoints, OperationException};
use App\Application\Shared\Access;
use App\Engine\{AuditLog, Database, DemoMode};

final class AccountService
{
    public static function read(ActorContext $actor): array
    {
        $actor = (new ConnectionAuthenticator())->refresh($actor);
        if (!$actor->isStaff() || array_intersect(['account:read', 'account:manage'], $actor->scopes) === []) {
            throw new OperationException('forbidden', 403);
        }

        $table = $actor->principalType === 'operator' ? 'operators' : 'business_users';
        $row = Database::query('SELECT id,name,email FROM ' . $table . ' WHERE id = ?', [$actor->principalId])[0]
            ?? throw new OperationException('unauthorized', 401);
        return [
            ...$row,
            'role' => $actor->role,
            'business_ids' => $actor->businessIds,
            'version' => CanonicalJson::hash([$row['name'], $row['email']]),
            'credentials_url' => Endpoints::baseUrl() . '/admin/account'
        ];
    }

    public static function update(ActorContext $actor, array $input, ?string $browserEmail = null): array
    {
        if (DemoMode::isActive()) {
            throw new OperationException('demo_restricted', 403);
        }

        return Access::transaction(static function () use ($actor, $input, $browserEmail): array {
            $before = self::read($actor);
            $table = $actor->principalType === 'operator' ? 'operators' : 'business_users';
            Database::query('SELECT id FROM ' . $table . ' WHERE id = ? FOR UPDATE', [$actor->principalId]);
            $actor = (new ConnectionAuthenticator())->refresh($actor);
            if (!in_array('account:manage', $actor->scopes, true)) {
                throw new OperationException('forbidden', 403);
            }

            $before = self::read($actor);
            if (!hash_equals($before['version'], $input['expected_version'])) {
                throw new OperationException('version_conflict', 409);
            }

            $name = trim($input['name']);
            if ($name === '' || mb_strlen($name) > 255) {
                throw new OperationException('validation_failed', 422, ['name' => ['required']]);
            }

            $email = $before['email'];
            if ($browserEmail !== null) {
                if ($actor->authMode !== 'session') {
                    throw new OperationException('trusted_review_required', 403);
                }

                $email = strtolower(trim($browserEmail));
                if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
                    throw new OperationException('validation_failed', 422, ['email' => ['invalid']]);
                }
            }

            try {
                Database::execute(
                    'UPDATE ' . $table . ' SET name = ?, email = ?, updated_at = NOW() WHERE id = ?',
                    [$name, $email, $actor->principalId]
                );
                if ($email !== $before['email']) {
                    Database::execute(
                        'UPDATE auth_emails SET email = ? WHERE user_type = ? AND user_id = ?',
                        [$email, $actor->principalType, $actor->principalId]
                    );
                    Database::execute(
                        'UPDATE agent_connections SET revoked_at = COALESCE(revoked_at, ?) WHERE principal_type = ? AND principal_id = ?',
                        [gmdate('Y-m-d H:i:s'), $actor->principalType, $actor->principalId]
                    );
                }
            } catch (\PDOException $error) {
                if (str_starts_with((string) $error->getCode(), '23')) {
                    throw new OperationException('identity_unavailable', 409);
                }

                throw $error;
            }

            AuditLog::log(
                'account.profile_updated',
                $actor->principalType,
                $actor->principalId,
                ['name_changed' => $name !== $before['name'], 'identity_changed' => $email !== $before['email']],
                actorType: $actor->auditType(),
                actorId: $actor->principalId
            );
            return [...$before, 'name' => $name, 'email' => $email, 'version' => CanonicalJson::hash([$name, $email])];
        });
    }
}
