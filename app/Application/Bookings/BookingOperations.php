<?php

declare(strict_types=1);

namespace App\Application\Bookings;

use App\Agent\{ActorContext, OperationDefinition, OperationException};
use App\Engine\{BookingService, Database, VenueDateTime};

final class BookingOperations
{
    /** Resolving a staff caller can fail before any booking is touched. */
    private const STAFF_ACTOR_ERRORS = ['unauthorized', 'identity_verification_required', 'forbidden', 'not_found'];

    /** BookingDraft and the pattern calculators report why a requested time cannot be booked. */
    private const DRAFT_ERRORS = [
        'validation_failed',
        'not_found',
        'staff_invalid',
        'invalid_date',
        'invalid_time',
        'invalid_party_size',
        'pattern_not_supported',
        'slot_unavailable',
        'too_soon',
        'too_far',
        'resource_not_found',
        'invalid_date_range',
        'min_stay_violation',
        'max_stay_violation',
        'invalid_check_in_day',
        'invalid_check_out_day',
        'date_blocked',
        'already_booked',
        'capacity_exceeded',
        'slot_not_found',
        'slot_not_on_day',
        'outside_booking_window',
        'party_too_small',
        'party_too_large',
        'event_not_found',
        'instance_cancelled',
        'spot_count_too_few',
        'spot_count_too_many',
        'waitlist_full',
        'event_full',
        'audit_unavailable'
    ];

    /** BookingService::rescheduleError keeps these codes and folds every other failure into reschedule_failed. */
    private const RESCHEDULE_ERRORS = [
        'validation_failed',
        'not_found',
        'not_confirmed',
        'invalid_date',
        'invalid_time',
        'invalid_slot',
        'invalid_event',
        'same_slot',
        'too_soon',
        'slot_unavailable',
        'capacity_exceeded',
        'event_full',
        'already_booked',
        'date_blocked',
        'outside_booking_window',
        'party_too_small',
        'party_too_large',
        'invalid_check_in_day',
        'invalid_check_out_day',
        'pattern_not_supported',
        'temporarily_unavailable',
        'reschedule_failed',
        'inventory_conflict',
        'audit_unavailable'
    ];

    public static function definitions(): array
    {
        $patterns = ['timeslot', 'resource', 'capacity', 'event'];
        $definitions = [];
        $id = ['type' => 'string', 'minLength' => 1, 'maxLength' => 26];
        $date = ['type' => 'string', 'format' => 'date'];
        $base = ['business_id' => $id];
        $selector = $base + ['booking_id' => $id];
        $filters = $base + [
            'sort' => ['type' => 'string', 'enum' => ['id', 'start_datetime', 'customer', 'service', 'status', 'tenant']],
            'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
            'status' => [
                'type' => 'string',
                'enum' => ['pending', 'confirmed', 'cancelled', 'completed', 'no_show', 'rescheduled', 'waitlisted']
            ],
            'from' => $date,
            'to' => $date,
            'staff_id' => $id,
            'customer_id' => $id,
            'event_id' => $id,
            'search' => ['type' => 'string', 'maxLength' => 200],
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100],
            'cursor' => ['type' => 'string', 'maxLength' => 4096]
        ];

        $booking = self::bookingSchema();
        // The customer's own booking also carries its add-to-calendar actions once confirmed.
        $customerBooking = $booking;
        $customerBooking['properties']['calendar'] = CalendarLinks::schema();
        $customerBooking['required'][] = 'calendar';
        $list = self::object(
            [
                'bookings' => ['type' => 'array', 'items' => $booking],
                'next_cursor' => ['type' => ['string', 'null']],
                'total' => ['type' => 'integer'],
                'timezone' => ['type' => ['string', 'null']]
            ],
            ['bookings', 'next_cursor', 'total', 'timezone']
        );

