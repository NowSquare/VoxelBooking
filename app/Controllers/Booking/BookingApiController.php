<?php

declare(strict_types=1);

namespace App\Controllers\Booking;

use App\Engine\CustomerService;
use App\Engine\CustomerCapability;
use App\Application\Bookings\BookingCommands;
use App\Application\Bookings\BookingNotifications;
use App\Application\Availability\AvailabilityService;
use App\Agent\ActorContext;
use App\Agent\OperationException;
use App\Engine\Database;
use App\Engine\Locale;
use App\Engine\BookingService;
use App\Engine\Ulid;
use App\Engine\AuditLog;
use App\Engine\Logger;
use App\Engine\Mailer;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\VenueDateTime;
use App\Middleware\CsrfMiddleware;

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

    /** Adapt the existing browser response shape around the shared application reads. */
    private function read(Request $request,callable $handler,?string $pattern=null):Response
    {
        $tenant=$this->resolveTenant($request->getAttribute('slug'));
        if($tenant===null)return Response::json(['error'=>'tenant_not_found'],404);
        if($pattern!==null && $tenant['booking_pattern']!==$pattern)return Response::json(['error'=>'pattern_not_supported'],422);
        $this->resolveLocale($tenant,$request);
        $input=array_filter($request->all(),static fn($value)=>$value!=='');
        $input['business_id']=$tenant['id'];
        foreach(['party_size','guest_count','spot_count','limit'] as $key)if(isset($input[$key]))$input[$key]=(int)$input[$key];
        $actor=AvailabilityService::publicActor($tenant);
        if(!empty($input['reschedule_booking_id'])){
            $actor=CustomerCapability::actor($request,CustomerCapability::MANAGE,$tenant['id'],$input['reschedule_booking_id']);
            if($actor===null)return CustomerCapability::protect(Response::json(['error'=>'verification_required'],403));
        }
        try{$response=Response::json($handler($actor,$input));}
        catch(OperationException $error){$response=Response::json(['error'=>$error->problemCode],$error->status);}
        return $actor->role==='verified_customer'?CustomerCapability::protect($response):$response;
    }



    /**
     * GET /api/{slug}/services — list active services.
     */
    public function services(Request $request): Response
    {
        return $this->read($request, static function($actor,$input){
            $input['kind']='services';$input['limit']=100;$items=[];
            do{$page=AvailabilityService::catalog($actor,$input);$items=[...$items,...$page['items']];$input['cursor']=$page['next_cursor'];}while($input['cursor']!==null);
            foreach($items as &$item)foreach(['cover_image_path','avatar_path'] as $key)if(!empty($item[$key]))$item[$key]='/'.ltrim($item[$key],'/');unset($item);
            return ['services'=>$items];
        });
    }

    /**
     * GET /api/{slug}/staff — list active staff.
     */
    public function staff(Request $request): Response
    {
        return $this->read($request, static function($actor,$input){
            $input['kind']='staff';$input['limit']=100;$items=[];
            do{$page=AvailabilityService::catalog($actor,$input);$items=[...$items,...$page['items']];$input['cursor']=$page['next_cursor'];}while($input['cursor']!==null);
            foreach($items as &$item)foreach(['cover_image_path','avatar_path'] as $key)if(!empty($item[$key]))$item[$key]='/'.ltrim($item[$key],'/');unset($item);
            return ['staff'=>$items];
        });
    }

    /**
     * GET /api/{slug}/availability — available slots for a date.
     */
    public function availability(Request $request): Response
    {
        return $this->read($request, static function($actor,$input){

            $result=AvailabilityService::search($actor,$input);
            return ['slots'=>$result['choices'],'date'=>$input['date']??null,'timezone'=>$result['timezone']];
        },'timeslot');
    }

    /**
     * GET /api/{slug}/available-dates — dates with slots in a month.
     */
    public function availableDates(Request $request): Response
    {
        return $this->read($request, static function($actor,$input){
            $input['month']=sprintf('%04d-%02d',(int)($input['year']??gmdate('Y')),(int)($input['month']??gmdate('n')));unset($input['year']);
            $result=AvailabilityService::search($actor,$input);
            return ['dates'=>$result['dates'],'year'=>(int)substr($input['month'],0,4),'month'=>(int)substr($input['month'],5,2)];
        },'timeslot');
    }

    // ── Resource-pattern endpoints ──

    /**
     * GET /api/{slug}/resources — list active resources.
     */
    public function resources(Request $request): Response
    {
        return $this->read($request, static function($actor,$input){
            $input['kind']='resources';$input['limit']=100;$items=[];
            do{$page=AvailabilityService::catalog($actor,$input);$items=[...$items,...$page['items']];$input['cursor']=$page['next_cursor'];}while($input['cursor']!==null);
            foreach($items as &$item)foreach(['cover_image_path','avatar_path'] as $key)if(!empty($item[$key]))$item[$key]='/'.ltrim($item[$key],'/');unset($item);
            return ['resources'=>$items];
        });
    }

    /**
     * GET /api/{slug}/resources/{id}/availability — available dates for a resource.
     */
    public function resourceAvailability(Request $request): Response
    {
        return $this->read($request, static function($actor,$input)use($request){
            $input['resource_id']=$request->getAttribute('id');
            if(isset($input['check_in'],$input['check_out'])){
                $input['guest_count']=(int)($input['guests']??$input['guest_count']??1);unset($input['guests']);
                $result=AvailabilityService::search($actor,$input);return $result['quote'];
            }
            $input['month']=sprintf('%04d-%02d',(int)($input['year']??gmdate('Y')),(int)($input['month']??gmdate('n')));unset($input['year']);
            $result=AvailabilityService::search($actor,$input);
            return ['dates'=>$result['dates'],'year'=>(int)substr($input['month'],0,4),'month'=>(int)substr($input['month'],5,2)]+$result['date_rules'];
        },'resource');
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

        // CSRF: the middleware skips /api/ routes, so the booking API asks
        // itself. Session token for the standalone page, same-origin
        // attestation for the cookie-free embed iframe.
        if (!CsrfMiddleware::verifyPublicSubmission($request)) {
            return Response::json(['error' => 'csrf_mismatch', 'message' => __('booking.api.csrf_mismatch')], 403);
        }

        try {
            $principalId = Ulid::generate();
            if (session_status() === PHP_SESSION_ACTIVE) {
                if (!is_string($_SESSION['public_actor_id'] ?? null) || !preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/D', $_SESSION['public_actor_id'])) $_SESSION['public_actor_id'] = $principalId;
                $principalId = $_SESSION['public_actor_id'];
            }
            $actor = new ActorContext('anonymous', $principalId, 'prospective_customer', [$tenant['id']], ['public:read','public:book'], authMode: 'public');
            $booking = BookingCommands::create($actor, array_replace($input, ['business_id' => $tenant['id']]));
            $booking = array_replace($booking, BookingNotifications::deliverQueued());

            return CustomerCapability::protect(Response::json(['booking' => $booking], 201));
        } catch (OperationException $error) {
            $problem = BookingProblemResponse::forCreate($error, $tenant, $input);
            if ($problem['body']['error'] === 'slot_unavailable' && $tenant['booking_pattern'] === 'timeslot') {
                $problem['body']['alternatives'] = $this->refreshedTimes($actor, $tenant, $input);
            }

            return CustomerCapability::protect(Response::json($problem['body'], $problem['status']));
        } catch (\Throwable) {
            Logger::error('Booking creation failed', ['tenant_id' => $tenant['id']]);
            return CustomerCapability::protect(Response::json(['error' => 'booking_failed', 'message' => __('booking.api.booking_failed')], 500));
        }
    }

    /**
     * Up to three other times on the same day, so a customer who lost a slot
     * can pick again without leaving the review step. A failed lookup returns
     * no times; the conflict message still explains what happened.
     *
     * @return list<array<string, mixed>>
     */
    private function refreshedTimes(ActorContext $actor, array $tenant, array $input): array
    {
        try {
            $date = $input['date'] ?? substr(str_replace('T', ' ', (string) ($input['start_datetime'] ?? '')), 0, 10);
            $search = AvailabilityService::search($actor, array_replace($input, ['business_id' => $tenant['id'], 'date' => $date]));

            return array_slice($search['choices'], 0, 3);
        } catch (\Throwable) {
            return [];
        }
    }

    // ── Capacity-pattern read endpoints ──

    /**
     * GET /api/{slug}/capacity/available-dates — dates with capacity remaining.
     */
    public function capacityAvailableDates(Request $request): Response
    {
        return $this->read($request, static function($actor,$input){
            $input['month']=sprintf('%04d-%02d',(int)($input['year']??gmdate('Y')),(int)($input['month']??gmdate('n')));unset($input['year']);
            $result=AvailabilityService::search($actor,$input);
            return ['dates'=>$result['dates'],'year'=>(int)substr($input['month'],0,4),'month'=>(int)substr($input['month'],5,2)];
        },'capacity');
    }

    /**
     * GET /api/{slug}/capacity/slots — available time slots for a date.
     */
    public function capacitySlots(Request $request): Response
    {
        return $this->read($request, static function($actor,$input){

            $result=AvailabilityService::search($actor,$input);
            return ['slots'=>$result['choices'],'date'=>$input['date']??null,'timezone'=>$result['timezone']];
        },'capacity');
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
        return $this->read($request, static function($actor,$input){
            $input['limit']=100;$events=[];
            do{$page=AvailabilityService::eventOccurrences($actor,$input);$events=[...$events,...$page['events']];$input['cursor']=$page['next_cursor'];}while($input['cursor']!==null);
            return ['events'=>$events];
        },'event');
    }

    /**
     * GET /api/{slug}/events/{id}
     *
     * Returns a single event detail with availability info.
     * For recurring events, pass ?date=YYYY-MM-DD to select the instance.
     */
    public function eventDetail(Request $request): Response
    {
        return $this->read($request,static fn($actor,$input)=>AvailabilityService::eventDetail($actor,array_replace($input,['event_id'=>$request->getAttribute('id')])),'event');
    }

    // ── Self-service booking management ──

    /**
     * GET /api/{slug}/bookings/{id} — booking detail for the manage page.
     *
     * Requires a separate booking.manage capability, never an identifier alone.
     */
    public function bookingDetail(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $tenant = $this->resolveTenant($slug);
        if (!$tenant) {
            return CustomerCapability::protect(Response::json(['error' => 'tenant_not_found'], 404));
        }

        $this->resolveLocale($tenant, $request);

        $bookingId = $request->getAttribute('id');
        if (!CustomerCapability::requestAuthorized($request, CustomerCapability::MANAGE, $tenant['id'], $bookingId)) {
            return CustomerCapability::protect(Response::json(['error' => 'verification_required', 'message' => __('booking.access.expired')], 403));
        }
        $booking = BookingService::findByIdWithDetails($bookingId, $tenant['id']);

        if (!$booking) {
            return CustomerCapability::protect(Response::json(['error' => 'booking_not_found', 'message' => __('booking.manage.not_found')], 404));
        }

        // Existing bookings retain their service's time-display mode even when
        // the service no longer appears in the active booking catalog.
        $isVirtual = false;
        if (!empty($booking['service_id'])) {
            $service = Database::query(
                'SELECT `is_virtual` FROM `services` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1',
                [$booking['service_id'], $tenant['id']]
            );
            $isVirtual = (bool) ($service[0]['is_virtual'] ?? false);
        }

        // The related ID is metadata. Its management page still requires its own grant.
        $replacementId = null;
        $linkedId = $booking['rescheduled_to_id'] ?? null;
        if (is_string($linkedId) && $linkedId !== '' && $linkedId !== $booking['id'] && !empty($booking['customer_id'])) {
            $replacement = Database::query(
                'SELECT `id` FROM `bookings` WHERE `id` = ? AND `tenant_id` = ? AND `customer_id` = ? LIMIT 1',
                [$linkedId, $tenant['id'], $booking['customer_id']]
            );
            $replacementId = $replacement[0]['id'] ?? null;
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

        return CustomerCapability::protect(Response::json([
            'booking' => [
                'id'              => $booking['id'],
                'status'          => $booking['status'],
                'booking_pattern' => $booking['booking_pattern'],
                'date'            => $startDt->format('Y-m-d'),
                'time'            => $startDt->format('H:i'),
                'end_time'        => $endDt->format('H:i'),
                'start_datetime'  => $booking['start_datetime'],
                'end_datetime'    => $booking['end_datetime'],
                // A stay publishes local dates (check-in, check-out, nights); timed bookings publish UTC instants.
                ...VenueDateTime::presentRange($pattern, $booking['start_datetime'], $booking['end_datetime'], $tenant['timezone'] ?? 'UTC'),
                'party_size'      => (int) $booking['party_size'],
                'service_id'      => $booking['service_id'] ?? null,
                'is_virtual'      => $isVirtual,
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
                'customer_timezone' => $booking['customer_timezone'] ?? null,
                'rescheduled_to_id' => $replacementId,
                'cancelled_at'    => $booking['cancelled_at'] ?? null,
                'cancellation_reason' => $booking['cancellation_reason'] ?? null,
                'check_in'        => $checkIn,
                'check_out'       => $checkOut,
            ],
            'can_cancel'     => $canCancel['allowed'],
            'cancel_reason'  => $canCancel['reason'],
            'can_reschedule' => $canReschedule['allowed'],
            'reschedule_reason' => $canReschedule['reason'],
        ]));
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

        // Browser session grants require CSRF; explicit capability headers are nonambient.
        if ($request->header('X-Customer-Capability') === null) \App\Engine\Auth::startSession();
        if ($request->header('X-Customer-Capability') === null && !CsrfMiddleware::tokenMatches($request)) {
            return Response::json(['error' => 'csrf_mismatch', 'message' => __('booking.api.csrf_mismatch')], 403);
        }

        $bookingId = (string) $request->getAttribute('id');
        $actor = CustomerCapability::actor($request, CustomerCapability::MANAGE, $tenant['id'], $bookingId);
        if ($actor === null) return CustomerCapability::protect(Response::json(['error' => 'verification_required', 'message' => __('booking.access.expired')], 403));
        try {
            BookingCommands::cancel($actor, array_replace($request->json(), ['business_id' => $tenant['id'], 'booking_id' => $bookingId]));

            return CustomerCapability::protect(Response::json(
                ['cancelled' => true, 'booking_id' => $bookingId] + BookingNotifications::deliverQueued()
            ));
        } catch (OperationException $error) {
            $problem = BookingProblemResponse::forManage($error, 'cancel');

            return CustomerCapability::protect(Response::json($problem['body'], $problem['status']));
        }
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

        // Browser session grants require CSRF; explicit capability headers are nonambient.
        if ($request->header('X-Customer-Capability') === null) \App\Engine\Auth::startSession();
        if ($request->header('X-Customer-Capability') === null && !CsrfMiddleware::tokenMatches($request)) {
            return Response::json(['error' => 'csrf_mismatch', 'message' => __('booking.api.csrf_mismatch')], 403);
        }

        $bookingId = (string) $request->getAttribute('id');
        $actor = CustomerCapability::actor($request, CustomerCapability::MANAGE, $tenant['id'], $bookingId);
        if ($actor === null) return CustomerCapability::protect(Response::json(['error' => 'verification_required', 'message' => __('booking.access.expired')], 403));
        try {
            $result = BookingCommands::reschedule($actor, array_replace($request->json(), ['business_id' => $tenant['id'], 'booking_id' => $bookingId]));
            $replacement = [
                'id' => $result['new_booking_id'],
                'manage_url' => $result['manage_url'],
                'date' => $result['new_date'],
                'time' => $result['new_time'],
                'end_time' => substr($result['new_end'], 11, 5),
                // A moved stay is new local dates; a moved timed booking is new UTC instants.
                ...VenueDateTime::presentRange($result['pattern'], $result['new_start'], $result['new_end'], $tenant['timezone'])
            ];
            if ($result['pattern'] === 'event') $replacement['event_name'] = BookingService::findByIdWithDetails($result['new_booking_id'], $tenant['id'])['event_name'];
            return CustomerCapability::protect(Response::json(
                ['rescheduled' => true, 'new_booking_id' => $result['new_booking_id'], 'new_booking' => $replacement]
                    + BookingNotifications::deliverQueued()
            ));
        } catch (OperationException $error) {
            $problem = BookingProblemResponse::forManage($error, 'reschedule');

            return CustomerCapability::protect(Response::json($problem['body'], $problem['status']));
        }
    }

}

