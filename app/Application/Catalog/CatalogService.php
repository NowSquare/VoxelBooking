<?php

declare(strict_types=1);

namespace App\Application\Catalog;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Agent\PrincipalResolver;
use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\DemoMode;
use App\Engine\Ulid;

/** Catalog rules shared by forms and both agent transports. */
final class CatalogService
{
    public static function list(ActorContext $actor, string $kind, array $input): array
    {
        [$actor, $tenant] = self::guard($actor, $kind, $input);
        $limit = max(1, min(100, (int) ($input['limit'] ?? 50)));
        $filter = $input['is_active'] ?? null;
        $context = [$actor->principalType, $actor->principalId, $actor->connectionId, $actor->credentialId, $actor->businessIds, $actor->scopes, $kind, $tenant['id'], $filter];
        $cursor = null;
        if (!empty($input['cursor'])) {
            try {
                $cursor = (new \App\Agent\PayloadCipher())->decrypt($input['cursor']);
            } catch (\Throwable) {
                throw new OperationException('invalid_cursor', 422);
            }
            if (($cursor['context'] ?? null) !== $context || ($cursor['expires'] ?? 0) < time()
                || !is_string($cursor['after'] ?? null) || !is_string($cursor['through'] ?? null)) {
                throw new OperationException('invalid_cursor', 422);
            }
        }
        $where = 'tenant_id = ?';
        $values = [$tenant['id']];
        if ($filter !== null && $kind !== 'blocked_dates') {
            $where .= ' AND is_active = ?';
            $values[] = (int) $filter;
        }
        $through = $cursor['through'] ?? (Database::query("SELECT MAX(id) AS last_id FROM `{$kind}` WHERE {$where}", $values)[0]['last_id'] ?? '');
        $where .= ' AND id > ? AND id <= ?';
        $values[] = $cursor['after'] ?? '';
        $values[] = $through;
        // Immutable identifiers keep pages stable when names or display order change.
        $rows = Database::query("SELECT * FROM `{$kind}` WHERE {$where} ORDER BY id LIMIT " . ($limit + 1), $values);
        $hasMore = count($rows) > $limit;
        $rows = array_slice($rows, 0, $limit);
        $next = $hasMore ? (new \App\Agent\PayloadCipher())->encrypt([
            'context' => $context, 'after' => $rows[array_key_last($rows)]['id'], 'through' => $through,
            'expires' => $cursor['expires'] ?? time() + 3600,
        ]) : null;
        return ['items' => array_map(static fn (array $row): array => self::project($kind, $row, $tenant), $rows), 'next_cursor' => $next];
    }

    public static function get(ActorContext $actor, string $kind, array $input): array
    {
        [, $tenant] = self::guard($actor, $kind, $input);
        return self::project($kind, self::row($kind, (string) ($input['id'] ?? ''), $tenant['id']), $tenant);
    }

    public static function create(ActorContext $actor, string $kind, array $input): array
    {
        return self::transaction(function () use ($actor, $kind, $input): array {
            [$actor, $tenant] = self::guard($actor, $kind, $input, true);
            $data = CatalogFields::validate($kind, array_replace(CatalogFields::defaults($kind), $input), $tenant);
            self::validateReferences($kind, $data, $tenant);
            $id = Ulid::generate();
            $data = self::writeMedia($actor, $kind, $tenant['id'], $input, $data);
            $relations = self::extractRelations($kind, $data);
            if (in_array($kind, ['services', 'staff', 'resources', 'events'], true)) {
                $max = Database::query("SELECT COALESCE(MAX(sort_order), -1) AS max_order FROM `{$kind}` WHERE tenant_id = ?", [$tenant['id']]);
                $data['sort_order'] = (int) $max[0]['max_order'] + 1;
            }
            self::encodeJson($data);
            $data = ['id' => $id, 'tenant_id' => $tenant['id'], ...$data];
            $columns = implode(', ', array_map(static fn (string $name): string => '`' . $name . '`', array_keys($data)));
            try {
                Database::execute("INSERT INTO `{$kind}` ({$columns}) VALUES (" . implode(', ', array_fill(0, count($data), '?')) . ')', array_values($data));
                self::syncRelations($kind, $id, $tenant['id'], $relations);
            } catch (\PDOException $exception) {
                if (str_starts_with((string) $exception->getCode(), '23')) {
                    throw new OperationException('conflict', 409, nextAction: 'refresh');
                }
                throw $exception;
            }
            self::audit($actor, $kind, $id, $tenant['id'], 'created');
            return self::project($kind, self::row($kind, $id, $tenant['id']), $tenant);
        });
    }

