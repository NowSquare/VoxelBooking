#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * php scripts/test-isolated.php [--keep] [--serve|--upgrade-check] [--mysql-image=mysql:8.4] [PHPUnit arguments]
 *
 * Every invocation owns its database container, app copy and loopback server.
 * No development configuration, uploaded files or storage are copied.
 */
$source = dirname(__DIR__);
$arguments = array_slice($argv, 1);
$keep = false;
$serve = false;
$upgradeCheck = false;
$image = 'mysql:8.4';
$phpunitArguments = [];
foreach ($arguments as $argument) {
    if ($argument === '--keep') {
        $keep = true;
    } elseif ($argument === '--serve') {
        $serve = true;
    } elseif ($argument === '--upgrade-check') {
        $upgradeCheck = true;
    } elseif (str_starts_with($argument, '--mysql-image=')) {
        $image = substr($argument, strlen('--mysql-image='));
        if (!preg_match('/^(mysql|mariadb):[a-zA-Z0-9._-]+$/D', $image)) {
            throw new RuntimeException('Use an official mysql or mariadb image with an explicit tag.');
        }
    } elseif ($argument === '--help') {
        fwrite(STDOUT, "Usage: php scripts/test-isolated.php [--keep] [--serve|--upgrade-check] [--mysql-image=mysql:8.4] [PHPUnit arguments]\n");
        exit(0);
    } else {
        $phpunitArguments[] = $argument;
    }
}
if ($upgradeCheck && ($serve || $phpunitArguments !== [])) {
    throw new RuntimeException('Upgrade rehearsal runs alone, before the HTTP server and PHPUnit.');
}
if (!extension_loaded('pdo_mysql') || !extension_loaded('pcntl') || !extension_loaded('posix')) {
    fwrite(STDERR, "The isolated runner needs PHP pdo_mysql, pcntl and posix extensions on a POSIX host.\n");
    exit(2);
}

/** @return array{int, string} */
function runCommand(array $command, ?string $cwd = null, ?array $environment = null): array
{
    $process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['redirect', 1]], $pipes, $cwd, $environment);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start command: ' . $command[0]);
    }
    $output = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    return [proc_close($process), (string) $output];
}

function copyTree(string $source, string $destination): void
{
    mkdir($destination, 0700, true);
    foreach (new DirectoryIterator($source) as $entry) {
        if ($entry->isDot() || $entry->isLink()) {
            continue;
        }
        $target = $destination . '/' . $entry->getFilename();
        if ($entry->isDir()) {
            copyTree($entry->getPathname(), $target);
        } else {
            copy($entry->getPathname(), $target);
        }
    }
}

/**
 * Print the first distinct problems the HTTP server and application logged.
 *
 * Access lines, the multi-worker poll notice and informational log entries
 * are routine. Everything else is a warning, fatal or error a failing test
 * could not show by itself.
 */
function reportServerDiagnostics(string ...$logFiles): void
{
    $routine = '/(Accepted$|Closing$| \[\d{3}\]: [A-Z]+ |Development Server .* started|Failed to poll event|\] (DEBUG|INFO): )/';
    foreach ($logFiles as $logFile) {
        if (!is_file($logFile)) {
            continue;
        }
        $seen = [];
        $handle = fopen($logFile, 'r');
        while ($handle !== false && ($line = fgets($handle)) !== false && count($seen) < 40) {
            $line = rtrim($line);
            if ($line === '' || preg_match($routine, $line)) {
                continue;
            }
            // Workers prefix their pid and a timestamp. Repeats differ only there.
            $seen[preg_replace('/^\[\d+\] \[[^\]]+\] /', '', $line)] ??= $line;
        }
        if ($handle !== false) {
            fclose($handle);
        }
        if ($seen !== []) {
            fwrite(STDERR, "\nFirst distinct entries in " . basename($logFile) . ":\n" . implode("\n", $seen) . "\n");
        }
    }
}

function deleteTree(string $path): void
{
    if (!is_dir($path) || is_link($path)) {
        @unlink($path);
        return;
    }
    foreach (new DirectoryIterator($path) as $entry) {
        if (!$entry->isDot()) {
            deleteTree($entry->getPathname());
        }
    }
    rmdir($path);
}

[$dockerCode, $dockerVersion] = runCommand(['docker', 'info', '--format', '{{.ServerVersion}}']);
if ($dockerCode !== 0) {
    fwrite(STDERR, "Docker is required for a disposable MySQL server. No tests ran.\n" . $dockerVersion);
    exit(2);
}
$id = bin2hex(random_bytes(16));
$runRoot = rtrim(sys_get_temp_dir(), '/') . '/voxelbooking-test-' . $id;
$appRoot = $runRoot . '/app';
$container = 'voxelbooking-test-' . $id;
$server = null;
$serverPid = null;
$containerStarted = false;
$cleaned = false;
umask(0077);
mkdir($runRoot, 0700);
file_put_contents($runRoot . '/.test-run', $id);

