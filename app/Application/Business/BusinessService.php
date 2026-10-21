<?php

declare(strict_types=1);

namespace App\Application\Business;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Application\Shared\Access;
use App\Application\Shared\Csv;
use App\Engine\Database;
use App\Models\Tenant;

final class BusinessService
{
    public const FIELDS = [
        'id',
        'name',
        'slug',
        'email',
        'booking_pattern',
        'status',
        'timezone',
        'currency',
        'locale',
        'date_format',
        'number_format',
        'time_format',
        'week_start',
        'brand_color'
    ];

    public static function get(ActorContext $actor, array $input): array
    {
        $actor = BusinessAccess::actor($actor, 'businesses:read', 'staff');
        return self::project(BusinessAccess::tenant($actor, $input, archived: $actor->role === 'operator'));
    }

    public static function list(ActorContext $actor, array $input): array
    {
        $actor = BusinessAccess::actor($actor, 'businesses:read', 'staff');
        [$where, $bindings] = self::filter($actor, $input);
        return BusinessAccess::page($actor, 'tenants', $where, $bindings, $input, self::project(...));
    }

    public static function create(
        ActorContext $actor,
        array $input,
        ?string $browserOwnerPassword = null,
        bool $sendInvitation = true
    ): array {
        return Access::transaction(function () use ($actor, $input, $browserOwnerPassword, $sendInvitation): array {
            $actor = BusinessAccess::actor($actor, 'businesses:manage', write: true);
            $data = self::validate($input, true);
            try {
                $id = Tenant::create($data);
                if (isset($input['owner'])) {
                    if (!in_array('team:manage', $actor->scopes, true)) {
                        throw new OperationException('insufficient_scope', 403);
                    }
                    try {
                        TeamService::createForNewBusiness(
                            $actor,
                            $id,
                            $input['owner'],
                            $browserOwnerPassword,
                            $sendInvitation
                        );
                    } catch (OperationException $error) {
                        $fields = [];
                        foreach ($error->fieldErrors as $field => $reason) {
                            $fields['owner_' . $field] = $reason;
                        }
                        throw new OperationException(
                            $error->problemCode,
                            $error->status,
                            $fields,
                            $error->nextAction,
                            $error->retryable
                        );
                    }
                }
            } catch (\PDOException $exception) {
                if (str_starts_with((string) $exception->getCode(), '23')) {
                    throw new OperationException('conflict', 409, ['slug' => 'unavailable']);
                }
                throw $exception;
            }
            BusinessAccess::audit($actor, 'tenant.created', 'tenant', $id, $id);
            return self::project(Tenant::find($id));
        });
    }

    public static function previewCreate(ActorContext $actor, array $input): array
    {
        $actor = BusinessAccess::actor($actor, 'businesses:manage', write: true);
        $data = self::validate($input, true);
        $owner = isset($input['owner']) ? TeamService::previewNewOwner($actor, $input['owner']) : null;

        return ['business' => $data, 'owner_invitation' => $owner, 'new_business_grant_included' => false];
    }

    public static function update(ActorContext $actor, array $input): array
    {
        return Access::transaction(function () use ($actor, $input): array {
            $actor = BusinessAccess::actor($actor, 'businesses:manage', write: true);
            $tenant = BusinessAccess::tenant($actor, $input, true);
            $actor = BusinessAccess::actor($actor, 'businesses:manage', write: true);
            $actor->business($tenant['id']);
            BusinessAccess::check(self::project($tenant), $input);
            if (isset($input['booking_pattern']) && $input['booking_pattern'] !== $tenant['booking_pattern']) {
                BusinessAccess::invalid('booking_pattern', 'immutable');
            }
            $data = self::validate(array_replace($tenant, $input), false, $tenant['id']);
            Tenant::update($tenant['id'], $data);
            BusinessAccess::audit($actor, 'tenant.updated', 'tenant', $tenant['id'], $tenant['id']);
            return self::project(Tenant::find($tenant['id']));
        });
    }

    public static function setStatus(ActorContext $actor, array $input): array
    {
        return Access::transaction(function () use ($actor, $input): array {
            $actor = BusinessAccess::actor($actor, 'businesses:manage', write: true);
            $tenant = BusinessAccess::tenant($actor, $input, true, true);
            $actor = BusinessAccess::actor($actor, 'businesses:manage', write: true);
            $actor->business($tenant['id']);
            BusinessAccess::check(self::project($tenant), $input);
            if (!in_array($input['status'] ?? '', ['active', 'archived'], true)) {
                BusinessAccess::invalid('status');
            }
            Tenant::update($tenant['id'], ['status' => $input['status']]);
            BusinessAccess::audit(
                $actor,
                'tenant.' . ($input['status'] === 'active' ? 'activated' : 'archived'),
                'tenant',
                $tenant['id'],
                $tenant['id']
            );
            return self::project(Tenant::find($tenant['id']));
        });
    }