    public static function update(ActorContext $actor, string $kind, array $input): array
    {
        return self::transaction(function () use ($actor, $kind, $input): array {
            [$actor, $tenant] = self::guard($actor, $kind, $input, true);
            $id = (string) ($input['id'] ?? '');
            $before = self::project($kind, self::row($kind, $id, $tenant['id']), $tenant);
            self::checkVersion($before, $input);
            $data = CatalogFields::validate($kind, array_replace($before, $input), $tenant);
            self::validateReferences($kind, $data, $tenant, $id);
            if ($kind === 'events') {
                self::validateEventInventory($id, $data, $before);
            }
            if ($kind === 'capacity_slots') {
                self::validateCapacityInventory($before, $data);
            }
            $data = self::writeMedia($actor, $kind, $tenant['id'], $input, $data);
            $relations = self::extractRelations($kind, $data);
            self::encodeJson($data);
            $assignments = implode(', ', array_map(static fn (string $name): string => '`' . $name . '` = ?', array_keys($data)));
            try {
                Database::execute("UPDATE `{$kind}` SET {$assignments} WHERE id = ? AND tenant_id = ?", [...array_values($data), $id, $tenant['id']]);
                self::syncRelations($kind, $id, $tenant['id'], $relations);
            } catch (\PDOException $exception) {
                if (str_starts_with((string) $exception->getCode(), '23')) {
                    throw new OperationException('conflict', 409, nextAction: 'refresh');
                }
                throw $exception;
            }
            $imageField = $kind === 'staff' ? 'avatar_path' : 'cover_image_path';
            if (array_key_exists($imageField, $data) && !empty($before['image_path']) && $before['image_path'] !== $data[$imageField]) {
                \App\Application\Media\MediaService::scheduleDeletion($before['image_path'], $tenant['id'], 'catalog-media:' . $id . ':' . $before['version']);
            }
            self::audit($actor, $kind, $id, $tenant['id'], 'updated');
            return self::project($kind, self::row($kind, $id, $tenant['id']), $tenant);
        });
    }

    public static function setActive(ActorContext $actor, string $kind, array $input): array
    {
        if ($kind === 'blocked_dates') {
            throw new OperationException('not_found', 404);
        }
        return self::transaction(function () use ($actor, $kind, $input): array {
            [$actor, $tenant] = self::guard($actor, $kind, $input, true, in_array($kind, ['services', 'staff', 'resources'], true));
            $record = self::project($kind, self::row($kind, (string) ($input['id'] ?? ''), $tenant['id']), $tenant);
            self::checkVersion($record, $input);
            if (!is_bool($input['is_active'] ?? null)) {
                CatalogFields::invalid('is_active', 'invalid_type');
            }
            Database::execute("UPDATE `{$kind}` SET is_active = ? WHERE id = ? AND tenant_id = ?", [(int) $input['is_active'], $record['id'], $tenant['id']]);
            self::audit($actor, $kind, $record['id'], $tenant['id'], $input['is_active'] ? 'activated' : 'deactivated');
            return self::project($kind, self::row($kind, $record['id'], $tenant['id']), $tenant);
        });
    }

    public static function reorder(ActorContext $actor, string $kind, array $input): array
    {
        if (!in_array($kind, ['services', 'staff', 'resources'], true)) {
            throw new OperationException('not_found', 404);
        }
        return self::transaction(function () use ($actor, $kind, $input): array {
            [$actor, $tenant] = self::guard($actor, $kind, $input, true);
            $record = self::project($kind, self::row($kind, (string) ($input['id'] ?? ''), $tenant['id']), $tenant);
            self::checkVersion($record, $input);
            if (!in_array($input['direction'] ?? '', ['up', 'down'], true)) {
                CatalogFields::invalid('direction', 'invalid_value');
            }
            $siblings = Database::query("SELECT id FROM `{$kind}` WHERE tenant_id = ? AND is_active = ? ORDER BY sort_order, name, id", [$tenant['id'], (int) $record['is_active']]);
            $ids = array_column($siblings, 'id');
            $index = array_search($record['id'], $ids, true);
            $target = $index + ($input['direction'] === 'up' ? -1 : 1);
            if (isset($ids[$target])) {
                [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];
            }
            foreach ($ids as $order => $id) {
                Database::execute("UPDATE `{$kind}` SET sort_order = ? WHERE id = ? AND tenant_id = ?", [$order, $id, $tenant['id']]);
            }
            self::audit($actor, $kind, $record['id'], $tenant['id'], 'reordered');
            return self::project($kind, self::row($kind, $record['id'], $tenant['id']), $tenant);
        });
    }

