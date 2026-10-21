<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\Database;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Tenant;

/**
 * Operator dashboard controller.
 *
 * Queries real tenant/booking metrics from the database,
 * including week-over-week deltas and upcoming bookings.
 */
final class DashboardController
{
    public function index(Request $request): Response
    {
        if (!Auth::isOperator()) { return Response::redirect('/admin/login'); }
        $data = \App\Application\Business\DashboardService::operator(\App\Application\Business\BusinessUi::actor());
        return View::response('admin.dashboard', ['user' => Auth::user(), 'version' => Version::get(), ...$data]);
    }

    /**
     * Tenant-scoped business dashboard.
     *
     * Business users land here via AuthMiddleware redirect from /admin.
     * Operators can also view any tenant's dashboard.
     */
    public function tenantDashboard(Request $request): Response
    {
        $tenantId = (string)$request->getAttribute('tenant_id');
        try {
            $data = \App\Application\Business\DashboardService::business(\App\Application\Business\BusinessUi::actor(), ['business_id' => $tenantId]);
            return View::response('admin.dashboard-business', ['user' => Auth::user(), 'version' => Version::get(),
                'pageTitle' => $data['tenant']['name'], 'activePage' => 'dashboard', 'csrfToken' => \App\Middleware\CsrfMiddleware::generateToken(), ...$data]);
        } catch (\App\Agent\OperationException $error) {
            return $error->status === 404 ? Response::redirect('/admin') : \App\Application\Business\BusinessUi::forbidden($request, $error);
        }
    }

}
