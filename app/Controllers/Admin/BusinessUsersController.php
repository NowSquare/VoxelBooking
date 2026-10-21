<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\Mailer;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Ulid;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/**
 * Business user CRUD controller (tenant-scoped).
 *
 * Access: operators + business owners (Auth::canManageTenant()).
 * Managers cannot access any of these routes.
 *
 * GET  /admin/tenants/{tenant_id}/users               → list
 * GET  /admin/tenants/{tenant_id}/users/invite         → invite form
 * POST /admin/tenants/{tenant_id}/users/invite         → store
 * POST /admin/tenants/{tenant_id}/users/{id}/deactivate → deactivate
 * POST /admin/tenants/{tenant_id}/users/{id}/activate   → activate
 */
final class BusinessUsersController
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

        $users = \App\Application\Business\BusinessUi::all(\App\Application\Business\TeamService::list(...), ['business_id' => $tenantId]);

        return $this->render('admin.tenants.users.index', __('admin.users.page_title'), [
            'documentTitle' => __('admin.users.page_title') . ' — ' . $tenant['name'],
            'tenant'   => $tenant,
            'users'    => $users,
            'flash'    => FormState::getToast(),
        ], $tenantId);
    }

    // ── Invite form ──

    public function invite(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!$this->canAccess($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        return $this->render('admin.tenants.users.invite', __('admin.users.invite_title'), [
            'documentTitle' => __('admin.users.invite_title') . ' — ' . $tenant['name'],
            'tenant' => $tenant,
            'flash'  => FormState::getToast(),
        ], $tenantId);
    }

    // ── Store invited user ──

    public function store(Request $request): Response
    {
        return \App\Application\Business\BusinessUi::team($request, 'invite');
    }

    // ── Deactivate ──

    public function deactivate(Request $request): Response
    {
        return \App\Application\Business\BusinessUi::team($request, 'deactivate');
    }

    // ── Activate ──

    public function activate(Request $request): Response
    {
        return \App\Application\Business\BusinessUi::team($request, 'activate');
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
            'SELECT `id`, `name`, `slug`, `email` FROM `tenants` WHERE `id` = ? LIMIT 1',
            [$tenantId]
        );

        return $rows[0] ?? null;
    }

    private function loadUser(string $userId, string $tenantId): ?array
    {
        $rows = Database::query(
            'SELECT `id`, `name`, `email`, `role`, `is_active`
             FROM `business_users`
             WHERE `id` = ? AND `tenant_id` = ?
             LIMIT 1',
            [$userId, $tenantId]
        );

        return $rows[0] ?? null;
    }

    private function render(string $template, string $pageTitle, array $extra, string $tenantId): Response
    {
        return View::response($template, array_merge([
            'user'       => Auth::user(),
            'version'    => Version::get(),
            'pageTitle'  => $pageTitle,
            'activePage' => 'users',
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