    public static function delete(ActorContext $actor, string $kind, array $input): array
    {
        if (!in_array($kind, ['capacity_slots', 'events', 'blocked_dates'], true)) {
            throw new OperationException('not_found', 404);
        }
        return self::transaction(function () use ($actor, $kind, $input): array {
            [$actor, $tenant] = self::guard($actor, $kind, $input, true);
            $record = self::project($kind, self::row($kind, (string) ($input['id'] ?? ''), $tenant['id']), $tenant);
            self::checkVersion($record, $input);
            if ($kind === 'events' && Database::query('SELECT id FROM bookings WHERE tenant_id = ? AND event_id = ? LIMIT 1', [$tenant['id'], $record['id']]) !== []) {
                throw new OperationException('record_in_use', 409, nextAction: 'deactivate_instead');
            }
            if ($kind === 'capacity_slots') { self::validateCapacityInventory($record, null); }
            Database::execute("DELETE FROM `{$kind}` WHERE id = ? AND tenant_id = ?", [$record['id'], $tenant['id']]);
            if (!empty($record['image_path'])) {
                \App\Application\Media\MediaService::scheduleDeletion($record['image_path'], $tenant['id'], 'catalog-delete:' . $record['id']);
            }
            self::audit($actor, $kind, $record['id'], $tenant['id'], 'deleted');
            return ['id' => $record['id'], 'business_id' => $tenant['id'], 'deleted' => true];
        });
    }

    public static function preview(ActorContext $actor, string $kind, string $action, array $input): array
    {
        return self::transaction(function () use ($actor, $kind, $action, $input): array {
            [, $tenant] = self::guard($actor, $kind, $input, true, $action === 'set_active' && in_array($kind, ['services', 'staff', 'resources'], true));
            $before = null;
            if ($action !== 'create') {
                $before = self::project($kind, self::row($kind, (string) ($input['id'] ?? ''), $tenant['id']), $tenant);
                self::checkVersion($before, $input);
            }
            $after = match ($action) {
                'delete' => null,
                'set_active' => array_replace($before, ['is_active' => $input['is_active']]),
                default => CatalogFields::validate($kind, array_replace($before ?? CatalogFields::defaults($kind), $input), $tenant),
            };
            if ($after !== null && $action !== 'set_active') {
                self::validateReferences($kind, $after, $tenant, $before['id'] ?? null);
                if ($kind === 'events' && $before !== null) { self::validateEventInventory($before['id'], $after, $before); }
            }
            if ($kind === 'capacity_slots' && $before !== null && $action !== 'set_active') {
                self::validateCapacityInventory($before, $after);
            }
            if ($kind === 'events' && $action === 'delete' && Database::query('SELECT id FROM bookings WHERE tenant_id = ? AND event_id = ? LIMIT 1', [$tenant['id'], $before['id']]) !== []) {
                throw new OperationException('record_in_use', 409, nextAction: 'deactivate_instead');
            }
            return [
                'business_id' => $tenant['id'], 'action' => $action,
                'before' => $before, 'after' => $after,
                'version' => $before['version'] ?? null,
                'affected_booking_count' => $kind === 'blocked_dates' ? self::blockImpact($tenant['id'], $after ?? $before) : null,
                'existing_bookings_preserved' => true,
            ];
        });
    }

    public static function getSchedule(ActorContext $actor, array $input): array
    {
        [, $tenant] = self::guard($actor, 'services', $input, scope: 'availability');
        $staffId = $input['staff_id'] ?? null;
        if ($staffId !== null) {
            self::row('staff', (string) $staffId, $tenant['id']);
        }
        return self::schedule($tenant, $staffId);
    }

