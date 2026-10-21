<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Ulid;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/**
 * Capacity slot management controller (tenant-scoped, capacity-pattern only).
 *
 * Provides CRUD for weekly time windows with max_capacity and max_party_size.
 * Access: operators + business owners (Auth::canManageTenant()).
 */
final class CapacitySlotsController
{

    public function index(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!Auth::canAccessTenant($tenantId) || !Auth::canManageTenant()) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        if (($tenant['booking_pattern'] ?? '') !== 'capacity') {
            return Response::redirect("/admin/tenants/{$tenantId}");
        }

        $slots = Database::query(
            'SELECT * FROM `capacity_slots`
             WHERE `tenant_id` = ?
             ORDER BY `day_of_week` ASC, `start_time` ASC',
            [$tenantId]
        );

        return $this->render('admin.tenants.capacity-slots.index', __('admin.capacity_slots.title'), [
            'recordVersions' => \App\Application\Catalog\CatalogUi::versions('capacity_slots', $tenantId, $slots),
            'documentTitle' => __('admin.capacity_slots.title'),
            'tenant'        => $tenant,
            'tenantId'      => $tenantId,
            'slots'         => $slots,
            'flash'         => FormState::getToast(),
        ], $tenantId);
    }

    public function store(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'capacity_slots', 'create');
    }

    public function toggleActive(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'capacity_slots', 'toggle');
    }

    public function delete(Request $request): Response
    {
        return \App\Application\Catalog\CatalogUi::mutate($request, 'capacity_slots', 'delete');
    }

    // ── Private helpers (same pattern as ResourceController) ──

    private function loadTenant(string $tenantId): ?array
    {
        $rows = Database::query('SELECT * FROM `tenants` WHERE `id` = ? LIMIT 1', [$tenantId]);
        return $rows[0] ?? null;
    }

    private function render(string $template, string $pageTitle, array $extra, string $tenantId): Response
    {
        return View::response($template, array_merge([
            'user'       => Auth::user(),
            'version'    => Version::get(),
            'pageTitle'  => $pageTitle,
            'activePage' => 'capacity-slots',
            'csrfToken'  => CsrfMiddleware::generateToken(),
            'tenantId'   => $tenantId,
        ], $extra));
    }

    private function forbidden(Request $request): Response
    {
        if ($request->isJson()) {
            return Response::json([
                'error'   => 'forbidden',
                'message' => __('admin.common.forbidden'),
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