$cleanup = static function () use (&$cleaned, &$server, &$serverPid, &$containerStarted, $container, $keep, $runRoot): void {
    if ($cleaned) {
        return;
    }
    $cleaned = true;
    if ($serverPid !== null) {
        // The launcher creates its own session. Include every PHP HTTP worker.
        @posix_kill(-$serverPid, SIGTERM);
    }
    if (is_resource($server)) {
        @proc_terminate($server);
        @proc_close($server);
    }
    if ($containerStarted) {
        runCommand(['docker', 'rm', '--force', '--volumes', $container]);
    }
    if (!$keep) {
        deleteTree($runRoot);
    }
};
register_shutdown_function($cleanup);
pcntl_async_signals(true);
pcntl_signal(SIGINT, static function () use ($cleanup): void { $cleanup(); exit(130); });
pcntl_signal(SIGTERM, static function () use ($cleanup): void { $cleanup(); exit(143); });

try {
    mkdir($appRoot, 0700);
    foreach (['app', 'config', 'lang', 'templates', 'resources', 'tests', 'scripts', 'vendor'] as $directory) {
        copyTree($source . '/' . $directory, $appRoot . '/' . $directory);
    }
    mkdir($appRoot . '/public', 0700);
    foreach (new DirectoryIterator($source . '/public') as $entry) {
        if ($entry->isDot() || $entry->isLink() || $entry->getFilename() === 'uploads') {
            continue;
        }
        if ($entry->isDir()) {
            copyTree($entry->getPathname(), $appRoot . '/public/' . $entry->getFilename());
        } else {
            copy($entry->getPathname(), $appRoot . '/public/' . $entry->getFilename());
        }
    }
    foreach (['phpunit.xml', 'composer.json', 'composer.lock', 'README.md', 'VERSION', 'demo-seed.php'] as $file) {
        if (is_file($source . '/' . $file)) {
            copy($source . '/' . $file, $appRoot . '/' . $file);
        }
    }
    foreach (['storage/logs', 'storage/sessions', 'storage/cache', 'public/uploads'] as $directory) {
        mkdir($appRoot . '/' . $directory, 0700, true);
    }

    $database = 'voxelbooking_test_' . $id;
    $password = bin2hex(random_bytes(24));
    $rootPassword = bin2hex(random_bytes(24));
    $dockerEnv = str_starts_with($image, 'mariadb:') ? 'MARIADB' : 'MYSQL';
    file_put_contents($runRoot . '/mysql.env', "{$dockerEnv}_ROOT_PASSWORD={$rootPassword}\n{$dockerEnv}_DATABASE={$database}\n{$dockerEnv}_USER=vb_test\n{$dockerEnv}_PASSWORD={$password}\n");
    [$code, $output] = runCommand([
        'docker', 'run', '--detach', '--name', $container,
        '--label', 'voxelbooking.disposable-test=' . $id,
        '--publish', '127.0.0.1::3306', '--env-file', $runRoot . '/mysql.env',
        $image, '--max-connections=100',
    ]);
    if ($code !== 0) {
        throw new RuntimeException('Disposable MySQL startup failed: ' . $output);
    }
    $containerStarted = true;
    [$code, $binding] = runCommand(['docker', 'port', $container, '3306/tcp']);
    if ($code !== 0 || !preg_match('/^127\.0\.0\.1:(\d+)$/', trim($binding), $matches)) {
        throw new RuntimeException('MySQL must bind only to loopback.');
    }
    $dbPort = $matches[1];
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Could not reserve a loopback HTTP port: ' . $error);
    }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $baseUrl = 'http://' . $address;
    $manifest = [
        'version' => 1, 'run_id' => $id, 'run_root' => $runRoot, 'app_root' => $appRoot,
        'image' => $image, 'container' => $container,
        'database' => ['DB_HOST' => '127.0.0.1', 'DB_PORT' => $dbPort, 'DB_DATABASE' => $database, 'DB_USERNAME' => 'vb_test', 'DB_PASSWORD' => $password],
        'environment' => [
            'APP_ENV' => 'test', 'APP_DEBUG' => 'true', 'APP_TIMEZONE' => 'UTC',
            'APP_TEST_URL' => $baseUrl, 'APP_URL' => $baseUrl, 'FORCE_HTTPS' => 'false',
            'MAIL_TRANSPORT' => 'log', 'MAIL_HOST' => '', 'MAIL_PORT' => '1',
            'MAIL_USERNAME' => '', 'MAIL_PASSWORD' => '', 'MAIL_ENCRYPTION' => '',
            'MAIL_FROM_ADDRESS' => 'test@example.test', 'MAIL_FROM_NAME' => 'VoxelBooking Tests',
        ],
    ];
    file_put_contents($runRoot . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    // Installer tests may rewrite this disposable .env. The immutable manifest
    // remains the authority for test and server processes.
    $environmentLines = [];
    foreach (array_merge($manifest['environment'], $manifest['database']) as $key => $value) {
        $environmentLines[] = $key . '=' . $value;
    }
    file_put_contents($appRoot . '/.env', implode("\n", $environmentLines) . "\n");
    $processEnvironment = array_merge(getenv(), ['VOXELBOOKING_TEST_MANIFEST' => $runRoot . '/manifest.json', 'PHP_CLI_SERVER_WORKERS' => '4']);
    file_put_contents($runRoot . '/run-tests.sh', '#!/bin/sh' . "\n" . 'cd ' . escapeshellarg($appRoot) . "\n" . 'VOXELBOOKING_TEST_MANIFEST=' . escapeshellarg($runRoot . '/manifest.json') . ' ' . escapeshellarg(PHP_BINARY) . ' vendor/bin/phpunit "$@"' . "\n");
    chmod($runRoot . '/run-tests.sh', 0700);

    $deadline = microtime(true) + 90;
    do {
        try {
            $pdo = new PDO("mysql:host=127.0.0.1;port={$dbPort};dbname={$database};charset=utf8mb4", 'vb_test', $password, [PDO::ATTR_TIMEOUT => 1, PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            break;
        } catch (PDOException $exception) {
            if (microtime(true) >= $deadline) {
                throw new RuntimeException('Disposable MySQL did not become ready.', 0, $exception);
            }
            usleep(200000);
        }
    } while (true);
    $version = $pdo->query('SELECT VERSION()')->fetchColumn();
    $pdo = null;
    if ($upgradeCheck) {
        [$code,$output]=runCommand([PHP_BINARY,'scripts/test-upgrade.php'],$appRoot,$processEnvironment);
        fwrite(STDOUT,$output."\nDisposable upgrade rehearsal: ".$runRoot."\n");
        $cleanup();
        exit($code);
    }
    [$code, $output] = runCommand([PHP_BINARY, 'scripts/test-setup.php'], $appRoot, $processEnvironment);
    if ($code !== 0) {
        throw new RuntimeException('Test migrations failed: ' . $output);
    }

    $server = proc_open([
        PHP_BINARY, '-r', 'posix_setsid(); pcntl_exec(PHP_BINARY, array_slice($argv, 1));',
        '-S', $address, '-t', $appRoot . '/public', $appRoot . '/tests/Support/router.php',
    ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $runRoot . '/server.log', 'a'], 2 => ['file', $runRoot . '/server.log', 'a']], $pipes, $appRoot, $processEnvironment);
    if (!is_resource($server)) {
        throw new RuntimeException('Could not start isolated HTTP server.');
    }
    $serverPid = proc_get_status($server)['pid'];
    $deadline = microtime(true) + 10;
    $ready = false;
    do {
        $context = stream_context_create(['http' => ['timeout' => 1, 'header' => 'X-Test-Run: ' . $id]]);
        $body = @file_get_contents($baseUrl . '/__test/ready', false, $context);
        if ($body !== false && hash_equals($id, trim($body))) {
            $ready = true;
            break;
        }
        usleep(100000);
    } while (microtime(true) < $deadline);
    if (!$ready) {
        throw new RuntimeException('The isolated server did not pass its run identity check. Inspect ' . $runRoot . '/server.log');
    }

    fwrite(STDOUT, "Disposable test run: {$runRoot}\nMySQL: {$version}; PHP: " . PHP_VERSION . "\nApplication: {$baseUrl} (4 workers)\nTests: {$runRoot}/run-tests.sh [PHPUnit arguments]\n");
    if ($serve) {
        fwrite(STDOUT, "Serving until interrupted. Each runner invocation uses separate fixtures.\n");
        while (true) {
            sleep(1);
        }
    }
    $testProcess = proc_open([PHP_BINARY, 'vendor/bin/phpunit', ...$phpunitArguments], [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $appRoot, $processEnvironment);
    if (!is_resource($testProcess)) {
        throw new RuntimeException('Could not start PHPUnit.');
    }
    $exitCode = proc_close($testProcess);
    if ($exitCode !== 0) {
        // Cleanup deletes the run root, and CI keeps nothing else. A broken
        // HTTP worker fails hundreds of tests with one cause, so show it.
        reportServerDiagnostics($runRoot . '/server.log', $appRoot . '/storage/logs/app.log');
    }
    $cleanup();
    if ($keep) {
        fwrite(STDOUT, "Retained files: {$runRoot}. The database and server have stopped.\n");
    }
    exit($exitCode);
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    if ($containerStarted) {
        [, $logs] = runCommand(['docker', 'logs', '--tail', '20', $container]);
        fwrite(STDERR, $logs);
    }
    $cleanup();
    exit(2);
}
