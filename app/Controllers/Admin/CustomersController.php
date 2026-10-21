<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;
use App\Models\Customer;
use App\Agent\{Operations, SessionActor, OperationException};
use App\Application\Customers\CustomerDirectory;

/**
 * Customer list & detail controller (tenant-scoped).
 *
 * Access: all authenticated admin roles (operator, owner, manager).
 * Per PRD Roles Checklist: managers have operational access to customers.
 *
 * GET /admin/tenants/{tenant_id}/customers          → list
 * GET /admin/tenants/{tenant_id}/customers/{id}     → detail + booking history
 */
final class CustomersController
{
    private const PER_PAGE = 25;

    // ── List ──

    public function index(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!Auth::canAccessTenant($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        $search = $request->string('search') ?: null;
        $page   = max(1, (int) ($request->string('page') ?: 1));
        $offset = ($page - 1) * self::PER_PAGE;

        $result = CustomerDirectory::search(
            SessionActor::resolve(Operations::registry()),
            ['business_id' => $tenantId, 'search' => $search, 'limit' => self::PER_PAGE],
            $offset,
        );
        $customers = $result['items'];
        $total = $result['total'];

        return $this->render('admin.tenants.customers.index', __('admin.customers.page_title'), [
            'documentTitle' => __('admin.customers.page_title') . ' — ' . $tenant['name'],
            'tenant'    => $tenant,
            'customers' => $customers,
            'total'     => $total,
            'page'      => $page,
            'perPage'   => self::PER_PAGE,
            'search'    => $search ?? '',
        ], $tenantId);
    }

    // ── Detail ──

    public function show(Request $request): Response
    {
        $tenantId   = $request->getAttribute('tenant_id');
        $customerId = $request->getAttribute('id');

        if (!Auth::canAccessTenant($tenantId)) {
            return $this->forbidden($request);
        }

        $tenant = $this->loadTenant($tenantId);
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        $actor = SessionActor::resolve(Operations::registry());
        try {
            $customer = CustomerDirectory::detail($actor, $tenantId, $customerId);
        } catch (OperationException) {
            return Response::redirect("/admin/tenants/{$tenantId}/customers");
        }

        $history = CustomerDirectory::history($actor, [
            'business_id' => $tenantId,
            'customer_id' => $customerId,
            'cursor' => $request->string('cursor'),
        ]);
        $bookings = $history['items'];

        // Derive stats from live bookings so the stat cards and the
        // history table below them are always self-consistent.
        $liveBookingCount = $customer['booking_count'];
        $liveLastBookingAt = $customer['last_booking_at'];

        return $this->render('admin.tenants.customers.show', $customer['name'], [
            'documentTitle'     => $customer['name'] . ' — ' . __('admin.customers.page_title'),
            'tenant'            => $tenant,
            'customer'          => $customer,
            'bookings'          => $bookings,
            'liveBookingCount'  => $liveBookingCount,
            'liveLastBookingAt' => $liveLastBookingAt,
            'nextHistoryCursor' => $history['next_cursor'],
        ], $tenantId);
    }

    // ── Helpers ──

    private function loadTenant(string $tenantId): ?array
    {
        $rows = \App\Engine\Database::query(
            'SELECT `id`, `name`, `slug`, `email`, `timezone`, `currency` FROM `tenants` WHERE `id` = ? LIMIT 1',
            [$tenantId]
        );

        return $rows[0] ?? null;
    }

    private function render(string $template, string $pageTitle, array $extra, string $tenantId): Response
    {
        return View::response($template, array_merge([
            'user'       => Auth::user(),
            'version'    => Version::get(),
            'pageTitle'  => $pageTitle,
            'activePage' => 'customers',
            'csrfToken'  => CsrfMiddleware::generateToken(),
            'tenantId'   => $tenantId,
        ], $extra));
    }

    private function forbidden(Request $request): Response
    {
        if ($request->isJson()) {
            return Response::json([
                'error'   => 'forbidden',
                'message' => 'Access denied.',
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
     * Export customers as CSV — tenant-scoped.
     */
    public function export(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        if (!Auth::canAccessTenant($tenantId)) {
            return $this->forbidden($request);
        }

        $actor = SessionActor::resolve(Operations::registry());
        $cursor = $request->string('cursor') ?: null;
        $csv = '';
        // Keep the existing browser export bound while sharing query/projection/CSV safety.
        for ($page = 0; $page < 10; $page++) {
            $chunk = CustomerDirectory::export($actor, [
                'business_id' => $tenantId,
                'search' => $request->string('search'),
                'cursor' => $cursor,
            ]);
            $csv .= $page === 0 ? $chunk['content'] : substr($chunk['content'], strpos($chunk['content'], "\n") + 1);
            $cursor = $chunk['next_cursor'];
            if ($cursor === null) {
                break;
            }
        }
        $filename = 'customers-export-' . gmdate('Y-m-d') . '.csv';

        $response = new Response();
        return $response
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Cache-Control', 'no-store')
            ->header('X-Next-Cursor', $cursor ?? '')
            ->body($csv);
    }

}