    public static function saveSchedule(ActorContext $actor, array $input): array
    {
        return self::transaction(function () use ($actor, $input): array {
            [$actor, $tenant] = self::guard($actor, 'services', $input, true, scope: 'availability');
            $staffId = $input['staff_id'] ?? null;
            if ($staffId !== null) {
                self::row('staff', $staffId, $tenant['id']);
            }
            self::checkVersion(self::schedule($tenant, $staffId), $input);
            $normalized = self::normalizeWindows($input['windows'] ?? null);
            self::clearSchedule($tenant['id'], $staffId);
            foreach ($normalized as $window) {
                Database::execute('INSERT INTO availability (id, tenant_id, staff_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, ?, ?, ?, ?, 1)', [Ulid::generate(), $tenant['id'], $staffId, $window['day_of_week'], $window['start_time'], $window['end_time']]);
            }
            // A closed override must remain an override, rather than inheriting.
            if ($staffId !== null && $normalized === []) {
                Database::execute("INSERT INTO availability (id, tenant_id, staff_id, day_of_week, start_time, end_time, is_available) VALUES (?, ?, ?, 0, '00:00:00', '00:00:00', 0)", [Ulid::generate(), $tenant['id'], $staffId]);
            }
            self::audit($actor, 'availability', $staffId ?? $tenant['id'], $tenant['id'], $staffId === null ? 'updated' : 'staff_override_set');
            return self::schedule($tenant, $staffId);
        });
    }

    public static function resetSchedule(ActorContext $actor, array $input): array
    {
        return self::transaction(function () use ($actor, $input): array {
            [$actor, $tenant] = self::guard($actor, 'services', $input, true, scope: 'availability');
            $staffId = (string) ($input['staff_id'] ?? '');
            self::row('staff', $staffId, $tenant['id']);
            self::checkVersion(self::schedule($tenant, $staffId), $input);
            self::clearSchedule($tenant['id'], $staffId);
            self::audit($actor, 'availability', $staffId, $tenant['id'], 'staff_override_reset');
            return self::schedule($tenant, $staffId);
        });
    }

    public static function previewSchedule(ActorContext $actor, array $input, bool $reset = false): array
    {
        [, $tenant] = self::guard($actor, 'services', $input, true, scope: 'availability');
        $staffId = $input['staff_id'] ?? null;
        if ($staffId !== null) {
            self::row('staff', $staffId, $tenant['id']);
        }
        $before = self::schedule($tenant, $staffId);
        self::checkVersion($before, $input);
        $windows = $reset ? self::schedule($tenant, null)['windows'] : self::normalizeWindows($input['windows'] ?? null);
        $rows = Database::query("SELECT b.staff_id, b.start_datetime, b.end_datetime FROM bookings b WHERE b.tenant_id = ? AND b.booking_pattern = 'timeslot' AND b.status IN ('confirmed', 'pending') AND b.end_datetime > ?" . ($staffId === null ? ' AND (b.staff_id IS NULL OR NOT EXISTS (SELECT 1 FROM availability a WHERE a.tenant_id = b.tenant_id AND a.staff_id = b.staff_id))' : ' AND b.staff_id = ?'), $staffId === null ? [$tenant['id'], (new \DateTimeImmutable('now', new \DateTimeZone($tenant['timezone'])))->format('Y-m-d H:i:s')] : [$tenant['id'], (new \DateTimeImmutable('now', new \DateTimeZone($tenant['timezone'])))->format('Y-m-d H:i:s'), $staffId]);
        $affected = 0;
        foreach ($rows as $row) {
            $start = new \DateTimeImmutable($row['start_datetime']);
            $covered = false;
            foreach ($windows as $window) {
                if ((int) $start->format('N') - 1 === $window['day_of_week']
                    && substr($row['start_datetime'], 0, 10) === substr($row['end_datetime'], 0, 10)
                    && substr($row['start_datetime'], 11, 5) >= substr($window['start_time'], 0, 5)
                    && substr($row['end_datetime'], 11, 5) <= substr($window['end_time'], 0, 5)) {
                    $covered = true;
                    break;
                }
            }
            $affected += (int) !$covered;
        }
        return ['business_id' => $tenant['id'], 'staff_id' => $staffId, 'timezone' => $tenant['timezone'], 'before' => $before,
            'after' => ['windows' => $windows, 'inherits_business_schedule' => $reset], 'version' => $before['version'],
            'affected_booking_count' => $affected, 'existing_bookings_preserved' => true];
    }

