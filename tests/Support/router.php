<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';
$manifest = \Tests\Support\TestEnvironment::manifest();
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);

if (str_starts_with($path, '/__test/')) {
    if (!hash_equals($manifest['run_id'], $_SERVER['HTTP_X_TEST_RUN'] ?? '')) {
        http_response_code(403);
        exit;
    }
    if ($path === '/__test/ready') {
        \Tests\Support\TestEnvironment::assertDatabase();
        \App\Engine\Database::query('SELECT 1');
        echo $manifest['run_id'];
        exit;
    }
    if ($path === '/__test/webhook') {
        file_put_contents($manifest['run_root'] . '/webhooks.jsonl', json_encode([
            'headers' => getallheaders(), 'body' => file_get_contents('php://input'),
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        http_response_code(204);
        exit;
    }
    http_response_code(404);
    exit;
}

$publicRoot = realpath($manifest['app_root'] . '/public');
$file = realpath($publicRoot . '/' . ltrim($path, '/'));
if ($file !== false && str_starts_with($file, $publicRoot . '/') && is_file($file) && pathinfo($file, PATHINFO_EXTENSION) !== 'php') {
    return false;
}
require $publicRoot . '/index.php';
