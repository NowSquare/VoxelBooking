<?php

declare(strict_types=1);

namespace App\Controllers\Booking;

use App\Engine\Database;
use App\Engine\BookingService;
use App\Engine\BrandColorHelper;
use App\Engine\DemoMode;
use App\Engine\Locale;
use App\Engine\Request;
use App\Engine\Response;
use App\Middleware\CsrfMiddleware;

/**
 * Public booking page controller.
 *
 * Serves the booking page shell for a tenant.
 * The page is a single-page application: the shell template loads
 * a JSON config blob and the booking.js orchestrator handles all
 * step transitions client-side via the public API.
 */
final class BookingPageController
{
    /**
     * GET /book/{slug} — render the booking page shell.
     */
    public function show(Request $request): Response
    {
        $slug = $request->getAttribute('slug');

        $tenant = Database::query(
            'SELECT * FROM `tenants` WHERE `slug` = ? AND `status` = ? LIMIT 1',
            [$slug, 'active']
        );

        if (empty($tenant)) {
            ob_start();
            require __DIR__ . '/../../../templates/booking/404.php';
            return Response::html(ob_get_clean(), 404);
        }

        $tenant = $tenant[0];

        // Calculate brand tokens
        $brandTokens = BrandColorHelper::derive($tenant['brand_color'] ?? '#2563EB');
        $brandStyle = BrandColorHelper::inlineStyle($tenant['brand_color'] ?? '#2563EB');

        // Resolve locale: override → browser Accept-Language → tenant default → 'en'
        $acceptLang = $request->header('Accept-Language');
        $resolvedLocale = Locale::resolveForBooking($tenant, $acceptLang);

        // Build tenant config for the JavaScript app
        $tenantConfig = [
            'slug'                  => $tenant['slug'],
            'name'                  => $tenant['name'],
            'timezone'              => $tenant['timezone'],
            'locale'                => $resolvedLocale,
            'currency'              => $tenant['currency'],
            'booking_pattern'       => $tenant['booking_pattern'],
            'require_phone'         => (bool) $tenant['require_phone'],
            'requires_consent'      => (bool) $tenant['requires_consent'],
            'consent_text'          => $tenant['consent_text'] ?: __('booking.form.consent_default'),
            'privacy_policy_url'    => $tenant['privacy_policy_url'] ?: null,
            'custom_fields'         => json_decode($tenant['custom_fields'] ?? '[]', true) ?: [],
            'brand_color'           => $tenant['brand_color'],
            'brand_text'            => $brandTokens['brand_text'],
            'show_powered_by'       => (bool) ($tenant['show_powered_by'] ?? true),
            'is_demo'               => DemoMode::isActive(),
            'booking_page_heading'  => $tenant['booking_page_heading'] ?: null,
            'booking_page_description' => $tenant['booking_page_description'] ?: null,
            'confirmation_message'  => $tenant['confirmation_message'] ?: null,
            'cancellation_policy'   => $tenant['cancellation_policy'] ?: null,
            'allow_cancellation'    => (bool) ($tenant['allow_cancellation'] ?? true),
            'allow_rescheduling'    => (bool) ($tenant['allow_rescheduling'] ?? true),
        ];

        // Capacity pattern: inject party size bounds from slot configuration
        if ($tenant['booking_pattern'] === 'capacity') {
            $partyBounds = Database::query(
                'SELECT MIN(`min_party_size`) AS `min_ps`, MAX(`max_party_size`) AS `max_ps` FROM `capacity_slots` WHERE `tenant_id` = ? AND `is_active` = 1',
                [$tenant['id']]
            );
            $tenantConfig['min_party_size'] = (int) ($partyBounds[0]['min_ps'] ?? 1);
            $tenantConfig['max_party_size'] = (int) ($partyBounds[0]['max_ps'] ?? 8);
        }

        // Inject translations and formatting config for JS
        $translations = Locale::getTranslationsForDomain('booking');
        $formatting   = Locale::getFormattingConfig($tenant['currency'] ?? 'EUR');

        // Generate grouped timezones from canonical PHP source
        $timezoneGroups = self::buildTimezoneGroups();

        // Detect embed mode (?embed=1) — renders chromeless booking UI in an iframe
        $isEmbed = ($request->string('embed') === '1');

        // CSRF: embed mode is fully stateless (no session, no cookies), so
        // the page ships an empty token. The booking API accepts the browser's
        // Origin header in its place (CsrfMiddleware::verifyPublicSubmission).
        // Non-embed mode uses a per-session CSRF token as normal.
        $csrfToken = $isEmbed ? '' : CsrfMiddleware::generateToken();

        // Render template to string
        ob_start();
        require __DIR__ . '/../../../templates/booking/page.php';
        $html = ob_get_clean();

        $response = Response::html($html);

        // In embed mode, set frame-ancestors CSP to allow configured domains
        // and prevent the browser from setting cookies (stateless iframe).
        if ($isEmbed) {
            $frameAncestors = "'self'";
            $allowedDomains = $tenant['allowed_embed_domains'] ?? '';
            if ($allowedDomains !== '' && $allowedDomains !== null) {
                $domains = array_map('trim', explode(',', $allowedDomains));
                $frameAncestors .= ' ' . implode(' ', $domains);
            }
            // Full CSP with embed-specific frame-ancestors
            $response->header('Content-Security-Policy',
                "default-src 'self'; script-src 'self' 'unsafe-inline'; "
                . "style-src 'self' 'unsafe-inline'; "
                . "img-src 'self' data:; font-src 'self'; "
                . "connect-src 'self'; frame-ancestors {$frameAncestors}"
            );
        }

        return $response;
    }

