<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Centralized i18n engine.
 *
 * Manages the active locale, loads translation files, and provides
 * formatting functions for dates, times, numbers, and currencies.
 * Supports 12 locales: en, nl, de, es, fr, id, it, ja, pt, pl, tr, ar.
 * Arabic (ar) is RTL — direction() returns 'rtl', templates emit dir="rtl".
 *
 * v1 ships with English translations only. All 12 locales have complete
 * formatting rules in the registry (config/locales.php). Setting a tenant
 * locale to any registered locale activates direction and formatting
 * immediately; untranslated UI strings fall back to English.
 *
 * Resolution order (booking + privacy pages — resolveForBooking()):
 *   1. Tenant locale_override (explicit lock, if set)
 *   2. Browser Accept-Language (only if translations exist for that locale)
 *   3. Tenant default locale (always honored for direction + formatting)
 *   4. Fallback: 'en'
 *
 * Resolution order (admin panel — resolveForAdmin()):
 *   1. Tenant locale (in tenant context)
 *   2. APP_LOCALE env var (operator-level pages)
 *   3. Fallback: 'en'
 *
 * Emails inherit the request-scoped locale at send time. All email
 * renderers inject lang and dir attributes based on the active locale.
 *
 * Timezone policy:
 *   - Storage/availability: Tenant timezone (authoritative, never negotiated)
 *   - Display-local time:   Browser timezone (JS-side, via Intl.DateTimeFormat)
 */
final class Locale
{
    private static string $locale = 'en';
    private static string $fallback = 'en';
    private static array $registry = [];
    private static array $translations = [];
    private static string $basePath = '';

    /**
     * Per-request tenant overrides.
     *
     * Supported keys: 'week_start' (int|null), 'time_format' ('12h'|'24h'|null),
     * 'date_format' (string|null), 'number_format' (string|null).
     * NULL means "follow system default → locale config".
     */
    private static array $tenantOverrides = [];

    /**
     * System-level defaults from the settings table.
     *
     * Loaded lazily on first access. Sits between tenant overrides and
     * locale config in the resolution chain.
     */
    private static ?array $systemDefaults = null;
    private static bool $systemDefaultsLoaded = false;

    /**
     * Currency registry from config/currencies.php.
     * Lazy-loaded on first currencySymbol() call.
     * @var array<string, string>|null
     */
    private static ?array $currencyRegistry = null;

    /**
     * Initialize the locale engine.
     *
     * @param string $basePath Application root path
     */
    public static function init(string $basePath): void
    {
        self::$basePath = rtrim($basePath, '/');

        $registryPath = self::$basePath . '/config/locales.php';
        if (is_file($registryPath)) {
            self::$registry = require $registryPath;
        }
    }

    /**
     * Set the active locale.
     */
    public static function setLocale(string $locale): void
    {
        if (isset(self::$registry[$locale])) {
            self::$locale = $locale;
        }
    }

    /**
     * Get the active locale code.
     */
    public static function getLocale(): string
    {
        return self::$locale;
    }

    /**
     * Get the text direction for the active locale ('ltr' or 'rtl').
     *
     * Used by templates to set dir="..." on <html> and by the email
     * renderer for inline RTL styling.
     */
    public static function direction(): string
    {
        $config = self::getConfig();
        return $config['direction'] ?? 'ltr';
    }

    /**
     * Check if the active locale is right-to-left.
     */
    public static function isRtl(): bool
    {
        return self::direction() === 'rtl';
    }

    /**
     * Set per-request tenant overrides for week_start, time_format,
     * date_format, and number_format.
     *
     * Call once per request after loading the tenant row.
     * NULL values = follow locale default.
     *
     * @param array{week_start?: int|null, time_format?: string|null, date_format?: string|null, number_format?: string|null} $overrides
     */
    public static function setTenantOverrides(array $overrides): void
    {
        self::$tenantOverrides = $overrides;
    }

