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
 * Resource management controller (tenant-scoped, resource-pattern only).
 *
 * Access: operators + business owners (Auth::canManageTenant()).
 */
final class ResourceController
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

        if (!$this->isResource($tenant)) {
            return Response::redirect("/admin/tenants/{$tenantId}");
        }

        $resources = Database::query(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM `bookings` b
                     WHERE b.`resource_id` = r.`id`
                     AND b.`status` IN (\'confirmed\', \'rescheduled\')
                    ) AS active_bookings
             FROM `resources` r
             WHERE r.`tenant_id` = ?
             ORDER BY r.`is_active` DESC, r.`sort_order` ASC, r.`name` ASC',
            [$tenantId]
        );

        // Decode amenities JSON
        foreach ($resources as &$r) {
            $r['amenities'] = $r['amenities'] ? json_decode($r['amenities'], true) : [];
        }
        unset($r);

        return $this->render('admin.tenants.resources.index', __('admin.resources.title'), [
            'recordVersions' => \App\Application\Catalog\CatalogUi::versions('resources', $tenantId, $resources),
            'documentTitle' => __('admin.resources.title') . ' — ' . $tenant['name'],
            'tenant'    => $tenant,
            'resources' => $resources,
            'flash'     => FormState::getToast(),
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

        if (!$this->isResource($tenant)) {
            return Response::redirect("/admin/tenants/{$tenantId}");
        }

        return $this->render('admin.tenants.resources.create', __('admin.resources.new'), [
            'documentTitle' => __('admin.resources.new') . ' — ' . $tenant['name'],
            'tenant' => $tenant,
            'flash'  => FormState::getToast(),
        ], $tenantId);
    }

    // ── Store ──

    public function store(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'resources', 'create');
    }

    // ── Edit form ──

    public function edit(Request $request): Response
    {
        $tenantId   = $request->getAttribute('tenant_id');
        $resourceId = $request->getAttribute('id');

        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        if (!$this->isResource($tenant)) {
            return Response::redirect("/admin/tenants/{$tenantId}");
        }

        $resource = $this->loadResource($resourceId, $tenantId);
        if ($resource === null) {
            return Response::redirect("/admin/tenants/{$tenantId}/resources");
        }

        $resource['amenities'] = $resource['amenities'] ? json_decode($resource['amenities'], true) : [];

        // Load seasonal pricing entries
        $seasonalPricing = Database::query(
            'SELECT * FROM `seasonal_pricing`
             WHERE `resource_id` = ? AND `tenant_id` = ?
             ORDER BY `start_date` ASC',
            [$resourceId, $tenantId]
        );

        return $this->render('admin.tenants.resources.edit', __('admin.resources.edit'), [
            'recordVersion' => \App\Application\Catalog\CatalogUi::version('resources', $tenantId, $resourceId),
            'documentTitle'   => $resource['name'] . ' — ' . __('admin.resources.edit'),
            'tenant'          => $tenant,
            'resource'        => $resource,
            'seasonalPricing' => $seasonalPricing,
            'flash'           => FormState::getToast(),
        ], $tenantId);
    }

    // ── Update ──

    public function update(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'resources', 'update');
    }

    // ── Activate ──

    public function activate(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'resources', 'activate');
    }

    // ── Deactivate ──

    public function deactivate(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'resources', 'deactivate');
    }

    // ── Reorder (move up / move down) ──

    public function reorder(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'resources', 'reorder');
    }

    // ── Helpers ──

    private function canAccess(string $tenantId): bool
    {
        return Auth::canAccessTenant($tenantId) && Auth::canManageTenant();
    }

    private function isResource(?array $tenant): bool
    {
        return ($tenant['booking_pattern'] ?? '') === 'resource';
    }

    private function loadTenant(string $tenantId): ?array
    {
        $rows = Database::query(
            'SELECT `id`, `name`, `slug`, `email`, `booking_pattern`, `currency`,
                    `timezone`, `min_advance_hours`, `max_advance_days`
             FROM `tenants` WHERE `id` = ? LIMIT 1',
            [$tenantId]
        );
        return $rows[0] ?? null;
    }

    private function loadResource(string $resourceId, string $tenantId): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `resources` WHERE `id` = ? AND `tenant_id` = ? LIMIT 1',
            [$resourceId, $tenantId]
        );
        return $rows[0] ?? null;
    }









    private function render(string $template, string $pageTitle, array $extra, string $tenantId): Response
    {
        return View::response($template, array_merge([
            'user'       => Auth::user(),
            'version'    => Version::get(),
            'pageTitle'  => $pageTitle,
            'activePage' => 'resources',
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
