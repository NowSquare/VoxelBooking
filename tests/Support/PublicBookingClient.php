<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Talks to the public booking API the way the booking page does.
 *
 * Each submission opens the booking page for a fresh session and CSRF token,
 * then posts JSON with the anti-spam timestamp and an empty honeypot. Manage
 * actions authenticate with an explicit customer capability header, as an
 * emailed manage link does after verification.
 */
final class PublicBookingClient
{
    public function __construct(private readonly string $baseUrl)
    {
    }

    public static function fromEnvironment(): self
    {
        $baseUrl = rtrim((string) ($_ENV['APP_TEST_URL'] ?? ''), '/');
        if ($baseUrl === '') {
            throw new \RuntimeException('APP_TEST_URL is not configured.');
        }

        return new self($baseUrl);
    }

    /**
     * @return array{code: int, body: string, json: array<string, mixed>}
     */
    public function book(string $slug, array $payload): array
    {
        $cookies = (string) tempnam(sys_get_temp_dir(), 'vb-public-');
        try {
            $page = curl_init($this->baseUrl . '/book/' . $slug);
            curl_setopt_array($page, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_COOKIEJAR => $cookies,
                CURLOPT_COOKIEFILE => $cookies,
                CURLOPT_TIMEOUT => 10,
            ]);
            $html = (string) curl_exec($page);
            // PHP writes the cookie jar when the handle is destroyed.
            unset($page);
            if (!preg_match('/window\.__VB_CSRF__\s*=\s*"([^"]+)"/', $html, $match)) {
                throw new \RuntimeException('The booking page rendered no CSRF token.');
            }

            $payload += [
                'customer_timezone' => 'UTC',
                '__ts' => (string) ((time() - 10) * 1000),
                '__hp' => '',
            ];

            return $this->post('/api/' . $slug . '/bookings', $payload, [
                'X-CSRF-Token: ' . $match[1],
            ], $cookies);
        } finally {
            @unlink($cookies);
        }
    }

    /**
     * @return array{code: int, body: string, json: array<string, mixed>}
     */
    public function manage(string $slug, string $bookingId, string $action, string $capability, array $payload = []): array
    {
        return $this->post('/api/' . $slug . '/bookings/' . $bookingId . '/' . $action, $payload, [
            'X-Customer-Capability: ' . $capability,
        ]);
    }

    /**
     * @return array{code: int, body: string, json: array<string, mixed>}
     */
    private function post(string $path, array $payload, array $headers, ?string $cookies = null): array
    {
        $request = curl_init($this->baseUrl . $path);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', ...$headers],
        ];
        if ($cookies !== null) {
            $options[CURLOPT_COOKIEJAR] = $cookies;
            $options[CURLOPT_COOKIEFILE] = $cookies;
        }
        curl_setopt_array($request, $options);
        $body = (string) curl_exec($request);

        return [
            'code' => (int) curl_getinfo($request, CURLINFO_HTTP_CODE),
            'body' => $body,
            'json' => json_decode($body, true) ?: [],
        ];
    }
}
