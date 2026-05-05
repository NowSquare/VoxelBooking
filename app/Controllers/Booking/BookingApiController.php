<?php

declare(strict_types=1);

namespace App\Controllers\Booking;

use App\Engine\CustomerService;
use App\Engine\Database;
use App\Engine\Locale;
use App\Engine\TimeSlotCalculator;
use App\Engine\ResourceCalculator;
use App\Engine\CapacityCalculator;
use App\Engine\EventCalculator;
use App\Engine\BookingService;
use App\Engine\Ulid;
use App\Engine\AuditLog;
use App\Engine\Logger;
use App\Engine\Mailer;
use App\Engine\Request;
use App\Engine\Response;

/**
 * Public API controller for booking pages.
 *
 * All endpoints are per-tenant, resolved via {slug} route parameter.
 * No authentication required — these serve the public booking page.
 *
 * Rate limits per PRD §X:
 * - GET endpoints: 60/min/IP
 * - POST /bookings: 10/min/IP
 */
final class BookingApiController
{
    /**
     * Resolve tenant from slug. Returns null if not found or not active.
     */
    private function resolveTenant(string $slug): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `tenants` WHERE `slug` = ? AND `status` = ? LIMIT 1',
            [$slug, 'active']
        );

        return $rows[0] ?? null;
    }

    /**
     * Apply the public booking locale resolution chain.
     *
     * Must be called before any __() response to ensure the locale
     * matches what the booking page shell uses.
     */
    private function resolveLocale(array $tenant, Request $request): void
    {
        $acceptLang = $request->header('Accept-Language');
        Locale::resolveForBooking($tenant, $acceptLang);
    }

    /**
     * GET /api/{slug}/services — list active services.
     */
    public function services(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $services = Database::query(
            'SELECT `id`, `name`, `description`, `duration_minutes`, `price`, `price_label`, `category`, `preparation_text`, `cover_image_path`, `color`
             FROM `services`
             WHERE `tenant_id` = ? AND `is_active` = 1
             ORDER BY `sort_order` ASC, `name` ASC',
            [$tenant['id']]
        );

        // Normalize cover image paths to absolute (consistent with resource/staff pattern)
        foreach ($services as &$svc) {
            if (!empty($svc['cover_image_path'])) {
                $svc['cover_image_path'] = '/' . ltrim($svc['cover_image_path'], '/');
            }
        }
        unset($svc);

        return Response::json(['services' => $services]);
    }

    /**
     * GET /api/{slug}/staff — list active staff.
     */
    public function staff(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $serviceId = $request->string('service_id');

        if ($serviceId) {
            // Staff for specific service
            $staff = Database::query(
                'SELECT s.`id`, s.`name`, s.`title`, s.`avatar_path`
                 FROM `staff` s
                 JOIN `service_staff` ss ON ss.`staff_id` = s.`id`
                 WHERE ss.`service_id` = ? AND s.`tenant_id` = ? AND s.`is_active` = 1
                 ORDER BY s.`sort_order` ASC',
                [$serviceId, $tenant['id']]
            );
        } else {
            $staff = Database::query(
                'SELECT `id`, `name`, `title`, `avatar_path`
                 FROM `staff`
                 WHERE `tenant_id` = ? AND `is_active` = 1
                 ORDER BY `sort_order` ASC',
                [$tenant['id']]
            );
        }
        // Normalize avatar paths to absolute
        foreach ($staff as &$s) {
            if (!empty($s['avatar_path'])) {
                $s['avatar_path'] = '/' . ltrim($s['avatar_path'], '/');
            }
        }
        unset($s);

        return Response::json(['staff' => $staff]);
    }

    /**
     * GET /api/{slug}/availability — available slots for a date.
     */
    public function availability(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $this->resolveLocale($tenant, $request);

        $date = $request->string('date');
        $serviceId = $request->string('service_id');
        $staffId = $request->string('staff_id');

        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Response::json(['error' => 'invalid_date', 'message' => __('booking.api.invalid_date')], 400);
        }

        $result = TimeSlotCalculator::getAvailableSlots($tenant, $date, $serviceId ?: null, $staffId ?: null);

        return Response::json($result);
    }

    /**
     * GET /api/{slug}/available-dates — dates with slots in a month.
     */
    public function availableDates(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $year = (int) ($request->string('year') ?: date('Y'));
        $month = (int) ($request->string('month') ?: date('n'));
        $serviceId = $request->string('service_id');
        $staffId = $request->string('staff_id');

        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2040) {
            return Response::json(['error' => 'invalid_month'], 400);
        }

        $dates = TimeSlotCalculator::getAvailableDates($tenant, $year, $month, $serviceId ?: null, $staffId ?: null);

        return Response::json(['dates' => $dates, 'year' => $year, 'month' => $month]);
    }

    /**
     * POST /api/{slug}/bookings — create a booking.
     *
     * Implements double-booking prevention per PRD §III:
     * 1. Begin transaction
     * 2. SELECT ... FOR UPDATE on time window
     * 3. Re-check availability
     * 4. Insert booking + customer
     * 5. Commit
     * 6. After commit: dispatch emails
     */
    // ── Resource-pattern endpoints ──

    /**
     * GET /api/{slug}/resources — list active resources.
     */
    public function resources(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $resources = Database::query(
            'SELECT * FROM `resources`
             WHERE `tenant_id` = ? AND `is_active` = 1
             ORDER BY `sort_order` ASC, `name` ASC',
            [$tenant['id']]
        );

        // Normalize + decode resource fields for API response
        foreach ($resources as &$r) {
            $r['amenities'] = ($r['amenities'] ?? null) ? json_decode($r['amenities'], true) : [];
            $r['price_per_night'] = ($r['price_per_night'] ?? null) !== null ? (float) $r['price_per_night'] : null;
            $r['capacity'] = (int) ($r['capacity'] ?? 1);
            $r['min_stay_nights'] = (int) ($r['min_stay_nights'] ?? 1);
            $r['max_stay_nights'] = (int) ($r['max_stay_nights'] ?? 30);
            // Decode day restrictions (migration-safe: columns may not exist yet)
            $raw = $r['check_in_days'] ?? null;
            $r['check_in_days'] = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
            $raw = $r['check_out_days'] ?? null;
            $r['check_out_days'] = ($raw !== null && $raw !== '') ? json_decode($raw, true) : null;
            // Normalize cover image path to absolute
            if (!empty($r['cover_image_path'])) {
                $r['cover_image_path'] = '/' . ltrim($r['cover_image_path'], '/');
            }
        }
        unset($r);

        return Response::json(['resources' => $resources]);
    }

    /**
     * GET /api/{slug}/resources/{id}/availability — available dates for a resource.
     */
    public function resourceAvailability(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $resourceId = $request->getAttribute('id');

        // Range-check mode: check_in + check_out → full availability + pricing
        $checkIn = $request->string('check_in');
        $checkOut = $request->string('check_out');

        if ($checkIn && $checkOut) {
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)) {
                return Response::json(['error' => 'invalid_date_range'], 400);
            }

            $guestCount = max(1, (int) ($request->string('guests') ?: 1));

            $result = ResourceCalculator::checkAvailability(
                $tenant,
                $resourceId,
                $checkIn,
                $checkOut,
                $guestCount,
            );

            return Response::json($result);
        }

        // Month-view mode: year + month → list of available dates
        $year = (int) ($request->string('year') ?: date('Y'));
        $month = (int) ($request->string('month') ?: date('n'));

        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2040) {
            return Response::json(['error' => 'invalid_month'], 400);
        }

        $result = ResourceCalculator::getAvailableDates($tenant, $resourceId, $year, $month);

        return Response::json($result);
    }

    // ── Booking creation ──

    /**
     * POST /api/{slug}/bookings — create a booking.
     *
     * Dispatches to pattern-specific creation logic based on the tenant's booking_pattern.
     * Timeslot: double-booking prevention per PRD §III.
     * Resource: date-range availability check + capacity validation.
     * Capacity: party-size slot availability + overbooking prevention.
     */
    public function createBooking(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $this->resolveLocale($tenant, $request);

        $input = $request->json();

        if (empty($input)) {
            return Response::json(['error' => 'invalid_json'], 400);
        }

        // Anti-spam: check __ts (timestamp field populated by JS on page load)
        $ts = $input['__ts'] ?? null;
        if (!$ts || !is_numeric($ts)) {
            return Response::json(['error' => 'spam_detected', 'message' => __('booking.api.spam_detected')], 422);
        }
        $pageLoadTime = (int) $ts;
        $elapsed = time() * 1000 - $pageLoadTime;
        if ($elapsed < 3000) {
            return Response::json(['error' => 'spam_detected', 'message' => __('booking.api.spam_retry')], 422);
        }

        // Anti-spam: honeypot (hidden field — bots fill it, humans don't)
        if (trim($input['__hp'] ?? '') !== '') {
            return Response::json(['error' => 'spam_detected', 'message' => __('booking.api.spam_detected')], 422);
        }

        // CSRF: manually validate for booking POST (API routes skip middleware CSRF)
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        $submittedToken = $request->header('X-CSRF-Token') ?? '';
        if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            return Response::json(['error' => 'csrf_mismatch', 'message' => __('booking.api.csrf_mismatch')], 403);
        }

        // Validate required fields
        $customer = $input['customer'] ?? [];
        $customerName = trim($customer['name'] ?? '');
        $customerEmail = trim($customer['email'] ?? '');
        $customerPhone = trim($customer['phone'] ?? '');

        if ($customerName === '' || $customerEmail === '') {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.name_email_required')], 422);
        }

        if (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.invalid_email')], 422);
        }

        if ((int) $tenant['require_phone'] === 1 && $customerPhone === '') {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.phone_required')], 422);
        }

        // Pattern dispatch: route to pattern-specific creation logic
        $pattern = $tenant['booking_pattern'] ?? 'timeslot';
        if ($pattern === 'resource') {
            return $this->createResourceBooking($tenant, $input, $customerName, $customerEmail, $customerPhone);
        }
        if ($pattern === 'capacity') {
            return $this->createCapacityBooking($tenant, $input, $customerName, $customerEmail, $customerPhone);
        }
        if ($pattern === 'event') {
            return $this->createEventBooking($tenant, $input, $customerName, $customerEmail, $customerPhone);
        }

        // ── Timeslot-specific validation and booking creation ──

        $serviceId = $input['service_id'] ?? null;
        $staffId = $input['staff_id'] ?? null;
        $startDatetime = $input['start_datetime'] ?? null;
        $consentGiven = (bool) ($input['consent_given'] ?? false);

        if (!$startDatetime) {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.start_time_required')], 422);
        }

        // Resolve service for duration
        $serviceDuration = (int) ($tenant['slot_duration_minutes'] ?? 30);
        if ($serviceId) {
            $service = Database::query(
                'SELECT `duration_minutes` FROM `services` WHERE `id` = ? AND `tenant_id` = ? AND `is_active` = 1 LIMIT 1',
                [$serviceId, $tenant['id']]
            );
            if (!empty($service)) {
                $serviceDuration = (int) $service[0]['duration_minutes'];
            }
        }

        // Calculate end time
        $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
        $startDt = new \DateTimeImmutable($startDatetime, $tz);
        $endDt = $startDt->modify("+{$serviceDuration} minutes");
        $date = $startDt->format('Y-m-d');
        $startTime = $startDt->format('H:i');

        // Double-booking prevention: transaction + FOR UPDATE lock
        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            // Lock existing bookings in the time window
            $lockSql = 'SELECT `id` FROM `bookings`
                        WHERE `tenant_id` = ? AND `status` IN (\'confirmed\', \'rescheduled\')
                        AND DATE(`start_datetime`) = ?';
            $lockParams = [$tenant['id'], $date];

            if ($staffId) {
                $lockSql .= ' AND `staff_id` = ?';
                $lockParams[] = $staffId;
            }

            $lockSql .= ' FOR UPDATE';
            $lockStmt = $pdo->prepare($lockSql);
            $lockStmt->execute($lockParams);

            // Re-check availability inside the lock
            $availResult = TimeSlotCalculator::getAvailableSlots($tenant, $date, $serviceId, $staffId);
            $stillAvailable = false;
            $resolvedStaffId = $staffId;

            foreach ($availResult['slots'] as $slot) {
                if ($slot['time'] === $startTime) {
                    $stillAvailable = true;
                    if (!$staffId && $slot['staff_id']) {
                        $resolvedStaffId = $slot['staff_id'];
                    }
                    break;
                }
            }

            if (!$stillAvailable) {
                $pdo->rollBack();

                $alternatives = array_slice(
                    array_map(fn($s) => ['time' => $s['time'], 'end_time' => $s['end_time'], 'staff_id' => $s['staff_id']], $availResult['slots']),
                    0,
                    3
                );

                return Response::json([
                    'error'        => 'slot_unavailable',
                    'message'      => __('booking.api.slot_unavailable'),
                    'alternatives' => $alternatives,
                ], 409);
            }

            // Find or create customer
            $customerId = CustomerService::findOrCreate(
                $tenant['id'],
                $customerName,
                $customerEmail,
                $customerPhone,
            );

            // Transaction-safe daily limit: lock customer row, then count
            $maxPerDay = (int) ($tenant['max_bookings_per_customer_per_day'] ?? 3);
            if ($maxPerDay > 0) {
                // Serialize concurrent requests for this customer within the transaction
                Database::query(
                    'SELECT `id` FROM `customers` WHERE `id` = ? FOR UPDATE',
                    [$customerId]
                );

                $countRows = Database::query(
                    'SELECT COUNT(*) AS `cnt` FROM `bookings` WHERE `customer_id` = ? AND `tenant_id` = ? AND DATE(`start_datetime`) = ? AND `status` IN (?, ?)',
                    [$customerId, $tenant['id'], $startDt->format('Y-m-d'), 'confirmed', 'rescheduled']
                );
                $existingCount = (int) ($countRows[0]['cnt'] ?? 0);

                if ($existingCount >= $maxPerDay) {
                    $pdo->rollBack();
                    return Response::json([
                        'error'   => 'max_bookings_exceeded',
                        'message' => __('booking.api.max_bookings_exceeded'),
                    ], 422);
                }
            }

            // Create booking via BookingService (handles consent evidence)
            $bookingData = [
                'tenant_id'       => $tenant['id'],
                'customer_id'     => $customerId,
                'booking_pattern' => $tenant['booking_pattern'] ?? 'timeslot',
                'start_datetime'  => $startDt->format('Y-m-d H:i:s'),
                'end_datetime'    => $endDt->format('Y-m-d H:i:s'),
                'source'          => 'web',
            ];

            if ($serviceId) {
                $bookingData['service_id'] = $serviceId;
            }
            if ($resolvedStaffId) {
                $bookingData['staff_id'] = $resolvedStaffId;
            }

            $notes = trim($input['notes'] ?? '');
            if ($notes !== '') {
                $bookingData['notes'] = $notes;
            }

            $customFields = $input['custom_fields'] ?? null;
            if ($customFields && is_array($customFields)) {
                $bookingData['custom_field_data'] = $customFields;
            }

            // Capture customer timezone from browser (Intl.DateTimeFormat)
            $customerTimezone = trim($input['customer_timezone'] ?? '');
            if ($customerTimezone !== '' && @timezone_open($customerTimezone)) {
                $bookingData['customer_timezone'] = $customerTimezone;
            }

            // If tenant requires approval, set booking to pending
            if ((int) ($tenant['booking_requires_approval'] ?? 0) === 1) {
                $bookingData['status'] = 'pending';
            }

            $result = BookingService::createBooking($bookingData, $tenant, $consentGiven);

            // Schedule reminder if tenant has reminders enabled (skip pending bookings)
            if ((int) ($tenant['send_reminders'] ?? 0) === 1 && ($bookingData['status'] ?? 'confirmed') !== 'pending') {
                $reminderHours = max(1, (int) ($tenant['reminder_hours_before'] ?? 24));
                $reminderAt = (clone $startDt)->modify("-{$reminderHours} hours");
                // Only schedule if reminder time is in the future
                if ($reminderAt > new \DateTimeImmutable('now', $startDt->getTimezone())) {
                    try {
                        $reminderId = Ulid::generate();
                        Database::execute(
                            "INSERT INTO `reminders` (`id`, `booking_id`, `tenant_id`, `scheduled_at`) VALUES (?, ?, ?, ?)",
                            [(string) $reminderId, $result['id'], $tenant['id'], $reminderAt->format('Y-m-d H:i:s')]
                        );
                    } catch (\Throwable $e) {
                        Logger::error('Failed to schedule reminder', [
                            'booking' => $result['id'],
                            'error'   => $e->getMessage(),
                        ]);
                    }
                }
            }

            $pdo->commit();

            // After commit: update customer stats
            Database::execute(
                'UPDATE `customers` SET `booking_count` = `booking_count` + 1, `last_booking_at` = NOW() WHERE `id` = ?',
                [$customerId]
            );

            // Build confirmation response
            $serviceName = null;
            if ($serviceId) {
                $svc = Database::query('SELECT `name` FROM `services` WHERE `id` = ? LIMIT 1', [$serviceId]);
                $serviceName = $svc[0]['name'] ?? null;
            }

            $staffName = null;
            if ($resolvedStaffId) {
                $stf = Database::query('SELECT `name` FROM `staff` WHERE `id` = ? LIMIT 1', [$resolvedStaffId]);
                $staffName = $stf[0]['name'] ?? null;
            }

            // After commit: dispatch email (never inside transaction — PRD §III)
            $bookingStatus = $bookingData['status'] ?? 'confirmed';
            $emailSent = false;
            if (Mailer::isConfigured()) {
                try {
                    $emailData = [
                        'date'           => $startDt->format('Y-m-d'),
                        'formatted_date' => Locale::dateLong($startDt),
                        'time'           => $startDt->format('H:i'),
                        'end_time'       => $endDt->format('H:i'),
                        'duration'       => $serviceDuration,
                    ];

                    if ($bookingStatus === 'pending') {
                        // Send approval request email for pending bookings
                        Mailer::sendApprovalRequest(
                            $customerEmail, $customerName, $emailData,
                            $serviceName, $staffName,
                            $tenant['name'], $tenant['id'], $result['id'],
                            $tenant['brand_color'] ?? '#2563EB',
                            null, $tenant['slug'],
                        );
                    } else {
                        $emailResult = Mailer::sendBookingConfirmation(
                            $customerEmail, $customerName, $emailData,
                            $serviceName, $staffName,
                            $tenant['name'], $tenant['id'], $result['id'],
                            $tenant['brand_color'] ?? '#2563EB',
                            null, $tenant['slug'],
                        );
                        $emailSent = ($emailResult['sent'] ?? false) && Mailer::isProductionSmtp();
                    }
                } catch (\Throwable $e) {
                    Logger::error('Booking email dispatch failed', [
                        'booking' => $result['id'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            // Staff notification (fire-and-forget)
            if (Mailer::isConfigured() && (int) ($tenant['notify_on_booking'] ?? 0) === 1) {
                try {
                    Mailer::sendStaffBookingNotification(
                        $emailData, $serviceName, $staffName, $customerName,
                        $tenant['name'], $tenant['id'], $result['id'],
                        $tenant['brand_color'] ?? '#2563EB',
                    );
                } catch (\Throwable $e) {
                    Logger::error('Staff booking notification failed', [
                        'booking' => $result['id'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            return Response::json([
                'booking' => [
                    'id'               => $result['id'],
                    'status'           => $bookingStatus,
                    'service'          => $serviceName,
                    'staff'            => $staffName,
                    'date'             => $startDt->format('Y-m-d'),
                    'time'             => $startDt->format('H:i'),
                    'end_time'         => $endDt->format('H:i'),
                    'duration'         => $serviceDuration,
                    'consent_recorded' => $result['consent_recorded'],
                    'email_sent'       => $emailSent,
                ],
            ], 201);

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::error('Booking creation failed', [
                'tenant' => $tenant['slug'] ?? $slug,
                'error'  => $e->getMessage(),
            ]);

            return Response::json([
                'error'   => 'booking_failed',
                'message' => __('booking.api.booking_failed'),
            ], 500);
        }
    }

    // ── Resource-pattern booking creation ──

    /**
     * Create a resource-pattern booking (hotel room, meeting room, etc.).
     *
     * Validates resource availability for the requested date range,
     * checks guest capacity, and creates the booking with date-based datetimes.
     */
    private function createResourceBooking(
        array $tenant,
        array $input,
        string $customerName,
        string $customerEmail,
        string $customerPhone,
    ): Response {
        $resourceId = $input['resource_id'] ?? null;
        $checkIn = $input['check_in'] ?? null;
        $checkOut = $input['check_out'] ?? null;
        $guestCount = (int) ($input['guest_count'] ?? 1);
        $consentGiven = (bool) ($input['consent_given'] ?? false);

        if (!$resourceId) {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.resource_required')], 422);
        }
        if (!$checkIn || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkIn)) {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.check_in_required')], 422);
        }
        if (!$checkOut || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $checkOut)) {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.check_out_required')], 422);
        }
        if ($guestCount < 1) {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.guest_count_invalid')], 422);
        }

        // Check availability via ResourceCalculator
        $availability = ResourceCalculator::checkAvailability(
            $tenant,
            $resourceId,
            $checkIn,
            $checkOut,
            $guestCount,
        );

        if (!$availability['available']) {
            return Response::json([
                'error'   => $availability['error'],
                'message' => __('booking.api.resource_unavailable'),
            ], 409);
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
            $lockStmt->execute([$tenant['id'], $resourceId, $checkOut . ' 00:00:00', $checkIn . ' 00:00:00']);

            // Re-check availability inside the lock
            $recheck = ResourceCalculator::checkAvailability($tenant, $resourceId, $checkIn, $checkOut, $guestCount);
            if (!$recheck['available']) {
                $pdo->rollBack();
                return Response::json([
                    'error'   => 'resource_unavailable',
                    'message' => __('booking.api.resource_unavailable'),
                ], 409);
            }

            // Find or create customer
            $customerId = CustomerService::findOrCreate(
                $tenant['id'],
                $customerName,
                $customerEmail,
                $customerPhone,
            );

            // Build booking data
            $bookingData = [
                'tenant_id'       => $tenant['id'],
                'customer_id'     => $customerId,
                'booking_pattern' => 'resource',
                'resource_id'     => $resourceId,
                'start_datetime'  => $checkIn . ' 00:00:00',
                'end_datetime'    => $checkOut . ' 00:00:00',
                'party_size'      => $guestCount,
                'source'          => 'web',
            ];

            $notes = trim($input['notes'] ?? '');
            if ($notes !== '') {
                $bookingData['notes'] = $notes;
            }

            $customFields = $input['custom_fields'] ?? null;
            if ($customFields && is_array($customFields)) {
                $bookingData['custom_field_data'] = $customFields;
            }

            $customerTimezone = trim($input['customer_timezone'] ?? '');
            if ($customerTimezone !== '' && @timezone_open($customerTimezone)) {
                $bookingData['customer_timezone'] = $customerTimezone;
            }

            // If tenant requires approval, set booking to pending
            if ((int) ($tenant['booking_requires_approval'] ?? 0) === 1) {
                $bookingData['status'] = 'pending';
            }

            $result = BookingService::createBooking($bookingData, $tenant, $consentGiven);

            // Schedule reminder if tenant has reminders enabled (skip pending bookings)
            if ((int) ($tenant['send_reminders'] ?? 0) === 1 && ($bookingData['status'] ?? 'confirmed') !== 'pending') {
                $reminderHours = max(1, (int) ($tenant['reminder_hours_before'] ?? 24));
                $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
                $checkInDt2 = new \DateTimeImmutable($checkIn, $tz);
                $reminderAt = $checkInDt2->modify("-{$reminderHours} hours");
                if ($reminderAt > new \DateTimeImmutable('now', $tz)) {
                    try {
                        $reminderId = Ulid::generate();
                        Database::execute(
                            "INSERT INTO `reminders` (`id`, `booking_id`, `tenant_id`, `scheduled_at`) VALUES (?, ?, ?, ?)",
                            [(string) $reminderId, $result['id'], $tenant['id'], $reminderAt->format('Y-m-d H:i:s')]
                        );
                    } catch (\Throwable $e) {
                        Logger::error('Failed to schedule resource reminder', ['booking' => $result['id'], 'error' => $e->getMessage()]);
                    }
                }
            }

            $pdo->commit();

            // After commit: update customer stats
            Database::execute(
                'UPDATE `customers` SET `booking_count` = `booking_count` + 1, `last_booking_at` = NOW() WHERE `id` = ?',
                [$customerId]
            );

            // After commit: dispatch email
            $bookingStatus = $bookingData['status'] ?? 'confirmed';
            $emailSent = false;
            if (Mailer::isConfigured()) {
                try {
                    $resourceName = $availability['resource']['name'] ?? null;
                    $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
                    $checkInDt = new \DateTimeImmutable($checkIn, $tz);
                    $checkOutDt = new \DateTimeImmutable($checkOut, $tz);

                    $emailData = [
                        'date'           => $checkIn,
                        'formatted_date' => Locale::dateLong($checkInDt) . ' – ' . Locale::dateLong($checkOutDt),
                        'time'           => '',
                        'end_time'       => '',
                        'duration'       => $availability['nights'] . ' ' . ($availability['nights'] === 1 ? 'night' : 'nights'),
                    ];

                    // Resource-specific detail rows for the email summary card
                    $resourceDetails = [];
                    if ($resourceName) {
                        $resourceDetails[__('email.common.room')] = $resourceName;
                    }
                    $resourceDetails[__('email.common.check_in')]  = Locale::dateLong($checkInDt);
                    $resourceDetails[__('email.common.check_out')] = Locale::dateLong($checkOutDt);
                    if ($guestCount > 1) {
                        $resourceDetails[__('email.common.guests')] = (string) $guestCount;
                    }
                    $resourceDetails[__('email.common.total')] = Locale::currency($availability['total'], $tenant['currency'] ?? 'EUR');

                    if ($bookingStatus === 'pending') {
                        Mailer::sendApprovalRequest(
                            $customerEmail, $customerName, $emailData,
                            $resourceName, null,
                            $tenant['name'], $tenant['id'], $result['id'],
                            $tenant['brand_color'] ?? '#2563EB',
                            $resourceDetails, $tenant['slug'],
                        );
                    } else {
                        $emailResult = Mailer::sendBookingConfirmation(
                            $customerEmail, $customerName, $emailData,
                            $resourceName, null,
                            $tenant['name'], $tenant['id'], $result['id'],
                            $tenant['brand_color'] ?? '#2563EB',
                            $resourceDetails, $tenant['slug'],
                        );
                        $emailSent = ($emailResult['sent'] ?? false) && Mailer::isProductionSmtp();
                    }
                } catch (\Throwable $e) {
                    Logger::error('Resource booking email dispatch failed', [
                        'booking' => $result['id'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            // Staff notification (fire-and-forget)
            if (Mailer::isConfigured() && (int) ($tenant['notify_on_booking'] ?? 0) === 1) {
                try {
                    Mailer::sendStaffBookingNotification(
                        $emailData, $resourceName, null, $customerName,
                        $tenant['name'], $tenant['id'], $result['id'],
                        $tenant['brand_color'] ?? '#2563EB',
                    );
                } catch (\Throwable $e) {
                    Logger::error('Staff booking notification failed', [
                        'booking' => $result['id'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            return Response::json([
                'booking' => [
                    'id'               => $result['id'],
                    'status'           => $bookingStatus,
                    'resource'         => $availability['resource']['name'] ?? null,
                    'check_in'         => $checkIn,
                    'check_out'        => $checkOut,
                    'nights'           => $availability['nights'],
                    'guest_count'      => $guestCount,
                    'total'            => $availability['total'],
                    'consent_recorded' => $result['consent_recorded'],
                    'email_sent'       => $emailSent,
                ],
            ], 201);

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::error('Resource booking creation failed', [
                'tenant' => $tenant['slug'] ?? '',
                'error'  => $e->getMessage(),
            ]);

            return Response::json([
                'error'   => 'booking_failed',
                'message' => __('booking.api.booking_failed'),
            ], 500);
        }
    }

    // ── Capacity-pattern read endpoints ──

    /**
     * GET /api/{slug}/capacity/available-dates — dates with capacity remaining.
     */
    public function capacityAvailableDates(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $year = (int) ($request->string('year') ?: date('Y'));
        $month = (int) ($request->string('month') ?: date('n'));
        $partySize = max(1, (int) ($request->string('party_size') ?: 1));

        if ($month < 1 || $month > 12 || $year < 2020 || $year > 2040) {
            return Response::json(['error' => 'invalid_month'], 400);
        }

        $monthStr = sprintf('%04d-%02d', $year, $month);
        $result = CapacityCalculator::getAvailableDates($tenant, $monthStr, $partySize);

        return Response::json($result);
    }

    /**
     * GET /api/{slug}/capacity/slots — available time slots for a date.
     */
    public function capacitySlots(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $date = $request->string('date');
        $partySize = max(1, (int) ($request->string('party_size') ?: 1));

        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Response::json(['error' => 'invalid_date'], 400);
        }

        $result = CapacityCalculator::getAvailableSlots($tenant, $date, $partySize);

        return Response::json($result);
    }

    // ── Capacity-pattern booking creation ──

    /**
     * Create a capacity-pattern booking (restaurant, escape room, group class).
     *
     * Validates slot availability for the requested party size,
     * checks capacity limits, and creates the booking.
     */
    private function createCapacityBooking(
        array $tenant,
        array $input,
        string $customerName,
        string $customerEmail,
        string $customerPhone,
    ): Response {
        $slotId    = $input['slot_id'] ?? null;
        $date      = $input['date'] ?? null;
        $partySize = (int) ($input['party_size'] ?? 1);
        $consentGiven = (bool) ($input['consent_given'] ?? false);

        if (!$slotId) {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.slot_required')], 422);
        }
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.date_required')], 422);
        }
        if ($partySize < 1) {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.party_size_invalid')], 422);
        }

        // Pre-lock check for fast feedback
        $availability = CapacityCalculator::checkSlotAvailability($tenant, $slotId, $date, $partySize);
        if (!$availability['available']) {
            $errorKey = $availability['error'] ?? 'capacity_exceeded';
            $messageKey = match ($errorKey) {
                'party_too_small' => 'booking.api.party_too_small',
                default => 'booking.api.capacity_exceeded',
            };
            return Response::json([
                'error'   => $errorKey,
                'message' => __($messageKey),
            ], 409);
        }

        // Load slot details for start/end time
        $slotRows = Database::query(
            'SELECT `start_time`, `end_time`, `label` FROM `capacity_slots` WHERE `id` = ? AND `tenant_id` = ?',
            [$slotId, $tenant['id']]
        );
        if (empty($slotRows)) {
            return Response::json(['error' => 'slot_not_found', 'message' => __('booking.api.slot_required')], 422);
        }
        $slot = $slotRows[0];

        // Double-booking prevention: transaction + FOR UPDATE lock
        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            // Lock existing capacity bookings for this slot time on this date
            $startDt = $date . ' ' . $slot['start_time'];
            $lockStmt = $pdo->prepare(
                'SELECT `id` FROM `bookings`
                 WHERE `tenant_id` = ? AND `booking_pattern` = \'capacity\'
                 AND `start_datetime` = ? AND `status` IN (\'confirmed\', \'rescheduled\')
                 FOR UPDATE'
            );
            $lockStmt->execute([$tenant['id'], $startDt]);

            // Re-check availability inside the lock
            $recheck = CapacityCalculator::checkSlotAvailability($tenant, $slotId, $date, $partySize);
            if (!$recheck['available']) {
                $pdo->rollBack();
                return Response::json([
                    'error'   => 'capacity_exceeded',
                    'message' => __('booking.api.capacity_exceeded'),
                ], 409);
            }

            // Find or create customer
            $customerId = CustomerService::findOrCreate(
                $tenant['id'],
                $customerName,
                $customerEmail,
                $customerPhone,
            );

            // Build booking data
            $endDt = $date . ' ' . $slot['end_time'];
            $bookingData = [
                'tenant_id'       => $tenant['id'],
                'customer_id'     => $customerId,
                'booking_pattern' => 'capacity',
                'start_datetime'  => $startDt,
                'end_datetime'    => $endDt,
                'party_size'      => $partySize,
                'source'          => 'web',
            ];

            $notes = trim($input['notes'] ?? '');
            if ($notes !== '') {
                $bookingData['notes'] = $notes;
            }

            $customFields = $input['custom_fields'] ?? null;
            if ($customFields && is_array($customFields)) {
                $bookingData['custom_field_data'] = $customFields;
            }

            $customerTimezone = trim($input['customer_timezone'] ?? '');
            if ($customerTimezone !== '' && @timezone_open($customerTimezone)) {
                $bookingData['customer_timezone'] = $customerTimezone;
            }

            // If tenant requires approval, set booking to pending
            if ((int) ($tenant['booking_requires_approval'] ?? 0) === 1) {
                $bookingData['status'] = 'pending';
            }

            $result = BookingService::createBooking($bookingData, $tenant, $consentGiven);

            // Schedule reminder if tenant has reminders enabled (skip pending bookings)
            if ((int) ($tenant['send_reminders'] ?? 0) === 1 && ($bookingData['status'] ?? 'confirmed') !== 'pending') {
                $reminderHours = max(1, (int) ($tenant['reminder_hours_before'] ?? 24));
                $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
                $slotStartDt = new \DateTimeImmutable($startDt, $tz);
                $reminderAt = $slotStartDt->modify("-{$reminderHours} hours");
                if ($reminderAt > new \DateTimeImmutable('now', $tz)) {
                    try {
                        $reminderId = Ulid::generate();
                        Database::execute(
                            "INSERT INTO `reminders` (`id`, `booking_id`, `tenant_id`, `scheduled_at`) VALUES (?, ?, ?, ?)",
                            [(string) $reminderId, $result['id'], $tenant['id'], $reminderAt->format('Y-m-d H:i:s')]
                        );
                    } catch (\Throwable $e) {
                        Logger::error('Failed to schedule capacity reminder', ['booking' => $result['id'], 'error' => $e->getMessage()]);
                    }
                }
            }

            $pdo->commit();

            // After commit: update customer stats
            Database::execute(
                'UPDATE `customers` SET `booking_count` = `booking_count` + 1, `last_booking_at` = NOW() WHERE `id` = ?',
                [$customerId]
            );

            // After commit: dispatch email
            $bookingStatus = $bookingData['status'] ?? 'confirmed';
            $emailSent = false;
            if (Mailer::isConfigured()) {
                try {
                    $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
                    $slotDt = new \DateTimeImmutable($startDt, $tz);
                    $slotEndDt = new \DateTimeImmutable($endDt, $tz);

                    $emailData = [
                        'date'           => $date,
                        'formatted_date' => Locale::dateLong($slotDt),
                        'time'           => substr($slot['start_time'], 0, 5),
                        'end_time'       => substr($slot['end_time'], 0, 5),
                        'duration'       => $partySize . ' ' . ($partySize === 1 ? __('booking.capacity.guest') : __('booking.capacity.guests')),
                    ];
                    $slotLabel = $slot['label'] ?? null;

                    if ($bookingStatus === 'pending') {
                        Mailer::sendApprovalRequest(
                            $customerEmail, $customerName, $emailData,
                            $slotLabel, null,
                            $tenant['name'], $tenant['id'], $result['id'],
                            $tenant['brand_color'] ?? '#2563EB',
                            null, $tenant['slug'],
                        );
                    } else {
                        $emailResult = Mailer::sendBookingConfirmation(
                            $customerEmail, $customerName, $emailData,
                            $slotLabel, null,
                            $tenant['name'], $tenant['id'], $result['id'],
                            $tenant['brand_color'] ?? '#2563EB',
                            null, $tenant['slug'],
                        );
                        $emailSent = ($emailResult['sent'] ?? false) && Mailer::isProductionSmtp();
                    }
                } catch (\Throwable $e) {
                    Logger::error('Capacity booking email dispatch failed', [
                        'booking' => $result['id'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            // Staff notification (fire-and-forget)
            if (Mailer::isConfigured() && (int) ($tenant['notify_on_booking'] ?? 0) === 1) {
                try {
                    Mailer::sendStaffBookingNotification(
                        $emailData, $slotLabel, null, $customerName,
                        $tenant['name'], $tenant['id'], $result['id'],
                        $tenant['brand_color'] ?? '#2563EB',
                    );
                } catch (\Throwable $e) {
                    Logger::error('Staff booking notification failed', [
                        'booking' => $result['id'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            return Response::json([
                'booking' => [
                    'id'               => $result['id'],
                    'status'           => $bookingStatus,
                    'date'             => $date,
                    'time'             => substr($slot['start_time'], 0, 5),
                    'end_time'         => substr($slot['end_time'], 0, 5),
                    'label'            => $slot['label'],
                    'party_size'       => $partySize,
                    'consent_recorded' => $result['consent_recorded'],
                    'email_sent'       => $emailSent,
                ],
            ], 201);

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::error('Capacity booking creation failed', [
                'tenant' => $tenant['slug'] ?? '',
                'error'  => $e->getMessage(),
            ]);

            return Response::json([
                'error'   => 'booking_failed',
                'message' => __('booking.api.booking_failed'),
            ], 500);
        }
    }

    // ── Event-pattern read endpoints ──

    /**
     * GET /api/{slug}/events
     *
     * Returns upcoming events with remaining spots, sorted chronologically.
     * Recurring events are expanded from RRULE into concrete instances.
     */
    public function events(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $this->resolveLocale($tenant, $request);

        $result = EventCalculator::getUpcomingEvents($tenant);

        return Response::json($result);
    }

    /**
     * GET /api/{slug}/events/{id}
     *
     * Returns a single event detail with availability info.
     * For recurring events, pass ?date=YYYY-MM-DD to select the instance.
     */
    public function eventDetail(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $this->resolveLocale($tenant, $request);

        $eventId = $request->getAttribute('id');
        $date = $request->string('date') ?: null;

        $result = EventCalculator::getEventDetail($tenant, $eventId, $date);

        if (!$result['event']) {
            return Response::json(['error' => 'event_not_found'], 404);
        }

        return Response::json($result);
    }

    // ── Event-pattern booking creation ──

    /**
     * Create an event-pattern booking.
     *
     * Validates event availability and spot count, handles waitlist behavior.
     * If event is full and allows waitlist, creates booking with status 'waitlisted'.
     */
    private function createEventBooking(
        array $tenant,
        array $input,
        string $customerName,
        string $customerEmail,
        string $customerPhone,
    ): Response {
        $eventId   = $input['event_id'] ?? null;
        $date      = $input['date'] ?? null;
        $spotCount = (int) ($input['spot_count'] ?? 1);
        $consentGiven = (bool) ($input['consent_given'] ?? false);

        if (!$eventId) {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.event_required')], 422);
        }
        if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.date_required')], 422);
        }
        if ($spotCount < 1) {
            return Response::json(['error' => 'validation', 'message' => __('booking.api.spot_count_invalid')], 422);
        }

        // Pre-lock check for fast feedback
        $availability = EventCalculator::checkAvailability($tenant, $eventId, $date, $spotCount);
        if (!$availability['available']) {
            $errorKey = $availability['error'] ?? 'event_full';
            $messageKey = match ($errorKey) {
                'spot_count_too_few'  => 'booking.api.spot_count_too_few',
                'spot_count_too_many' => 'booking.api.spot_count_too_many',
                'waitlist_full'       => 'booking.api.waitlist_full',
                'instance_cancelled'  => 'booking.api.event_cancelled',
                default               => 'booking.api.event_full',
            };
            return Response::json([
                'error'   => $errorKey,
                'message' => __($messageKey),
            ], 409);
        }

        $isWaitlisted = $availability['waitlisted'];

        // Load event details for datetime
        $eventRows = Database::query(
            'SELECT * FROM `events` WHERE `id` = ? AND `tenant_id` = ? AND `is_active` = 1',
            [$eventId, $tenant['id']]
        );
        if (empty($eventRows)) {
            return Response::json(['error' => 'event_not_found', 'message' => __('booking.api.event_required')], 422);
        }
        $event = $eventRows[0];

        // Build start/end datetime from event time + instance date
        $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
        $origStart = new \DateTimeImmutable($event['start_datetime'], $tz);
        $origEnd = new \DateTimeImmutable($event['end_datetime'], $tz);
        $startDt = $date . ' ' . $origStart->format('H:i:s');
        $endDt = $date . ' ' . $origEnd->format('H:i:s');

        // Double-booking prevention: transaction + FOR UPDATE lock
        $pdo = Database::connect();
        $pdo->beginTransaction();

        try {
            // Lock existing event bookings for this event+date
            $lockStmt = $pdo->prepare(
                'SELECT `id` FROM `bookings`
                 WHERE `tenant_id` = ? AND `event_id` = ? AND `booking_pattern` = \'event\'
                 AND DATE(`start_datetime`) = ? AND `status` IN (\'confirmed\', \'rescheduled\', \'waitlisted\')
                 FOR UPDATE'
            );
            $lockStmt->execute([$tenant['id'], $eventId, $date]);

            // Re-check availability inside the lock
            $recheck = EventCalculator::checkAvailability($tenant, $eventId, $date, $spotCount);
            if (!$recheck['available']) {
                $pdo->rollBack();
                $errorKey = $recheck['error'] ?? 'event_full';
                $messageKey = match ($errorKey) {
                    'waitlist_full' => 'booking.api.waitlist_full',
                    default => 'booking.api.event_full',
                };
                return Response::json([
                    'error'   => $errorKey,
                    'message' => __($messageKey),
                ], 409);
            }

            $isWaitlisted = $recheck['waitlisted'];

            // Find or create customer
            $customerId = CustomerService::findOrCreate(
                $tenant['id'],
                $customerName,
                $customerEmail,
                $customerPhone,
            );

            // Build booking data
            $bookingData = [
                'tenant_id'       => $tenant['id'],
                'customer_id'     => $customerId,
                'booking_pattern' => 'event',
                'event_id'        => $eventId,
                'start_datetime'  => $startDt,
                'end_datetime'    => $endDt,
                'party_size'      => $spotCount,
                'status'          => $isWaitlisted ? 'waitlisted' : 'confirmed',
                'source'          => 'web',
            ];

            $notes = trim($input['notes'] ?? '');
            if ($notes !== '') {
                $bookingData['notes'] = $notes;
            }

            $customFields = $input['custom_fields'] ?? null;
            if ($customFields && is_array($customFields)) {
                $bookingData['custom_field_data'] = $customFields;
            }

            $customerTimezone = trim($input['customer_timezone'] ?? '');
            if ($customerTimezone !== '' && @timezone_open($customerTimezone)) {
                $bookingData['customer_timezone'] = $customerTimezone;
            }

            // If tenant requires approval (and not waitlisted — waitlist has its own status)
            if (!$isWaitlisted && (int) ($tenant['booking_requires_approval'] ?? 0) === 1) {
                $bookingData['status'] = 'pending';
            }

            $result = BookingService::createBooking($bookingData, $tenant, $consentGiven);

            // Schedule reminder if tenant has reminders enabled (not for waitlisted or pending)
            if (!$isWaitlisted && ($bookingData['status'] ?? 'confirmed') !== 'pending' && (int) ($tenant['send_reminders'] ?? 0) === 1) {
                $reminderHours = max(1, (int) ($tenant['reminder_hours_before'] ?? 24));
                $eventStartDt = new \DateTimeImmutable($startDt, $tz);
                $reminderAt = $eventStartDt->modify("-{$reminderHours} hours");
                if ($reminderAt > new \DateTimeImmutable('now', $tz)) {
                    try {
                        $reminderId = Ulid::generate();
                        Database::execute(
                            "INSERT INTO `reminders` (`id`, `booking_id`, `tenant_id`, `scheduled_at`) VALUES (?, ?, ?, ?)",
                            [(string) $reminderId, $result['id'], $tenant['id'], $reminderAt->format('Y-m-d H:i:s')]
                        );
                    } catch (\Throwable $e) {
                        Logger::error('Failed to schedule event reminder', ['booking' => $result['id'], 'error' => $e->getMessage()]);
                    }
                }
            }

            $pdo->commit();

            // After commit: update customer stats
            Database::execute(
                'UPDATE `customers` SET `booking_count` = `booking_count` + 1, `last_booking_at` = NOW() WHERE `id` = ?',
                [$customerId]
            );

            // After commit: dispatch email (different for waitlisted/pending/confirmed)
            $bookingStatus = $bookingData['status'] ?? ($isWaitlisted ? 'waitlisted' : 'confirmed');
            $emailSent = false;
            if (Mailer::isConfigured()) {
                try {
                    $slotDt = new \DateTimeImmutable($startDt, $tz);
                    $slotEndDt = new \DateTimeImmutable($endDt, $tz);

                    $emailBookingData = [
                        'date'           => $date,
                        'formatted_date' => Locale::dateLong($slotDt),
                        'time'           => $origStart->format('H:i'),
                        'end_time'       => $origEnd->format('H:i'),
                        'duration'       => $spotCount . ' ' . ($spotCount === 1 ? __('booking.event.spot') : __('booking.event.spots')),
                    ];

                    if ($bookingStatus === 'pending') {
                        Mailer::sendApprovalRequest(
                            $customerEmail, $customerName, $emailBookingData,
                            $event['name'], null,
                            $tenant['name'], $tenant['id'], $result['id'],
                            $tenant['brand_color'] ?? '#2563EB',
                            null, $tenant['slug'],
                        );
                    } elseif ($isWaitlisted) {
                        $emailResult = Mailer::sendWaitlistConfirmation(
                            $customerEmail,
                            $customerName,
                            $emailBookingData,
                            $event['name'],
                            $tenant['name'],
                            $tenant['id'],
                            $result['id'],
                            $tenant['brand_color'] ?? '#2563EB',
                            $tenant['slug'],
                        );
                        $emailSent = ($emailResult['sent'] ?? false) && Mailer::isProductionSmtp();
                    } else {
                        $emailResult = Mailer::sendBookingConfirmation(
                            $customerEmail,
                            $customerName,
                            $emailBookingData,
                            $event['name'],
                            null, // no staff
                            $tenant['name'],
                            $tenant['id'],
                            $result['id'],
                            $tenant['brand_color'] ?? '#2563EB',
                            null, $tenant['slug'],
                        );
                        $emailSent = ($emailResult['sent'] ?? false) && Mailer::isProductionSmtp();
                    }
                } catch (\Throwable $e) {
                    Logger::error('Event booking email dispatch failed', [
                        'booking' => $result['id'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            // Staff notification (fire-and-forget)
            if (Mailer::isConfigured() && (int) ($tenant['notify_on_booking'] ?? 0) === 1) {
                try {
                    Mailer::sendStaffBookingNotification(
                        $emailBookingData, $event['name'], null, $customerName,
                        $tenant['name'], $tenant['id'], $result['id'],
                        $tenant['brand_color'] ?? '#2563EB',
                    );
                } catch (\Throwable $e) {
                    Logger::error('Staff booking notification failed', [
                        'booking' => $result['id'],
                        'error'   => $e->getMessage(),
                    ]);
                }
            }

            return Response::json([
                'booking' => [
                    'id'               => $result['id'],
                    'event_name'       => $event['name'],
                    'date'             => $date,
                    'time'             => $origStart->format('H:i'),
                    'end_time'         => $origEnd->format('H:i'),
                    'spot_count'       => $spotCount,
                    'status'           => $bookingStatus,
                    'waitlisted'       => $isWaitlisted,
                    'consent_recorded' => $result['consent_recorded'],
                    'email_sent'       => $emailSent,
                ],
            ], 201);

        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            Logger::error('Event booking creation failed', [
                'tenant' => $tenant['slug'] ?? '',
                'error'  => $e->getMessage(),
            ]);

            return Response::json([
                'error'   => 'booking_failed',
                'message' => __('booking.api.booking_failed'),
            ], 500);
        }
    }

    // ── Self-service booking management ──

    /**
     * GET /api/{slug}/bookings/{id} — booking detail for the manage page.
     *
     * Authentication: booking ULID is the bearer token (128-bit entropy).
     * Same pattern as the GDPR privacy page.
     */
    public function bookingDetail(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $this->resolveLocale($tenant, $request);

        $bookingId = $request->getAttribute('id');
        $booking = BookingService::findByIdWithDetails($bookingId, $tenant['id']);

        if (!$booking) {
            return Response::json(['error' => 'booking_not_found', 'message' => __('booking.manage.not_found')], 404);
        }

        // Compute permission flags
        $canCancel = BookingService::canCancel($booking, $tenant);
        $canReschedule = BookingService::canReschedule($booking, $tenant);

        // Format dates for JS
        $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
        $startDt = new \DateTimeImmutable($booking['start_datetime'], $tz);
        $endDt = new \DateTimeImmutable($booking['end_datetime'], $tz);

        // Resource pattern: compute check-in/check-out from start/end dates
        $pattern = $booking['booking_pattern'] ?? 'timeslot';
        $checkIn = null;
        $checkOut = null;
        if ($pattern === 'resource') {
            $checkIn = $startDt->format('Y-m-d');
            $checkOut = $endDt->format('Y-m-d');
        }

        return Response::json([
            'booking' => [
                'id'              => $booking['id'],
                'status'          => $booking['status'],
                'booking_pattern' => $booking['booking_pattern'],
                'date'            => $startDt->format('Y-m-d'),
                'time'            => $startDt->format('H:i'),
                'end_time'        => $endDt->format('H:i'),
                'start_datetime'  => $booking['start_datetime'],
                'end_datetime'    => $booking['end_datetime'],
                'party_size'      => (int) $booking['party_size'],
                'service_id'      => $booking['service_id'] ?? null,
                'staff_id'        => $booking['staff_id'] ?? null,
                'resource_id'     => $booking['resource_id'] ?? null,
                'event_id'        => $booking['event_id'] ?? null,
                'service_name'    => $booking['service_name'] ?? null,
                'staff_name'      => $booking['staff_name'] ?? null,
                'resource_name'   => $booking['resource_name'] ?? null,
                'event_name'      => $booking['event_name'] ?? null,
                'event_location'  => $booking['event_location'] ?? null,
                'customer_id'     => $booking['customer_id'] ?? null,
                'customer_name'   => $booking['customer_name'] ?? null,
                'customer_email'  => $booking['customer_email'] ?? null,
                'cancelled_at'    => $booking['cancelled_at'] ?? null,
                'cancellation_reason' => $booking['cancellation_reason'] ?? null,
                'check_in'        => $checkIn,
                'check_out'       => $checkOut,
            ],
            'can_cancel'     => $canCancel['allowed'],
            'cancel_reason'  => $canCancel['reason'],
            'can_reschedule' => $canReschedule['allowed'],
            'reschedule_reason' => $canReschedule['reason'],
        ]);
    }

    /**
     * POST /api/{slug}/bookings/{id}/cancel — cancel a booking.
     *
     * Validates CSRF, enforces time gate, updates status to cancelled,
     * dispatches cancellation email, and returns the result.
     */
    public function cancelBookingAction(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $this->resolveLocale($tenant, $request);

        // CSRF validation
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        $submittedToken = $request->header('X-CSRF-Token') ?? '';
        if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            return Response::json(['error' => 'csrf_mismatch', 'message' => __('booking.api.csrf_mismatch')], 403);
        }

        $bookingId = $request->getAttribute('id');
        $input = $request->json();
        $reason = trim($input['reason'] ?? '');

        try {
            $booking = BookingService::cancelBooking($bookingId, $tenant, $reason);
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains($msg, 'not found')) {
                return Response::json(['error' => 'booking_not_found', 'message' => __('booking.manage.not_found')], 404);
            }
            if (str_contains($msg, 'not_confirmed')) {
                return Response::json(['error' => 'already_cancelled', 'message' => __('booking.manage.already_cancelled')], 409);
            }
            if (str_contains($msg, 'too_late')) {
                return Response::json(['error' => 'time_gate', 'message' => __('booking.manage.time_gate_cancel')], 409);
            }
            if (str_contains($msg, 'cancellation_disabled')) {
                return Response::json(['error' => 'cancellation_disabled', 'message' => __('booking.manage.cancellation_disabled')], 403);
            }
            return Response::json(['error' => 'cancel_failed', 'message' => __('booking.api.booking_failed')], 500);
        }

        // Load booking details once for both customer and staff emails
        $details = BookingService::findByIdWithDetails($bookingId, $tenant['id']);

        // Dispatch cancellation notification email to customer
        $emailSent = false;
        if (Mailer::isConfigured() && $details) {
            try {
                $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
                $startDt = new \DateTimeImmutable($details['start_datetime'], $tz);
                $endDt = new \DateTimeImmutable($details['end_datetime'], $tz);

                $emailResult = Mailer::sendCancellationConfirmation(
                    $details['customer_email'],
                    $details['customer_name'],
                    [
                        'date'           => $startDt->format('Y-m-d'),
                        'formatted_date' => Locale::dateLong($startDt),
                        'time'           => $startDt->format('H:i'),
                        'end_time'       => $endDt->format('H:i'),
                    ],
                    $details['service_name'] ?? $details['resource_name'] ?? $details['event_name'] ?? null,
                    $details['staff_name'] ?? null,
                    $tenant['name'],
                    $tenant['id'],
                    $bookingId,
                    $tenant['brand_color'] ?? '#2563EB',
                    $tenant['slug'],
                );
                $emailSent = $emailResult['sent'] && Mailer::isProductionSmtp();
            } catch (\Throwable $e) {
                Logger::error('Cancellation email dispatch failed', [
                    'booking' => $bookingId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // Staff cancellation notification (fire-and-forget)
        if (Mailer::isConfigured() && (int) ($tenant['notify_on_cancellation'] ?? 0) === 1 && $details) {
            try {
                $tz = $tz ?? new \DateTimeZone($tenant['timezone'] ?? 'UTC');
                $startDt = $startDt ?? new \DateTimeImmutable($details['start_datetime'], $tz);
                $endDt = $endDt ?? new \DateTimeImmutable($details['end_datetime'], $tz);

                $cancelServiceName = $details['service_name'] ?? $details['resource_name'] ?? $details['event_name'] ?? null;
                Mailer::sendStaffCancellationNotification(
                    [
                        'date'           => $startDt->format('Y-m-d'),
                        'formatted_date' => Locale::dateLong($startDt),
                        'time'           => $startDt->format('H:i'),
                        'end_time'       => $endDt->format('H:i'),
                    ],
                    $cancelServiceName,
                    $details['staff_name'] ?? null,
                    $details['customer_name'],
                    $tenant['name'],
                    $tenant['id'],
                    $bookingId,
                    $tenant['brand_color'] ?? '#2563EB',
                );
            } catch (\Throwable $e) {
                Logger::error('Staff cancellation notification failed', [
                    'booking' => $bookingId,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        return Response::json([
            'cancelled'  => true,
            'booking_id' => $bookingId,
            'email_sent' => $emailSent,
        ]);
    }

    /**
     * POST /api/{slug}/bookings/{id}/reschedule — reschedule a booking.
     *
     * Validates CSRF, delegates to BookingService::rescheduleBooking(),
     * dispatches reschedule confirmation and staff notification emails.
     *
     * JSON body: { "new_date": "YYYY-MM-DD", "new_time": "HH:MM" }
     */
    public function rescheduleBookingAction(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return Response::json(['error' => 'tenant_not_found'], 404);
        }

        $this->resolveLocale($tenant, $request);

        // CSRF validation (same pattern as cancelBookingAction)
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        $submittedToken = $request->header('X-CSRF-Token') ?? '';
        if ($sessionToken === '' || $submittedToken === '' || !hash_equals($sessionToken, $submittedToken)) {
            return Response::json(['error' => 'csrf_mismatch', 'message' => __('booking.api.csrf_mismatch')], 403);
        }

        $bookingId = $request->getAttribute('id');
        $input = $request->json();

        // Build pattern-aware target from request input.
        // The booking's pattern determines which keys are required.
        // For backward compatibility, the public reschedule page currently
        // sends new_date + new_time (timeslot). Other patterns send their
        // own fields.
        $target = [];
        if (isset($input['new_date'])) $target['new_date'] = trim($input['new_date'] ?? '');
        if (isset($input['new_time'])) $target['new_time'] = trim($input['new_time'] ?? '');
        if (isset($input['check_in'])) $target['check_in'] = trim($input['check_in'] ?? '');
        if (isset($input['check_out'])) $target['check_out'] = trim($input['check_out'] ?? '');
        if (isset($input['date'])) $target['date'] = trim($input['date'] ?? '');
        if (isset($input['slot_id'])) $target['slot_id'] = trim($input['slot_id'] ?? '');
        if (isset($input['event_id'])) $target['event_id'] = trim($input['event_id'] ?? '');

        // Delegate to the engine
        try {
            $result = BookingService::rescheduleBooking(
                $bookingId,
                $tenant,
                $target,
                'customer', // actor_type
                'web',      // source
            );
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage();
            return match ($msg) {
                'not_found' => Response::json([
                    'error' => 'booking_not_found',
                    'message' => __('booking.manage.not_found'),
                ], 404),
                'not_confirmed' => Response::json([
                    'error' => 'not_confirmed',
                    'message' => __('booking.manage.already_cancelled'),
                ], 409),
                'rescheduling_disabled' => Response::json([
                    'error' => 'rescheduling_disabled',
                    'message' => __('booking.manage.rescheduling_disabled'),
                ], 403),
                'too_late' => Response::json([
                    'error' => 'time_gate',
                    'message' => __('booking.manage.time_gate_reschedule'),
                ], 409),
                'same_slot' => Response::json([
                    'error' => 'same_slot',
                    'message' => __('booking.manage.same_slot'),
                ], 422),
                'slot_unavailable', 'already_booked' => Response::json([
                    'error' => $msg,
                    'message' => __('booking.api.slot_unavailable'),
                ], 409),
                'capacity_exceeded', 'event_full' => Response::json([
                    'error' => $msg,
                    'message' => __('booking.api.slot_unavailable'),
                ], 409),
                'pattern_not_supported' => Response::json([
                    'error' => 'pattern_not_supported',
                    'message' => __('booking.manage.rescheduling_disabled'),
                ], 409),
                'invalid_date' => Response::json([
                    'error' => 'invalid_date',
                    'message' => __('booking.api.invalid_date'),
                ], 422),
                'invalid_time' => Response::json([
                    'error' => 'invalid_time',
                    'message' => __('booking.api.start_time_required'),
                ], 422),
                'invalid_slot', 'invalid_event' => Response::json([
                    'error' => $msg,
                    'message' => __('booking.api.booking_failed'),
                ], 422),
                default => Response::json([
                    'error' => 'reschedule_failed',
                    'message' => __('booking.api.booking_failed'),
                ], 500),
            };
        }

        // Post-commit: send reschedule confirmation email to customer
        $oldBooking = $result['old_booking'];
        $emailSent = false;

        if (Mailer::isConfigured()) {
            try {
                $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
                $newStartDt = new \DateTimeImmutable($result['new_start'], $tz);
                $newEndDt = new \DateTimeImmutable($result['new_end'], $tz);
                $serviceName = $oldBooking['service_name'] ?? null;

                // Load service name if not in the booking row
                if (!$serviceName && !empty($oldBooking['service_id'])) {
                    $svc = Database::query(
                        'SELECT `name` FROM `services` WHERE `id` = ? LIMIT 1',
                        [$oldBooking['service_id']]
                    );
                    $serviceName = $svc[0]['name'] ?? null;
                }

                // Load customer details
                $customer = Database::query(
                    'SELECT `name`, `email` FROM `customers` WHERE `id` = ? LIMIT 1',
                    [$oldBooking['customer_id']]
                );
                $customerEmail = $customer[0]['email'] ?? '';
                $customerName = $customer[0]['name'] ?? '';

                // Load staff name if present
                $staffName = null;
                if (!empty($oldBooking['staff_id'])) {
                    $staffRow = Database::query(
                        'SELECT `name` FROM `staff` WHERE `id` = ? LIMIT 1',
                        [$oldBooking['staff_id']]
                    );
                    $staffName = $staffRow[0]['name'] ?? null;
                }

                $emailData = [
                    'date'           => $result['new_date'],
                    'formatted_date' => Locale::dateLong($newStartDt),
                    'time'           => $newStartDt->format('H:i'),
                    'end_time'       => $newEndDt->format('H:i'),
                ];

                $emailResult = Mailer::sendRescheduleConfirmation(
                    $customerEmail,
                    $customerName,
                    $emailData,
                    $serviceName,
                    $staffName,
                    $tenant['name'],
                    $tenant['id'],
                    $result['new_booking_id'],
                    $tenant['brand_color'] ?? '#2563EB',
                    $tenant['slug'],
                );
                $emailSent = ($emailResult['sent'] ?? false) && Mailer::isProductionSmtp();
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
                        $emailData, $serviceName, $staffName ?? null,
                        $customerName,
                        $tenant['name'], $tenant['id'], $result['new_booking_id'],
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

        // Build response with new booking details
        $newBookingResponse = [
            'id'       => $result['new_booking_id'],
            'date'     => $result['new_date'],
            'time'     => $result['new_time'],
            'end_time' => (new \DateTimeImmutable($result['new_end']))->format('H:i'),
        ];

        // Enrich with pattern-specific fields
        $pattern = $result['pattern'] ?? 'timeslot';
        if ($pattern === 'resource') {
            $tz = new \DateTimeZone($tenant['timezone'] ?? 'UTC');
            $newBookingResponse['check_in'] = (new \DateTimeImmutable($result['new_start'], $tz))->format('Y-m-d');
            $newBookingResponse['check_out'] = (new \DateTimeImmutable($result['new_end'], $tz))->format('Y-m-d');
        }
        if ($pattern === 'event' && !empty($target['event_id'])) {
            $event = Database::query('SELECT `name` FROM `events` WHERE `id` = ? LIMIT 1', [$target['event_id']]);
            $newBookingResponse['event_name'] = $event[0]['name'] ?? null;
        }

        return Response::json([
            'rescheduled'    => true,
            'new_booking_id' => $result['new_booking_id'],
            'email_sent'     => $emailSent,
            'new_booking'    => $newBookingResponse,
        ]);
    }

}

