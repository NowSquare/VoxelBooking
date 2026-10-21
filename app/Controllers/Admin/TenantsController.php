<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\Auth;
use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\FormState;
use App\Engine\Logger;
use App\Engine\Mailer;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;
use App\Models\Tenant;

/**
 * Tenant management controller (operator-only).
 *
 * Access control: Every public method checks Auth::isOperator() and
 * returns 403 if false. This is NOT in OPERATOR_ONLY_PREFIXES because
 * the /admin/tenants/{tenant_id}/... prefix is used by business users.
 *
 * GET  /admin/tenants          → Tenant list
 * GET  /admin/tenants/create   → Create form
 * POST /admin/tenants/create   → Store new tenant
 * GET  /admin/tenants/{id}/edit → Edit form
 * POST /admin/tenants/{id}/edit → Update tenant
 * POST /admin/tenants/{id}/archive → Archive tenant
 * POST /admin/tenants/{id}/activate → Re-activate tenant
 */
final class TenantsController
{
    public function index(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $search = trim($request->string('search'));
        $status = trim($request->string('status'));

        $tenants = \App\Application\Business\BusinessUi::all(\App\Application\Business\BusinessService::list(...), ['search' => $search, 'status' => $status ?: 'all']);

        foreach ($tenants as &$t) {
            $t['booking_count'] = Tenant::bookingCount($t['id']);
            $t['service_count'] = Tenant::serviceCount($t['id']);
        }
        unset($t);

        return $this->render('admin.tenants.index', __('admin.tenants.title'), [
            'tenants' => $tenants,
            'counts'  => Tenant::counts(),
            'filters' => ['search' => $search, 'status' => $status],
            'flash'   => FormState::getToast(),
        ]);
    }

    public function create(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $defaults = $this->resolveSystemDefaults();

        return $this->render('admin.tenants.create', __('admin.tenants.title'), [
            'documentTitle'   => __('admin.tenants.create'),
            'defaultTimezone' => $defaults['timezone'],
            'defaultCurrency' => $defaults['currency'],
            'flash'           => FormState::getToast(),
        ]);
    }

    public function store(Request $request): Response
    {
        return \App\Application\Business\BusinessUi::business($request, 'create');
    }

    public function edit(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return $this->forbidden($request);
        }

        $tenant = Tenant::find($request->getAttribute('id'));
        if ($tenant === null) {
            return Response::redirect('/admin/tenants');
        }

        return $this->render('admin.tenants.edit', __('admin.tenants.title'), [
            'documentTitle' => __('admin.tenants.edit'),
            'tenant'        => $tenant,
            'recordVersion' => \App\Application\Business\BusinessService::project($tenant)['version'],
            'flash'         => FormState::getToast(),
        ]);
    }

    public function update(Request $request): Response
    {
        return \App\Application\Business\BusinessUi::business($request, 'update');
    }

    public function archive(Request $request): Response
    {
        return \App\Application\Business\BusinessUi::business($request, 'archive');
    }

    public function activate(Request $request): Response
    {
        return \App\Application\Business\BusinessUi::business($request, 'activate');
    }

    // ── Helpers ──

    /**
     * Resolve system defaults for new tenants.
     *
     * Priority for timezone: settings.timezone (admin General save)
     * → settings.default_timezone (install wizard seed) → UTC.
     *
     * All other regional settings: read from settings table or fall back to sensible defaults.
     *
     * @return array{timezone: string, currency: string, locale: string, date_format: string, number_format: string, time_format: string, week_start: string}
     */
    private function resolveSystemDefaults(): array
    {
        return \App\Application\Business\BusinessService::defaults();
    }

    private function generateSlug(string $name): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', mb_strtolower($name)), '-');
        return $slug !== '' && self::isValidSlug($slug) ? $slug : 'tenant';
    }

    /**
     * Validate a slug against the strict format: lowercase a-z, 0-9, hyphens only.
     *
     * No leading/trailing hyphens, no consecutive hyphens, no uppercase,
     * no spaces, no underscores, no path traversal, no query chars.
     */
    private static function isValidSlug(string $slug): bool
    {
        return (bool) preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug);
    }

    /**
     * Map translated error messages back to field names for per-field marking.
     *
     * Returns ['field_name' => 'translated error message'] for each error that
     * matches a known validation message.
     */
    private function mapFieldErrors(array $errors): array
    {
        $map = [
            __('admin.tenants.flash_name_required')        => 'name',
            __('admin.tenants.flash_email_required')        => 'email',
            __('admin.tenants.flash_email_invalid')         => 'email',
            __('admin.tenants.flash_slug_taken')            => 'slug',
            __('admin.tenants.flash_slug_invalid')          => 'slug',
            __('admin.tenants.flash_owner_name_required')   => 'owner_name',
            __('admin.tenants.flash_owner_email_required')  => 'owner_email',
            __('admin.tenants.flash_owner_email_invalid')   => 'owner_email',
            __('admin.tenants.flash_owner_email_taken')     => 'owner_email',
        ];

        $result = [];
        foreach ($errors as $msg) {
            if (isset($map[$msg])) {
                $result[$map[$msg]] = $msg;
            }
        }
        return $result;
    }

    private function render(string $template, string $pageTitle, array $extra = []): Response
    {
        return View::response($template, array_merge([
            'user'      => Auth::user(),
            'version'   => Version::get(),
            'pageTitle' => $pageTitle,
            'activePage' => 'tenants',
            'csrfToken' => CsrfMiddleware::generateToken(),
        ], $extra));
    }

    private function forbidden(Request $request): Response
    {
        if ($request->isJson()) {
            return Response::json([
                'error' => 'forbidden',
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
     * Export tenants as CSV — operator-only.
     */
    public function export(Request $request): Response
    {
        if (!Auth::isOperator()) { return $this->forbidden($request); }
        try {
            $export = \App\Application\Business\BusinessService::export(\App\Application\Business\BusinessUi::actor(), ['search' => $request->string('search'), 'status' => $request->string('status') ?: 'all']);
            return (new Response())->header('Content-Type', $export['content_type'])->header('Content-Disposition', 'attachment; filename="' . preg_replace('/^businesses-/', 'tenants-export-', $export['filename']) . '"')->header('Cache-Control', 'no-store')->body($export['content']);
        } catch (\App\Agent\OperationException $error) {
            FormState::toast('error', __('agent-business.errors.' . $error->problemCode));
            return Response::redirect('/admin/tenants');
        }
    }

}
