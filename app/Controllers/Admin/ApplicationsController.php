<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\FormState;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/**
 * Business application review surface — operator-only.
 *
 * Lists, approves, and rejects access requests submitted via the
 * public landing page. Approval does not auto-create tenants;
 * operators manually create them via the existing tenant creation flow.
 */
final class ApplicationsController
{
    /**
     * GET /admin/applications — list all applications with status filter.
     */
    public function index(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return Response::html('Forbidden', 403);
        }

        $statusFilter = $request->string('status') ?: 'pending';
        $validStatuses = ['pending', 'approved', 'rejected', 'all'];
        if (!in_array($statusFilter, $validStatuses, true)) {
            $statusFilter = 'pending';
        }

        $applications = \App\Application\Business\BusinessUi::all(
            \App\Application\Business\ApplicationService::list(...),
            ['status' => $statusFilter],
        );

        // Pending count for badge
        $pendingCount = Database::query(
            "SELECT COUNT(*) AS `cnt` FROM `business_applications` WHERE `status` = 'pending'"
        )[0]['cnt'] ?? 0;

        return View::response('admin.applications.index', [
            'user'          => Auth::user(),
            'version'       => Version::get(),
            'pageTitle'     => __('admin.applications.title'),
            'documentTitle' => __('admin.applications.title'),
            'activePage'    => 'applications',
            'csrfToken'     => CsrfMiddleware::generateToken(),
            'applications'  => $applications,
            'statusFilter'  => $statusFilter,
            'pendingCount'  => (int) $pendingCount,
            'flash'         => FormState::getToast(),
        ]);
    }

    /**
     * POST /admin/applications/{id}/approve
     */
    public function approve(Request $request): Response
    {
        return \App\Application\Business\BusinessUi::application($request, 'approved');
    }

    /**
     * POST /admin/applications/{id}/reject
     */
    public function reject(Request $request): Response
    {
        return \App\Application\Business\BusinessUi::application($request, 'rejected');
    }

    private function findApplication(string $id): ?array
    {
        $rows = Database::query(
            'SELECT * FROM `business_applications` WHERE `id` = ? LIMIT 1',
            [$id]
        );
        return $rows[0] ?? null;
    }
}