    /**
     * Explicitly set system-level defaults (for testing or manual injection).
     *
     * @param array{date_format?: string|null, number_format?: string|null} $defaults
     */
    public static function setSystemDefaults(array $defaults): void
    {
        self::$systemDefaults = $defaults;
        self::$systemDefaultsLoaded = true;
    }

    /**
     * Lazily load system defaults from the settings table.
     *
     * Called internally by date()/number()/getFormattingConfig() when
     * no tenant override is set and before falling back to locale config.
     */
    private static function loadSystemDefaults(): array
    {
        if (self::$systemDefaultsLoaded) {
            return self::$systemDefaults ?? [];
        }

        self::$systemDefaultsLoaded = true;

        try {
            $rows = Database::query(
                "SELECT `key`, `value` FROM `settings` WHERE `key` IN ('date_format', 'number_format', 'week_start', 'time_format', 'default_locale', 'default_currency')"
            );
            $defaults = [];
            foreach ($rows as $row) {
                if ($row['value'] !== '' && $row['value'] !== null) {
                    $defaults[$row['key']] = $row['value'];
                }
            }
            self::$systemDefaults = $defaults;
            return $defaults;
        } catch (\Throwable) {
            self::$systemDefaults = [];
            return [];
        }
    }

    /**
     * Get the full registry entry for the active locale.
     *
     * @return array<string, mixed>
     */
    public static function getConfig(): array
    {
        return self::$registry[self::$locale] ?? self::$registry[self::$fallback] ?? [];
    }

    /**
     * Get the list of supported locale codes.
     *
     * @return string[]
     */
    public static function supported(): array
    {
        return array_keys(self::$registry);
    }

    /**
     * Check if a locale is supported (registered in config/locales.php).
     */
    public static function isSupported(string $locale): bool
    {
        return isset(self::$registry[$locale]);
    }

    /**
     * Get locale options for admin select fields.
     *
     * Returns all registered locales from config/locales.php. A locale
     * does not need a lang/ translation directory to be selectable —
     * formatting rules (date, time, currency, direction) work from the
     * registry alone, and untranslated UI strings gracefully fall back
     * to the English base.
     *
     * @return array<string, string> Locale code => display label
     */
    public static function localeOptions(): array
    {
        $options = [];

        foreach (self::$registry as $code => $meta) {
            $name = $meta['name'] ?? $code;
            $native = $meta['native_name'] ?? $name;
            $label = $name === $native ? $name : "{$name} ({$native})";
            $options[$code] = $label;
        }

        // Sort alphabetically by label, but keep 'en' first
        uasort($options, fn(string $a, string $b) => strcasecmp($a, $b));
        if (isset($options['en'])) {
            $en = $options['en'];
            unset($options['en']);
            $options = ['en' => $en] + $options;
        }

        return $options ?: ['en' => 'English (English)'];
    }

    /**
     * Check if a locale has translation files (lang/{locale}/ directory exists).
     *
     * A locale can be registered (formatting rules) but not yet translated.
     * The negotiator uses this to avoid resolving to a locale that would
     * produce mixed-language output (English labels + localized dates).
     */
    public static function hasTranslations(string $locale): bool
    {
        if (self::$basePath === '') {
            return $locale === self::$fallback;
        }

        return is_dir(self::$basePath . '/lang/' . $locale);
    }

    // ════════════════════════════════════════════════════════════════
    // Translation
    // ════════════════════════════════════════════════════════════════

    /**
     * Translate a key with optional replacements.
     *
     * Key format: 'domain.section.element' or 'domain.key'
     * The domain maps to a file in lang/{locale}/{domain}.php.
     *
     * Replacements: ['name' => 'John'] replaces :name in the string.
     *
     * Falls back to the fallback locale, then returns the key itself.
     */
    public static function translate(string $key, array $replace = []): string
    {
        $parts = explode('.', $key, 2);
        if (count($parts) < 2) {
            return $key;
        }

        [$domain, $subKey] = $parts;

        // Try active locale
        $value = self::resolveFromFile($domain, $subKey, self::$locale);

        // Fallback to base locale
        if ($value === null && self::$locale !== self::$fallback) {
            $value = self::resolveFromFile($domain, $subKey, self::$fallback);
        }

        // Key not found — return the key itself
        if ($value === null) {
            return $key;
        }

        // Apply replacements
        foreach ($replace as $search => $replacement) {
            $value = str_replace(':' . $search, (string) $replacement, $value);
        }

        return $value;
    }

