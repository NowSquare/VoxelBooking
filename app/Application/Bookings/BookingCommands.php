<?php

declare(strict_types=1);

namespace App\Application\Bookings;

use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Engine\AuditLog;
use App\Engine\BookingService;
use App\Engine\CustomerCapability;
use App\Engine\CustomerService;
use App\Engine\Ulid;
use App\Engine\VenueDateTime;
use App\Engine\Database;
use App\Engine\DemoMode;

/** Authorized application commands shared by browser, versioned HTTP and MCP. */
final class BookingCommands
{
    public static function create(ActorContext $actor, array $input): array
    {
        $actor = self::currentActor($actor);
        $businessId = $actor->business($input['business_id'] ?? null);

        return BookingTransaction::run($businessId, static function (array $tenant) use ($actor, $input): array {
            $actor = self::authorize($actor, $tenant, true, $actor->isStaff() ? 'bookings:write' : 'public:book');
            if (
                !$actor->isStaff()
                && !in_array($actor->role, ['prospective_customer', 'verified_customer'], true)
            ) throw new OperationException('forbidden', 403);

            // Trusted fields are set server side; a client that sends them is probing, not booking.
            foreach (['source', 'status', '_skip_consent_enforcement', 'customer_id', 'internal_notes'] as $field) {
                if (array_key_exists($field, $input)) throw new OperationException('validation_failed', 422, [$field => 'unsupported']);
            }

            $customer = $input['customer'] ?? [];
            if (!is_array($customer)) throw new OperationException('validation_failed', 422, ['customer' => 'invalid']);
            $name = self::text($customer, 'name', 255);
            $email = mb_strtolower(self::text($customer, 'email', 255));
            $phone = self::text($customer, 'phone', 50);

            $errors = [];
            if ($name === '') $errors['customer.name'] = 'required';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors['customer.email'] = 'invalid';
            if ((bool) $tenant['require_phone'] && $phone === '') $errors['customer.phone'] = 'required';
            if ($errors) throw new OperationException('validation_failed', 422, $errors);

            $consent = ($input['consent_given'] ?? false) === true;
            if (
                !$actor->isStaff()
                && (bool) $tenant['requires_consent']
                && !$consent
            ) throw new OperationException('consent_required', 422, ['consent' => 'required']);

            $draft = BookingDraft::prepare($tenant, $input);
            $data = $draft['data'];
            $customerId = CustomerService::findOrCreate($tenant['id'], $name, $email, $phone);
            if (!$actor->isStaff() && (int) $tenant['max_bookings_per_customer_per_day'] > 0) {
                $count = self::customerBookingCountForDate($customerId, $tenant['id'], substr($data['start_datetime'], 0, 10));
                if (
                    (int) $count >= (int) $tenant['max_bookings_per_customer_per_day']
                ) throw new OperationException('max_bookings_exceeded', 422);
            }

            $data += ['customer_id' => $customerId, 'source' => $actor->isStaff() ? 'admin' : 'web'];
            $data['customer_name_snapshot'] = $name;
            $data['customer_email_snapshot'] = $email;
            $data['customer_phone_snapshot'] = $phone ?: null;
            $data['notes'] = self::text($input, 'notes', 10000) ?: null;

            $timezone = self::text($input, 'customer_timezone', 100);
            if (
                $timezone !== ''
                && !in_array($timezone, timezone_identifiers_list(), true)
                && $timezone !== 'UTC'
            ) throw new OperationException('validation_failed', 422, ['customer_timezone' => 'invalid']);
            $data['customer_timezone'] = $timezone ?: null;
            $data['custom_field_data'] = BookingFields::answers($tenant, $input['custom_fields'] ?? null);

            // A waitlisted booking waits for a seat, not for approval; promotion handles it later.
            if (
                !$actor->isStaff()
                && (bool) $tenant['booking_requires_approval']
                && ($data['status'] ?? 'confirmed') !== 'waitlisted'
            ) $data['status'] = 'pending';

            $result = BookingService::createBooking($data, $tenant, $consent, $actor);
            Database::execute(
                'UPDATE customers SET booking_count = booking_count + 1, last_booking_at = UTC_TIMESTAMP() WHERE id = ? AND tenant_id = ?',
                [$customerId, $tenant['id']]
            );
            $status = $data['status'] ?? 'confirmed';
            self::scheduleReminder($tenant, $result['id'], $data['start_datetime'], $status);
            BookingNotifications::queue($tenant, $result['id'], 'created');
            self::publish($tenant, $result['id'], 'booking.created', $actor);

            $receipt = $draft['display'] + [
                'id' => $result['id'],
                'business_id' => $tenant['id'],
                'booking_pattern' => $tenant['booking_pattern'],
                'status' => $status,
                'timezone' => $tenant['timezone'],
                'consent_recorded' => $result['consent_recorded'],
                'email_sent' => false,
                'notification_state' => 'queued',
                ...VenueDateTime::utcRange($data['start_datetime'], $data['end_datetime'], $tenant['timezone'])
            ];
            if (!$actor->isStaff()) $receipt['manage_url'] = CustomerCapability::manageLink($tenant, $result['id']);

            return $receipt;
        });
    }

