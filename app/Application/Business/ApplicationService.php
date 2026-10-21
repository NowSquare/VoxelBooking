<?php

declare(strict_types=1);

namespace App\Application\Business;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Application\Shared\Access;
use App\Engine\Database;
use App\Engine\DemoMode;
use App\Engine\Ulid;

final class ApplicationService
{
    public static function submit(ActorContext $actor, array $input): array
    {
        return Access::transaction(function () use ($actor, $input): array {
            if (DemoMode::isActive()) {
                throw new OperationException('demo_restricted', 403);
            }
            // Serializes pending-email dedupe and per-email abuse admission.
            $enabled = Database::query("SELECT value FROM settings WHERE `key` = 'enable_applications' FOR UPDATE")[0][
                'value'
            ] ?? '0';
            if ($enabled !== '1') {
                throw new OperationException('applications_closed', 403);
            }
            $data = [];
            foreach (
                [
                    'business_name' => 255,
                    'contact_name' => 255,
                    'email' => 255,
                    'phone' => 50,
                    'website' => 2048,
                    'message' => 10000
                ] as $field => $limit
            ) {
                $value = trim((string) ($input[$field] ?? ''));
                if (
                    mb_strlen($value) > $limit
                    || ($value === '' && in_array($field, ['business_name', 'contact_name', 'email'], true))
                ) {
                    BusinessAccess::invalid($field);
                }
                $data[$field] = $value === '' ? null : $value;
            }
            $data['email'] = mb_strtolower($data['email']);
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                BusinessAccess::invalid('email');
            }
            if (
                $data['website'] !== null
                && (!filter_var($data['website'], FILTER_VALIDATE_URL)
                    || !in_array(strtolower(parse_url($data['website'], PHP_URL_SCHEME)), ['http', 'https'], true))
            ) {
                BusinessAccess::invalid('website');
            }
            $recent = Database::query(
                'SELECT status FROM business_applications WHERE email = ? AND (status = ? OR created_at > ?)',
                [$data['email'], 'pending', gmdate('Y-m-d H:i:s', time() - 86400)]
            );
            // Same acknowledgement avoids revealing whether an email already applied.
            if (array_filter($recent, static fn (array $row): bool => $row['status'] === 'pending')) {
                return ['accepted' => true, 'status' => 'received'];
            }
            if (count($recent) >= 3) {
                throw new OperationException('rate_limited', 429, retryable: true);
            }
            $id = Ulid::generate();
            Database::execute(
                "INSERT INTO business_applications (id,business_name,contact_name,email,phone,website,message,status,created_at) VALUES (?,?,?,?,?,?,?,'pending',?)",
                [$id, ...array_values($data), gmdate('Y-m-d H:i:s')]
            );
            return ['accepted' => true, 'status' => 'received'];
        });
    }

    public static function list(ActorContext $actor, array $input): array
    {
        $actor = BusinessAccess::actor($actor, 'applications:read');
        $status = $input['status'] ?? 'pending';
        if (!in_array($status, ['pending', 'approved', 'rejected', 'all'], true)) {
            BusinessAccess::invalid('status');
        }

        return BusinessAccess::page(
            $actor,
            'business_applications',
            $status === 'all' ? '1=1' : 'status = ?',
            $status === 'all' ? [] : [$status],
            $input,
            self::project(...)
        );
    }

    public static function get(ActorContext $actor, array $input): array
    {
        BusinessAccess::actor($actor, 'applications:read');
        return self::project(self::row((string) ($input['id'] ?? '')));
    }

    public static function review(ActorContext $actor, array $input): array
    {
        return Access::transaction(function () use ($actor, $input): array {
            $actor = BusinessAccess::actor($actor, 'applications:manage', write: true);
            $row = self::row((string) ($input['id'] ?? ''), true);
            $actor = BusinessAccess::actor($actor, 'applications:manage', write: true);
            BusinessAccess::check(self::project($row), $input);
            if ($row['status'] !== 'pending') {
                throw new OperationException('already_reviewed', 409);
            }
            if (!in_array($input['decision'] ?? '', ['approved', 'rejected'], true)) {
                BusinessAccess::invalid('decision');
            }
            $notes = trim((string) ($input['admin_notes'] ?? '')) ?: null;
            if ($notes !== null && mb_strlen($notes) > 10000) {
                BusinessAccess::invalid('admin_notes');
            }
            Database::execute(
                'UPDATE business_applications SET status = ?, admin_notes = ?, reviewed_at = ?, reviewed_by = ? WHERE id = ?',
                [$input['decision'], $notes, gmdate('Y-m-d H:i:s'), $actor->principalId, $row['id']]
            );
            BusinessAccess::audit($actor, 'application.' . $input['decision'], 'business_application', $row['id']);
            return self::project(self::row($row['id']));
        });
    }

    public static function preview(ActorContext $actor, array $input): array
    {
        BusinessAccess::actor($actor, 'applications:manage', write: true);
        $row = self::project(self::row((string) ($input['id'] ?? '')));
        BusinessAccess::check($row, $input);

        return [
            'version' => $row['version'],
            'before' => $row,
            'after' => array_replace($row, [
                'status' => $input['decision'],
                'admin_notes' => $input['admin_notes'] ?? null
            ]),
            'business_provisioned' => false
        ];
    }

    public static function project(array $row): array
    {
        $fields = [
            'id',
            'business_name',
            'contact_name',
            'email',
            'phone',
            'website',
            'message',
            'status',
            'admin_notes'
        ];
        return BusinessAccess::version(array_intersect_key($row, array_flip($fields))) + [
            'created_at' => $row['created_at'],
            'reviewed_at' => $row['reviewed_at'],
            'reviewed_by' => $row['reviewed_by']
        ];
    }

    private static function row(string $id, bool $lock = false): array
    {
        return Database::query(
            'SELECT * FROM business_applications WHERE id = ?' . ($lock ? ' FOR UPDATE' : ''),
            [$id]
        )[0] ?? throw new OperationException('not_found', 404);
    }
}