        // The calendar is a booking list plus the context needed to shade closed days.
        $calendar = $list;
        $calendar['properties']['blocked_dates'] = [
            'type' => 'array',
            'items' => self::object(
                [
                    'id' => ['type' => 'string'],
                    'start_date' => $date,
                    'end_date' => $date,
                    'staff_id' => ['type' => ['string', 'null']],
                    'resource_id' => ['type' => ['string', 'null']],
                    'reason' => ['type' => ['string', 'null']]
                ],
                ['id', 'start_date', 'end_date', 'staff_id', 'resource_id', 'reason']
            )
        ];
        $calendar['properties']['hours'] = [
            'type' => 'array',
            'items' => self::object(
                [
                    'day_of_week' => ['type' => 'integer'],
                    'start_time' => ['type' => 'string'],
                    'end_time' => ['type' => 'string'],
                    'is_available' => ['type' => 'boolean']
                ],
                ['day_of_week', 'start_time', 'end_time', 'is_available']
            )
        ];
        $calendar['properties']['available_weekdays'] = [
            'type' => 'array',
            'items' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 6],
        ];
        array_push($calendar['required'], 'blocked_dates', 'hours', 'available_weekdays');

        $listErrors = [...self::STAFF_ACTOR_ERRORS, 'invalid_cursor', 'invalid_date', 'validation_failed'];
        $exportErrors = [...self::STAFF_ACTOR_ERRORS, 'invalid_date', 'validation_failed'];
        $readErrors = [
            'bookings_list' => $listErrors,
            'booking_get' => self::STAFF_ACTOR_ERRORS,
            'customer_booking_get' => ['unauthorized', 'forbidden', 'not_found'],
            'calendar_get' => [...$listErrors, 'invalid_interval'],
            'bookings_export' => $exportErrors
        ];
        // Read operations: [name, title, policy, scopes, path, input properties, required, output schema, handler, capability].
        foreach ([
            [
                'bookings_list',
                __('agent-booking.titles.bookings_list'),
                'staff',
                ['bookings:read'],
                '/businesses/{business_id}/bookings',
                $filters,
                [],
                $list,
                static fn ($a, $i) => BookingQueries::listing($a, $i),
                // status=waitlisted with event_id is an event's waitlist, in arrival order with sort=id.
                ['C05', 'C19']
            ],
            [
                'booking_get',
                __('agent-booking.titles.booking_get'),
                'staff',
                ['bookings:read'],
                '/businesses/{business_id}/bookings/{booking_id}',
                $selector,
                ['booking_id'],
                $booking,
                static fn ($a, $i) => BookingQueries::get($a, $i),
                'C05'
            ],
            [
                'customer_booking_get',
                __('agent-booking.titles.customer_booking_get'),
                'customer_booking',
                ['bookings:read'],
                '/businesses/{business_id}/customer/booking',
                $base,
                [],
                $customerBooking,
                static function ($a, $i) {
                    $booking = BookingQueries::get($a, $i);
                    $booking['calendar'] = CalendarLinks::forBooking($booking, self::calendarTenant($booking['business_id']));

                    return $booking;
                },
                ['C09', 'C31']
            ],
            [
                'calendar_get',
                __('agent-booking.titles.calendar_get'),
                'staff',
                ['bookings:read'],
                '/businesses/{business_id}/calendar',
                $filters,
                ['from', 'to'],
                $calendar,
                static fn ($a, $i) => BookingQueries::calendar($a, $i),
                'C10'
            ],
            [
                'bookings_export',
                __('agent-booking.titles.bookings_export'),
                'staff',
                ['bookings:read', 'bookings:export'],
                '/businesses/{business_id}/bookings/export',
                $filters,
                [],
                self::object(
                    [
                        'filename' => ['type' => 'string'],
                        'media_type' => ['type' => 'string'],
                        'content' => ['type' => 'string'],
                        'count' => ['type' => 'integer'],
                        'truncated' => ['type' => 'boolean'],
                        'timezone' => ['type' => 'string']
                    ],
                    ['filename', 'media_type', 'content', 'count', 'truncated', 'timezone']
                ),
                static fn ($a, $i) => BookingQueries::export($a, $i),
                'C05'
            ],
        ] as [$name, $title, $policy, $scopes, $path, $properties, $required, $output, $handler, $capability]) {
            $definitions[] = new OperationDefinition(
                $name,
                $title,
                __('agent-booking.descriptions.' . $name),
                self::object($properties, $required),
                $output,
                $scopes,
                $policy,
                true,
                false,
                'none',
                $patterns,
                'booking-tools',
                (array) $capability,
                ['A01', 'A02', 'A40', 'A41'],
                'GET',
                $path,
                $handler,
                errorCodes: $readErrors[$name]
            );
        }

        // Operator variants span every business, so business_id is not an input.
        $operatorFilters = $filters;
        unset($operatorFilters['business_id']);
        $definitions[] = new OperationDefinition(
            'operator_bookings_list',
            __('agent-booking.titles.operator_bookings_list'),
            __('agent-booking.descriptions.operator_bookings_list'),
            self::object($operatorFilters),
            $list,
            ['bookings:read'],
            'operator',
            true,
            false,
            'none',
            $patterns,
            'booking-tools',
            ['C05'],
            ['A02', 'A40', 'A41'],
            'GET',
            '/operator/bookings',
            BookingQueries::operatorListing(...),
            errorCodes: $listErrors,
            allowArchivedBusiness: true
        );
        // Cross-business exports have no single timezone, so it may be null here.
        $operatorExport = self::object(
            [
                'filename' => ['type' => 'string'],
                'media_type' => ['type' => 'string'],
                'content' => ['type' => 'string'],
                'count' => ['type' => 'integer'],
                'truncated' => ['type' => 'boolean'],
                'timezone' => ['type' => ['string', 'null']]
            ],
            ['filename', 'media_type', 'content', 'count', 'truncated', 'timezone']
        );
        $definitions[] = new OperationDefinition(
            'operator_bookings_export',
            __('agent-booking.titles.operator_bookings_export'),
            __('agent-booking.descriptions.operator_bookings_export'),
            self::object($operatorFilters),
            $operatorExport,
            ['bookings:read', 'bookings:export'],
            'operator',
            true,
            false,
            'none',
            $patterns,
            'booking-tools',
            ['C05'],
            ['A02', 'A40', 'A41'],
            'GET',
            '/operator/bookings/export',
            static fn ($a, $i) => BookingQueries::export($a, $i, true),
            errorCodes: $exportErrors,
            allowArchivedBusiness: true
        );

        $create = self::createSchema();
        foreach ([
            [
                'booking_create',
                __('agent-booking.titles.booking_create'),
                'staff',
                ['bookings:write'],
                '/businesses/{business_id}/bookings',
                'C06'
            ],
            [
                'public_booking_create',
                __('agent-booking.titles.public_booking_create'),
                'public',
                ['public:book'],
                '/public/{slug}/bookings',
                'C31'
            ]
        ] as [$name, $title, $policy, $scopes, $path, $capability]) {
            $public = $policy === 'public';
            $definitions[] = new OperationDefinition(
                $name,
                $title,
                __('agent-booking.descriptions.' . $name),
                $create,
                self::creationSchema(),
                $scopes,
                $policy,
                false,
                false,
                'trusted',
                $patterns,
                'book-an-appointment',
                [$capability, 'C32', 'C19'],
                ['A11', 'A13', 'A14', 'A16', 'A20', 'A22', 'A23', 'A24', 'A27'],
                'POST',
                $path,
                static function ($a, $i) use ($public) {
                    $a = BookingCommands::currentActor($a);
                    // The public endpoint is for customers; staff book through booking_create.
                    if ($public && $a->isStaff()) {
                        throw new OperationException('forbidden', 403);
                    }

                    $receipt = BookingCommands::create($a, $i);
                    if ($public) {
                        // The customer's own result offers the booking page's add-to-calendar actions.
                        $receipt['calendar'] = CalendarLinks::forCreation($receipt, self::calendarTenant($receipt['business_id']));
                    }

                    return $receipt;
                },
                sideEffects: ['booking.notification'],
                errorCodes: array_values(array_unique([
                    ...self::STAFF_ACTOR_ERRORS,
                    ...self::DRAFT_ERRORS,
                    ...($public ? ['consent_required', 'max_bookings_exceeded'] : [])
                ])),
                demoAllowed: true,
                previewHandler: static fn ($a, $i) => self::previewCreate($a, $i)
            );
        }

        $target = self::object([
            'new_date' => $date,
            'new_time' => ['type' => 'string', 'pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9]$'],
            'check_in' => $date,
            'check_out' => $date,
            'date' => $date,
            'slot_id' => $id,
            'event_id' => $id
        ]);
        foreach ([
            [
                'booking_reschedule',
                __('agent-booking.titles.booking_reschedule'),
                'staff',
                ['bookings:write'],
                '/businesses/{business_id}/bookings/{booking_id}/reschedule'
            ],
            [
                'customer_booking_reschedule',
                __('agent-booking.titles.customer_booking_reschedule'),
                'customer_booking',
                ['bookings:reschedule'],
                '/businesses/{business_id}/customer/booking/reschedule'
            ]
        ] as [$name, $title, $policy, $scopes, $path]) {
            // Customers act on the booking their capability names, so only staff pass booking_id.
            $properties = ($policy === 'staff' ? $selector : $base) + ['target' => $target];
            $required = $policy === 'staff' ? ['booking_id', 'target'] : ['target'];
            $definitions[] = new OperationDefinition(
                $name,
                $title,
                __('agent-booking.descriptions.' . $name),
                self::object($properties, $required),
                $booking,
                $scopes,
                $policy,
                false,
                false,
                'trusted',
                $patterns,
                'manage-your-booking',
                ['C08'],
                ['A11', 'A16', 'A17', 'A18'],
                'POST',
                $path,
                static function ($a, $i) {
                    $a = BookingCommands::currentActor($a);
                    $i['booking_id'] ??= $a->bookingId;
                    $tenant = BookingQueries::tenant($a, $i, $a->isStaff() ? 'bookings:write' : 'bookings:reschedule');
                    $result = BookingCommands::reschedule($a, $i);

                    // Return the replacement booking; the original stays behind with status rescheduled.
                    return BookingQueries::project(
                        BookingService::findByIdWithDetails($result['new_booking_id'], $tenant['id']),
                        $tenant,
                        $a
                    );
                },
                sideEffects: ['booking.notification'],
                // Customers also meet their business's self-service limits and the event lock.
                errorCodes: array_values(array_unique($policy === 'staff'
                    ? [...self::STAFF_ACTOR_ERRORS, ...self::RESCHEDULE_ERRORS]
                    : ['unauthorized', 'forbidden', ...self::RESCHEDULE_ERRORS, 'rescheduling_disabled', 'too_late'])),
                demoAllowed: true,
                previewHandler: static fn ($a, $i) => self::previewReschedule($a, $i)
            );
        }

        $definitions[] = new OperationDefinition(
            'customer_booking_cancel',
            __('agent-booking.titles.customer_booking_cancel'),
            __('agent-booking.descriptions.customer_booking_cancel'),
            self::object($base + ['reason' => ['type' => 'string', 'maxLength' => 255]]),
            $booking,
            ['bookings:cancel'],
            'customer_booking',
            false,
            true,
            'trusted',
            $patterns,
            'manage-your-booking',
            ['C09'],
            ['A08', 'A19', 'A20'],
            'POST',
            '/businesses/{business_id}/customer/booking/cancel',
            static function ($a, $i) {
                $a = BookingCommands::currentActor($a);
                $i['booking_id'] = $a->bookingId;
                BookingCommands::cancel($a, $i);
                $tenant = BookingQueries::tenant($a, $i, 'bookings:cancel');

                return BookingQueries::project(BookingService::findByIdWithDetails($i['booking_id'], $tenant['id']), $tenant, $a);
            },
            sideEffects: ['booking.notification'],
            errorCodes: [
                'unauthorized',
                'forbidden',
                'not_found',
                'validation_failed',
                'not_confirmed',
                'cancellation_disabled',
                'too_late',
                'invalid_transition',
                'cancellation_unavailable',
                'audit_unavailable'
            ],
            demoAllowed: true,
            previewHandler: static fn ($a, $i) => self::previewStatus($a, $i + ['booking_id' => $a->bookingId, 'status' => 'cancelled']),
            // Cancelling a cancelled booking returns it unchanged and notifies nobody again.
            idempotencyPolicy: 'natural'
        );
        $definitions[] = new OperationDefinition(
            'booking_status_set',
            __('agent-booking.titles.booking_status_set'),
            __('agent-booking.descriptions.booking_status_set'),
            self::object(
                $selector + [
                    'status' => ['type' => 'string', 'enum' => ['confirmed', 'cancelled', 'completed', 'no_show']],
                    'reason' => ['type' => 'string', 'maxLength' => 255]
                ],
                ['booking_id', 'status']
            ),
            $booking,
            ['bookings:write'],
            'staff',
            false,
            true,
            'trusted',
            $patterns,
            'booking-tools',
            ['C07', 'C19'],
            ['A11', 'A12', 'A19', 'A20'],
            'POST',
            '/businesses/{business_id}/bookings/{booking_id}/status',
            static function ($a, $i) {
                $a = BookingCommands::currentActor($a);
                BookingCommands::changeStatus($a, $i);
                $tenant = BookingQueries::tenant($a, $i, 'bookings:write');

                return BookingQueries::project(BookingService::findByIdWithDetails($i['booking_id'], $tenant['id']), $tenant, $a);
            },
            sideEffects: ['booking.notification'],
            errorCodes: [
                ...self::STAFF_ACTOR_ERRORS,
                'validation_failed',
                'invalid_transition',
                'inventory_conflict',
                'pattern_not_supported',
                'audit_unavailable'
            ],
            demoAllowed: true,
            previewHandler: static fn ($a, $i) => self::previewStatus($a, $i),
            // Setting the status a booking already has returns it unchanged.
            idempotencyPolicy: 'natural'
        );

        return $definitions;
    }

    public static function previewCreate(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        $tenant = BookingQueries::tenant($actor, $input, $actor->isStaff() ? 'bookings:write' : 'public:book');
        $draft = BookingDraft::prepare($tenant, $input);
        // Validate answers now so the preview fails the same way the real create would.
        BookingFields::answers($tenant, $input['custom_fields'] ?? null, requireAnswers: !$actor->isStaff());
        $status = ($draft['data']['status'] ?? 'confirmed') === 'waitlisted'
            ? 'waitlisted'
            : (!$actor->isStaff() && $tenant['booking_requires_approval'] ? 'pending' : 'confirmed');

        return [
            'booking' => $draft['display'],
            'status' => $status,
            'timezone' => $tenant['timezone'],
            'currency' => $tenant['currency'],
            'customer' => $input['customer'] ?? [],
            'consent_text' => BookingService::resolveConsentText($tenant),
            'requires_consent' => (bool) $tenant['requires_consent'],
            'cancellation_policy' => $tenant['cancellation_policy'],
            'requires_approval' => (bool) $tenant['booking_requires_approval'],
            ...self::selfServiceReview($tenant, array_replace($draft['data'], ['status' => $status])),
            ...VenueDateTime::presentRange(
                $tenant['booking_pattern'],
                $draft['data']['start_datetime'],
                $draft['data']['end_datetime'],
                $tenant['timezone']
            )
        ];
    }

    /** Hash and display the effective policy, even when its free-form text is unchanged. */
    private static function selfServiceReview(array $tenant, array $booking): array
    {
        $start = new \DateTimeImmutable($booking['start_datetime'], new \DateTimeZone($tenant['timezone']));
        $review = [];
        foreach ([
            'cancellation' => BookingService::canCancel($booking, $tenant),
            'rescheduling' => BookingService::canReschedule($booking, $tenant)
        ] as $action => $permission) {
            $hours = (int) $tenant[$action . '_hours_before'];
            // Eligibility uses elapsed hours. Preserve that same meaning across DST.
            $deadline = $start->setTimestamp($start->getTimestamp() - $hours * 3600);
            $review['customer_' . $action] = [
                'enabled' => (bool) $tenant['allow_' . $action],
                'eligible' => $permission['allowed'],
                'notice_hours' => $hours,
                'deadline' => $deadline->format('Y-m-d H:i:s P')
            ];
        }

        return $review;
    }

    public static function previewStatus(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        $scope = $actor->isStaff() ? 'bookings:write' : 'bookings:cancel';
        $tenant = BookingQueries::tenant($actor, $input, $scope);
        $booking = BookingCommands::booking($actor, $tenant, $input['booking_id'], scope: $scope);
        $status = $input['status'];

        // Mirror the command's guards so a preview never promises a transition the write would refuse.
        if (
            $status !== $booking['status']
            && !in_array($status, BookingCommands::transitions($booking['status']), true)
        ) {
            throw new OperationException('invalid_transition', 409);
        }
        if (
            !$actor->isStaff()
            && $status === 'cancelled'
            && $booking['status'] !== 'cancelled'
            && !BookingService::canCancel($booking, $tenant)['allowed']
        ) {
            throw new OperationException('cancellation_unavailable', 409);
        }
        if ($status === 'confirmed') {
            BookingInventory::assertAvailable($tenant, $booking);
        }

        return [
            'booking_id' => $booking['id'],
            'from_status' => $booking['status'],
            'to_status' => $status,
            'updated_at' => $booking['updated_at'],
            'cancellation_policy' => $tenant['cancellation_policy'],
            'timezone' => $tenant['timezone'],
            'start_datetime' => $booking['start_datetime']
        ];
    }

    public static function previewReschedule(ActorContext $actor, array $input): array
    {
        $actor = BookingCommands::currentActor($actor);
        $scope = $actor->isStaff() ? 'bookings:write' : 'bookings:reschedule';
        $tenant = BookingQueries::tenant($actor, $input, $scope);
        $booking = BookingCommands::booking($actor, $tenant, $input['booking_id'] ?? $actor->bookingId ?? '', scope: $scope);
        $review = BookingService::previewReschedule($booking, $tenant, $input['target'], $actor->isStaff());

        // Policy deadlines are judged against the new time, since that is what the customer will hold.
        return $review + self::selfServiceReview(
            $tenant,
            array_replace($booking, ['start_datetime' => $review['new_start'], 'end_datetime' => $review['new_end']])
        );
    }

    /** The tenant fields calendar actions need: its name for the title fallback, slug for the UID and timezone. */
    private static function calendarTenant(string $businessId): array
    {
        return Database::query('SELECT name, slug, timezone FROM tenants WHERE id = ?', [$businessId])[0]
            ?? throw new OperationException('not_found', 404);
    }

    public static function object(array $properties, array $required = []): array
    {
        return ['type' => 'object', 'additionalProperties' => false, 'properties' => $properties, 'required' => $required];
    }

    public static function createSchema(): array
    {
        $id = ['type' => 'string', 'maxLength' => 26];
        $date = ['type' => 'string', 'format' => 'date'];

        return self::object(
            [
                'business_id' => $id,
                'service_id' => $id,
                'staff_id' => $id,
                'resource_id' => $id,
                'slot_id' => $id,
                'event_id' => $id,
                'date' => $date,
                'time' => ['type' => 'string', 'pattern' => '^([01][0-9]|2[0-3]):[0-5][0-9]$'],
                'check_in' => $date,
                'check_out' => $date,
                'party_size' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
                'guest_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
                'spot_count' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 10000],
                'customer' => self::object(
                    [
                        'name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255],
                        'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255],
                        'phone' => ['type' => 'string', 'maxLength' => 50]
                    ],
                    ['name', 'email']
                ),
                'notes' => ['type' => 'string', 'maxLength' => 10000],
                'customer_timezone' => ['type' => 'string', 'maxLength' => 100],
                'custom_fields' => ['type' => ['object', 'null']]
            ],
            ['customer']
        );
    }

    public static function creationSchema(): array
    {
        $properties = [];
        foreach ([
            'id', 'business_id', 'booking_pattern', 'status', 'timezone', 'notification_state', 'date', 'time', 'end_time',
            'start_utc', 'end_utc', 'manage_url', 'service', 'resource', 'check_in', 'check_out', 'event_name'
        ] as $field) {
            $properties[$field] = ['type' => 'string'];
        }
        foreach (['staff', 'total', 'label'] as $field) {
            $properties[$field] = ['type' => ['string', 'null']];
        }
        foreach (['duration', 'nights', 'guest_count', 'party_size', 'spot_count'] as $field) {
            $properties[$field] = ['type' => 'integer'];
        }
        foreach (['consent_recorded', 'email_sent', 'waitlisted'] as $field) {
            $properties[$field] = ['type' => 'boolean'];
        }
        // Customer results only; null until the booking is confirmed.
        $properties['calendar'] = CalendarLinks::schema();

        return self::periodRequired(
            self::object(
                $properties,
                [
                    'id',
                    'business_id',
                    'booking_pattern',
                    'status',
                    'timezone',
                    'notification_state',
                    'date',
                    'consent_recorded',
                    'email_sent',
                ]
            ),
            ['check_in', 'check_out', 'nights'],
            ['time', 'end_time', 'start_utc', 'end_utc']
        );
    }

    /**
     * A stay is published as local dates and a timed booking as UTC instants (see VenueDateTime).
     * The contract requires whichever pair the booking's pattern publishes.
     */
    private static function periodRequired(array $schema, array $stayFields, array $timedFields): array
    {
        $schema['if'] = ['properties' => ['booking_pattern' => ['const' => 'resource']], 'required' => ['booking_pattern']];
        $schema['then'] = ['required' => $stayFields];
        $schema['else'] = ['required' => $timedFields];

        return $schema;
    }

    public static function bookingSchema(): array
    {
        $properties = [];
        foreach ([
            'id', 'business_id', 'business_name', 'business_slug', 'created_at', 'updated_at', 'booking_pattern', 'status',
            'start_datetime', 'end_datetime', 'timezone', 'start_utc', 'end_utc'
        ] as $field) {
            $properties[$field] = ['type' => 'string'];
        }
        $properties['check_in'] = ['type' => 'string', 'format' => 'date'];
        $properties['check_out'] = ['type' => 'string', 'format' => 'date'];
        $properties['nights'] = ['type' => 'integer'];
        foreach ([
            'service_name', 'service_color', 'staff_name', 'resource_name', 'event_name', 'price_total', 'price_currency',
            'service_id', 'staff_id', 'resource_id', 'event_id', 'notes', 'customer_timezone', 'rescheduled_to_id',
            'customer_id', 'internal_notes', 'source'
        ] as $field) {
            $properties[$field] = ['type' => ['string', 'null']];
        }
        $properties['party_size'] = ['type' => 'integer'];
        $properties['customer'] = self::object(
            ['name' => ['type' => ['string', 'null']], 'email' => ['type' => ['string', 'null']]],
            ['name', 'email']
        );
        $properties['custom_fields'] = ['type' => ['object', 'array', 'null']];
        foreach (['cancellation', 'rescheduling'] as $field) {
            $properties[$field] = self::object(
                ['allowed' => ['type' => 'boolean'], 'reason' => ['type' => ['string', 'null']]],
                ['allowed', 'reason']
            );
        }
        $properties['allowed_statuses'] = ['type' => 'array', 'items' => ['type' => 'string']];

        return self::periodRequired(
            self::object(
                $properties,
                [
                    'id', 'business_id', 'booking_pattern', 'status', 'start_datetime', 'end_datetime', 'timezone', 'party_size',
                    'customer', 'cancellation', 'rescheduling', 'allowed_statuses'
                ]
            ),
            ['check_in', 'check_out', 'nights'],
            ['start_utc', 'end_utc']
        );
    }
}