    public static function scheduleReminder(array $tenant, string $bookingId, string $start, string $status): void
    {
        if (DemoMode::isActive() || !(bool) $tenant['send_reminders'] || $status !== 'confirmed') return;

        $tz = new \DateTimeZone($tenant['timezone']);
        $at = (new \DateTimeImmutable($start, $tz))->modify('-' . max(1, (int) $tenant['reminder_hours_before']) . ' hours');
        if ($at <= new \DateTimeImmutable('now', $tz)) return;

        // Status changes can call this repeatedly; keep a single pending reminder per booking.
        if (!Database::query('SELECT id FROM reminders WHERE booking_id = ? AND sent_at IS NULL', [$bookingId])) {
            Database::execute(
                'INSERT INTO reminders (id,booking_id,tenant_id,scheduled_at) VALUES (?,?,?,?)',
                [Ulid::generate(), $bookingId, $tenant['id'], $at->format('Y-m-d H:i:s')]
            );
        }
    }

    public static function cancel(ActorContext $actor, array $input): array
    {
        $actor = self::currentActor($actor);
        $businessId = $actor->business($input['business_id'] ?? null);

        return BookingTransaction::run($businessId, static function (array $tenant) use ($actor, $input): array {
            $booking = self::booking(
                $actor,
                $tenant,
                (string) ($input['booking_id'] ?? ''),
                true,
                $actor->isStaff() ? 'bookings:write' : 'bookings:cancel'
            );
            // Cancelling twice is a no-op so retried requests stay idempotent.
            if ($booking['status'] === 'cancelled') return $booking;

            // Customers are bound by the cancellation policy; staff only by the state machine.
            if (!$actor->isStaff()) {
                $permission = BookingService::canCancel($booking, $tenant);
                if (!$permission['allowed']) throw new OperationException($permission['reason'], 409);
            } elseif (!in_array($booking['status'], ['pending', 'confirmed', 'waitlisted'], true)) {
                throw new OperationException('invalid_transition', 409);
            }

            Database::execute(
                "UPDATE bookings SET status = 'cancelled', cancelled_at = UTC_TIMESTAMP(), cancellation_reason = ?, updated_at = UTC_TIMESTAMP() WHERE id = ? AND tenant_id = ?",
                [self::text($input, 'reason', 255) ?: null, $booking['id'], $tenant['id']]
            );
            Database::execute(
                'UPDATE customers SET booking_count = GREATEST(0, booking_count - 1) WHERE id = ? AND tenant_id = ?',
                [$booking['customer_id'], $tenant['id']]
            );
            Database::execute('UPDATE reminders SET sent_at = UTC_TIMESTAMP() WHERE booking_id = ? AND sent_at IS NULL', [$booking['id']]);
            self::audit($actor, 'booking.cancelled', $booking['id'], $tenant['id'], ['old_status' => $booking['status']]);
            BookingNotifications::queue($tenant, $booking['id'], 'cancellation');
            self::publish($tenant, $booking['id'], 'booking.cancelled', $actor);

            return BookingService::findById($booking['id']);
        });
    }

