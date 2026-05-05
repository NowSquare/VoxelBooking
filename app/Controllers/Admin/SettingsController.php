<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\DemoMode;

use App\Engine\Auth;
use App\Engine\FormState;
use App\Engine\AuditLog;
use App\Engine\Database;
use App\Engine\Logger;
use App\Engine\ReminderJob;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\RetentionJob;
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
        $settings = $this->loadSettings([
            'app_name', 'brand_url', 'timezone',
            'default_locale', 'default_currency',
            'date_format', 'number_format', 'time_format', 'week_start',
            'enable_applications',
        ]);

        return $this->render('admin.settings.general', 'General', [
            'settings'        => $settings,
            'flash'           => FormState::getToast(),
            'localeOptions'   => \App\Engine\Locale::localeOptions(),
            'currencyOptions' => get_supported_currencies(),
        ]);
    }

    public function saveGeneral(Request $request): Response
    {
        if (DemoMode::isActive()) {
            FormState::toast('error', __('admin.demo.settings_locked'));
            return Response::redirect('/admin/settings');
        }

        $appName    = trim($request->string('app_name'));
        $brandUrl   = rtrim(trim($request->string('brand_url')), '/');
        $timezone   = trim($request->string('timezone'));
        $dateFormat = trim($request->string('date_format'));
        $numberFormat = trim($request->string('number_format'));

        $errors = [];
        if ($appName === '') {
            $errors[] = 'Application name is required.';
        }
        if ($brandUrl !== '' && !filter_var($brandUrl, FILTER_VALIDATE_URL)) {
            $errors[] = 'Brand URL must be a valid URL.';
        }

        if (!empty($errors)) {
            FormState::toast('error', implode(' ', $errors));
            return Response::redirect('/admin/settings');
        }

        // Audit log: track what changed
        $oldSettings = $this->loadSettings([
            'app_name', 'brand_url', 'timezone', 'default_locale', 'default_currency',
            'date_format', 'number_format', 'time_format', 'week_start', 'enable_applications',
        ]);
        $changes = [];

        try {
            $this->saveSetting('app_name', $appName);
            if ($appName !== ($oldSettings['app_name'] ?? '')) {
                $changes['app_name'] = ['old' => $oldSettings['app_name'] ?? '', 'new' => $appName];
            }
            if ($brandUrl !== '') {
                $this->saveSetting('brand_url', $brandUrl);
                if ($brandUrl !== ($oldSettings['brand_url'] ?? '')) {
                    $changes['brand_url'] = ['old' => $oldSettings['brand_url'] ?? '', 'new' => $brandUrl];
                }
            }
            if ($timezone !== '' && in_array($timezone, get_supported_timezones(), true)) {
                $this->saveSetting('timezone', $timezone);
                if ($timezone !== ($oldSettings['timezone'] ?? '')) {
                    $changes['timezone'] = ['old' => $oldSettings['timezone'] ?? '', 'new' => $timezone];
                }
            }
            if ($dateFormat !== '' && in_array($dateFormat, ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd.m.Y'], true)) {
                $this->saveSetting('date_format', $dateFormat);
                if ($dateFormat !== ($oldSettings['date_format'] ?? '')) {
                    $changes['date_format'] = ['old' => $oldSettings['date_format'] ?? '', 'new' => $dateFormat];
                }
            }
            if ($numberFormat !== '' && in_array($numberFormat, ['period', 'comma', 'space'], true)) {
                $this->saveSetting('number_format', $numberFormat);
                if ($numberFormat !== ($oldSettings['number_format'] ?? '')) {
                    $changes['number_format'] = ['old' => $oldSettings['number_format'] ?? '', 'new' => $numberFormat];
                }
            }

            // Regional: locale
            $locale = trim($request->string('default_locale'));
            if ($locale !== '' && \App\Engine\Locale::isSupported($locale)) {
                $this->saveSetting('default_locale', $locale);
                if ($locale !== ($oldSettings['default_locale'] ?? '')) {
                    $changes['default_locale'] = ['old' => $oldSettings['default_locale'] ?? '', 'new' => $locale];
                }
            }

            // Regional: currency
            $currency = strtoupper(trim($request->string('default_currency')));
            $supportedCurrencies = array_keys(get_supported_currencies());
            if ($currency !== '' && in_array($currency, $supportedCurrencies, true)) {
                $this->saveSetting('default_currency', $currency);
                if ($currency !== ($oldSettings['default_currency'] ?? '')) {
                    $changes['default_currency'] = ['old' => $oldSettings['default_currency'] ?? '', 'new' => $currency];
                }
            }

            // Regional: time format
            $timeFormat = trim($request->string('time_format'));
            if (in_array($timeFormat, ['12h', '24h'], true)) {
                $this->saveSetting('time_format', $timeFormat);
                if ($timeFormat !== ($oldSettings['time_format'] ?? '')) {
                    $changes['time_format'] = ['old' => $oldSettings['time_format'] ?? '', 'new' => $timeFormat];
                }
            }

            // Regional: week start
            $weekStart = trim($request->string('week_start'));
            if ($weekStart !== '' && in_array($weekStart, ['0', '1', '6'], true)) {
                $this->saveSetting('week_start', $weekStart);
                if ($weekStart !== ($oldSettings['week_start'] ?? '')) {
                    $changes['week_start'] = ['old' => $oldSettings['week_start'] ?? '', 'new' => $weekStart];
                }
            }

            $enableApps = $request->string('enable_applications') === '1' ? '1' : '0';
            $this->saveSetting('enable_applications', $enableApps);
            if ($enableApps !== ($oldSettings['enable_applications'] ?? '0')) {
                $changes['enable_applications'] = ['old' => $oldSettings['enable_applications'] ?? '0', 'new' => $enableApps];
            }
        } catch (\Throwable $e) {
            Logger::error('Settings persistence failed', ['error' => $e->getMessage()]);
            FormState::toast('error', __('admin.flash.general_failed'));
            return Response::redirect('/admin/settings');
        }

        if (!empty($changes)) {
            AuditLog::logSettingsChanged($changes);
        }

        FormState::toast('success', __('admin.flash.general_saved'));
        return Response::redirect('/admin/settings');
    }

    // ── Email ──

    public function email(Request $request): Response
    {
        $settings = $this->loadSettings([
            'smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption',
            'mail_from_address', 'mail_from_name', 'mail_transport',
        ]);

        // Merge .env fallbacks for display — operator sees the effective config.
        // Password is never displayed regardless of source.
        //
        // IMPORTANT: Only apply .env fallback when the DB row is MISSING.
        // If a row exists with an empty value, the operator deliberately cleared it.
        // This must match Mailer::loadConfig() behavior exactly.
        $envMap = [
            'mail_transport'    => 'MAIL_TRANSPORT',
            'smtp_host'         => 'MAIL_HOST',
            'smtp_port'         => 'MAIL_PORT',
            'smtp_username'     => 'MAIL_USERNAME',
            'smtp_encryption'   => 'MAIL_ENCRYPTION',
            'mail_from_address' => 'MAIL_FROM_ADDRESS',
            'mail_from_name'    => 'MAIL_FROM_NAME',
        ];

        foreach ($envMap as $settingKey => $envKey) {
            if (!$this->settingExistsInDb($settingKey)) {
                $envValue = $_ENV[$envKey] ?? $_SERVER[$envKey] ?? getenv($envKey);
                if ($envValue !== false && $envValue !== '') {
                    $settings[$settingKey] = (string) $envValue;
                }
            }
        }

        return $this->render('admin.settings.email', 'Email', [
            'settings' => $settings,
            'flash'    => FormState::getToast(),
        ]);
    }

    public function saveEmail(Request $request): Response
    {
        if (DemoMode::isActive()) {
            FormState::toast('error', __('admin.demo.settings_locked'));
            return Response::redirect('/admin/settings/email');
        }

        // Track changes for audit log
        $oldSettings = $this->loadSettings(['smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption', 'mail_from_address', 'mail_from_name', 'mail_transport']);
        $changes = [];

        try {
            $fields = ['smtp_host', 'smtp_port', 'smtp_username', 'smtp_encryption', 'mail_from_address', 'mail_from_name', 'mail_transport'];
            foreach ($fields as $field) {
                $value = trim($request->string($field));
                if ($value !== '') {
                    $this->saveSetting($field, $value);
                    if ($value !== ($oldSettings[$field] ?? '')) {
                        $changes[$field] = ['old' => $oldSettings[$field] ?? '', 'new' => $value];
                    }
                }
            }

            // Save password separately (don't overwrite if blank)
            $smtpPassword = $request->string('smtp_password');
            if ($smtpPassword !== '') {
                $this->saveSetting('smtp_password', $smtpPassword);
                $changes['smtp_password'] = ['old' => '[REDACTED]', 'new' => '[REDACTED]'];
            }
        } catch (\Throwable $e) {
            Logger::error('Email settings persistence failed', ['error' => $e->getMessage()]);
            FormState::toast('error', __('admin.flash.email_failed'));
            return Response::redirect('/admin/settings/email');
        }

        if (!empty($changes)) {
            AuditLog::logSettingsChanged($changes);
        }

        FormState::toast('success', __('admin.flash.email_saved'));
        return Response::redirect('/admin/settings/email');
    }

    // ── Cron ──

    public function cron(Request $request): Response
    {
        $cronToken = $this->getSetting('cron_token');
        if ($cronToken === '' && !DemoMode::isActive()) {
            $cronToken = bin2hex(random_bytes(32));
            $this->saveSetting('cron_token', $cronToken);
        }
        if ($cronToken === '') {
            $cronToken = 'demo-mode-token-not-persisted';
        }

        $lastRun = $this->getSetting('cron_last_run');

        return $this->render('admin.settings.cron', 'Cron', [
            'cronToken' => $cronToken,
            'lastRun'   => $lastRun,
            'flash'     => FormState::getToast(),
        ]);
    }

    /**
     * Operator "Run now" action — executes cron tasks immediately.
     *
     * POST /admin/settings/cron/run
     */
    public function cronRunNow(Request $request): Response
    {
        $results = [];
        $errors  = [];

        // 1. Retention processing
        try {
            $retResult = RetentionJob::run();
            $results['retention'] = $retResult;
            if (!empty($retResult['errors'])) {
                $errors = array_merge($errors, $retResult['errors']);
            }
        } catch (\Throwable $e) {
            Logger::error('Cron manual run: retention failed', ['error' => $e->getMessage()]);
            $errors[] = 'Retention failed: ' . $e->getMessage();
        }

        // 2. Reminder processing
        try {
            $remResult = ReminderJob::run();
            $results['reminders'] = $remResult;
            if (!empty($remResult['errors'])) {
                $errors = array_merge($errors, $remResult['errors']);
            }
        } catch (\Throwable $e) {
            Logger::error('Cron manual run: reminders failed', ['error' => $e->getMessage()]);
            $errors[] = 'Reminders failed: ' . $e->getMessage();
        }

        // Update timestamp
        try {
            Database::upsertSetting('cron_last_run', date('Y-m-d H:i:s'));
        } catch (\Throwable) {
            // Non-critical
        }

        // Audit log
        AuditLog::log('system.cron_manual_run', 'system', null, [
            'tasks'  => array_keys($results),
            'errors' => count($errors),
        ]);

        // Flash result
        if (empty($errors)) {
            $taskSummary = [];
            if (isset($results['retention'])) {
                $anon = count($results['retention']['anonymization'] ?? []);
                $audit = $results['retention']['audit_cleanup'] ?? 0;
                $email = $results['retention']['email_cleanup'] ?? 0;
                $rate  = $results['retention']['rate_limit_cleanup'] ?? 0;
                $taskSummary[] = __('admin.cron.run_result_retention', [
                    'anon'  => $anon,
                    'audit' => $audit,
                    'email' => $email,
                    'rate'  => $rate,
                ]);
            }
            if (isset($results['reminders'])) {
                $sent    = $results['reminders']['sent'] ?? 0;
                $skipped = $results['reminders']['skipped'] ?? 0;
                $taskSummary[] = __('admin.cron.run_result_reminders', [
                    'sent'    => $sent,
                    'skipped' => $skipped,
                ]);
            }
            FormState::toast('success', __('admin.cron.run_success') . ' ' . implode(' ', $taskSummary));
        } else {
            FormState::toast('error', __('admin.cron.run_partial', ['errors' => count($errors)]));
        }

        return Response::redirect('/admin/settings/cron');
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

        $result = AuditLog::query(
            limit: $perPage,
            offset: ($page - 1) * $perPage,
            action: $actionFilter !== '' ? $actionFilter : null,
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

    /**
     * Load multiple settings from the settings table.
     *
     * @return array<string, string>
     */
    private function loadSettings(array $keys): array
    {
        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->getSetting($key);
        }
        return $result;
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

    /**
     * Check if a setting key has a row in the database (even if empty).
     *
     * Used by the email settings page to distinguish "never configured"
     * (show .env fallback) from "deliberately cleared" (show empty).
     * Must match the logic in Mailer::loadConfig().
     */
    private function settingExistsInDb(string $key): bool
    {
        try {
            $rows = Database::query(
                'SELECT 1 FROM `settings` WHERE `key` = ? LIMIT 1',
                [$key]
            );
            return !empty($rows);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Persist a setting to the database.
     *
     * @throws \RuntimeException if the database write fails
     */
    private function saveSetting(string $key, string $value): void
    {
        Database::upsertSetting($key, $value);
    }


    // ── Audit Export ──

    /**
     * Export audit log as CSV — operator-only.
     *
     * Excludes the details JSON column to prevent accidental PII leakage.
     * Actor IDs and entity IDs remain (they are ULIDs, not PII).
     */
    public function auditExport(Request $request): Response
    {
        $actionFilter = $request->query('action');

        $entries = AuditLog::queryForExport(
            $actionFilter !== '' ? $actionFilter : null,
        );

        $filename = 'audit-log-export-' . date('Y-m-d') . '.csv';
        $headers = ['Date', 'Action', 'Entity Type', 'Entity ID', 'Actor Type', 'Actor ID', 'IP'];

        $output = fopen('php://temp', 'r+');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers, escape: '\\');

        foreach ($entries as $e) {
            fputcsv($output, [
                $e['created_at'] ?? '',
                $e['action'] ?? '',
                $e['entity_type'] ?? '',
                $e['entity_id'] ?? '',
                $e['actor_type'] ?? '',
                $e['actor_id'] ?? '',
                $e['ip_address'] ?? '',
            ], escape: '\\');
        }

        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);

        $response = new Response();
        return $response
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Cache-Control', 'no-store')
            ->body($csv);
    }

}