    /**
     * GET /book/{slug}/manage/{booking_id} — render the booking manage page.
     *
     * Uses the same page shell as the booking page but injects manage_mode
     * so the Alpine component loads the booking and presents cancel/reschedule UI.
     * Authentication: booking ULID is the bearer token (128-bit entropy).
     */
    public function manage(Request $request): Response
    {
        $slug = $request->getAttribute('slug');
        $bookingId = $request->getAttribute('booking_id');

        $tenant = Database::query(
            'SELECT * FROM `tenants` WHERE `slug` = ? AND `status` = ? LIMIT 1',
            [$slug, 'active']
        );

        if (empty($tenant)) {
            ob_start();
            require __DIR__ . '/../../../templates/booking/404.php';
            return Response::html(ob_get_clean(), 404);
        }

        $tenant = $tenant[0];

        // Verify the booking exists and belongs to this tenant
        $booking = BookingService::findById($bookingId);
        if (!$booking || $booking['tenant_id'] !== $tenant['id']) {
            ob_start();
            require __DIR__ . '/../../../templates/booking/404.php';
            return Response::html(ob_get_clean(), 404);
        }

        // Calculate brand tokens
        $brandTokens = BrandColorHelper::derive($tenant['brand_color'] ?? '#2563EB');
        $brandStyle = BrandColorHelper::inlineStyle($tenant['brand_color'] ?? '#2563EB');

        // Resolve locale
        $acceptLang = $request->header('Accept-Language');
        $resolvedLocale = Locale::resolveForBooking($tenant, $acceptLang);

        // Build tenant config (same as booking page, plus manage flags)
        $tenantConfig = [
            'slug'                  => $tenant['slug'],
            'name'                  => $tenant['name'],
            'timezone'              => $tenant['timezone'],
            'locale'                => $resolvedLocale,
            'currency'              => $tenant['currency'],
            'booking_pattern'       => $tenant['booking_pattern'],
            'require_phone'         => (bool) $tenant['require_phone'],
            'requires_consent'      => (bool) $tenant['requires_consent'],
            'consent_text'          => $tenant['consent_text'] ?: __('booking.form.consent_default'),
            'privacy_policy_url'    => $tenant['privacy_policy_url'] ?: null,
            'custom_fields'         => json_decode($tenant['custom_fields'] ?? '[]', true) ?: [],
            'brand_color'           => $tenant['brand_color'],
            'brand_text'            => $brandTokens['brand_text'],
            'show_powered_by'       => (bool) ($tenant['show_powered_by'] ?? true),
            'is_demo'               => DemoMode::isActive(),
            'booking_page_heading'  => $tenant['booking_page_heading'] ?: null,
            'booking_page_description' => $tenant['booking_page_description'] ?: null,
            'confirmation_message'  => $tenant['confirmation_message'] ?: null,
            'cancellation_policy'   => $tenant['cancellation_policy'] ?: null,
            'allow_cancellation'    => (bool) ($tenant['allow_cancellation'] ?? true),
            'allow_rescheduling'    => (bool) ($tenant['allow_rescheduling'] ?? true),
            // Manage mode flags
            'manage_mode'           => true,
            'manage_booking_id'     => $bookingId,
        ];

        // Inject translations and formatting config for JS
        $translations = Locale::getTranslationsForDomain('booking');
        $formatting   = Locale::getFormattingConfig($tenant['currency'] ?? 'EUR');

        // Generate grouped timezones from canonical PHP source
        $timezoneGroups = self::buildTimezoneGroups();

        // Generate CSRF token
        $csrfToken = CsrfMiddleware::generateToken();

        // Render template to string
        ob_start();
        require __DIR__ . '/../../../templates/booking/page.php';
        $html = ob_get_clean();

        return Response::html($html);
    }

    /**
     * Build timezone groups from PHP's canonical timezone_identifiers_list().
     *
     * Groups IANA timezones by continent, adding UTC as its own group.
     * Label keys match the booking JS translation convention.
     *
     * @return array<int, array{labelKey: string, zones: list<string>}>
     */
    private static function buildTimezoneGroups(): array
    {
        $continentMap = [
            'Africa'     => 'timezone.group_africa',
            'America'    => 'timezone.group_americas',
            'Antarctica' => 'timezone.group_other',
            'Arctic'     => 'timezone.group_other',
            'Asia'       => 'timezone.group_asia',
            'Atlantic'   => 'timezone.group_other',
            'Australia'  => 'timezone.group_asia',
            'Europe'     => 'timezone.group_europe',
            'Indian'     => 'timezone.group_other',
            'Pacific'    => 'timezone.group_asia',
        ];

        $grouped = [];
        foreach (get_supported_timezones() as $tz) {
            $parts = explode('/', $tz, 2);
            $continent = $parts[0] ?? '';
            $labelKey = $continentMap[$continent] ?? 'timezone.group_other';

            if (!isset($grouped[$labelKey])) {
                $grouped[$labelKey] = [];
            }
            $grouped[$labelKey][] = $tz;
        }

        // Ensure UTC is included
        if (!in_array('UTC', $grouped['timezone.group_other'] ?? [], true)) {
            $grouped['timezone.group_other'][] = 'UTC';
        }

        // Sort zones within each group
        foreach ($grouped as &$zones) {
            sort($zones);
        }
        unset($zones);

        // Build ordered output — major continents first, "Other" last
        $order = [
            'timezone.group_americas',
            'timezone.group_europe',
            'timezone.group_asia',
            'timezone.group_africa',
            'timezone.group_other',
        ];

        $result = [];
        foreach ($order as $key) {
            if (!empty($grouped[$key])) {
                $result[] = ['labelKey' => $key, 'zones' => $grouped[$key]];
            }
        }

        return $result;
    }
}
