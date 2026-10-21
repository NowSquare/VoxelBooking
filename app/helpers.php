<?php

declare(strict_types=1);

/**
 * Global helper functions.
 *
 * Available in all templates and controllers via Composer autoload.
 */

use App\Engine\Database;
use App\Engine\FormState;
use App\Engine\Locale;
use App\Engine\View;

/**
 * Escape HTML output.
 */
function e(mixed $value): string
{
    return View::e($value);
}

/**
 * Get environment variable.
 */
function env(string $key, string $default = ''): string
{
    return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
}

/**
 * Get the application base URL.
 *
 * Host resolution: SERVER_NAME (set by server config, not the request)
 * is the trusted primary source. HTTP_HOST is used only as a fallback
 * and is validated against a strict hostname pattern to prevent Host
 * header poisoning in password-reset and magic-link emails.
 *
 * Scheme resolution: HTTPS server var → X-Forwarded-Proto (only when
 * TRUSTED_PROXIES env is set) → FORCE_HTTPS env → fallback to http.
 */
function app_url(string $path = ''): string
{
    // Explicit public origin supports CLI jobs and reverse proxies without trusting request headers.
    $configured = rtrim($_ENV['APP_URL'] ?? '', '/');
    if ($configured !== '') {
        $parts = parse_url($configured);
        if (is_array($parts) && in_array($parts['scheme'] ?? null, ['http', 'https'], true)
            && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass'])
            && !isset($parts['query']) && !isset($parts['fragment']) && empty($parts['path'])
            && !preg_match('/[\x00-\x20\x7f\\\\]/', $configured)) {
            return $configured . ($path === '' ? '' : '/' . ltrim($path, '/'));
        }
        throw new \RuntimeException('APP_URL must be an HTTP(S) origin.');
    }
    // Scheme: prefer server-level HTTPS flag
    $isHttps = ($_SERVER['HTTPS'] ?? '') === 'on';

    // Only trust X-Forwarded-Proto when behind a configured proxy
    if (!$isHttps && ($_ENV['TRUSTED_PROXIES'] ?? '') !== '') {
        $isHttps = ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    // Manual override for proxies that strip all headers
    if (!$isHttps) {
        $isHttps = ($_ENV['FORCE_HTTPS'] ?? $_SERVER['FORCE_HTTPS'] ?? 'false') === 'true';
    }

    $scheme = $isHttps ? 'https' : 'http';

    // Host: prefer SERVER_NAME (set by server config, not user-controllable)
    // Fall back to HTTP_HOST only after strict validation
    $host = $_SERVER['SERVER_NAME'] ?? '';

    if ($host === '') {
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    }

    // Validate: hostname must be alphanumeric/dots/hyphens with optional port
    if (!preg_match('/^[a-zA-Z0-9]([a-zA-Z0-9\-\.]*[a-zA-Z0-9])?(:\d{1,5})?$/', $host)) {
        $host = 'localhost';
    }
    $serverPort = filter_var($_SERVER['SERVER_PORT'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1,'max_range'=>65535]]);
    if (!str_contains($host, ':') && $serverPort && !in_array($serverPort, [80, 443], true)) {
        $host .= ':' . $serverPort;
    }

    $base = $scheme . '://' . $host;

    if ($path === '') {
        return $base;
    }

    return $base . '/' . ltrim($path, '/');
}

/**
 * Get path to a compiled asset (reads Vite manifest in production).
 */
function asset(string $path): string
{
    return app_url('assets/' . ltrim($path, '/'));
}

// ════════════════════════════════════════════════════════════════
// Form State Helpers
// ════════════════════════════════════════════════════════════════

/**
 * Get an old input value from the previous request's form submission.
 *
 * Use in templates: value="<?= e(old('name', $entity['name'] ?? '')) ?>"
 */
function old(string $key, mixed $default = ''): mixed
{
    return FormState::oldValue($key, $default);
}

/**
 * Check if a form field has a validation error.
 */
function has_error(string $key): bool
{
    return FormState::hasError($key);
}

/**
 * Get the validation error message for a form field.
 */
function field_error(string $key): string
{
    return FormState::fieldError($key);
}

/**
 * Return the error CSS class if a field has an error, empty string otherwise.
 *
 * Use in templates: class="vb-input<?= error_class('email') ?>"
 * Returns ' is-invalid' (with leading space for concatenation).
 */
function error_class(string $key): string
{
    return FormState::hasError($key) ? ' is-invalid' : '';
}

// ════════════════════════════════════════════════════════════════
// Translation & Formatting Helpers
// ════════════════════════════════════════════════════════════════

/**
 * Translate a key with optional replacements.
 *
 * @param string $key     Dot-notation: 'booking.steps.service_title'
 * @param array  $replace Placeholder replacements: ['name' => 'John']
 */
