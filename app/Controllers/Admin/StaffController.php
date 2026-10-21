<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\Database;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/**
 * Staff CRUD controller (tenant-scoped).
 *
 * Access: operators + business owners (Auth::canManageTenant()).
 * Managers cannot access any of these routes.
 *
 * GET  /admin/tenants/{tenant_id}/staff                → list
 * GET  /admin/tenants/{tenant_id}/staff/create         → create form
 * POST /admin/tenants/{tenant_id}/staff/create         → store
 * GET  /admin/tenants/{tenant_id}/staff/{id}/edit      → edit form
 * POST /admin/tenants/{tenant_id}/staff/{id}/edit      → update
 * POST /admin/tenants/{tenant_id}/staff/{id}/activate  → activate
 * POST /admin/tenants/{tenant_id}/staff/{id}/deactivate→ deactivate
 * POST /admin/tenants/{tenant_id}/staff/{id}/reorder    → reorder (chevron swap)
 */
final class StaffController
{
    // ── List ──

    public function index(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        if (!$this->isTimeslot($tenant)) {
            return Response::redirect("/admin/tenants/{$tenantId}");
        }

        // Staff with service count via subquery
        $staff = Database::query(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM `service_staff` ss WHERE ss.`staff_id` = s.`id`) AS service_count
             FROM `staff` s
             WHERE s.`tenant_id` = ?
             ORDER BY s.`is_active` DESC, s.`sort_order` ASC, s.`name` ASC',
            [$tenantId]
        );

        return $this->render('admin.tenants.staff.index', __('admin.staff.title'), [
            'recordVersions' => \App\Application\Catalog\CatalogUi::versions('staff', $tenantId, $staff),
            'documentTitle' => __('admin.staff.title') . ' — ' . $tenant['name'],
            'tenant' => $tenant,
            'staff'  => $staff,
            'flash'  => FormState::getToast(),
        ], $tenantId);
    }

    // ── Create form ──

    public function create(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        if (!$this->isTimeslot($tenant)) {
            return Response::redirect("/admin/tenants/{$tenantId}");
        }

        $services = $this->loadServices($tenantId);

        return $this->render('admin.tenants.staff.create', __('admin.staff.new'), [
            'documentTitle' => __('admin.staff.new') . ' — ' . $tenant['name'],
            'tenant'   => $tenant,
            'services' => $services,
            'flash'    => FormState::getToast(),
        ], $tenantId);
    }

    // ── Store ──

    public function store(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'staff', 'create');
    }

    // ── Edit form ──

    public function edit(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');
        $staffId  = $request->getAttribute('id');

        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        if (!$this->isTimeslot($tenant)) {
            return Response::redirect("/admin/tenants/{$tenantId}");
        }

        $member = $this->loadStaff($staffId, $tenantId);
        if ($member === null) {
            return Response::redirect("/admin/tenants/{$tenantId}/staff");
        }

        $services = $this->loadServices($tenantId);
        $linkedServiceIds = array_column(Database::query(
            'SELECT `service_id` FROM `service_staff` WHERE `staff_id` = ?',
            [$staffId]
        ), 'service_id');

        return $this->render('admin.tenants.staff.edit', __('admin.staff.edit'), [
            'recordVersion' => \App\Application\Catalog\CatalogUi::version('staff', $tenantId, $staffId),
            'documentTitle'    => $member['name'] . ' — ' . __('admin.staff.edit'),
            'tenant'           => $tenant,
            'member'           => $member,
            'services'         => $services,
            'linkedServiceIds' => $linkedServiceIds,
            'flash'            => FormState::getToast(),
        ], $tenantId);
    }

    // ── Update ──

    public function update(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'staff', 'update');
    }

    // ── Activate ──

    public function activate(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'staff', 'activate');
    }

    // ── Deactivate ──

    public function deactivate(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'staff', 'deactivate');
    }

    // ── Reorder (move up / move down) ──

    public function reorder(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'staff', 'reorder');
    }

    // ── Helpers ──

    private function canAccess(string $tenantId): bool
    {
        if (!Auth::canAccessTenant($tenantId)) {
            return false;
        }

        return Auth::canManageTenant();
    }

    private function loadTenant(string $tenantId): ?array
    {
        $rows = Database::query(
            'SELECT `id`, `name`, `slug`, `email`, `booking_pattern` FROM `tenants` WHERE `id` = ? LIMIT 1',
            [$tenantId]
        );

        return $rows[0] ?? null;
    }

    private function isTimeslot(?array $tenant): bool
    {
        return ($tenant['booking_pattern'] ?? '') === 'timeslot';
    }

    private function loadStaff(string $staffId, string $tenantId): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `staff` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1',
            [$staffId, $tenantId]
        );

        return $rows[0] ?? null;
    }

    private function loadServices(string $tenantId): array
    {
        return Database::query(
            'SELECT `id`, `name` FROM `services` WHERE `tenant_id` = ? AND `is_active` = 1 ORDER BY `sort_order` ASC, `name` ASC',
            [$tenantId]
        );
    }



    private function render(string $template, string $pageTitle, array $extra, string $tenantId): Response
    {
        return View::response($template, array_merge([
            'user'       => Auth::user(),
            'version'    => Version::get(),
            'pageTitle'  => $pageTitle,
            'activePage' => 'staff',
            'csrfToken'  => CsrfMiddleware::generateToken(),
            'tenantId'   => $tenantId,
        ], $extra));
    }

    private function forbidden(Request $request): Response
    {
        if ($request->isJson()) {
            return Response::json([
                'error'   => 'forbidden',
                'message' => 'Owner or operator access required.',
            ], 403);
        }

        return View::response('admin.errors.403', [
            'user'      => Auth::user(),
            'version'   => Version::get(),
            'pageTitle' => '403',
            'csrfToken' => CsrfMiddleware::generateToken(),
        ], 403);
    }


}
