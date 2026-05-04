<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\AuditLog;
use App\Engine\BookingService;
use App\Engine\CustomerService;
use App\Engine\Database;
use App\Engine\Locale;
use App\Engine\Logger;
use App\Engine\Mailer;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\ResourceCalculator;
use App\Engine\CapacityCalculator;
use App\Engine\EventCalculator;
use App\Engine\TimeSlotCalculator;
use App\Engine\Ulid;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;
use App\Models\Booking;

/**
 * Booking management controller.
 *
 * Operator routes (cross-tenant): /admin/bookings
 * Tenant-context routes (business user + operator): /admin/tenants/{tenant_id}/bookings
 *
 * GET  /admin/bookings              → Cross-tenant list (operator-only)
 * GET  /admin/bookings/{id}         → Detail (operator-only)
 * POST /admin/bookings/{id}/status  → Update status (operator-only)
 * GET  /admin/tenants/{tenant_id}/bookings              → Per-tenant list
 * GET  /admin/tenants/{tenant_id}/bookings/{id}         → Per-tenant detail
 * POST /admin/tenants/{tenant_id}/bookings/{id}/status  → Per-tenant status update
 */
final class BookingsController
{
    private const PER_PAGE = 25; // PRD §1338

    // ── Operator cross-tenant routes ──

