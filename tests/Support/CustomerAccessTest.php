<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Engine\CustomerCapability;

/** Follow the same fragment-to-session exchange as the customer browser. */
final class CustomerAccessTest
{
    public static function verifyBrowser(string $tenantId, string $slug, string $subjectId, string $purpose, string $cookieJar): string
    {
        $base = rtrim($_ENV['APP_TEST_URL'], '/');
        $path = CustomerCapability::link($slug, $purpose, $subjectId);
        $curl = curl_init($base . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_TIMEOUT => 10]);
        $page = (string) curl_exec($curl);
        if (!preg_match('/data-csrf="([a-f0-9]+)"/', $page, $match)) {
            // A prior exchange in this browser already authorized this subject.
            if (preg_match('/(?:name="_csrf_token"\s+value="|window\.__VB_CSRF__\s*=\s*")([a-f0-9]+)"/', $page, $match)) {
                return $match[1];
            }
            throw new \RuntimeException('Customer access page did not provide a CSRF token.');
        }
        $token = $purpose === CustomerCapability::MANAGE
            ? CustomerCapability::issueManage($tenantId, $subjectId)
            : CustomerCapability::issuePrivacy($tenantId, $subjectId);
        $curl = curl_init($base . '/api/' . rawurlencode($slug) . '/customer-access');
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode(['purpose' => $purpose, 'subject_id' => $subjectId, 'token' => $token], JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-CSRF-Token: ' . $match[1]],
            CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_COOKIEJAR => $cookieJar, CURLOPT_TIMEOUT => 10]);
        $response = (string) curl_exec($curl);
        if (curl_getinfo($curl, CURLINFO_HTTP_CODE) !== 200 || json_decode($response, true)['verified'] !== true) {
            throw new \RuntimeException('Customer access exchange failed: ' . $response);
        }
        return $match[1];
    }

    public static function createSqliteCapabilities(\PDO $pdo): void
    {
        $pdo->exec('CREATE TABLE customer_capabilities (id TEXT, token_hash TEXT, purpose TEXT, tenant_id TEXT, subject_id TEXT, customer_id TEXT, created_at TEXT, expires_at TEXT, revoked_at TEXT)');
    }
}