    /**
     * Pluralize a translation key based on count.
     *
     * Supports ICU-style: '{0} None|{1} One|[2,*] :count items'
     */
    public static function plural(string $key, int $count, array $replace = []): string
    {
        $replace['count'] = (string) $count;
        $raw = self::translate($key, []);

        // If the raw value doesn't contain |, just do replacements
        if (!str_contains($raw, '|')) {
            return self::applyReplacements($raw, $replace);
        }

        $segments = explode('|', $raw);
        foreach ($segments as $segment) {
            $segment = trim($segment);

            // {exact} match: {0}, {1}
            if (preg_match('/^\{(\d+)\}\s*(.*)$/', $segment, $m)) {
                if ($count === (int) $m[1]) {
                    return self::applyReplacements($m[2], $replace);
                }
                continue;
            }

            // [min,max] range: [2,*]
            if (preg_match('/^\[(\d+),(\d+|\*)\]\s*(.*)$/', $segment, $m)) {
                $min = (int) $m[1];
                $max = $m[2] === '*' ? PHP_INT_MAX : (int) $m[2];
                if ($count >= $min && $count <= $max) {
                    return self::applyReplacements($m[3], $replace);
                }
                continue;
            }
        }

        // No match — use last segment as fallback (common: 'other' form)
        $last = trim(end($segments));
        // Strip any prefix like [2,*]
        $last = preg_replace('/^\{?\d+\}?\s*/', '', $last);
        $last = preg_replace('/^\[\d+,\d+\*?\]\s*/', '', $last);

        return self::applyReplacements($last, $replace);
    }

    // ════════════════════════════════════════════════════════════════
    // Number Formatting
    // ════════════════════════════════════════════════════════════════

    /**
     * Number format presets: named keys → [decimal_sep, thousands_sep].
     *
     * Covers the three major global conventions:
     *   - period: 1,234.56 (US, UK, JP, AU)
     *   - comma:  1.234,56 (DE, NL, BR, ID, ES, IT, TR)
     *   - space:  1 234,56 (FR, PL)
     */
    private const NUMBER_FORMAT_PRESETS = [
        'period' => ['.', ','],   // 1,234.56
        'comma'  => [',', '.'],   // 1.234,56
        'space'  => [',', ' '],   // 1 234,56
    ];

    /**
     * Get available number format presets for UI selects.
     *
     * @return array<string, string> preset key => human-readable example
     */
    public static function numberFormatPresets(): array
    {
        return [
            'period' => '1,234.56',
            'comma'  => '1.234,56',
            'space'  => '1 234,56',
        ];
    }

    /**
     * Format a number according to the active locale.
     *
     * Resolution: tenant number_format override → system default → locale config.
     */
    public static function number(float $value, int $decimals = 0): string
    {
        // 1. Tenant override
        $override = self::$tenantOverrides['number_format'] ?? null;
        if ($override !== null && isset(self::NUMBER_FORMAT_PRESETS[$override])) {
            [$dec, $thou] = self::NUMBER_FORMAT_PRESETS[$override];
            return number_format($value, $decimals, $dec, $thou);
        }

        // 2. System default
        $sysDefaults = self::loadSystemDefaults();
        $sysFormat = $sysDefaults['number_format'] ?? null;
        if ($sysFormat !== null && isset(self::NUMBER_FORMAT_PRESETS[$sysFormat])) {
            [$dec, $thou] = self::NUMBER_FORMAT_PRESETS[$sysFormat];
            return number_format($value, $decimals, $dec, $thou);
        }

        // 3. Locale config
        $config = self::getConfig();

        return number_format(
            $value,
            $decimals,
            $config['decimal_sep'] ?? '.',
            $config['thousands_sep'] ?? ','
        );
    }