function __(string $key, array $replace = []): string
{
    return Locale::translate($key, $replace);
}

/**
 * Get the text direction for the active locale ('ltr' or 'rtl').
 */
function locale_dir(): string
{
    return Locale::direction();
}

/**
 * Pluralize a translation key based on count.
 */
function __p(string $key, int $count, array $replace = []): string
{
    return Locale::plural($key, $count, $replace);
}

/**
 * Format a number according to the active locale.
 */
function __n(float $value, int $decimals = 0): string
{
    return Locale::number($value, $decimals);
}

/**
 * Format a currency value according to the active locale.
 */
function __c(float $value, string $currency): string
{
    return Locale::currency($value, $currency);
}

/**
 * Format a date (short) according to the active locale.
 */
function __d(\DateTimeInterface $dt): string
{
    return Locale::date($dt);
}

/**
 * Format a date (long) according to the active locale.
 */
function __dl(\DateTimeInterface $dt): string
{
    return Locale::dateLong($dt);
}

/**
 * Format a time according to the active locale.
 */
function __t(\DateTimeInterface $dt): string
{
    return Locale::time($dt);
}

/**
 * Get the configured application name.
 *
 * Reads app_name from the settings table (cached per-request).
 * Falls back to APP_NAME env var, then generic default.
 * Used for white-label support: operators set the name during installation
 * or in Admin → Settings → General.
 */
function app_name(): string
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    try {
        $rows = Database::query(
            "SELECT `value` FROM `settings` WHERE `key` = 'app_name' LIMIT 1"
        );
        if (!empty($rows) && !empty($rows[0]['value'])) {
            $cached = $rows[0]['value'];
            return $cached;
        }
    } catch (\Throwable) {
        // DB unavailable (pre-install, test context) — fall through
    }

    $cached = $_ENV['APP_NAME'] ?? 'Booking System';
    return $cached;
}

/**
 * Get the configured brand/vendor URL.
 *
 * Resolution order: DB setting → BRAND_URL env → default.
 * Used for white-label support: the "Powered by" footer link
 * can point to the operator's own domain instead of voxelbooking.com.
 */
function brand_url(): string
{
    static $cached = null;

    if ($cached !== null) {
        return $cached;
    }

    try {
        $rows = Database::query(
            "SELECT `value` FROM `settings` WHERE `key` = 'brand_url' LIMIT 1"
        );
        if (!empty($rows) && !empty($rows[0]['value'])) {
            $cached = $rows[0]['value'];
            return $cached;
        }
    } catch (\Throwable) {
        // DB unavailable (pre-install, test context) — fall through
    }

    $cached = $_ENV['BRAND_URL'] ?? 'https://voxelbooking.com';
    return $cached;
}

/**
 * Resolve the display label for a booking based on its pattern.
 *
 * Returns the most specific name available: event_name for event bookings,
 * resource_name for resource bookings, service_name for timeslot.
 * Capacity bookings typically have no service/resource/event — falls back
 * to a translated pattern label so shared surfaces never render '—'.
 */
function booking_display_label(array $booking): string
{
    $pattern = $booking['booking_pattern'] ?? 'timeslot';

    return match ($pattern) {
        'event'    => $booking['event_name'] ?? $booking['service_name'] ?? __('admin.bookings.capacity_booking'),
        'resource' => $booking['resource_name'] ?? $booking['service_name'] ?? '—',
        'capacity' => $booking['service_name'] ?? __('admin.bookings.capacity_booking'),
        default    => $booking['service_name'] ?? '—',
    };
}

/**
 * Get all supported currencies with their display labels in a unified array.
 *
 * Labels follow the format: {symbol} {ISO} — {translated name}.
 * The name portion is i18n-backed via admin.currencies.{code}.
 */
function get_supported_currencies(): array
{
    static $currencies = null;
    if ($currencies !== null) {
        return $currencies;
    }

    $registry = require dirname(__DIR__) . '/config/currencies.php';
    $currencies = [];
    foreach ($registry as $code => $symbol) {
        $label = $symbol !== $code ? "{$symbol} {$code}" : $code;
        $currencies[$code] = $label . ' — ' . __("admin.currencies.{$code}");
    }
    return $currencies;
}

/**
 * Get all standard PHP timezones for consistent global availability.
 */
function get_supported_timezones(): array
{
    static $tzs = null;
    if ($tzs === null) {
        $tzs = timezone_identifiers_list();
    }
    return $tzs;
}

/**
 * Formats a raw timezone string ('America/New_York') into a clean display label ('America / New York').
 */
function format_timezone(string $tz): string
{
    if ($tz === 'UTC') return 'UTC';
    return str_replace(['_', '/'], [' ', ' / '], $tz);
}
