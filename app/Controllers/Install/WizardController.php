<?php

declare(strict_types=1);

namespace App\Controllers\Install;

use App\Engine\Database;
use App\Engine\EnvWriter;
use App\Engine\FormState;
use App\Engine\Locale;
use App\Engine\Migrator;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Ulid;
use App\Engine\Validator;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;
use PDO;

/**
 * 5-step installation wizard controller.
 *
 * Step 1: System requirements (read-only)
 * Step 2: Database configuration (writes .env, runs migrations)
 * Step 3: Email configuration (optional, skippable)
 * Step 4: Operator account creation
 * Step 5: First tenant creation (optional, skippable)
 * Complete: writes installed_at, shows success
 */
final class WizardController
{
    public function show(Request $request): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $step = $request->query('step', '1');

        if ($step === 'complete') {
            return View::response('install.wizard', [
                'step' => 'complete',
                'checks' => [],
                'errors' => [],
                'flash' => (($_toast = FormState::getToast()) !== null) ? [$_toast] : [],
                'session' => $_SESSION['install'] ?? [],
                'csrfToken' => CsrfMiddleware::generateToken(),
            ]);
        }

        $step = max(1, min(5, (int) $step));

        $data = [
            'step' => $step,
            'checks' => $this->runSystemChecks(),
            'errors' => [],
            'flash' => (($_toast = FormState::getToast()) !== null) ? [$_toast] : [],
            'session' => $_SESSION['install'] ?? [],
            'csrfToken' => CsrfMiddleware::generateToken(),
        ];