    /**
     * Format a currency value according to the active locale.
     */
    public static function currency(float $value, string $currencyCode): string
    {
        $config = self::getConfig();
        $symbol = self::currencySymbol($currencyCode);
        $formatted = self::number($value, 2);

        $space = ($config['currency_space'] ?? false) ? ' ' : '';

        if (($config['currency_position'] ?? 'before') === 'before') {
            return $symbol . $space . $formatted;
        }

        return $formatted . $space . $symbol;
    }

    // ════════════════════════════════════════════════════════════════
    // Date/Time Formatting
    // ════════════════════════════════════════════════════════════════

    /**
     * Format date (short): 03/27/2026 or 27-03-2026.
     *
     * Resolution: tenant date_format override → system default → locale config.
     */
    public static function date(\DateTimeInterface $dt): string
    {
        // 1. Tenant override
        $override = self::$tenantOverrides['date_format'] ?? null;
        if ($override !== null && $override !== '') {
            return $dt->format($override);
        }

        // 2. System default
        $sysDefaults = self::loadSystemDefaults();
        $sysFormat = $sysDefaults['date_format'] ?? null;
        if ($sysFormat !== null && $sysFormat !== '') {
            return $dt->format($sysFormat);
        }

        // 3. Locale config
        $config = self::getConfig();

        return $dt->format($config['date_format'] ?? 'Y-m-d');
    }

    /**
     * Format date (long): March 27, 2026 or 27 maart 2026.
     */
    public static function dateLong(\DateTimeInterface $dt): string
    {
        $config = self::getConfig();
        $format = $config['date_format_long'] ?? 'F j, Y';

        // PHP always renders the "F" token as an English month name. Swap each
        // unescaped "F" for a control character that DateTime::format() leaves
        // untouched, then put the translated month name in its place.
        $marker    = "\x1F";
        $formatted = $dt->format(preg_replace('/(?<!\\\\)F/', $marker, $format));

        return str_replace($marker, self::monthNameInDate((int) $dt->format('n')), $formatted);
    }

    /**
     * Format time: 2:30 PM or 14:30.
     */
    public static function time(\DateTimeInterface $dt): string
    {
        $override = self::$tenantOverrides['time_format'] ?? null;

        // System default fallback
        if ($override === null) {
            $sysDefaults = self::loadSystemDefaults();
            $override = $sysDefaults['time_format'] ?? null;
        }

        if ($override === '12h') {
            return $dt->format('g:i A');
        }
        if ($override === '24h') {
            return $dt->format('H:i');
        }

        // Locale default
        $config = self::getConfig();
        return $dt->format($config['time_format'] ?? 'H:i');
    }

    /**
     * Format datetime.
     */
    public static function datetime(\DateTimeInterface $dt): string
    {
        $config = self::getConfig();

        return $dt->format($config['datetime_format'] ?? 'Y-m-d H:i');
    }

    /**
     * Format datetime with seconds (audit-grade precision).
     *
     * Reads datetime_full_format from locale config. Every locale in
     * config/locales.php defines this explicitly to handle 12h vs 24h
     * clocks correctly (e.g. 'g:i:s A' vs 'H:i:s').
     */
    public static function datetimeFull(\DateTimeInterface $dt): string
    {
        $config = self::getConfig();

        return $dt->format($config['datetime_full_format'] ?? 'Y-m-d H:i:s');
    }

    /**
     * Get day name for a day of the week (0=Sunday, 6=Saturday).
     */
    public static function dayName(int $dayOfWeek): string
    {
        $key = 'booking.days.' . $dayOfWeek;
        $value = self::translate($key);

        // Fallback if no translation
        if ($value === $key) {
            $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

            return $days[$dayOfWeek % 7];
        }

        return $value;
    }

    /**
     * Get month name (1-12).
     */
    public static function monthName(int $month): string
    {
        $key = 'booking.months.' . $month;
        $value = self::translate($key);

        // Fallback if no translation
        if ($value === $key) {
            $dt = \DateTimeImmutable::createFromFormat('!m', str_pad((string) $month, 2, '0', STR_PAD_LEFT));

            return $dt ? $dt->format('F') : '';
        }

        return $value;
    }

