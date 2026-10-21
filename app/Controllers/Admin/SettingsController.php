<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\DemoMode;

use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\Database;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/**
 * System settings controller (operator-only).
 *
 * GET  /admin/settings          → General settings (read + form)
 * POST /admin/settings          → Save general settings
 * GET  /admin/settings/email    → Email/SMTP config
 * POST /admin/settings/email    → Save email config
 * GET  /admin/settings/cron     → Cron status
 * GET  /admin/settings/logs     → Log viewer
 * GET  /admin/settings/audit    → Audit log viewer (read-only, compliance §5)
 *
 * Account management now lives in AccountController (/admin/account).
 */
final class SettingsController
{
    // ── General ──

    public function general(Request $request): Response
    {
        $record = \App\Application\System\SystemSettings::get(\App\Application\System\SystemUi::actor(), ['section' => 'general']);
        $values = $record['values'];
        $values['enable_applications'] = $values['enable_applications'] ? '1' : '0';
        return $this->render('admin.settings.general', __('admin.settings.title'), [
            'settings' => $values, 'recordVersion' => $record['version'], 'flash' => FormState::getToast(),
            'localeOptions' => \App\Engine\Locale::localeOptions(), 'currencyOptions' => get_supported_currencies(),
        ]);
    }

    public function saveGeneral(Request $request): Response
    {
        return \App\Application\System\SystemUi::save($request, 'general');
    }

    public function email(Request $request): Response
    {
        $record = \App\Application\System\SystemSettings::get(\App\Application\System\SystemUi::actor(), ['section' => 'mail']);
        return $this->render('admin.settings.email', __('admin.email.smtp_title'), [
            'settings' => $record['values'], 'recordVersion' => $record['version'], 'flash' => FormState::getToast(),
        ]);
    }

    public function saveEmail(Request $request): Response
    {
        return \App\Application\System\SystemUi::save($request, 'mail');
    }

    public function cron(Request $request): Response
    {
        $cronToken = $this->getSetting('cron_token');
        if ($cronToken === '' && !DemoMode::isActive()) {
            $cronToken = bin2hex(random_bytes(32));
            Database::upsertSetting('cron_token', $cronToken);
        }
        $status = \App\Application\System\JobCoordinator::status(\App\Application\System\SystemUi::actor());
        return $this->render('admin.settings.cron', __('agent-system.title'), [
            'cronToken' => $cronToken ?: 'demo-mode-token-not-persisted',
            'lastRun' => $status['last_run_at'],
            'jobStatus' => $status,
            'flash' => FormState::getToast(),
        ]);
    }

    public function cronRunNow(Request $request): Response
    {
        return \App\Application\System\SystemUi::run();
    }

    // ── Logs ──

    public function logs(Request $request): Response
    {
        $logContent = '';
        $logFile = dirname(__DIR__, 3) . '/storage/logs/app.log';

        if (is_file($logFile)) {
            // Read last 100 lines
            $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if ($lines !== false) {
                $logContent = implode("\n", array_slice($lines, -100));
            }
        }

        return $this->render('admin.settings.logs', 'Logs', [
            'logContent' => $logContent,
        ]);
    }

    // ── Audit Log ──

    public function audit(Request $request): Response
    {
        $page = max(1, $request->int('page', 1));
        $perPage = 50;
        $actionFilter = $request->query('action');

        $result = \App\Application\System\SystemAudit::browser(
            \App\Application\System\SystemUi::actor(),
            ['action' => $actionFilter],
            $page,
        );

        return $this->render('admin.settings.audit', 'Audit Log', [
            'entries'      => $result['entries'],
            'total'        => $result['total'],
            'page'         => $page,
            'perPage'      => $perPage,
            'actionFilter' => $actionFilter,
        ]);
    }

    // ── Helpers ──

    private function render(string $template, string $pageTitle, array $extra = []): Response
    {
        return View::response($template, array_merge([
            'user'      => Auth::user(),
            'version'   => Version::get(),
            'pageTitle' => $pageTitle,
            'csrfToken' => CsrfMiddleware::generateToken(),
        ], $extra));
    }

    private function getSetting(string $key): string
    {
        try {
            $rows = Database::query(
                'SELECT `value` FROM `settings` WHERE `key` = ? LIMIT 1',
                [$key]
            );
            return $rows[0]['value'] ?? '';
        } catch (\Throwable) {
            return '';
        }
    }

    public function auditExport(Request $request): Response
    {
        $export = \App\Application\System\SystemAudit::export(
            \App\Application\System\SystemUi::actor(),
            ['action' => $request->query('action')],
        );
        return (new Response())
            ->header('Content-Type', $export['content_type'])
            ->header(
                'Content-Disposition',
                'attachment; filename="' . preg_replace('/^audit-/', 'audit-log-export-', $export['filename']) . '"',
            )
            ->header('Cache-Control', 'no-store')
            ->body($export['content']);
    }
}