    private static function normalizeWindows(mixed $windows): array
    {
        if (!is_array($windows) || !array_is_list($windows) || count($windows) > 100) {
            CatalogFields::invalid('windows', 'invalid_list');
        }
        $normalized = [];
        foreach ($windows as $window) {
            $day = $window['day_of_week'] ?? null;
            $start = $window['start_time'] ?? '';
            $end = $window['end_time'] ?? '';
            if (!is_int($day) || $day < 0 || $day > 6 || !is_string($start) || !is_string($end)
                || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:00)?$/D', $start)
                || !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9](:00)?$/D', $end)
                || substr($start, 0, 5) >= substr($end, 0, 5)) {
                CatalogFields::invalid('windows', 'invalid_time');
            }
            $normalized[] = ['day_of_week' => $day, 'start_time' => substr($start, 0, 5) . ':00', 'end_time' => substr($end, 0, 5) . ':00'];
        }
        usort($normalized, static fn (array $a, array $b): int => [$a['day_of_week'], $a['start_time']] <=> [$b['day_of_week'], $b['start_time']]);
        $last = null;
        foreach ($normalized as $window) {
            if ($last !== null && $last['day_of_week'] === $window['day_of_week'] && $last['end_time'] > $window['start_time']) {
                CatalogFields::invalid('windows', 'overlapping_windows');
            }
            $last = $window;
        }
        return $normalized;
    }

    private static function schedule(array $tenant, ?string $staffId): array
    {
        $rows = Database::query('SELECT day_of_week, start_time, end_time, is_available FROM availability WHERE tenant_id = ? AND ' . ($staffId === null ? 'staff_id IS NULL' : 'staff_id = ?') . ' ORDER BY day_of_week, start_time, id', $staffId === null ? [$tenant['id']] : [$tenant['id'], $staffId]);
        $inherits = $staffId !== null && $rows === [];
        if ($inherits) {
            $rows = Database::query('SELECT day_of_week, start_time, end_time, is_available FROM availability WHERE tenant_id = ? AND staff_id IS NULL ORDER BY day_of_week, start_time, id', [$tenant['id']]);
        }
        $version = hash('sha256', json_encode([$tenant['id'], $staffId, $inherits, $rows], JSON_THROW_ON_ERROR));
        $windows = array_map(static fn (array $row): array => ['day_of_week' => (int) $row['day_of_week'], 'start_time' => substr($row['start_time'], 0, 5), 'end_time' => substr($row['end_time'], 0, 5)], array_values(array_filter($rows, static fn (array $row): bool => (bool) $row['is_available'])));
        return ['business_id' => $tenant['id'], 'staff_id' => $staffId, 'timezone' => $tenant['timezone'], 'inherits_business_schedule' => $inherits, 'windows' => $windows, 'version' => $version];
    }

    private static function clearSchedule(string $businessId, ?string $staffId): void
    {
        Database::execute('DELETE FROM availability WHERE tenant_id = ? AND ' . ($staffId === null ? 'staff_id IS NULL' : 'staff_id = ?'), $staffId === null ? [$businessId] : [$businessId, $staffId]);
    }

    private static function guard(ActorContext $actor, string $kind, array $input, bool $write = false, bool $demoAllowed = false, ?string $scope = null): array
    {
        $definition = CatalogFields::definition($kind);
        $actor = self::authorizeActor($actor, $definition, $write, $demoAllowed, $scope);
        $business = $actor->business($input['business_id'] ?? null);
        $rows = Database::query("SELECT id, booking_pattern, timezone, slug FROM tenants WHERE id = ? AND status != 'archived'" . ($write && !Database::isSQLite() ? ' FOR UPDATE' : ''), [$business]);
        if ($rows === [] || !in_array($rows[0]['booking_pattern'], $definition['patterns'], true)) {
            throw new OperationException('not_found', 404);
        }
        if ($write) {
            // A role or grant may have changed while this writer waited.
            $actor = self::authorizeActor($actor, $definition, true, $demoAllowed, $scope);
            $actor->business($business);
        }
        return [$actor, $rows[0]];
    }

    private static function authorizeActor(ActorContext $actor, array $definition, bool $write, bool $demoAllowed, ?string $scope): ActorContext
    {
        $actor = (new \App\Agent\ConnectionAuthenticator())->refresh($actor);
        $actor = (new PrincipalResolver())->resolve($actor->principalType, $actor->principalId, $actor->businessIds, $actor->scopes, connectionId: $actor->connectionId, credentialId: $actor->credentialId, authMode: $actor->authMode);
        if (!$actor->isStaff() || ($write && !in_array($actor->role, ['operator', 'business_owner'], true))) {
            throw new OperationException('forbidden', 403);
        }
        $required = ($scope ?? $definition['scope']) . ($write ? ':manage' : ':read');
        if (!in_array($required, $actor->scopes, true)) {
            throw new OperationException('insufficient_scope', 403);
        }
        if ($write && !$demoAllowed && DemoMode::isActive()) {
            throw new OperationException('demo_restricted', 403);
        }
        return $actor;
    }

    private static function row(string $kind, string $id, string $businessId): array
    {
        CatalogFields::definition($kind);
        $rows = Database::query("SELECT * FROM `{$kind}` WHERE id = ? AND tenant_id = ?", [$id, $businessId]);
        return $rows[0] ?? throw new OperationException('not_found', 404);
    }

    private static function checkVersion(array $record, array $input): void
    {
        if (!is_string($input['expected_version'] ?? null) || !hash_equals($record['version'], $input['expected_version'])) {
            throw new OperationException('version_conflict', 409, nextAction: 'refresh');
        }
    }

    private static function validateReferences(string $kind, array $data, array $tenant, ?string $id = null): void
    {
        foreach (['staff_ids' => 'staff', 'service_ids' => 'services'] as $key => $table) {
            foreach ($data[$key] ?? [] as $reference) {
                self::row($table, $reference, $tenant['id']);
            }
        }
        if ($kind === 'blocked_dates') {
            foreach (['staff_id' => 'staff', 'resource_id' => 'resources'] as $key => $table) {
                if ($data[$key] !== null) {
                    self::row($table, $data[$key], $tenant['id']);
                }
            }
            $conditions = 'tenant_id = ? AND start_date <= ? AND end_date >= ?';
            $parameters = [$tenant['id'], $data['end_date'], $data['start_date']];
            foreach (['staff_id', 'resource_id'] as $field) {
                $conditions .= $data[$field] === null ? " AND {$field} IS NULL" : " AND {$field} = ?";
                if ($data[$field] !== null) {
                    $parameters[] = $data[$field];
                }
            }
            if ($id !== null) {
                $conditions .= ' AND id != ?';
                $parameters[] = $id;
            }
            if (Database::query("SELECT id FROM blocked_dates WHERE {$conditions} LIMIT 1", $parameters) !== []) {
                throw new OperationException('conflict', 409, ['start_date' => 'overlapping_block']);
            }
        }
    }

    private static function validateEventInventory(string $id, array $data, array $before): void
    {
        $rows = Database::query("SELECT DATE(start_datetime) AS occurrence_date, SUM(COALESCE(party_size, 1)) AS occupied FROM bookings WHERE tenant_id = ? AND event_id = ? AND (status IN ('confirmed', 'pending') OR (status='rescheduled' AND NULLIF(rescheduled_to_id,'') IS NULL)) GROUP BY DATE(start_datetime)", [$before['business_id'], $id]);
        foreach ($rows as $row) {
            if ((int) $row['occupied'] > $data['max_participants']) {
                throw new OperationException('capacity_conflict', 409, ['max_participants' => 'below_existing_bookings']);
            }
        }
        $booked = Database::query("SELECT DISTINCT start_datetime FROM bookings WHERE tenant_id = ? AND event_id = ? AND (status IN ('confirmed','pending','waitlisted') OR (status='rescheduled' AND NULLIF(rescheduled_to_id,'') IS NULL))", [$before['business_id'], $id]);
        if ($booked !== [] && (substr($before['start_datetime'], 0, 19) !== str_replace(' ', 'T', $data['start_datetime']) || substr($before['end_datetime'], 0, 19) !== str_replace(' ', 'T', $data['end_datetime']))) {
            throw new OperationException('record_in_use', 409, nextAction: 'manage_existing_bookings');
        }
        $timezone = new \DateTimeZone($before['timezone']);
        $rule = $data['is_recurring'] ? new \RRule\RRule($data['rrule'], new \DateTimeImmutable($data['start_datetime'], $timezone)) : null;
        foreach ($booked as $booking) {
            $date = substr($booking['start_datetime'],0,10);
            if (in_array($date,$data['exception_dates'],true)
                || ($rule === null && $date !== substr($data['start_datetime'],0,10))
                || ($rule !== null && !$rule->occursAt(new \DateTimeImmutable($booking['start_datetime'],$timezone)))) {
                throw new OperationException('record_in_use',409,['recurrence'=>'existing_occurrence_removed'],nextAction:'manage_existing_bookings');
            }
        }
    }

    /** Preserve active reservations, including pre-slot-ID legacy bookings. */
    private static function validateCapacityInventory(array $before, ?array $after): void
    {
        $rows = Database::query(
            "SELECT start_datetime,end_datetime,party_size FROM bookings
             WHERE tenant_id=? AND booking_pattern='capacity'
             AND (status IN ('confirmed','pending') OR (status='rescheduled' AND NULLIF(rescheduled_to_id,'') IS NULL))
             AND (capacity_slot_id=? OR (capacity_slot_id IS NULL AND WEEKDAY(start_datetime)=? AND TIME(start_datetime)=? AND TIME(end_datetime)=?))",
            [$before['business_id'],$before['id'],$before['day_of_week'],$before['start_time'],$before['end_time']]
        );
        if ($rows === []) { return; }
        if ($after === null || (int)$after['day_of_week'] !== (int)$before['day_of_week']
            || substr($after['start_time'],0,5) !== substr($before['start_time'],0,5)
            || substr($after['end_time'],0,5) !== substr($before['end_time'],0,5)) {
            throw new OperationException('record_in_use',409,nextAction:'manage_existing_bookings');
        }
        $occupied = [];
        foreach ($rows as $row) {
            $date = substr($row['start_datetime'],0,10);
            $occupied[$date] = ($occupied[$date] ?? 0) + (int)$row['party_size'];
        }
        if (max($occupied) > (int)$after['max_capacity']) {
            throw new OperationException('capacity_conflict',409,['max_capacity'=>'below_existing_bookings']);
        }
    }

    private static function extractRelations(string $kind, array &$data): array
    {
        $keys = match ($kind) { 'services' => ['staff_ids'], 'staff' => ['service_ids'], 'resources' => ['seasonal_pricing'], default => [] };
        $relations = [];
        foreach ($keys as $key) {
            $relations[$key] = $data[$key];
            unset($data[$key]);
        }
        return $relations;
    }

    private static function syncRelations(string $kind, string $id, string $businessId, array $relations): void
    {
        if ($kind === 'services' || $kind === 'staff') {
            $owner = $kind === 'services' ? 'service_id' : 'staff_id';
            $related = $kind === 'services' ? 'staff_id' : 'service_id';
            Database::execute("DELETE FROM service_staff WHERE {$owner} = ?", [$id]);
            foreach ($relations[$kind === 'services' ? 'staff_ids' : 'service_ids'] as $target) {
                Database::execute("INSERT INTO service_staff ({$owner}, {$related}) VALUES (?, ?)", [$id, $target]);
            }
        }
        if ($kind === 'resources') {
            Database::execute('DELETE FROM seasonal_pricing WHERE tenant_id = ? AND resource_id = ?', [$businessId, $id]);
            foreach ($relations['seasonal_pricing'] as $season) {
                Database::execute('INSERT INTO seasonal_pricing (id, tenant_id, resource_id, start_date, end_date, price_per_night, label) VALUES (?, ?, ?, ?, ?, ?, ?)', [Ulid::generate(), $businessId, $id, $season['start_date'], $season['end_date'], $season['price_per_night'], $season['label'] ?? null]);
            }
        }
    }

    private static function encodeJson(array &$data): void
    {
        foreach (['amenities', 'check_in_days', 'check_out_days', 'exception_dates'] as $field) {
            if (isset($data[$field])) {
                $data[$field] = json_encode($data[$field], JSON_THROW_ON_ERROR);
            }
        }
        foreach ($data as &$value) {
            if (is_bool($value)) {
                $value = (int) $value;
            }
        }
    }

    private static function project(string $kind, array $row, array $tenant): array
    {
        $data = ['id' => $row['id'], 'business_id' => $row['tenant_id']];
        foreach (CatalogFields::properties($kind) as $field => $schema) {
            if (!array_key_exists($field, $row)) {
                continue;
            }
            $value = $row[$field];
            if ($value !== null && in_array('integer', (array) $schema['type'], true)) {
                $value = (int) $value;
            }
            if ($value !== null && in_array('boolean', (array) $schema['type'], true)) {
                $value = (bool) $value;
            }
            if (in_array($field, ['amenities', 'check_in_days', 'check_out_days', 'exception_dates'], true)) {
                $value = $value === null ? ($field === 'amenities' || $field === 'exception_dates' ? [] : null) : json_decode($value, true, flags: JSON_THROW_ON_ERROR);
            }
            $data[$field] = $value;
        }
        foreach (['sort_order', 'is_active'] as $field) {
            if (isset($row[$field])) {
                $data[$field] = $field === 'is_active' ? (bool) $row[$field] : (int) $row[$field];
            }
        }
        $data['image_path'] = $row[$kind === 'staff' ? 'avatar_path' : 'cover_image_path'] ?? null;
        if ($kind === 'services' || $kind === 'staff') {
            $owner = $kind === 'services' ? 'service_id' : 'staff_id';
            $related = $kind === 'services' ? 'staff_id' : 'service_id';
            $data[$kind === 'services' ? 'staff_ids' : 'service_ids'] = array_column(Database::query("SELECT {$related} FROM service_staff WHERE {$owner} = ? ORDER BY {$related}", [$row['id']]), $related);
        }
        if ($kind === 'resources') {
            $data['seasonal_pricing'] = Database::query('SELECT start_date, end_date, price_per_night, label FROM seasonal_pricing WHERE resource_id = ? AND tenant_id = ? ORDER BY start_date, id', [$row['id'], $tenant['id']]);
        }
        if ($kind === 'events') {
            $timezone = new \DateTimeZone($tenant['timezone']);
            $data['timezone'] = $tenant['timezone'];
            foreach (['start_datetime', 'end_datetime'] as $field) {
                $data[$field] = (new \DateTimeImmutable($row[$field], $timezone))->format(DATE_ATOM);
            }
            $data['recurrence'] = null;
            if ($row['is_recurring'] && preg_match('/^FREQ=(DAILY|WEEKLY|MONTHLY);COUNT=(\d+)$/D', (string) $row['rrule'], $parts)) {
                $data['recurrence'] = ['frequency' => $parts[1], 'count' => (int) $parts[2]];
            }
        }
        $data['version'] = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        if ($kind === 'blocked_dates') {
            $data['affected_booking_count'] = self::blockImpact($tenant['id'], $row);
        }
        return $data;
    }

    public static function blockImpact(string $businessId, array $data): int
    {
        $where = "tenant_id = ? AND status IN ('confirmed', 'pending') AND start_datetime < ? AND end_datetime > ?";
        $parameters = [$businessId, (new \DateTimeImmutable($data['end_date']))->modify('+1 day')->format('Y-m-d') . ' 00:00:00', $data['start_date'] . ' 00:00:00'];
        foreach (['staff_id', 'resource_id'] as $field) {
            if (!empty($data[$field])) {
                $where .= " AND {$field} = ?";
                $parameters[] = $data[$field];
            }
        }
        return (int) Database::query("SELECT COUNT(*) AS total FROM bookings WHERE {$where}", $parameters)[0]['total'];
    }

    private static function writeMedia(ActorContext $actor, string $kind, string $businessId, array $input, array $data): array
    {
        if (!in_array($kind, ['services', 'staff', 'resources', 'events'], true)) {
            return $data;
        }
        $field = $kind === 'staff' ? 'avatar_path' : 'cover_image_path';
        if (!empty($input['media_id'])) {
            $purpose = CatalogFields::definition($kind)['entity'];
            $data[$field] = \App\Application\Media\MediaService::consume($actor, $businessId, $input['media_id'], $purpose);
        } elseif (($input['remove_image'] ?? false) === true) {
            $data[$field] = null;
        }
        return $data;
    }

    private static function audit(ActorContext $actor, string $kind, string $id, string $businessId, string $action): void
    {
        $entity = $kind === 'availability' ? 'availability' : CatalogFields::definition($kind)['entity'];
        AuditLog::log($entity . '.' . $action, $entity, $id, ['connection_id' => $actor->connectionId], $businessId, $actor->auditType(), $actor->principalId);
    }

    private static function transaction(\Closure $operation): mixed
    {
        return Database::pdo()->inTransaction() ? $operation() : Database::transaction($operation);
    }
}