    /**
     * Get the month name as written inside a date (1-12).
     *
     * Reads booking.months_date.N and falls back to monthName() when the
     * locale does not define it. Some languages (e.g. French) write months in
     * lowercase inside a date but capitalized as a calendar heading.
     */
    public static function monthNameInDate(int $month): string
    {
        $key = 'booking.months_date.' . $month;
        $value = self::translate($key);

        return $value === $key ? self::monthName($month) : $value;
    }

    /**
     * Get the week start day (0=Sunday, 1=Monday).
     */
    public static function weekStart(): int
    {
        $override = self::$tenantOverrides['week_start'] ?? null;
        if ($override !== null) {
            return (int) $override;
        }

        // System default fallback
        $sysDefaults = self::loadSystemDefaults();
        if (isset($sysDefaults['week_start']) && $sysDefaults['week_start'] !== '') {
            return (int) $sysDefaults['week_start'];
        }

        $config = self::getConfig();
        return $config['week_start'] ?? 1;
    }

    // ════════════════════════════════════════════════════════════════
    // Locale Negotiation
    // ════════════════════════════════════════════════════════════════

    /**
     * Negotiate locale from browser Accept-Language header.
     *
     * Returns the best-matching supported locale, or the fallback.
     */
    public static function negotiateFromHeader(?string $acceptLanguage): string
    {
        if ($acceptLanguage === null || $acceptLanguage === '') {
            return self::$fallback;
        }

        // Parse Accept-Language: en-US,en;q=0.9,nl;q=0.8
        $candidates = [];
        foreach (explode(',', $acceptLanguage) as $part) {
            $parts = explode(';', trim($part));
            $tag = strtolower(trim($parts[0]));
            $q = 1.0;
            if (isset($parts[1]) && preg_match('/q=([0-9.]+)/', $parts[1], $m)) {
                $q = (float) $m[1];
            }
            $candidates[$tag] = $q;
        }

        arsort($candidates);

        foreach ($candidates as $tag => $q) {
            // Exact match: nl -> nl (only if translations exist)
            if (self::isSupported($tag) && self::hasTranslations($tag)) {
                return $tag;
            }
            // Language prefix: nl-NL -> nl (only if translations exist)
            $prefix = explode('-', $tag)[0];
            if (self::isSupported($prefix) && self::hasTranslations($prefix)) {
                return $prefix;
            }
        }

        return self::$fallback;
    }

    /**
     * Resolve and set locale for the public booking page.
     *
     * Resolution order:
     *   1. Tenant locale_override (explicit lock from operator settings)
     *   2. Browser Accept-Language (best supported match)
     *   3. Tenant default locale
     *   4. Fallback: 'en'
     *
     * @param array  $tenant          Tenant row (must contain 'locale', may contain 'locale_override')
     * @param string|null $acceptLang Accept-Language header value
     * @return string                 The resolved locale code
     */
    public static function resolveForBooking(array $tenant, ?string $acceptLang): string
    {
        // Apply tenant formatting overrides for date/number/time/week
        self::setTenantOverrides([
            'week_start'    => isset($tenant['week_start']) && $tenant['week_start'] !== null ? (int) $tenant['week_start'] : null,
            'time_format'   => !empty($tenant['time_format']) ? $tenant['time_format'] : null,
            'date_format'   => !empty($tenant['date_format']) ? $tenant['date_format'] : null,
            'number_format' => !empty($tenant['number_format']) ? $tenant['number_format'] : null,
        ]);

        // 1. Explicit operator lock (always honored, even without translations)
        $override = $tenant['locale_override'] ?? '';
        if ($override !== '' && self::isSupported($override)) {
            self::setLocale($override);
            return self::$locale;
        }

        // 2. Browser preference (only if translations exist)
        if ($acceptLang !== null && $acceptLang !== '') {
            $negotiated = self::negotiateFromHeader($acceptLang);
            if ($negotiated !== self::$fallback || str_starts_with(strtolower($acceptLang), 'en')) {
                self::setLocale($negotiated);
                return self::$locale;
            }
        }

        // 3. Tenant default locale (always honored for direction + formatting;
        //    translations fall back to English when no lang/{locale}/ exists)
        $tenantLocale = $tenant['locale'] ?? '';
        if ($tenantLocale !== '' && self::isSupported($tenantLocale)) {
            self::setLocale($tenantLocale);
            return self::$locale;
        }

        // 4. Fallback
        self::setLocale(self::$fallback);
        return self::$locale;
    }