    public static function preview(ActorContext $actor, array $input, string $action): array
    {
        $actor = BusinessAccess::actor($actor, 'businesses:manage', write: true);
        $tenant = BusinessAccess::tenant($actor, $input, archived: $action === 'status');
        $before = self::project($tenant);
        BusinessAccess::check($before, $input);
        $after = $action === 'status'
            ? ['status' => $input['status']]
            : self::validate(array_replace($tenant, $input), false, $tenant['id']);

        return [
            'business_id' => $tenant['id'],
            'version' => $before['version'],
            'before' => $before,
            'after' => array_replace($before, $after),
            'affected_booking_count' => (int) Database::query(
                "SELECT COUNT(*) AS total FROM bookings WHERE tenant_id = ? AND status IN ('pending','confirmed','waitlisted') AND end_datetime > ?",
                [
                    $tenant['id'],
                    (new \DateTimeImmutable('now', new \DateTimeZone($tenant['timezone'])))->format('Y-m-d H:i:s')
                ]
            )[0]['total'],
            'existing_bookings_preserved' => true,
            'public_booking_available' => ($after['status'] ?? $tenant['status']) === 'active'
        ];
    }

    public static function export(ActorContext $actor, array $input): array
    {
        $actor = BusinessAccess::actor($actor, 'businesses:read');
        [$where, $bindings] = self::filter($actor, $input);
        $rows = Database::query("SELECT * FROM tenants WHERE {$where} ORDER BY id LIMIT 5001", $bindings);
        if (count($rows) > 5000) {
            throw new OperationException('export_too_large', 422, nextAction: 'narrow_filters');
        }
        $data = array_map(
            static fn (array $row): array => [
                $row['name'],
                $row['slug'],
                $row['email'],
                $row['booking_pattern'],
                $row['status'],
                Tenant::bookingCount($row['id']),
                Tenant::serviceCount($row['id'])
            ],
            $rows
        );

        return [
            'filename' => 'businesses-' . gmdate('Y-m-d') . '.csv',
            'content_type' => 'text/csv; charset=UTF-8',
            'row_count' => count($data),
            'content' => Csv::encode(['Name', 'Slug', 'Email', 'Pattern', 'Status', 'Bookings', 'Services'], $data)
        ];
    }

    public static function project(array $row): array
    {
        $data = array_intersect_key($row, array_flip(self::FIELDS));
        $data['week_start'] = (string) $data['week_start'];
        return BusinessAccess::version($data);
    }

    private static function validate(array $input, bool $create, ?string $id = null): array
    {
        $defaults = self::defaults();
        $data = array_intersect_key(
            array_replace($defaults, $input),
            array_flip([
                'name',
                'slug',
                'email',
                'timezone',
                'currency',
                'brand_color',
                ...($create
                    ? ['booking_pattern', 'locale', 'date_format', 'number_format', 'time_format', 'week_start']
                    : [])
            ])
        );
        foreach (['name' => 255, 'email' => 255] as $field => $limit) {
            $data[$field] = trim((string) ($data[$field] ?? ''));
            if ($data[$field] === '' || mb_strlen($data[$field]) > $limit) {
                BusinessAccess::invalid($field);
            }
        }
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            BusinessAccess::invalid('email');
        }
        $data['slug'] = trim((string) ($data['slug'] ?? ''));
        if ($data['slug'] === '' && $create) {
            $data['slug'] = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($data['name'])), '-') ?: 'business';
        }
        if (strlen($data['slug']) > 100 || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/D', $data['slug'])) {
            BusinessAccess::invalid('slug');
        }
        if (Tenant::slugExists($data['slug'], $id)) {
            throw new OperationException('conflict', 409, ['slug' => 'unavailable']);
        }
        if (!in_array($data['timezone'], get_supported_timezones(), true)) {
            BusinessAccess::invalid('timezone');
        }
        $data['currency'] = strtoupper($data['currency']);
        if (!array_key_exists($data['currency'], get_supported_currencies())) {
            BusinessAccess::invalid('currency');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/D', $data['brand_color'] ?? '#2563EB')) {
            BusinessAccess::invalid('brand_color');
        }
        if ($create && !in_array($data['booking_pattern'] ?? '', ['timeslot', 'resource', 'capacity', 'event'], true)) {
            BusinessAccess::invalid('booking_pattern');
        }

        return $data;
    }

    private static function filter(ActorContext $actor, array $input): array
    {
        $ids = $actor->businessIds;
        $where = $ids === [] ? '1=0' : 'id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $bindings = $ids;
        if (!empty($input['status']) && $input['status'] !== 'all') {
            if (!in_array($input['status'], ['active', 'paused', 'archived'], true)) {
                BusinessAccess::invalid('status');
            }
            $where .= ' AND status = ?';
            $bindings[] = $input['status'];
        }
        if (!empty($input['search'])) {
            if (strlen($input['search']) > 255) {
                BusinessAccess::invalid('search');
            }
            $where .= ' AND (name LIKE ? OR slug LIKE ? OR email LIKE ?)';
            $search = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $input['search']) . '%';
            array_push($bindings, $search, $search, $search);
        }

        return [$where, $bindings];
    }

    public static function defaults(): array
    {
        $settings = array_column(
            Database::query(
                "SELECT `key`, value FROM settings WHERE `key` IN ('timezone','default_timezone','default_currency','default_locale','date_format','number_format','time_format','week_start')"
            ),
            'value',
            'key'
        );
        return [
            'timezone' => ($settings['timezone'] ?? '') ?: ($settings['default_timezone'] ?? 'UTC'),
            'currency' => $settings['default_currency'] ?? 'EUR',
            'locale' => $settings['default_locale'] ?? 'en',
            'date_format' => $settings['date_format'] ?? 'Y-m-d',
            'number_format' => $settings['number_format'] ?? 'period',
            'time_format' => $settings['time_format'] ?? '24h',
            'week_start' => $settings['week_start'] ?? '1',
            'booking_pattern' => 'timeslot',
            'brand_color' => '#2563EB'
        ];
    }
}
