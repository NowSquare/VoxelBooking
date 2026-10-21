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
 * Service management controller (tenant-scoped, timeslot-only).
 *
 * Access: operators + business owners (Auth::canManageTenant()).
 */
final class ServiceController
{
    // ── Index ──

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

        $services = Database::query(
            'SELECT s.*,
                    (SELECT COUNT(*) FROM `service_staff` ss WHERE ss.`service_id` = s.`id`) AS staff_count
             FROM `services` s
             WHERE s.`tenant_id` = ?
             ORDER BY s.`is_active` DESC, s.`sort_order` ASC, s.`name` ASC',
            [$tenantId]
        );

        return $this->render('admin.tenants.services.index', __('admin.services.title'), [
            'recordVersions' => \App\Application\Catalog\CatalogUi::versions('services', $tenantId, $services),
            'documentTitle' => __('admin.services.title') . ' — ' . $tenant['name'],
            'tenant'   => $tenant,
            'services' => $services,
            'flash'    => FormState::getToast(),
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

        $staff = $this->loadActiveStaff($tenantId);

        return $this->render('admin.tenants.services.create', __('admin.services.new'), [
            'documentTitle' => __('admin.services.new') . ' — ' . $tenant['name'],
            'tenant' => $tenant,
            'staff'  => $staff,
            'flash'  => FormState::getToast(),
        ], $tenantId);
    }

    // ── Store ──

    public function store(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'services', 'create');
    }

    // ── Edit form ──

    public function edit(Request $request): Response
    {
        $tenantId  = $request->getAttribute('tenant_id');
        $serviceId = $request->getAttribute('id');

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

        $service = $this->loadService($serviceId, $tenantId);
        if ($service === null) {
            return Response::redirect("/admin/tenants/{$tenantId}/services");
        }

        $staff = $this->loadActiveStaff($tenantId);
        $linkedStaffIds = array_column(
            Database::query(
                'SELECT `staff_id` FROM `service_staff` WHERE `service_id` = ?',
                [$serviceId]
            ),
            'staff_id'
        );

        return $this->render('admin.tenants.services.edit', __('admin.services.edit'), [
            'recordVersion' => \App\Application\Catalog\CatalogUi::version('services', $tenantId, $serviceId),
            'documentTitle'  => $service['name'] . ' — ' . __('admin.services.edit'),
            'tenant'         => $tenant,
            'service'        => $service,
            'staff'          => $staff,
            'linkedStaffIds' => $linkedStaffIds,
            'flash'          => FormState::getToast(),
        ], $tenantId);
    }

    // ── Update ──

    public function update(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'services', 'update');
    }

    // ── Activate ──

    public function activate(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'services', 'activate');
    }

    // ── Deactivate ──

    public function deactivate(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'services', 'deactivate');
    }

    // ── Reorder (move up / move down) ──

    public function reorder(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'services', 'reorder');
    }

    // ── Helpers ──

    private function canAccess(string $tenantId): bool
    {
        return Auth::canAccessTenant($tenantId) && Auth::canManageTenant();
    }

    private function isTimeslot(?array $tenant): bool
    {
        return ($tenant['booking_pattern'] ?? '') === 'timeslot';
    }

    private function loadTenant(string $tenantId): ?array
    {
        $rows = Database::query(
            'SELECT `id`, `name`, `slug`, `email`, `booking_pattern`, `currency` FROM `tenants` WHERE `id` = ? LIMIT 1',
            [$tenantId]
        );
        return $rows[0] ?? null;
    }

    private function loadService(string $serviceId, string $tenantId): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `services` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1',
            [$serviceId, $tenantId]
        );
        return $rows[0] ?? null;
    }

    private function loadActiveStaff(string $tenantId): array
    {
        return Database::query(
            'SELECT `id`, `name`, `title` FROM `staff`
             WHERE `tenant_id` = ? AND `is_active` = 1
             ORDER BY `sort_order` ASC, `name` ASC',
            [$tenantId]
        );
    }



    private function render(string $template, string $pageTitle, array $extra, string $tenantId): Response
    {
        return View::response($template, array_merge([
            'user'       => Auth::user(),
            'version'    => Version::get(),
            'pageTitle'  => $pageTitle,
            'activePage' => 'services',
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