    /**
     * Resolve and set locale for admin pages.
     *
     * When in a tenant context, adopts the tenant's configured locale
     * for direction and formatting (dates, numbers, currency). Translation
     * strings fall back to English when no lang/{locale}/ exists.
     *
     * When no tenant is active (operator-level pages), uses APP_LOCALE
     * from .env or falls back to English.
     *
     * @param array|null $tenant Tenant row, or null for operator-level pages
     */
    public static function resolveForAdmin(?array $tenant): void
    {
        // Tenant context: use the tenant's configured locale
        if ($tenant !== null) {
            $locale = $tenant['locale'] ?? '';
            if ($locale !== '' && self::isSupported($locale)) {
                self::setLocale($locale);
                return;
            }
        }

        // Operator-level / no tenant: use APP_LOCALE or fallback
        $appLocale = $_ENV['APP_LOCALE'] ?? $_SERVER['APP_LOCALE'] ?? '';
        if ($appLocale !== '' && self::isSupported($appLocale)) {
            self::setLocale($appLocale);
            return;
        }

        self::setLocale(self::$fallback);
    }

    // ════════════════════════════════════════════════════════════════
    // JS Payload
    // ════════════════════════════════════════════════════════════════

    /**
     * Get all translations for a domain, for injection into JS.
     *
     * Merges fallback (en) with the active locale so keys missing from
     * a partial translation file still resolve to English.
     *
     * @return array<string, string>
     */
    public static function getTranslationsForDomain(string $domain): array
    {
        // Always load English as the base
        self::loadFile($domain, self::$fallback);
        $fallbackKey = self::$fallback . '.' . $domain;
        $base = self::flattenArray(self::$translations[$fallbackKey] ?? []);

        // If active locale differs, overlay it on top of English
        if (self::$locale !== self::$fallback) {
            self::loadFile($domain, self::$locale);
            $cacheKey = self::$locale . '.' . $domain;
            $overlay = self::flattenArray(self::$translations[$cacheKey] ?? []);
            return array_merge($base, $overlay);
        }

        return $base;
    }

    /**
     * Get formatting config for JS (date/time/number formats, week start).
     *
     * @param string $currencyCode ISO 4217 currency code for symbol resolution
     * @return array<string, mixed>
     */
    public static function getFormattingConfig(string $currencyCode = 'EUR'): array
    {
        $config = self::getConfig();
        $sysDefaults = self::loadSystemDefaults();

        // Resolve date_format: tenant override → system default → locale config
        $dateFormat = self::$tenantOverrides['date_format'] ?? null;
        if ($dateFormat === null || $dateFormat === '') {
            $dateFormat = $sysDefaults['date_format'] ?? null;
        }
        if ($dateFormat === null || $dateFormat === '') {
            $dateFormat = $config['date_format'] ?? 'Y-m-d';
        }

        // Resolve number separators: tenant override → system default → locale config
        $numOverride = self::$tenantOverrides['number_format'] ?? null;
        if ($numOverride === null) {
            $numOverride = $sysDefaults['number_format'] ?? null;
        }
        if ($numOverride !== null && isset(self::NUMBER_FORMAT_PRESETS[$numOverride])) {
            [$decSep, $thousSep] = self::NUMBER_FORMAT_PRESETS[$numOverride];
        } else {
            $decSep = $config['decimal_sep'] ?? '.';
            $thousSep = $config['thousands_sep'] ?? ',';
        }

        // Resolve time_format: tenant override → system default → locale config
        $timeOverride = self::$tenantOverrides['time_format'] ?? null;
        if ($timeOverride === null) {
            $timeOverride = $sysDefaults['time_format'] ?? null;
        }
        $timeFormat = match ($timeOverride) {
            '12h'   => 'g:i A',
            '24h'   => 'H:i',
            default => $config['time_format'] ?? 'H:i',
        };

        return [
            'locale'           => self::$locale,
            'direction'        => self::direction(),
            'intl_locale'      => $config['intl_locale'] ?? 'en-US',
            'week_start'       => self::weekStart(),
            'time_format'      => $timeFormat,
            'date_format'      => $dateFormat,
            'date_format_long' => $config['date_format_long'] ?? 'F j, Y',
            'decimal_sep'      => $decSep,
            'thousands_sep'    => $thousSep,
            'currency_position'=> $config['currency_position'] ?? 'before',
            'currency_space'   => $config['currency_space'] ?? false,
            'currency_symbol'  => self::currencySymbol($currencyCode),
        ];
    }