    public function index(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $status = $request->string('status') ?: null;
        $from   = $request->string('from') ?: null;
        $to     = $request->string('to') ?: null;
        $search = $request->string('search') ?: null;
        $sort   = $request->string('sort') ?: 'start_datetime';
        $direction = $request->string('direction') ?: 'desc';
        $page   = max(1, (int) ($request->string('page') ?: 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $bookings = Booking::all($status, $from, $to, $search, self::PER_PAGE, $offset, $sort, $direction);
        $total    = Booking::countAll($status, $from, $to, $search);

        return $this->render('admin.bookings.index', __('admin.bookings.title'), [
            'bookings'         => $bookings,
            'total'            => $total,
            'page'             => $page,
            'perPage'          => self::PER_PAGE,
            'showTenantColumn' => true,
            'filters'          => compact('status', 'from', 'to', 'search', 'sort', 'direction'),
            'sort'             => $sort,
            'direction'        => $direction,
            'flash'            => FormState::getToast(),
            'backUrl'          => '/admin/bookings',
        ]);
    }

    public function show(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $booking = Booking::find($request->getAttribute('id'));
        if ($booking === null) {
            return Response::redirect('/admin/bookings');
        }

        $timeline = AuditLog::forEntity('booking', $booking['id']);

        // Reverse lookup: find the original booking that was rescheduled into this one
        $rescheduledFrom = Database::query(
            "SELECT `id` FROM `bookings` WHERE `rescheduled_to_id` = ? LIMIT 1",
            [$booking['id']]
        );

        return $this->render('admin.bookings.show', __('admin.bookings.title'), [
            'documentTitle' => __('admin.bookings.detail_title'),
            'booking'   => $booking,
            'timeline'  => $timeline,
            'rescheduledFrom' => $rescheduledFrom[0]['id'] ?? null,
            'flash'     => FormState::getToast(),
            'backUrl'   => '/admin/bookings',
        ]);
    }

    public function updateStatus(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        return $this->doUpdateStatus($request, '/admin/bookings');
    }

    // ── Tenant-context routes (business users + operators) ──

    public function tenantIndex(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        // AuthMiddleware:59-66 already enforced tenant_id match for business users

        $status = $request->string('status') ?: null;
        $from   = $request->string('from') ?: null;
        $to     = $request->string('to') ?: null;
        $sort   = $request->string('sort') ?: 'start_datetime';
        $direction = $request->string('direction') ?: 'desc';
        $page   = max(1, (int) ($request->string('page') ?: 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $bookings = Booking::forTenant($tenantId, $status, $from, $to, self::PER_PAGE, $offset, $sort, $direction);
        $total    = Booking::countForTenant($tenantId, $status, $from, $to);

        return $this->render('admin.bookings.index', __('admin.bookings.title'), [
            'bookings'         => $bookings,
            'total'            => $total,
            'page'             => $page,
            'perPage'          => self::PER_PAGE,
            'showTenantColumn' => false,
            'tenantId'         => $tenantId,
            'filters'          => compact('status', 'from', 'to', 'sort', 'direction'),
            'sort'             => $sort,
            'direction'        => $direction,
            'flash'            => FormState::getToast(),
            'backUrl'          => "/admin/tenants/{$tenantId}/bookings",
        ]);
    }

    public function tenantShow(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $booking = Booking::find($request->getAttribute('id'));

        if ($booking === null || $booking['tenant_id'] !== $tenantId) {
            return Response::redirect("/admin/tenants/{$tenantId}/bookings");
        }

        $timeline = AuditLog::forEntity('booking', $booking['id']);

        // Reverse lookup: find the original booking that was rescheduled into this one
        $rescheduledFrom = Database::query(
            "SELECT `id` FROM `bookings` WHERE `rescheduled_to_id` = ? LIMIT 1",
            [$booking['id']]
        );

        return $this->render('admin.bookings.show', __('admin.bookings.title'), [
            'documentTitle' => __('admin.bookings.detail_title'),
            'booking'   => $booking,
            'timeline'  => $timeline,
            'rescheduledFrom' => $rescheduledFrom[0]['id'] ?? null,
            'flash'     => FormState::getToast(),
            'backUrl'   => "/admin/tenants/{$tenantId}/bookings",
        ]);
    }

    public function tenantUpdateStatus(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $id = $request->getAttribute('id');

        // Guard: verify the booking belongs to this tenant before allowing mutation
        $booking = Booking::find($id);
        if ($booking === null || $booking['tenant_id'] !== $tenantId) {
            FormState::toast('error', __('admin.bookings.flash_status_failed'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings");
        }

        return $this->doUpdateStatus($request, "/admin/tenants/{$tenantId}/bookings");
    }

    // ── Reschedule (dedicated endpoint — only way to set status=rescheduled) ──

    public function reschedule(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        return $this->doReschedule($request, '/admin/bookings');
    }

    public function tenantReschedule(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $id = $request->getAttribute('id');

        $booking = Booking::find($id);
        if ($booking === null || $booking['tenant_id'] !== $tenantId) {
            FormState::toast('error', __('admin.bookings.flash_reschedule_failed'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings");
        }

        return $this->doReschedule($request, "/admin/tenants/{$tenantId}/bookings");
    }

    /**
     * Shared reschedule handler.
     *
     * Delegates to BookingService::rescheduleBooking() for all patterns.
     * Handles email dispatch (customer + staff) as post-commit side effects.
     */
    private function doReschedule(Request $request, string $redirectBase): Response
    {
        $id = $request->getAttribute('id');
        $booking = Booking::find($id);

        if ($booking === null) {
            FormState::toast('error', __('admin.bookings.flash_reschedule_failed'));
            return Response::redirect($redirectBase);
        }

        $tenantId = $booking['tenant_id'];
        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            FormState::toast('error', __('admin.bookings.flash_reschedule_failed'));
            return Response::redirect($redirectBase);
        }

        // Build pattern-aware target from POST params
        $pattern = $booking['booking_pattern'] ?? 'timeslot';
        $target = match ($pattern) {
            'timeslot' => [
                'new_date' => trim($request->string('new_date')),
                'new_time' => trim($request->string('new_time')),
            ],
            'resource' => [
                'check_in'  => trim($request->string('check_in')),
                'check_out' => trim($request->string('check_out')),
            ],
            'capacity' => [
                'date'    => trim($request->string('date')),
                'slot_id' => trim($request->string('slot_id')),
            ],
            'event' => [
                'event_id' => trim($request->string('event_id') ?: ($booking['event_id'] ?? '')),
                'date'     => trim($request->string('date')),
            ],
            default => [],
        };

        // Delegate to the service
        try {
            $result = BookingService::rescheduleBooking(
                $id,
                $tenant,
                $target,
                Auth::isOperator() ? 'operator' : 'business_user',
                'admin',
            );
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            $errorKey = match ($msg) {
                'not_confirmed'         => 'error_reschedule_not_allowed',
                'rescheduling_disabled' => 'error_reschedule_not_allowed',
                'too_late'              => 'error_reschedule_not_allowed',
                'same_slot'             => 'error_same_slot',
                'slot_unavailable', 'already_booked', 'capacity_exceeded', 'event_full' => 'flash_slot_taken',
                'invalid_date'          => 'error_date_required',
                'invalid_time'          => 'error_time_required',
                'invalid_slot'          => 'flash_reschedule_failed',
                'invalid_event'         => 'flash_reschedule_failed',
                default                 => 'flash_reschedule_failed',
            };
            FormState::toast('error', __("admin.bookings.{$errorKey}"));
            return Response::redirect("{$redirectBase}/{$id}");
        }

        // Post-commit: send reschedule confirmation email to customer
        if (Mailer::isConfigured()) {
            try {
                $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
                $newStartDt = new \DateTimeImmutable($result['new_start'], $tz);
                $newEndDt = new \DateTimeImmutable($result['new_end'], $tz);

                // Load full booking details for email
                $details = BookingService::findByIdWithDetails($id, $tenantId);
                $serviceName = $details['service_name'] ?? $details['resource_name'] ?? $details['event_name'] ?? null;

                $emailData = [
                    'date'           => $result['new_date'],
                    'formatted_date' => Locale::dateLong($newStartDt),
                    'time'           => $newStartDt->format('H:i'),
                    'end_time'       => $newEndDt->format('H:i'),
                ];

                Mailer::sendRescheduleConfirmation(
                    $details['customer_email'] ?? '',
                    $details['customer_name'] ?? '',
                    $emailData,
                    $serviceName,
                    $details['staff_name'] ?? null,
                    $tenant['name'],
                    $tenantId,
                    $result['new_booking_id'],
                    $tenant['brand_color'] ?? '#2563EB',
                    $tenant['slug'],
                );
            } catch (\Throwable $e) {
                Logger::error('Reschedule email dispatch failed', [
                    'booking' => $result['new_booking_id'],
                    'error'   => $e->getMessage(),
                ]);
            }

            // Staff notification (fire-and-forget)
            if ((int) ($tenant['notify_on_booking'] ?? 0) === 1) {
                try {
                    Mailer::sendStaffBookingNotification(
                        $emailData, $serviceName, $details['staff_name'] ?? null,
                        $details['customer_name'] ?? '',
                        $tenant['name'], $tenantId, $result['new_booking_id'],
                        $tenant['brand_color'] ?? '#2563EB',
                    );
                } catch (\Throwable $e) {
                    Logger::error('Staff reschedule notification failed', [
                        'booking' => $result['new_booking_id'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }
        }

        FormState::toast('success', __('admin.bookings.flash_rescheduled'));
        return Response::redirect("{$redirectBase}/{$result['new_booking_id']}");
    }

    // ── Tenant-context: Manual booking creation ──

    public function tenantCreate(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!Auth::canAccessTenant($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        $isResourcePattern  = ($tenant['booking_pattern'] ?? '') === 'resource';
        $isCapacityPattern  = ($tenant['booking_pattern'] ?? '') === 'capacity';

        // Resource pattern: load resources instead of services/staff
        if ($isResourcePattern) {
            $resources = Database::query(
                'SELECT `id`, `name`, `capacity`, `price_per_night`
                 FROM `resources`
                 WHERE `tenant_id` = ? AND `is_active` = 1
                 ORDER BY `sort_order` ASC, `name` ASC',
                [$tenantId]
            );


            return $this->render('admin.tenants.bookings.create-resource', __('admin.bookings.create_title'), [
                'documentTitle' => __('admin.bookings.create_title'),
                'tenant'        => $tenant,
                'tenantId'      => $tenantId,
                'resources'     => $resources,
                'flash'         => FormState::getToast(),
            ]);
        }

        // Capacity pattern: load capacity slots
        if ($isCapacityPattern) {
            $slots = Database::query(
                'SELECT `id`, `day_of_week`, `start_time`, `end_time`, `max_capacity`, `max_party_size`, `label`
                 FROM `capacity_slots`
                 WHERE `tenant_id` = ? AND `is_active` = 1
                 ORDER BY `day_of_week` ASC, `start_time` ASC',
                [$tenantId]
            );


            return $this->render('admin.tenants.bookings.create-capacity', __('admin.bookings.create_title'), [
                'documentTitle' => __('admin.bookings.create_title'),
                'tenant'        => $tenant,
                'tenantId'      => $tenantId,
                'slots'         => $slots,
                'flash'         => FormState::getToast(),
            ]);
        }

        // Event pattern: load events
        $isEventPattern = ($tenant['booking_pattern'] ?? '') === 'event';
        if ($isEventPattern) {
            $events = Database::query(
                'SELECT `id`, `name`, `max_participants`, `start_datetime`, `end_datetime`, `allow_waitlist`
                 FROM `events`
                 WHERE `tenant_id` = ? AND `is_active` = 1
                 ORDER BY `start_datetime` ASC',
                [$tenantId]
            );


            return $this->render('admin.tenants.bookings.create-event', __('admin.bookings.create_title'), [
                'documentTitle' => __('admin.bookings.create_title'),
                'tenant'        => $tenant,
                'tenantId'      => $tenantId,
                'events'        => $events,
                'flash'         => FormState::getToast(),
            ]);
        }

        // Timeslot pattern: services + staff
        $services = Database::query(
            'SELECT `id`, `name`, `duration_minutes`, `price`, `price_label`
             FROM `services`
             WHERE `tenant_id` = ? AND `is_active` = 1
             ORDER BY `sort_order` ASC, `name` ASC',
            [$tenantId]
        );

        $staff = Database::query(
            'SELECT `id`, `name`, `title`
             FROM `staff`
             WHERE `tenant_id` = ? AND `is_active` = 1
             ORDER BY `sort_order` ASC',
            [$tenantId]
        );

        // Build service→staff mapping from pivot table for UI filtering.
        // Pre-seed every service with an empty array so services with no
        // pivot rows show "no eligible staff," not "all staff."
        $serviceStaffMap = [];
        foreach ($services as $svc) {
            $serviceStaffMap[$svc['id']] = [];
        }
        $pivotRows = Database::query(
            'SELECT ss.`service_id`, ss.`staff_id`
             FROM `service_staff` ss
             JOIN `staff` s ON s.`id` = ss.`staff_id`
             WHERE s.`tenant_id` = ? AND s.`is_active` = 1',
            [$tenantId]
        );
        foreach ($pivotRows as $row) {
            $serviceStaffMap[$row['service_id']][] = $row['staff_id'];
        }

        // Merge calendar deep-link params (?date=...&time=...) into form state
        // so old() picks them up in the template for prefill.
        $queryDate = $request->string('date');
        $queryTime = $request->string('time');
        $needsFlash = false;
        $prefill = FormState::old(); // consume existing old input
        if ($queryDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $queryDate) && !isset($prefill['date'])) {
            $prefill['date'] = $queryDate;
            $needsFlash = true;
        }
        if ($queryTime && preg_match('/^\d{2}:\d{2}$/', $queryTime) && !isset($prefill['time'])) {
            $prefill['time'] = $queryTime;
            $needsFlash = true;
        }
        if ($needsFlash && !empty($prefill)) {
            FormState::flashInput($prefill);
        }

        return $this->render('admin.tenants.bookings.create', __('admin.bookings.create_title'), [
            'documentTitle'   => __('admin.bookings.create_title'),
            'tenant'          => $tenant,
            'tenantId'        => $tenantId,
            'services'        => $services,
            'staff'           => $staff,
            'serviceStaffMap' => $serviceStaffMap,
            'flash'           => FormState::getToast(),
        ]);
    }

    public function tenantStore(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!Auth::canAccessTenant($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        // Dispatch to pattern-specific store
        $pattern = $tenant['booking_pattern'] ?? '';
        if ($pattern === 'resource') {
            return $this->storeResourceBooking($request, $tenant, $tenantId);
        }
        if ($pattern === 'capacity') {
            return $this->storeCapacityBooking($request, $tenant, $tenantId);
        }
        if ($pattern === 'event') {
            return $this->storeEventBooking($request, $tenant, $tenantId);
        }

        // Collect input
        $serviceId     = $request->string('service_id') ?: null;
        $staffId       = $request->string('staff_id') ?: null;
        $dateStr       = trim($request->string('date'));
        $timeStr       = trim($request->string('time'));
        $customerName  = trim($request->string('customer_name'));
        $customerEmail = trim($request->string('customer_email'));
        $customerPhone = trim($request->string('customer_phone'));
        $notes         = trim($request->string('notes'));

        // Store old input for re-display on validation failure
        $this->storeOldInput($request);

        // ── Validation ──
        if (!$serviceId) {
            FormState::toast('error', __('admin.bookings.error_service_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ($dateStr === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            FormState::toast('error', __('admin.bookings.error_date_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ($timeStr === '' || !preg_match('/^\d{2}:\d{2}$/', $timeStr)) {
            FormState::toast('error', __('admin.bookings.error_time_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ($customerName === '') {
            FormState::toast('error', __('admin.bookings.error_name_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ($customerEmail === '') {
            FormState::toast('error', __('admin.bookings.error_email_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            FormState::toast('error', __('admin.bookings.error_email_invalid'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ((int) ($tenant['require_phone'] ?? 0) === 1 && $customerPhone === '') {
            FormState::toast('error', __('admin.bookings.error_phone_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        // ── Validate service/staff pairing ──
        if ($staffId && $serviceId) {
            $pivot = Database::query(
                'SELECT 1 FROM `service_staff` ss
                 JOIN `staff` s ON s.`id` = ss.`staff_id`
                 WHERE ss.`service_id` = ? AND ss.`staff_id` = ? AND s.`tenant_id` = ? AND s.`is_active` = 1
                 LIMIT 1',
                [$serviceId, $staffId, $tenantId]
            );

            if (empty($pivot)) {
                FormState::toast('error', __('admin.bookings.error_staff_invalid'));
                return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
            }
        }

        // ── Resolve service duration ──
        $serviceDuration = (int) ($tenant['slot_duration_minutes'] ?? 30);
        $service = Database::query(
            'SELECT `duration_minutes` FROM `services` WHERE `id` = ? AND `tenant_id` = ? AND `is_active` = 1 LIMIT 1',
            [$serviceId, $tenantId]
        );
        if (!empty($service)) {
            $serviceDuration = (int) $service[0]['duration_minutes'];
        }

        // ── Calculate start/end ──
        $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
        $startDt = new \DateTimeImmutable("{$dateStr} {$timeStr}", $tz);
        $endDt   = $startDt->modify("+{$serviceDuration} minutes");

        // ── Double-booking prevention: transaction + FOR UPDATE ──
        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            // Lock existing bookings
            $lockSql = 'SELECT `id` FROM `bookings`
                        WHERE `tenant_id` = ? AND `status` IN (\'confirmed\', \'rescheduled\')
                        AND DATE(`start_datetime`) = ?';
            $lockParams = [$tenantId, $dateStr];

            if ($staffId) {
                $lockSql .= ' AND `staff_id` = ?';
                $lockParams[] = $staffId;
            }

            $lockSql .= ' FOR UPDATE';
            $lockStmt = $pdo->prepare($lockSql);
            $lockStmt->execute($lockParams);

            // Re-check availability
            $availResult = TimeSlotCalculator::getAvailableSlots($tenant, $dateStr, $serviceId, $staffId);
            $stillAvailable = false;
            $resolvedStaffId = $staffId;

            foreach ($availResult['slots'] as $slot) {
                if ($slot['time'] === $timeStr) {
                    $stillAvailable = true;
                    if (!$staffId && $slot['staff_id']) {
                        $resolvedStaffId = $slot['staff_id'];
                    }
                    break;
                }
            }

            if (!$stillAvailable) {
                $pdo->rollBack();
                FormState::toast('error', __('admin.bookings.flash_slot_taken'));
                return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
            }

            // Find or create customer
            $customerId = CustomerService::findOrCreate(
                $tenantId,
                $customerName,
                $customerEmail,
                $customerPhone,
            );

            // Create booking — source='admin', no consent
            $bookingData = [
                'tenant_id'       => $tenantId,
                'customer_id'     => $customerId,
                'booking_pattern' => 'timeslot',
                'start_datetime'  => $startDt->format('Y-m-d H:i:s'),
                'end_datetime'    => $endDt->format('Y-m-d H:i:s'),
                'source'          => 'admin',
            ];

            $bookingData['service_id'] = $serviceId;

            if ($resolvedStaffId) {
                $bookingData['staff_id'] = $resolvedStaffId;
            }

            if ($notes !== '') {
                $bookingData['notes'] = $notes;
            }

            $result = BookingService::createBooking($bookingData, $tenant, false);

            $pdo->commit();

            // After commit: update customer stats
            Database::execute(
                'UPDATE `customers` SET `booking_count` = `booking_count` + 1, `last_booking_at` = NOW() WHERE `id` = ?',
                [$customerId]
            );

            // Clear old input on success
            FormState::clear();

            FormState::toast('success', __('admin.bookings.flash_created'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/{$result['id']}");

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::error('Admin booking creation failed', [
                'tenant' => $tenantId,
                'error'  => $e->getMessage(),
            ]);

            FormState::toast('error', __('admin.bookings.flash_create_failed'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }
    }

    // ── Resource-pattern manual booking ──

    private function storeResourceBooking(Request $request, array $tenant, string $tenantId): Response
    {
        $resourceId    = $request->string('resource_id') ?: null;
        $checkIn       = trim($request->string('check_in'));
        $checkOut      = trim($request->string('check_out'));
        $guestCount    = max(1, (int) $request->string('guest_count'));
        $customerName  = trim($request->string('customer_name'));
        $customerEmail = trim($request->string('customer_email'));
        $customerPhone = trim($request->string('customer_phone'));
        $notes         = trim($request->string('notes'));

        $this->storeOldInput($request);

        // Validation
        if (!$resourceId) {
            FormState::toast('error', __('admin.bookings.error_resource_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ($checkIn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn)) {
            FormState::toast('error', __('admin.bookings.error_check_in_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ($checkOut === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)) {
            FormState::toast('error', __('admin.bookings.error_check_out_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ($customerName === '') {
            FormState::toast('error', __('admin.bookings.error_name_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            FormState::toast('error', __('admin.bookings.error_email_invalid'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ((int) ($tenant['require_phone'] ?? 0) === 1 && $customerPhone === '') {
            FormState::toast('error', __('admin.bookings.error_phone_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        // Check availability (early, pre-lock check for fast feedback)
        $availability = ResourceCalculator::checkAvailability(
            $tenant,
            $resourceId,
            $checkIn,
            $checkOut,
            $guestCount,
        );

        if (!$availability['available']) {
            FormState::toast('error', __('admin.bookings.flash_resource_unavailable'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        // Double-booking prevention: transaction + FOR UPDATE lock
        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            // Lock existing bookings for this resource in the date range
            $lockStmt = $pdo->prepare(
                'SELECT `id` FROM `bookings`
                 WHERE `tenant_id` = ? AND `resource_id` = ?
                 AND `status` IN (\'confirmed\', \'rescheduled\')
                 AND `start_datetime` < ? AND `end_datetime` > ?
                 FOR UPDATE'
            );
            $lockStmt->execute([$tenantId, $resourceId, $checkOut . ' 00:00:00', $checkIn . ' 00:00:00']);

            // Re-check availability inside the lock
            $recheck = ResourceCalculator::checkAvailability($tenant, $resourceId, $checkIn, $checkOut, $guestCount);
            if (!$recheck['available']) {
                $pdo->rollBack();
                FormState::toast('error', __('admin.bookings.flash_resource_unavailable'));
                return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
            }

            $customerId = CustomerService::findOrCreate(
                $tenantId,
                $customerName,
                $customerEmail,
                $customerPhone,
            );

            $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
            $startDt = new \DateTimeImmutable("{$checkIn} 00:00:00", $tz);
            $endDt   = new \DateTimeImmutable("{$checkOut} 00:00:00", $tz);

            $bookingData = [
                'tenant_id'       => $tenantId,
                'customer_id'     => $customerId,
                'booking_pattern' => 'resource',
                'resource_id'     => $resourceId,
                'start_datetime'  => $startDt->format('Y-m-d H:i:s'),
                'end_datetime'    => $endDt->format('Y-m-d H:i:s'),
                'party_size'      => $guestCount,
                'source'          => 'admin',
            ];

            if ($notes !== '') {
                $bookingData['notes'] = $notes;
            }

            $result = BookingService::createBooking($bookingData, $tenant, false);

            $pdo->commit();

            Database::execute(
                'UPDATE `customers` SET `booking_count` = `booking_count` + 1, `last_booking_at` = NOW() WHERE `id` = ?',
                [$customerId]
            );

            FormState::clear();
            FormState::toast('success', __('admin.bookings.flash_created'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/{$result['id']}");

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::error('Admin resource booking creation failed', [
                'tenant' => $tenantId,
                'error'  => $e->getMessage(),
            ]);

            FormState::toast('error', __('admin.bookings.flash_create_failed'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }
    }

    // ── Capacity-pattern manual booking ──

    private function storeCapacityBooking(Request $request, array $tenant, string $tenantId): Response
    {
        $slotId        = $request->string('slot_id') ?: null;
        $dateStr       = trim($request->string('date'));
        $partySize     = max(1, (int) $request->string('party_size'));
        $customerName  = trim($request->string('customer_name'));
        $customerEmail = trim($request->string('customer_email'));
        $customerPhone = trim($request->string('customer_phone'));
        $notes         = trim($request->string('notes'));

        $this->storeOldInput($request);

        // Validation
        if (!$slotId) {
            FormState::toast('error', __('admin.bookings.error_slot_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ($dateStr === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
            FormState::toast('error', __('admin.bookings.error_date_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ($customerName === '') {
            FormState::toast('error', __('admin.bookings.error_name_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ($customerEmail === '' || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            FormState::toast('error', __('admin.bookings.error_email_invalid'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        if ((int) ($tenant['require_phone'] ?? 0) === 1 && $customerPhone === '') {
            FormState::toast('error', __('admin.bookings.error_phone_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        // Pre-lock availability check
        $availability = CapacityCalculator::checkSlotAvailability($tenant, $slotId, $dateStr, $partySize);
        if (!$availability['available']) {
            FormState::toast('error', __('admin.bookings.flash_capacity_exceeded'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        // Load slot for start/end time
        $slotRows = Database::query(
            'SELECT `start_time`, `end_time` FROM `capacity_slots` WHERE `id` = ? AND `tenant_id` = ?',
            [$slotId, $tenantId]
        );
        if (empty($slotRows)) {
            FormState::toast('error', __('admin.bookings.error_slot_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }
        $slot = $slotRows[0];

        // Double-booking prevention: transaction + FOR UPDATE lock
        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            $startDt = $dateStr . ' ' . $slot['start_time'];
            $endDt   = $dateStr . ' ' . $slot['end_time'];

            // Lock existing capacity bookings for this slot time on this date
            $lockStmt = $pdo->prepare(
                'SELECT `id` FROM `bookings`
                 WHERE `tenant_id` = ? AND `booking_pattern` = \'capacity\'
                 AND `start_datetime` = ? AND `status` IN (\'confirmed\', \'rescheduled\')
                 FOR UPDATE'
            );
            $lockStmt->execute([$tenantId, $startDt]);

            // Re-check availability inside the lock
            $recheck = CapacityCalculator::checkSlotAvailability($tenant, $slotId, $dateStr, $partySize);
            if (!$recheck['available']) {
                $pdo->rollBack();
                FormState::toast('error', __('admin.bookings.flash_capacity_exceeded'));
                return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
            }

            $customerId = CustomerService::findOrCreate(
                $tenantId,
                $customerName,
                $customerEmail,
                $customerPhone,
            );

            $bookingData = [
                'tenant_id'       => $tenantId,
                'customer_id'     => $customerId,
                'booking_pattern' => 'capacity',
                'start_datetime'  => $startDt,
                'end_datetime'    => $endDt,
                'party_size'      => $partySize,
                'source'          => 'admin',
            ];

            if ($notes !== '') {
                $bookingData['notes'] = $notes;
            }

            $result = BookingService::createBooking($bookingData, $tenant, false);

            $pdo->commit();

            Database::execute(
                'UPDATE `customers` SET `booking_count` = `booking_count` + 1, `last_booking_at` = NOW() WHERE `id` = ?',
                [$customerId]
            );

            FormState::clear();
            FormState::toast('success', __('admin.bookings.flash_created'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/{$result['id']}");

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::error('Admin capacity booking creation failed', [
                'tenant' => $tenantId,
                'error'  => $e->getMessage(),
            ]);

            FormState::toast('error', __('admin.bookings.flash_create_failed'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }
    }

    /**
     * Store an event-pattern booking (admin manual entry).
     */
    private function storeEventBooking(Request $request, array $tenant, string $tenantId): Response
    {
        $this->storeOldInput($request);

        $eventId       = $request->string('event_id') ?: null;
        $date          = trim($request->string('date'));
        $spotCount     = max(1, (int) $request->string('spot_count'));
        $customerName  = trim($request->string('customer_name'));
        $customerEmail = trim($request->string('customer_email'));
        $customerPhone = trim($request->string('customer_phone'));
        $notes         = trim($request->string('notes'));

        // Validation
        if (!$eventId) {
            FormState::toast('error', __('admin.events.error_name_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }
        if (!$customerName || !$customerEmail) {
            FormState::toast('error', __('admin.bookings.flash_create_failed'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        // Load event
        $events = Database::query(
            'SELECT * FROM `events` WHERE `id` = ? AND `tenant_id` = ? AND `is_active` = 1',
            [$eventId, $tenantId]
        );
        if (empty($events)) {
            FormState::toast('error', __('admin.events.error_name_required'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }
        $event = $events[0];

        // Determine date (one-off: use event date; recurring: require date input)
        if (!$date) {
            $date = date('Y-m-d', strtotime($event['start_datetime']));
        }

        // Check availability
        $availability = EventCalculator::checkAvailability($tenant, $eventId, $date, $spotCount);
        if (!$availability['available']) {
            FormState::toast('error', __('booking.api.event_full'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }

        $isWaitlisted = $availability['waitlisted'];

        // Build booking
        $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
        $origStart = new \DateTimeImmutable($event['start_datetime'], $tz);
        $origEnd = new \DateTimeImmutable($event['end_datetime'], $tz);
        $startDt = $date . ' ' . $origStart->format('H:i:s');
        $endDt = $date . ' ' . $origEnd->format('H:i:s');

        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            $customerId = CustomerService::findOrCreate($tenantId, $customerName, $customerEmail, $customerPhone);

            $bookingData = [
                'tenant_id'       => $tenantId,
                'customer_id'     => $customerId,
                'booking_pattern' => 'event',
                'event_id'        => $eventId,
                'start_datetime'  => $startDt,
                'end_datetime'    => $endDt,
                'party_size'      => $spotCount,
                'status'          => $isWaitlisted ? 'waitlisted' : 'confirmed',
                'source'          => 'admin',
            ];

            if ($notes !== '') {
                $bookingData['notes'] = $notes;
            }

            $result = BookingService::createBooking($bookingData, $tenant, false);
            $pdo->commit();

            Database::execute(
                'UPDATE `customers` SET `booking_count` = `booking_count` + 1, `last_booking_at` = NOW() WHERE `id` = ?',
                [$customerId]
            );

            FormState::clear();
            FormState::toast('success', __('admin.bookings.flash_created'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/{$result['id']}");

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::error('Admin event booking creation failed', [
                'tenant' => $tenantId,
                'error'  => $e->getMessage(),
            ]);

            FormState::toast('error', __('admin.bookings.flash_create_failed'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }
    }

    // ── Shared helpers ──

    private function doUpdateStatus(Request $request, string $redirectBase): Response
    {
        $id = $request->getAttribute('id');
        $newStatus = trim($request->string('status'));
        $booking = Booking::find($id);

        if ($booking === null) {
            FormState::toast('error', __('admin.bookings.flash_status_failed'));
            return Response::redirect($redirectBase);
        }

        $oldStatus = $booking['status'];

        // Same-status submission: no-op, just redirect back.
        if ($newStatus === $oldStatus) {
            return Response::redirect("{$redirectBase}/{$id}");
        }

        // Enforce allowed status transitions. Must match the UI transition map
        // in show.php to prevent crafted POSTs from bypassing the dropdown.
        // rescheduled is intentionally terminal here — only the dedicated
        // reschedule endpoint (/reschedule) can set status=rescheduled.
        $transitions = [
            'pending'     => ['confirmed', 'cancelled'],
            'confirmed'   => ['cancelled', 'completed', 'no_show'],
            'waitlisted'  => ['confirmed', 'cancelled'],
            'cancelled'   => ['confirmed'],
            'completed'   => ['confirmed'],
            'no_show'     => ['confirmed'],
            'rescheduled' => [],
        ];
        $allowed = $transitions[$oldStatus] ?? [];

        if (!in_array($newStatus, $allowed, true)) {
            FormState::toast('error', __('admin.bookings.flash_status_failed'));
            return Response::redirect("{$redirectBase}/{$id}");
        }

        if (Booking::updateStatus($id, $newStatus)) {
            AuditLog::log('booking.status_changed', 'booking', $id, [
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
            ]);

            // Waitlisted → confirmed: send confirmation email
            if ($oldStatus === 'waitlisted' && $newStatus === 'confirmed') {
                $this->sendPromotionEmail($booking);
            }

            // Pending → confirmed: send approval confirmed email + schedule reminder
            if ($oldStatus === 'pending' && $newStatus === 'confirmed') {
                $this->sendStatusTransitionEmail($booking, 'approval_confirmed');
                $this->scheduleReminderForApprovedBooking($booking);
            }


            // NOTE: rescheduled status is excluded from the simple status dropdown.
            // Reschedule confirmation emails should only be sent from a dedicated
            // reschedule flow that updates booking timestamps first.

            FormState::toast('success', __('admin.bookings.flash_status_updated'));
        } else {
            FormState::toast('error', __('admin.bookings.flash_status_failed'));
        }

        return Response::redirect("{$redirectBase}/{$id}");
    }

    /**
     * Send a booking confirmation email when a waitlisted booking is promoted.
     */
    private function sendPromotionEmail(array $booking): void
    {
        if (!\App\Engine\Mailer::isConfigured()) {
            return;
        }

        try {
            $tenant = $this->loadTenant($booking['tenant_id']);
            if ($tenant === null) {
                return;
            }

            $customer = Database::query(
                'SELECT `name`, `email` FROM `customers` WHERE `id` = ? LIMIT 1',
                [$booking['customer_id']]
            );
            if (empty($customer) || empty($customer[0]['email'])) {
                return;
            }

            $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
            $startDt = new \DateTimeImmutable($booking['start_datetime'], $tz);
            $endDt = new \DateTimeImmutable($booking['end_datetime'], $tz);

            $serviceName = '';
            if ($booking['booking_pattern'] === 'event' && !empty($booking['event_name'])) {
                $serviceName = $booking['event_name'];
            } elseif (!empty($booking['service_name'])) {
                $serviceName = $booking['service_name'];
            } elseif (!empty($booking['resource_name'])) {
                $serviceName = $booking['resource_name'];
            }

            $emailBookingData = [
                'date'           => $startDt->format('Y-m-d'),
                'formatted_date' => \App\Engine\Locale::dateLong($startDt),
                'time'           => $startDt->format('H:i'),
                'end_time'       => $endDt->format('H:i'),
                'duration'       => (string) ($booking['party_size'] ?? 1),
            ];

            \App\Engine\Mailer::sendBookingConfirmation(
                $customer[0]['email'],
                $customer[0]['name'],
                $emailBookingData,
                $serviceName,
                null,
                $tenant['name'],
                $tenant['id'],
                $booking['id'],
                $tenant['brand_color'] ?? '#2563EB',
                null, $tenant['slug'],
            );
        } catch (\Throwable $e) {
            Logger::error('Promotion confirmation email failed', [
                'booking' => $booking['id'],
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Send a status transition email (approval confirmed or reschedule confirmation).
     */
    private function sendStatusTransitionEmail(array $booking, string $type): void
    {
        if (!\App\Engine\Mailer::isConfigured()) {
            return;
        }

        try {
            $tenant = $this->loadTenant($booking['tenant_id']);
            if ($tenant === null) {
                return;
            }

            $customer = Database::query(
                'SELECT `name`, `email` FROM `customers` WHERE `id` = ? LIMIT 1',
                [$booking['customer_id']]
            );
            if (empty($customer) || empty($customer[0]['email'])) {
                return;
            }

            $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
            $startDt = new \DateTimeImmutable($booking['start_datetime'], $tz);
            $endDt = new \DateTimeImmutable($booking['end_datetime'], $tz);

            $serviceName = '';
            if ($booking['booking_pattern'] === 'event' && !empty($booking['event_name'])) {
                $serviceName = $booking['event_name'];
            } elseif (!empty($booking['service_name'])) {
                $serviceName = $booking['service_name'];
            } elseif (!empty($booking['resource_name'])) {
                $serviceName = $booking['resource_name'];
            }

            $emailData = [
                'date'           => $startDt->format('Y-m-d'),
                'formatted_date' => \App\Engine\Locale::dateLong($startDt),
                'time'           => $startDt->format('H:i'),
                'end_time'       => $endDt->format('H:i'),
                'duration'       => (string) ($booking['party_size'] ?? 1),
            ];

            if ($type === 'approval_confirmed') {
                \App\Engine\Mailer::sendApprovalConfirmed(
                    $customer[0]['email'],
                    $customer[0]['name'],
                    $emailData,
                    $serviceName ?: null,
                    null,
                    $tenant['name'],
                    $tenant['id'],
                    $booking['id'],
                    $tenant['brand_color'] ?? '#2563EB',
                    $tenant['slug'],
                );
            } elseif ($type === 'reschedule') {
                \App\Engine\Mailer::sendRescheduleConfirmation(
                    $customer[0]['email'],
                    $customer[0]['name'],
                    $emailData,
                    $serviceName ?: null,
                    null,
                    $tenant['name'],
                    $tenant['id'],
                    $booking['id'],
                    $tenant['brand_color'] ?? '#2563EB',
                    $tenant['slug'],
                );
            }
        } catch (\Throwable $e) {
            Logger::error("Status transition email ({$type}) failed", [
                'booking' => $booking['id'],
                'error'   => $e->getMessage(),
            ]);
        }
    }

    /**
     * Schedule a reminder for a booking that was just approved (pending → confirmed).
     *
     * PRD: reminders are deferred until approval since pending bookings skip
     * reminder scheduling during creation.
     */
    private function scheduleReminderForApprovedBooking(array $booking): void
    {
        try {
            $tenant = $this->loadTenant($booking['tenant_id']);
            if ($tenant === null) {
                return;
            }

            if ((int) ($tenant['send_reminders'] ?? 0) !== 1) {
                return;
            }

            $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
            $startDt = new \DateTimeImmutable($booking['start_datetime'], $tz);
            $reminderHours = max(1, (int) ($tenant['reminder_hours_before'] ?? 24));
            $reminderAt = $startDt->modify("-{$reminderHours} hours");

            if ($reminderAt <= new \DateTimeImmutable('now', $tz)) {
                return; // Too late to schedule
            }

            // Dedupe: skip if an unsent reminder already exists for this booking
            $existing = Database::query(
                "SELECT COUNT(*) as cnt FROM `reminders` WHERE `booking_id` = ? AND `sent_at` IS NULL",
                [$booking['id']]
            );
            if ((int) ($existing[0]['cnt'] ?? 0) > 0) {
                return;
            }

            $reminderId = \App\Engine\Ulid::generate();
            Database::execute(
                "INSERT INTO `reminders` (`id`, `booking_id`, `tenant_id`, `scheduled_at`) VALUES (?, ?, ?, ?)",
                [(string) $reminderId, $booking['id'], $booking['tenant_id'], $reminderAt->format('Y-m-d H:i:s')]
            );
        } catch (\Throwable $e) {
            Logger::error('Failed to schedule reminder for approved booking', [
                'booking' => $booking['id'],
                'error'   => $e->getMessage(),
            ]);
        }
    }

    private function render(string $template, string $pageTitle, array $extra = []): Response
    {
        return View::response($template, array_merge([
            'user'       => Auth::user(),
            'version'    => Version::get(),
            'pageTitle'  => $pageTitle,
            'activePage' => 'bookings',
            'csrfToken'  => CsrfMiddleware::generateToken(),
        ], $extra));
    }

    /**
     * Load full tenant record. Returns null if not found.
     */
    private function loadTenant(string $tenantId): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `tenants` WHERE `id` = ? LIMIT 1',
            [$tenantId]
        );

        return $rows[0] ?? null;
    }

    /**
     * Store form input in session for re-display on validation failure.
     *
     * Stores all submitted fields (both timeslot and resource patterns)
     * so the form can be repopulated after a redirect.
     */
    private function storeOldInput(Request $request): void
    {
        $old = $_POST;
        unset($old['_csrf_token']);
        FormState::flashInput($old);
    }

    private function forbidden(Request $request): Response
    {
        if ($request->isJson()) {
            return Response::json([
                'error'   => 'forbidden',
                'message' => 'Operator access required.',
            ], 403);
        }

        return View::response('admin.errors.403', [
            'user'      => Auth::user(),
            'version'   => Version::get(),
            'pageTitle' => '403',
            'csrfToken' => CsrfMiddleware::generateToken(),
        ], 403);
    }


    // ── CSV Export ──

    /**
     * Export bookings as CSV — operator cross-tenant.
     *
     * Respects the same filter/sort query params as the index view so
     * the user gets exactly the dataset they see in the table.
     */
    public function export(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $status    = $request->string('status') ?: null;
        $from      = $request->string('from') ?: null;
        $to        = $request->string('to') ?: null;
        $search    = $request->string('search') ?: null;
        $sort      = $request->string('sort') ?: 'start_datetime';
        $direction = $request->string('direction') ?: 'desc';

        $bookings = Booking::allForExport($status, $from, $to, $search, $sort, $direction);

        return $this->streamCsv($bookings, 'bookings-export', true);
    }

    /**
     * Export bookings as CSV — tenant-scoped.
     */
    public function tenantExport(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        $status    = $request->string('status') ?: null;
        $from      = $request->string('from') ?: null;
        $to        = $request->string('to') ?: null;
        $sort      = $request->string('sort') ?: 'start_datetime';
        $direction = $request->string('direction') ?: 'desc';

        $bookings = Booking::forTenantExport($tenantId, $status, $from, $to, $sort, $direction);

        return $this->streamCsv($bookings, 'bookings-export', false);
    }

    /**
     * Build a CSV response from booking rows.
     *
     * @param list<array<string, mixed>> $bookings
     */
    private function streamCsv(array $bookings, string $filenamePrefix, bool $includeTenant): Response
    {
        $filename = $filenamePrefix . '-' . date('Y-m-d') . '.csv';

        $headers = ['Date', 'Time', 'End Time', 'Customer', 'Email', 'Service', 'Status'];
        if ($includeTenant) {
            $headers[] = 'Business';
        }

        $output = fopen('php://temp', 'r+');
        // UTF-8 BOM for Excel compatibility
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers, escape: '\\');

        foreach ($bookings as $b) {
            $row = [
                substr($b['start_datetime'] ?? '', 0, 10),
                substr($b['start_datetime'] ?? '', 11, 5),
                substr($b['end_datetime'] ?? '', 11, 5),
                $b['customer_name'] ?? '',
                $b['customer_email'] ?? '',
                $b['service_name'] ?? $b['resource_name'] ?? $b['event_name'] ?? '',
                $b['status'] ?? '',
            ];
            if ($includeTenant) {
                $row[] = $b['tenant_name'] ?? '';
            }
            fputcsv($output, $row, escape: '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $response = new Response();
        return $response
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Cache-Control', 'no-store')
            ->body($csv);
    }

}
