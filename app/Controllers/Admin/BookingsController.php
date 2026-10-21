<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\AuditLog;
use App\Engine\BookingService;
use App\Engine\CustomerService;
use App\Engine\Database;
use App\Application\Bookings\{BookingCommands,BookingQueries};
use App\Agent\{Operations,SessionActor};
use App\Agent\OperationException;
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

        $result=BookingQueries::browserPage(SessionActor::resolve(Operations::registry()),compact('status','from','to','search','sort','direction','page'),true);
        $bookings=$result['bookings'];$total=$result['total'];

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

        $booking = $this->bookingDetail((string)$request->getAttribute('id'),$request->getAttribute('tenant_id'));
        if ($booking === null) {
            return Response::redirect('/admin/bookings');
        }

        $timeline = AuditLog::forEntity('booking', $booking['id']);

        // Reverse lookup: find the original booking that was rescheduled into this one
        $rescheduledFrom = Database::query(
            "SELECT `id` FROM `bookings` WHERE `rescheduled_to_id` = ? AND tenant_id=? LIMIT 1",
            [$booking['id'],$booking['tenant_id']]
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

        $result=BookingQueries::browserPage(SessionActor::resolve(Operations::registry()),compact('status','from','to','sort','direction','page')+['business_id'=>$tenantId]);
        $bookings=$result['bookings'];$total=$result['total'];

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
        $booking = $this->bookingDetail((string)$request->getAttribute('id'),$request->getAttribute('tenant_id'));

        if ($booking === null || $booking['tenant_id'] !== $tenantId) {
            return Response::redirect("/admin/tenants/{$tenantId}/bookings");
        }

        $timeline = AuditLog::forEntity('booking', $booking['id']);

        // Reverse lookup: find the original booking that was rescheduled into this one
        $rescheduledFrom = Database::query(
            "SELECT `id` FROM `bookings` WHERE `rescheduled_to_id` = ? AND tenant_id=? LIMIT 1",
            [$booking['id'],$booking['tenant_id']]
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
        $id = (string) $request->getAttribute('id');
        $booking = Booking::find($id);
        if ($booking === null) return Response::redirect($redirectBase);
        try {
            $result = BookingCommands::reschedule(BookingCommands::sessionActor($booking['tenant_id']), ['business_id' => $booking['tenant_id'], 'booking_id' => $id, 'target' => $request->all()]);
            FormState::toast('success', __('admin.bookings.flash_rescheduled'));
            return Response::redirect("{$redirectBase}/{$result['new_booking_id']}");
        } catch (OperationException) {
            FormState::toast('error', __('admin.bookings.flash_reschedule_failed'));
            return Response::redirect("{$redirectBase}/{$id}");
        }
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
        $tenantId = (string) $request->getAttribute('tenant_id');
        $this->storeOldInput($request);
        try {
            $actor = BookingCommands::sessionActor($tenantId);
            $input = $request->all();
            $input['business_id'] = $tenantId;
            $tenant = $this->loadTenant($tenantId);
            foreach (json_decode($tenant['custom_fields'] ?? '[]', true) ?: [] as $field) {
                if (($field['type'] ?? '') === 'checkbox') {
                    $key=$field['name']??$field['key']??'';
                    if ($key!=='') $input['custom_fields'][$key]=($input['custom_fields'][$key]??'')==='1';
                }
            }
            $input['customer'] = ['name' => $request->string('customer_name'), 'email' => $request->string('customer_email'), 'phone' => $request->string('customer_phone')];
            $booking = BookingCommands::create($actor, $input);
            FormState::clear();
            FormState::toast('success', __('admin.bookings.flash_created'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/{$booking['id']}");
        } catch (\Throwable) {
            FormState::toast('error', __('admin.bookings.flash_create_failed'));
            return Response::redirect("/admin/tenants/{$tenantId}/bookings/create");
        }
    }

    // ── Resource-pattern manual booking ──



    // ── Capacity-pattern manual booking ──



    /**
     * Store an event-pattern booking (admin manual entry).
     */


    // ── Shared helpers ──

    private function doUpdateStatus(Request $request, string $redirectBase): Response
    {
        $id = (string) $request->getAttribute('id');
        $booking = Booking::find($id);
        if ($booking === null) return Response::redirect($redirectBase);
        try {
            BookingCommands::changeStatus(BookingCommands::sessionActor($booking['tenant_id']), ['business_id' => $booking['tenant_id'], 'booking_id' => $id, 'status' => $request->string('status')]);
            FormState::toast('success', __('admin.bookings.flash_status_updated'));
        } catch (OperationException) {
            FormState::toast('error', __('admin.bookings.flash_status_failed'));
        }
        return Response::redirect("{$redirectBase}/{$id}");
    }

    /**
     * Send a booking confirmation email when a waitlisted booking is promoted.
     */


    /**
     * Send a status transition email (approval confirmed or reschedule confirmation).
     */


    /**
     * Schedule a reminder for a booking that was just approved (pending → confirmed).
     *
     * PRD: reminders are deferred until approval since pending bookings skip
     * reminder scheduling during creation.
     */


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
        if(!Auth::isOperator())return $this->forbidden($request);
        $input=array_intersect_key($request->all(),array_flip(['status','from','to','search','sort','direction']));

        $data=BookingQueries::export(SessionActor::resolve(Operations::registry()),$input,true);
        return (new Response())->header('Content-Type','text/csv; charset=UTF-8')->header('Content-Disposition','attachment; filename="'.$data['filename'].'"')->header('Cache-Control','no-store')->body($data['content']);
    }

    /**
     * Export bookings as CSV — tenant-scoped.
     */
    public function tenantExport(Request $request): Response
    {

        $input=array_intersect_key($request->all(),array_flip(['status','from','to','search','sort','direction']));
        $input['business_id']=$request->getAttribute('tenant_id');
        $data=BookingQueries::export(SessionActor::resolve(Operations::registry()),$input,false);
        return (new Response())->header('Content-Type','text/csv; charset=UTF-8')->header('Content-Disposition','attachment; filename="'.$data['filename'].'"')->header('Cache-Control','no-store')->body($data['content']);
    }

    /**
     * Build a CSV response from booking rows.
     *
     * @param list<array<string, mixed>> $bookings
     */
    private function bookingDetail(string $id,?string $businessId):?array
    {
        $actor=SessionActor::resolve(Operations::registry());
        if($businessId===null){
            if($actor->role!=='operator')throw new OperationException('forbidden',403);
            $businessId=Database::query('SELECT tenant_id FROM bookings WHERE id=?',[$id])[0]['tenant_id']??null;
        }
        if($businessId===null)return null;
        try{return BookingQueries::browserProjection(BookingQueries::get($actor,['business_id'=>$businessId,'booking_id'=>$id]));}
        catch(OperationException $error){if($error->status===404)return null;throw $error;}
    }

}