    // ════════════════════════════════════════════════════════════════
    // Internal
    // ════════════════════════════════════════════════════════════════

    /**
     * Resolve a translation value from a domain file.
     */
    private static function resolveFromFile(string $domain, string $subKey, string $locale): ?string
    {
        self::loadFile($domain, $locale);

        $cacheKey = $locale . '.' . $domain;
        $data = self::$translations[$cacheKey] ?? [];

        // Support nested dot-notation: 'steps.service_title'
        $segments = explode('.', $subKey);
        $current = $data;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return is_string($current) ? $current : null;
    }

    /**
     * Load a translation file into the cache.
     */
    private static function loadFile(string $domain, string $locale): void
    {
        $cacheKey = $locale . '.' . $domain;
        if (isset(self::$translations[$cacheKey])) {
            return;
        }

        $path = self::$basePath . '/lang/' . $locale . '/' . $domain . '.php';

        if (is_file($path)) {
            $data = require $path;
            self::$translations[$cacheKey] = is_array($data) ? $data : [];
        } else {
            self::$translations[$cacheKey] = [];
        }
    }

    /**
     * Flatten a nested array into dot-notation keys.
     *
     * @return array<string, string>
     */
    private static function flattenArray(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $fullKey = $prefix !== '' ? $prefix . '.' . $key : (string) $key;
            if (is_array($value)) {
                $result = array_merge($result, self::flattenArray($value, $fullKey));
            } else {
                $result[$fullKey] = (string) $value;
            }
        }

        return $result;
    }

    /**
     * Apply :placeholder replacements to a string.
     */
    private static function applyReplacements(string $value, array $replace): string
    {
        foreach ($replace as $search => $replacement) {
            $value = str_replace(':' . $search, (string) $replacement, $value);
        }

        return $value;
    }

    /**
     * Get the currency symbol for a given ISO 4217 code.
     */
    private static function currencySymbol(string $code): string
    {
        if (self::$currencyRegistry === null) {
            $path = self::$basePath . '/config/currencies.php';
            self::$currencyRegistry = is_file($path) ? require $path : [];
        }
        return self::$currencyRegistry[strtoupper($code)] ?? $code;
    }

    /**
     * Get all supported currency codes.
     *
     * @return string[]
     */
    public static function supportedCurrencies(): array
    {
        if (self::$currencyRegistry === null) {
            $path = self::$basePath . '/config/currencies.php';
            self::$currencyRegistry = is_file($path) ? require $path : [];
        }
        return array_keys(self::$currencyRegistry);
    }

    /**
     * Reset state (for testing).
     */
    public static function reset(): void
    {
        self::$locale = 'en';
        self::$registry = [];
        self::$translations = [];
        self::$basePath = '';
        self::$tenantOverrides = [];
        self::$currencyRegistry = null;
        self::$systemDefaults = null;
        self::$systemDefaultsLoaded = false;
    }
}