    public static function reschedule(ActorContext $actor, array $input): array
    {
        $actor = self::currentActor($actor);
        $businessId = $actor->business($input['business_id'] ?? null);

        return BookingTransaction::run($businessId, static function (array $tenant) use ($actor, $input): array {
            $booking = self::booking(
                $actor,
                $tenant,
                (string) ($input['booking_id'] ?? ''),
                true,
                $actor->isStaff() ? 'bookings:write' : 'bookings:reschedule'
            );
            $target = $input['target'] ?? $input;
            if (!is_array($target)) throw new OperationException('validation_failed', 422, ['target' => 'invalid']);
            BookingService::previewReschedule($booking, $tenant, $target, $actor->isStaff());

            try {
                $result = BookingService::rescheduleBooking(
                    $booking['id'],
                    $tenant,
                    $target,
                    $actor->auditType(),
                    $actor->isStaff() ? 'admin' : 'web',
                    $actor
                );
            } catch (\RuntimeException $error) {
                throw BookingService::rescheduleError($error);
            }

            BookingNotifications::queue($tenant, $result['new_booking_id'], 'rescheduled', $booking['id']);
            // A customer's manage link must keep working after the booking id changes.
            CustomerCapability::followReplacement($actor, $booking['id'], $result['new_booking_id']);
            self::publish($tenant, $booking['id'], 'booking.rescheduled', $actor);

            return $result;
        });
    }

    public static function customerBookingCountForDate(string $customerId, string $tenantId, string $date): int
    {
        // A rescheduled row still counts until its replacement exists, mirroring inventory occupancy.
        return (int) Database::query(
            "SELECT COUNT(*) AS c FROM bookings WHERE tenant_id=? AND customer_id=? AND DATE(start_datetime)=? AND (status IN ('pending','confirmed','waitlisted') OR (status='rescheduled' AND NULLIF(rescheduled_to_id,'') IS NULL))",
            [$tenantId, $customerId, $date]
        )[0]['c'];
    }

    public static function sessionActor(string $businessId): ActorContext
    {
        $user = \App\Engine\Auth::user() ?? throw new OperationException('unauthorized', 401);

        return (new \App\Agent\PrincipalResolver())->resolve(
            $user['type'],
            $user['id'],
            [$businessId],
            ['bookings:read', 'bookings:write', 'bookings:cancel', 'bookings:reschedule'],
            authMode: 'session'
        );
    }

    public static function changeStatus(ActorContext $actor, array $input): array
    {
        $actor = self::currentActor($actor);
        if (!$actor->isStaff()) throw new OperationException('forbidden', 403);
        $businessId = $actor->business($input['business_id'] ?? null);

        return BookingTransaction::run($businessId, static function (array $tenant) use ($actor, $input): array {
            $booking = self::booking($actor, $tenant, (string) ($input['booking_id'] ?? ''), true);
            $target = self::text($input, 'status', 20);
            if ($target === $booking['status']) return $booking;
            if (!in_array($target, self::transitions($booking['status']), true)) throw new OperationException('invalid_transition', 409);
            if ($target === 'cancelled') return self::cancel($actor, $input);

            // Confirming re-occupies inventory, so the slot must still be free.
            if ($target === 'confirmed') BookingInventory::assertAvailable($tenant, $booking);

            Database::execute(
                'UPDATE bookings SET status = ?, cancelled_at = NULL, cancellation_reason = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ? AND tenant_id = ?',
                [$target, $booking['id'], $tenant['id']]
            );
            if ($booking['status'] === 'cancelled') Database::execute(
                'UPDATE customers SET booking_count = booking_count + 1 WHERE id = ? AND tenant_id = ?',
                [$booking['customer_id'], $tenant['id']]
            );
            if ($target !== 'confirmed') Database::execute(
                'UPDATE reminders SET sent_at = UTC_TIMESTAMP() WHERE booking_id = ? AND sent_at IS NULL',
                [$booking['id']]
            );
            self::scheduleReminder($tenant, $booking['id'], $booking['start_datetime'], $target);
            self::audit(
                $actor,
                'booking.status_changed',
                $booking['id'],
                $tenant['id'],
                ['old_status' => $booking['status'], 'new_status' => $target]
            );
            self::publish($tenant, $booking['id'], 'booking.status_changed', $actor);
            if ($target === 'confirmed' && in_array($booking['status'], ['pending', 'waitlisted'], true)) {
                BookingNotifications::queue($tenant, $booking['id'], $booking['status'] === 'pending' ? 'approval_confirmed' : 'promotion');
            }

            return BookingService::findById($booking['id']);
        });
    }