        return View::response('install.wizard', $data);
    }

    public function stepTwo(Request $request): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $errors = Validator::validate($request->all(), [
            'db_host'     => 'required',
            'db_port'     => 'required|integer',
            'db_database' => 'required',
            'db_username' => 'required',
        ]);

        // Preserve submitted values for re-populating the form on error
        $formValues = [
            'db_host'     => $request->string('db_host', 'localhost'),
            'db_port'     => $request->string('db_port', '3306'),
            'db_database' => $request->string('db_database', ''),
            'db_username' => $request->string('db_username', ''),
        ];
        $_SESSION['install']['form_db'] = $formValues;

        if (!empty($errors)) {
            return View::response('install.wizard', [
                'step' => 2,
                'checks' => $this->runSystemChecks(),
                'errors' => $errors,
                'flash' => [],
                'session' => $_SESSION['install'] ?? [],
                'csrfToken' => CsrfMiddleware::generateToken(),
            ]);
        }

        $host = $request->string('db_host');
        $port = $request->string('db_port');
        $database = $request->string('db_database');
        $username = $request->string('db_username');
        $password = $request->string('db_password');
        $reconnectAction = $request->string('_reconnect_action');
        $confirmRefresh = $request->string('confirm_refresh');

        // Force TCP/IP when host is 'localhost'.
        // PHP's PDO MySQL driver treats 'localhost' as a Unix socket connection,
        // ignoring the port entirely. This causes "No such file or directory"
        // on environments with non-standard socket paths (Herd, MAMP, Docker).
        // Using 127.0.0.1 forces TCP/IP, which respects the port setting.
        $dsnHost = ($host === 'localhost') ? '127.0.0.1' : $host;

        // Test MySQL connection
        try {
            $dsn = "mysql:host={$dsnHost};port={$port};dbname={$database};charset=utf8mb4";
            $testPdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_TIMEOUT => 5,
            ]);

            $version = $testPdo->query('SELECT VERSION()')->fetchColumn();
            $testPdo = null;
        } catch (\PDOException $e) {
            $errorMsg = $this->translateDbError($e);

            return View::response('install.wizard', [
                'step' => 2,
                'checks' => $this->runSystemChecks(),
                'errors' => ['db_connection' => $errorMsg],
                'flash' => [],
                'session' => $_SESSION['install'] ?? [],
                'csrfToken' => CsrfMiddleware::generateToken(),
            ]);
        }

        // Set up DB connection with provided credentials (before writing .env)
        $_ENV['DB_HOST'] = $dsnHost;
        $_ENV['DB_PORT'] = $port;
        $_ENV['DB_DATABASE'] = $database;
        $_ENV['DB_USERNAME'] = $username;
        $_ENV['DB_PASSWORD'] = $password;
        Database::reset();

        // ── Reconnect detection ──
        // Check for existing installation BEFORE writing .env.
        // If installed_at exists, show a choice screen instead of proceeding.
        $hasInstalledAt = false;
        try {
            $installed = Database::query(
                "SELECT `value` FROM `settings` WHERE `key` = 'installed_at' LIMIT 1"
            );
            $hasInstalledAt = !empty($installed[0]['value'] ?? '');
        } catch (\Throwable) {
            // settings table doesn't exist — fresh install
        }

        $basePath = dirname(__DIR__, 3);
        $envPath = $basePath . '/.env';
        $isSecure = $request->isSecure();

        // Helper: write .env with current credentials
        $writeEnv = function () use ($envPath, $isSecure, $dsnHost, $port, $database, $username, $password): void {
            EnvWriter::setMultiple($envPath, [
                'APP_NAME'      => $_ENV['APP_NAME'] ?? 'VoxelBooking',
                'APP_DEBUG'     => 'false',
                'APP_TIMEZONE'  => 'UTC',
                'FORCE_HTTPS'   => $isSecure ? 'true' : 'false',
                'DB_CONNECTION' => 'mysql',
                'DB_HOST'       => $dsnHost,
                'DB_PORT'       => $port,
                'DB_DATABASE'   => $database,
                'DB_USERNAME'   => $username,
                'DB_PASSWORD'   => $password,
            ]);
        };

        if ($hasInstalledAt && $reconnectAction === '') {
            // First visit with existing DB: show the reconnect choice screen.
            // .env is NOT written yet — middleware won't block install routes.
            return View::response('install.wizard', [
                'step' => '2-reconnect',
                'checks' => [],
                'errors' => [],
                'flash' => [],
                'session' => $_SESSION['install'] ?? [],
                'csrfToken' => CsrfMiddleware::generateToken(),
                'mysqlVersion' => $version,
                'dbCredentials' => [
                    'db_host' => $host,
                    'db_port' => $port,
                    'db_database' => $database,
                    'db_username' => $username,
                    'db_password' => $password,
                ],
            ]);
        }

        if ($hasInstalledAt && $reconnectAction === 'keep') {
            // Use existing data: write .env and go to login
            $writeEnv();
            FormState::toast('success', __('install.flash.reconnected_keep'));
            return Response::redirect('/admin/login');
        }

        if ($hasInstalledAt && $reconnectAction === 'refresh') {
            // Server-side guard: require checkbox confirmation before destructive action
            if (empty($confirmRefresh)) {
                return View::response('install.wizard', [
                    'step' => '2-reconnect',
                    'checks' => [],
                    'errors' => ['confirm_refresh' => __('install.flash.refresh_confirm_required')],
                    'flash' => [],
                    'session' => $_SESSION['install'] ?? [],
                    'csrfToken' => CsrfMiddleware::generateToken(),
                    'mysqlVersion' => $version,
                    'dbCredentials' => [
                        'db_host' => $host,
                        'db_port' => $port,
                        'db_database' => $database,
                        'db_username' => $username,
                        'db_password' => $password,
                    ],
                ]);
            }

            // Fresh install: drop all tables and re-run migrations
            try {
                $tables = Database::query(
                    'SELECT table_name AS tbl FROM information_schema.tables WHERE table_schema = ?',
                    [$database]
                );
                Database::execute('SET FOREIGN_KEY_CHECKS = 0');
                foreach ($tables as $row) {
                    $tbl = $row['tbl'] ?? '';
                    if ($tbl !== '') {
                        Database::execute('DROP TABLE IF EXISTS `' . $tbl . '`');
                    }
                }
                Database::execute('SET FOREIGN_KEY_CHECKS = 1');
            } catch (\Throwable $e) {
                return View::response('install.wizard', [
                    'step' => 2,
                    'checks' => $this->runSystemChecks(),
                    'errors' => ['db_migration' => str_replace(':error', $e->getMessage(), __('install.flash.migration_failed'))],
                    'flash' => [],
                    'session' => $_SESSION['install'] ?? [],
                    'csrfToken' => CsrfMiddleware::generateToken(),
                ]);
            }
        }

        // Write .env (fresh install or after refresh)
        $writeEnv();

        // Run migrations
        try {
            $migrator = new Migrator($basePath . '/app/Migrations');
            $count = $migrator->migrate();

            $_SESSION['install']['db_configured'] = true;
            $_SESSION['install']['db_version'] = $migrator->getCurrentVersion();
            $_SESSION['install']['mysql_version'] = $version;
            $_SESSION['install']['migrations_run'] = $count;

            if ($reconnectAction === 'refresh') {
                FormState::toast('success', str_replace([':version', ':count'], [$version, $count], __('install.flash.db_refreshed')));
            } elseif ($count > 0) {
                FormState::toast('success', str_replace([':version', ':count'], [$version, $count], __('install.flash.db_connected')));
            } else {
                FormState::toast('success', str_replace(':version', $version, __('install.flash.db_connected_existing')));
            }

            return Response::redirect('/install?step=3');
        } catch (\Throwable $e) {
            return View::response('install.wizard', [
                'step' => 2,
                'checks' => $this->runSystemChecks(),
                'errors' => ['db_migration' => str_replace(':error', $e->getMessage(), __('install.flash.migration_failed'))],
                'flash' => [],
                'session' => $_SESSION['install'] ?? [],
                'csrfToken' => CsrfMiddleware::generateToken(),
            ]);
        }
    }

    public function stepThree(Request $request): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $skip = $request->string('skip');

        if ($skip === '1') {
            // Default to log transport so email is always functional (logged, not lost)
            $this->setSetting('mail_transport', 'log');
            $_SESSION['install']['mail_configured'] = false;
            FormState::toast('info', __('install.flash.email_skipped'));

            return Response::redirect('/install?step=4');
        }

        $transport = $request->string('mail_transport', 'smtp');

        // Validate based on transport type
        if ($transport === 'smtp') {
            // SMTP: need host, port, from fields
            $errors = Validator::validate($request->all(), [
                'mail_host'         => 'required',
                'mail_port'         => 'required|integer',
                'mail_from_address' => 'required|email',
                'mail_from_name'    => 'required|max_length:255',
            ]);
        } elseif ($transport === 'resend') {
            // Resend: need API key and from fields
            $errors = Validator::validate($request->all(), [
                'mail_password'     => 'required',
                'mail_from_address' => 'required|email',
                'mail_from_name'    => 'required|max_length:255',
            ]);
        } else {
            // Log and Mailpit: only from fields (Mailer hardcodes host/port/auth)
            $errors = Validator::validate($request->all(), [
                'mail_from_address' => 'required|email',
                'mail_from_name'    => 'required|max_length:255',
            ]);
        }

        if (!empty($errors)) {
            return View::response('install.wizard', [
                'step' => 3,
                'checks' => [],
                'errors' => $errors,
                'flash' => [],
                'session' => $_SESSION['install'] ?? [],
                'csrfToken' => CsrfMiddleware::generateToken(),
                'mailTransport' => $transport,
                'mailForm' => [
                    'mail_from_address' => $request->string('mail_from_address'),
                    'mail_from_name'    => $request->string('mail_from_name'),
                    'mail_host'         => $request->string('mail_host'),
                    'mail_port'         => $request->string('mail_port'),
                    'mail_username'     => $request->string('mail_username'),
                    'mail_encryption'   => $request->string('mail_encryption', 'tls'),
                ],
            ]);
        }

        // Store mail settings in the database
        $mailSettings = [
            'mail_transport'    => $transport,
            'mail_from_address' => $request->string('mail_from_address'),
            'mail_from_name'    => $request->string('mail_from_name'),
        ];

        // Store transport-specific credentials
        if ($transport === 'smtp') {
            $mailSettings['smtp_host']       = $request->string('mail_host');
            $mailSettings['smtp_port']       = $request->string('mail_port');
            $mailSettings['smtp_username']   = $request->string('mail_username');
            $mailSettings['smtp_password']   = $request->string('mail_password');
            $mailSettings['smtp_encryption'] = $request->string('mail_encryption', 'tls');
        } elseif ($transport === 'resend') {
            // Resend: API key stored as smtp_password (used by Mailer::sendViaResendApi)
            $mailSettings['smtp_password']   = $request->string('mail_password');
        }

        foreach ($mailSettings as $key => $value) {
            $this->setSetting($key, $value);
        }

        $_SESSION['install']['mail_configured'] = true;
        FormState::toast('success', __('install.flash.email_saved'));

        return Response::redirect('/install?step=4');
    }

    public function stepFour(Request $request): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $errors = Validator::validate($request->all(), [
            'name'     => 'required|max_length:255',
            'email'    => 'required|email',
            'password' => 'required|min:8',
        ]);

        $password = $request->string('password');
        $confirm = $request->string('password_confirmation');

        if ($password !== $confirm) {
            $errors['password_confirmation'] = __('install.flash.passwords_mismatch');
        }

        if (!empty($errors)) {
            // Preserve submitted regional values so the form re-renders with them
            $_SESSION['install']['timezone']      = $request->string('timezone', 'UTC');
            $_SESSION['install']['locale']        = $request->string('locale', 'en');
            $_SESSION['install']['default_currency'] = $request->string('default_currency', 'EUR');
            $_SESSION['install']['date_format']   = $request->string('date_format', 'Y-m-d');
            $_SESSION['install']['number_format'] = $request->string('number_format', 'period');
            $_SESSION['install']['time_format']   = $request->string('time_format', '24h');
            $_SESSION['install']['week_start']    = $request->string('week_start', '1');

            return View::response('install.wizard', [
                'step' => 4,
                'checks' => [],
                'errors' => $errors,
                'flash' => [],
                'session' => $_SESSION['install'] ?? [],
                'csrfToken' => CsrfMiddleware::generateToken(),
            ]);
        }

        $operatorId = Ulid::generate();
        $passwordHash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        Database::execute(
            'INSERT INTO `operators` (`id`, `name`, `email`, `password_hash`) VALUES (?, ?, ?, ?)',
            [$operatorId, $request->string('name'), $request->string('email'), $passwordHash]
        );

        // Register in the global email registry for passwordless login
        Database::execute(
            "INSERT INTO `auth_emails` (`email`, `user_type`, `user_id`) VALUES (?, 'operator', ?)",
            [$request->string('email'), $operatorId]
        );

        // Store global settings
        $timezone = $request->string('timezone', 'UTC') ?: 'UTC';
        if (!in_array($timezone, get_supported_timezones(), true)) {
            $timezone = 'UTC';
        }
        $locale = $request->string('locale', 'en') ?: 'en';
        if (!Locale::isSupported($locale)) {
            $locale = 'en';
        }
        $dateFormat = $request->string('date_format', 'Y-m-d') ?: 'Y-m-d';
        if (!in_array($dateFormat, ['Y-m-d', 'd/m/Y', 'm/d/Y', 'd-m-Y', 'd.m.Y'], true)) {
            $dateFormat = 'Y-m-d';
        }
        $numberFormat = $request->string('number_format', 'period') ?: 'period';
        if (!in_array($numberFormat, ['period', 'comma', 'space'], true)) {
            $numberFormat = 'period';
        }
        $timeFormat = $request->string('time_format', '24h') ?: '24h';
        if (!in_array($timeFormat, ['12h', '24h'], true)) {
            $timeFormat = '24h';
        }
        $weekStart = $request->string('week_start', '1');
        if (!in_array($weekStart, ['0', '1', '6'], true)) {
            $weekStart = '1';
        }
        $defaultCurrency = strtoupper(trim($request->string('default_currency', 'EUR'))) ?: 'EUR';
        if (!array_key_exists($defaultCurrency, get_supported_currencies())) {
            $defaultCurrency = 'EUR';
        }

        $this->setSetting('operator_email', $request->string('email'));
        $defaultName = $_ENV['APP_NAME'] ?? 'VoxelBooking';
        $this->setSetting('app_name', $request->string('app_name', $defaultName) ?: $defaultName);
        $this->setSetting('timezone', $timezone);
        $this->setSetting('default_timezone', $timezone);
        $this->setSetting('default_locale', $locale);
        $this->setSetting('default_currency', $defaultCurrency);
        $this->setSetting('date_format', $dateFormat);
        $this->setSetting('number_format', $numberFormat);
        $this->setSetting('time_format', $timeFormat);
        $this->setSetting('week_start', $weekStart);
        $this->setSetting('cron_secret', bin2hex(random_bytes(32)));
        $this->setSetting('version', Version::get());

        $_SESSION['install']['operator_created'] = true;
        $_SESSION['install']['operator_id'] = $operatorId;
        $_SESSION['install']['operator_email'] = $request->string('email');
        $_SESSION['install']['timezone'] = $timezone;
        $_SESSION['install']['default_currency'] = $defaultCurrency;
        $_SESSION['install']['default_locale'] = $locale;
        $_SESSION['install']['date_format'] = $dateFormat;
        $_SESSION['install']['number_format'] = $numberFormat;
        $_SESSION['install']['time_format'] = $timeFormat;
        $_SESSION['install']['week_start'] = $weekStart;

        FormState::toast('success', __('install.flash.operator_created'));

        return Response::redirect('/install?step=5');
    }

    public function stepFive(Request $request): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $skip = $request->string('skip');

        if ($skip === '1') {
            return $this->finishInstallation();
        }

        $errors = Validator::validate($request->all(), [
            'name'            => 'required|max_length:255',
            'email'           => 'required|email',
            'booking_pattern' => 'required|in:timeslot,resource,capacity,event',
            'brand_color'     => 'required|max_length:7',
        ]);

        if (!empty($errors)) {
            return View::response('install.wizard', [
                'step' => 5,
                'checks' => [],
                'errors' => $errors,
                'flash' => [],
                'session' => $_SESSION['install'] ?? [],
                'csrfToken' => CsrfMiddleware::generateToken(),
            ]);
        }

        $tenantId = Ulid::generate();
        $slug = $this->generateSlug($request->string('name'));
        $brandColor = $request->string('brand_color', '#2563EB');

        Database::execute(
            "INSERT INTO `tenants` (`id`, `slug`, `name`, `email`, `booking_pattern`, `brand_color`, `brand_color_text`, `timezone`, `currency`, `locale`, `date_format`, `number_format`, `time_format`, `week_start`)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $tenantId,
                $slug,
                $request->string('name'),
                $request->string('email'),
                $request->string('booking_pattern'),
                $brandColor,
                $this->contrastColor($brandColor),
                $_SESSION['install']['timezone'] ?? 'UTC',
                $_SESSION['install']['default_currency'] ?? 'EUR',
                $_SESSION['install']['default_locale'] ?? 'en',
                $_SESSION['install']['date_format'] ?? 'Y-m-d',
                $_SESSION['install']['number_format'] ?? 'period',
                $_SESSION['install']['time_format'] ?? '24h',
                $_SESSION['install']['week_start'] ?? '1',
            ]
        );

        // Create upload directory
        $basePath = dirname(__DIR__, 3);
        $uploadDir = $basePath . '/public/uploads/' . $slug;
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }

        $_SESSION['install']['tenant_created'] = true;
        $_SESSION['install']['tenant_slug'] = $slug;
        $_SESSION['install']['tenant_name'] = $request->string('name');

        return $this->finishInstallation();
    }

    public function complete(Request $request): Response
    {
        return $this->finishInstallation();
    }

    // ── Private helpers ──

    private function finishInstallation(): Response
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }

        $this->setSetting('installed_at', date('Y-m-d H:i:s'));

        FormState::toast('success', __('install.flash.install_complete'));

        return Response::redirect('/admin/login');
    }

    /**
     * @return array<int, array{name: string, required: bool, passed: bool, message: string}>
     */
    private function runSystemChecks(): array
    {
        $basePath = dirname(__DIR__, 3);

        return [
            [
                'name'     => __('install.checks.php_version'),
                'required' => true,
                'passed'   => version_compare(PHP_VERSION, '8.3.0', '>='),
                'message'  => version_compare(PHP_VERSION, '8.3.0', '>=')
                    ? str_replace(':version', PHP_VERSION, __('install.checks.php_ok'))
                    : str_replace(':version', PHP_VERSION, __('install.checks.php_fail')),
            ],
            [
                'name'     => __('install.checks.pdo_mysql'),
                'required' => true,
                'passed'   => extension_loaded('pdo_mysql'),
                'message'  => extension_loaded('pdo_mysql')
                    ? __('install.checks.loaded')
                    : str_replace(':ext', 'pdo_mysql', __('install.checks.enable_ext')),
            ],
            [
                'name'     => __('install.checks.curl'),
                'required' => true,
                'passed'   => extension_loaded('curl'),
                'message'  => extension_loaded('curl')
                    ? __('install.checks.loaded')
                    : str_replace(':ext', 'curl', __('install.checks.enable_ext')),
            ],
            [
                'name'     => __('install.checks.mbstring'),
                'required' => true,
                'passed'   => extension_loaded('mbstring'),
                'message'  => extension_loaded('mbstring')
                    ? __('install.checks.loaded')
                    : str_replace(':ext', 'mbstring', __('install.checks.enable_ext')),
            ],
            [
                'name'     => __('install.checks.json'),
                'required' => true,
                'passed'   => extension_loaded('json'),
                'message'  => extension_loaded('json')
                    ? __('install.checks.loaded')
                    : str_replace(':ext', 'json', __('install.checks.enable_ext')),
            ],
            [
                'name'     => __('install.checks.fileinfo'),
                'required' => true,
                'passed'   => extension_loaded('fileinfo'),
                'message'  => extension_loaded('fileinfo')
                    ? __('install.checks.loaded')
                    : str_replace(':ext', 'fileinfo', __('install.checks.enable_ext')),
            ],
            [
                'name'     => __('install.checks.openssl'),
                'required' => true,
                'passed'   => extension_loaded('openssl'),
                'message'  => extension_loaded('openssl')
                    ? __('install.checks.loaded')
                    : str_replace(':ext', 'openssl', __('install.checks.enable_ext')),
            ],
            [
                'name'     => __('install.checks.gd'),
                'required' => true,
                'passed'   => extension_loaded('gd'),
                'message'  => extension_loaded('gd')
                    ? __('install.checks.loaded')
                    : str_replace(':ext', 'gd', __('install.checks.enable_ext')),
            ],
            [
                'name'     => __('install.checks.zip'),
                'required' => true,
                'passed'   => extension_loaded('zip'),
                'message'  => extension_loaded('zip')
                    ? __('install.checks.loaded')
                    : str_replace(':ext', 'zip', __('install.checks.enable_ext')),
            ],
            [
                'name'     => __('install.checks.storage_logs'),
                'required' => true,
                'passed'   => is_writable($basePath . '/storage/logs'),
                'message'  => is_writable($basePath . '/storage/logs')
                    ? __('install.checks.writable')
                    : str_replace(':path', 'storage/logs', __('install.checks.chmod')),
            ],
            [
                'name'     => __('install.checks.public_uploads'),
                'required' => true,
                'passed'   => is_writable($basePath . '/public/uploads'),
                'message'  => is_writable($basePath . '/public/uploads')
                    ? __('install.checks.writable')
                    : str_replace(':path', 'public/uploads', __('install.checks.chmod')),
            ],
        ];
    }

    private function translateDbError(\PDOException $e): string
    {
        $code = (int) $e->getCode();
        $msg = $e->getMessage();

        if (str_contains($msg, 'Access denied')) {
            return __('install.db_errors.access_denied');
        }

        if (str_contains($msg, 'Unknown database')) {
            return __('install.db_errors.unknown_database');
        }

        if (str_contains($msg, 'Connection refused')) {
            return __('install.db_errors.connection_refused');
        }

        // Unix socket not found — common on Herd, MAMP, Docker, custom builds.
        // The raw MySQL error "No such file or directory" is misleading.
        if (str_contains($msg, 'No such file or directory')) {
            return __('install.db_errors.socket_not_found');
        }

        if (str_contains($msg, 'timed out') || str_contains($msg, 'timeout')) {
            return __('install.db_errors.timed_out');
        }

        return str_replace(':message', $msg, __('install.db_errors.generic'));
    }

    private function setSetting(string $key, string $value): void
    {
        Database::upsertSetting($key, $value);
    }

    private function generateSlug(string $name): string
    {
        $slug = mb_strtolower($name);
        $slug = preg_replace('/[^a-z0-9\s-]/', '', $slug) ?? $slug;
        $slug = preg_replace('/[\s-]+/', '-', $slug) ?? $slug;
        $slug = trim($slug, '-');

        if ($slug === '') {
            $slug = 'business';
        }

        // Ensure uniqueness
        $baseSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug)) {
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    private function slugExists(string $slug): bool
    {
        $result = Database::query(
            'SELECT COUNT(*) as cnt FROM `tenants` WHERE `slug` = ?',
            [$slug]
        );

        return ($result[0]['cnt'] ?? 0) > 0;
    }

    /**
     * Returns white or dark text color based on background luminance.
     * Simplified contrast calculation for installation wizard.
     */
    private function contrastColor(string $hex): string
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) !== 6) {
            return '#FFFFFF';
        }

        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        // sRGB to linear
        $r = ($r <= 0.03928) ? $r / 12.92 : (($r + 0.055) / 1.055) ** 2.4;
        $g = ($g <= 0.03928) ? $g / 12.92 : (($g + 0.055) / 1.055) ** 2.4;
        $b = ($b <= 0.03928) ? $b / 12.92 : (($b + 0.055) / 1.055) ** 2.4;

        $luminance = 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;

        return $luminance > 0.179 ? '#1A1A2E' : '#FFFFFF';
    }
}