    private static function publish(array $tenant, string $id, string $event, ActorContext $actor): void
    {
        // Webhooks get a fixed, minimal projection; customer PII never leaves through this channel.
        $booking = BookingService::findById($id);
        \App\Application\Integrations\WebhookService::publish(
            $tenant['id'],
            $event,
            array_intersect_key(
                $booking,
                array_flip(['id', 'booking_pattern', 'status', 'start_datetime', 'end_datetime', 'rescheduled_to_id'])
            ) + ['timezone' => $tenant['timezone']],
            $actor
        );
    }

    public static function transitions(string $status): array
    {
        return match ($status) {
            'pending', 'waitlisted' => ['confirmed', 'cancelled'],
            'confirmed' => ['cancelled', 'completed', 'no_show'],
            'cancelled', 'completed', 'no_show' => ['confirmed'],
            default => [],
        };
    }

    public static function booking(ActorContext $actor, array $tenant, string $bookingId, bool $write = false, ?string $scope = null): array
    {
        $actor = self::currentActor($actor);
        $actor = self::authorize($actor, $tenant, $write, $scope);
        $booking = Database::query(
            'SELECT * FROM bookings WHERE id = ? AND tenant_id = ?' . ($write ? ' FOR UPDATE' : ''),
            [$bookingId, $tenant['id']]
        )[0] ?? null;

        // Customers only see the one booking their capability names; anything else is reported as missing.
        if (
            $booking === null
            || (
                !$actor->isStaff()
                && (
                    $actor->role !== 'verified_customer'
                    || $actor->bookingId !== $bookingId
                    || $actor->customerId !== $booking['customer_id']
                )
            )
        ) {
            throw new OperationException('not_found', 404);
        }

        return $booking;
    }

    public static function authorize(ActorContext $actor, array $tenant, bool $write, ?string $scope = null): ActorContext
    {
        $actor = self::currentActor($actor);
        $actor->business($tenant['id']);
        $scope ??= $actor->isStaff()
            ? 'bookings:' . ($write ? 'write' : 'read')
            : ($actor->role === 'verified_customer' ? 'bookings:read' : 'public:' . ($write ? 'book' : 'read'));
        if (!in_array($scope, $actor->scopes, true)) throw new OperationException('forbidden', 403);

        // Scopes live on the token; re-check that the principal behind it still exists and is active.
        if ($actor->isStaff()) {
            if ($actor->principalType === 'operator') {
                $exists = Database::query('SELECT id FROM operators WHERE id = ?', [$actor->principalId]);
            } else {
                $exists = Database::query(
                    'SELECT id FROM business_users WHERE id = ? AND tenant_id = ? AND is_active = 1',
                    [$actor->principalId, $tenant['id']]
                );
            }
            if (!$exists) throw new OperationException('forbidden', 403);
        } elseif ($tenant['status'] !== 'active') {
            throw new OperationException('not_found', 404);
        }

        return $actor;
    }

    /** Refresh reusable contexts before any role, scope, tenant or subject decision. */
    public static function currentActor(ActorContext $actor): ActorContext
    {
        $actor = (new \App\Agent\ConnectionAuthenticator())->refresh($actor);
        if (in_array($actor->principalType, ['operator', 'business_user'], true)) {
            return (new \App\Agent\PrincipalResolver())->resolve(
                $actor->principalType,
                $actor->principalId,
                $actor->businessIds,
                $actor->scopes,
                connectionId: $actor->connectionId,
                credentialId: $actor->credentialId,
                authMode: $actor->authMode
            );
        }
        if (
            $actor->principalType === 'customer'
            && $actor->authMode !== 'customer'
            && $actor->connectionId === null
        ) throw new OperationException('unauthorized', 401);

        return $actor;
    }

    public static function text(array $input, string $field, int $max): string
    {
        $value = $input[$field] ?? '';
        if (!is_string($value) || mb_strlen($value) > $max) throw new OperationException('validation_failed', 422, [$field => 'invalid']);

        return trim($value);
    }

    public static function audit(ActorContext $actor, string $action, string $id, string $businessId, array $details = []): void
    {
        AuditLog::log(
            $action,
            'booking',
            $id,
            $details + ['connection_id' => $actor->connectionId],
            $businessId,
            actorType: $actor->auditType(),
            actorId: $actor->principalId ?: null
        );
    }
}
