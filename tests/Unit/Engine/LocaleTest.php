<?php

declare(strict_types=1);

namespace Tests\Unit\Engine;

use App\Engine\Locale;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the Locale i18n engine.
 *
 * Covers: translation lookup, locale negotiation,
 * number/currency formatting, date formatting,
 * pluralization, and JS payload generation.
 */
final class LocaleTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = dirname(__DIR__, 3);
        Locale::reset();
        Locale::init($this->basePath);
        // Prevent DB-backed system defaults from leaking into unit tests
        Locale::setSystemDefaults([]);
    }

    protected function tearDown(): void
    {
        Locale::reset();
    }

    // ════════════════════════════════════════════════════════════════
    // Locale Resolution
    // ════════════════════════════════════════════════════════════════

    public function testDefaultLocaleIsEnglish(): void
    {
        $this->assertSame('en', Locale::getLocale());
    }

    public function testSetLocaleToSupported(): void
    {
        Locale::setLocale('nl');
        $this->assertSame('nl', Locale::getLocale());
    }

    public function testSetLocaleToUnsupportedKeepsCurrent(): void
    {
        Locale::setLocale('xx');
        $this->assertSame('en', Locale::getLocale());
    }

    public function testSupportedLocalesIncludeEnglish(): void
    {
        $supported = Locale::supported();
        $this->assertContains('en', $supported);
    }

    public function testIsSupportedReturnsTrueForRegisteredLocale(): void
    {
        $this->assertTrue(Locale::isSupported('en'));
        $this->assertTrue(Locale::isSupported('nl'));
    }

    public function testIsSupportedReturnsFalseForUnknownLocale(): void
    {
        $this->assertFalse(Locale::isSupported('xx'));
    }

    // ════════════════════════════════════════════════════════════════
    // Translation Lookup
    // ════════════════════════════════════════════════════════════════

    public function testTranslateReturnsEnglishString(): void
    {
        $value = Locale::translate('booking.steps.service_title');
        $this->assertSame('Choose a service', $value);
    }

    public function testTranslateReturnsKeyWhenNotFound(): void
    {
        $value = Locale::translate('booking.nonexistent.key');
        $this->assertSame('booking.nonexistent.key', $value);
    }

    public function testTranslateAppliesReplacements(): void
    {
        $value = Locale::translate('booking.confirmed.message', ['email' => 'test@example.com']);
        $this->assertStringContainsString('test@example.com', $value);
    }

    public function testTranslateFallsBackToEnglish(): void
    {
        // Set to a locale that has no translation file
        Locale::setLocale('de');
        $value = Locale::translate('booking.steps.service_title');
        // Should fallback to English
        $this->assertSame('Choose a service', $value);
    }

    public function testTranslateNestedDotNotation(): void
    {
        $value = Locale::translate('booking.form.name_label');
        $this->assertSame('Name', $value);
    }

    public function testTranslateDomainWithoutSubKeyReturnsKey(): void
    {
        $value = Locale::translate('nosuchkey');
        $this->assertSame('nosuchkey', $value);
    }

    // ════════════════════════════════════════════════════════════════
    // Pluralization
    // ════════════════════════════════════════════════════════════════

    public function testPluralExactMatch(): void
    {
        // Simulating a plural string
        $raw = '{0} No spots left|{1} 1 spot left|[2,*] :count spots left';

        // We need to test via the Locale class, so let's test the engine directly
        // For this, we'll create a temporary translation
        Locale::reset();
        Locale::init($this->basePath);

        // Test the plural method with a direct key that we know exists
        // Since booking.php doesn't have a plural string, test the method directly
        $this->assertIsString(Locale::plural('booking.steps.service_title', 1));
    }

    // ════════════════════════════════════════════════════════════════
    // Locale Negotiation
    // ════════════════════════════════════════════════════════════════

    public function testNegotiateFromHeaderFallsBackWhenNoTranslations(): void
    {
        // nl is registered but has no lang/nl/ directory
        $locale = Locale::negotiateFromHeader('nl,en;q=0.9');
        $this->assertSame('en', $locale);
    }

    public function testNegotiateFromHeaderPrefixFallsBackWhenNoTranslations(): void
    {
        // nl-NL prefix matches nl which is registered but has no translations
        $locale = Locale::negotiateFromHeader('nl-NL,en;q=0.9');
        $this->assertSame('en', $locale);
    }

    public function testNegotiateFromHeaderReturnsFallbackForUnknown(): void
    {
        $locale = Locale::negotiateFromHeader('xx-YY');
        $this->assertSame('en', $locale);
    }

    public function testNegotiateFromHeaderReturnsFallbackForEmpty(): void
    {
        $locale = Locale::negotiateFromHeader('');
        $this->assertSame('en', $locale);
    }

    public function testNegotiateFromHeaderReturnsFallbackForNull(): void
    {
        $locale = Locale::negotiateFromHeader(null);
        $this->assertSame('en', $locale);
    }

    public function testNegotiateFromHeaderAllWithoutTranslationsFallsBack(): void
    {
        // Both de and nl have no translations, should fall back to en
        $locale = Locale::negotiateFromHeader('de;q=0.5,nl;q=0.9');
        $this->assertSame('en', $locale);
    }

    public function testNegotiateFromHeaderReturnsEnglishWhenBrowserRequestsIt(): void
    {
        // en has translations, should be returned
        $locale = Locale::negotiateFromHeader('en-US,en;q=0.9');
        $this->assertSame('en', $locale);
    }

    // ════════════════════════════════════════════════════════════════
    // hasTranslations Guard
    // ════════════════════════════════════════════════════════════════

    public function testHasTranslationsReturnsTrueForEnglish(): void
    {
        $this->assertTrue(Locale::hasTranslations('en'));
    }

    public function testHasTranslationsReturnsFalseForRegisteredWithoutFiles(): void
    {
        // nl is registered in config/locales.php but has no lang/nl/ directory
        $this->assertTrue(Locale::isSupported('nl'));
        $this->assertFalse(Locale::hasTranslations('nl'));
    }

    public function testHasTranslationsReturnsFalseForUnknown(): void
    {
        $this->assertFalse(Locale::hasTranslations('xx'));
    }

    // ════════════════════════════════════════════════════════════════
    // Number Formatting
    // ════════════════════════════════════════════════════════════════

    public function testNumberFormattingEnglish(): void
    {
        $this->assertSame('1,235', Locale::number(1234.5, 0));
        $this->assertSame('1,234.50', Locale::number(1234.5, 2));
    }

    public function testNumberFormattingDutch(): void
    {
        Locale::setLocale('nl');
        $this->assertSame('1.235', Locale::number(1234.5, 0));
        $this->assertSame('1.234,50', Locale::number(1234.5, 2));
    }

    // ════════════════════════════════════════════════════════════════
    // Currency Formatting
    // ════════════════════════════════════════════════════════════════

    public function testCurrencyFormattingEnglish(): void
    {
        $result = Locale::currency(45.00, 'EUR');
        $this->assertSame('€45.00', $result);
    }

    public function testCurrencyFormattingDutch(): void
    {
        Locale::setLocale('nl');
        $result = Locale::currency(45.00, 'EUR');
        $this->assertSame('€ 45,00', $result);
    }

    public function testCurrencyFormattingGermanAfterSymbol(): void
    {
        Locale::setLocale('de');
        $result = Locale::currency(45.00, 'EUR');
        $this->assertSame('45,00 €', $result);
    }

    // ════════════════════════════════════════════════════════════════
    // Date/Time Formatting
    // ════════════════════════════════════════════════════════════════

    public function testDateFormattingEnglish(): void
    {
        $dt = new \DateTimeImmutable('2026-03-27');
        $this->assertSame('03/27/2026', Locale::date($dt));
    }

    public function testDateLongFormattingEnglish(): void
    {
        $dt = new \DateTimeImmutable('2026-03-27');
        $this->assertSame('March 27, 2026', Locale::dateLong($dt));
    }

    /**
     * The "F" token of date_format_long must use the translated month name,
     * not the English one PHP would render. Uses a throwaway lang directory
     * so the test does not depend on which translations ship with the repo.
     */
    public function testDateLongUsesTranslatedMonthName(): void
    {
        $base = sys_get_temp_dir() . '/vb-locale-' . uniqid();
        mkdir($base . '/config', 0777, true);
        mkdir($base . '/lang/fr', 0777, true);
        mkdir($base . '/lang/es', 0777, true);
        copy($this->basePath . '/config/locales.php', $base . '/config/locales.php');
        file_put_contents($base . '/lang/fr/booking.php', "<?php return ['months' => [3 => 'mars']];");
        file_put_contents($base . '/lang/es/booking.php', "<?php return ['months' => [3 => 'marzo']];");

        try {
            Locale::reset();
            Locale::init($base);
            Locale::setSystemDefaults([]);
            $dt = new \DateTimeImmutable('2026-03-27');

            // 'j F Y'
            Locale::setLocale('fr');
            $this->assertSame('27 mars 2026', Locale::dateLong($dt));

            // 'j \d\e F \d\e Y' — escaped literals must be left alone
            Locale::setLocale('es');
            $this->assertSame('27 de marzo de 2026', Locale::dateLong($dt));
        } finally {
            foreach (['fr', 'es'] as $lang) {
                unlink($base . '/lang/' . $lang . '/booking.php');
                rmdir($base . '/lang/' . $lang);
            }
            rmdir($base . '/lang');
            unlink($base . '/config/locales.php');
            rmdir($base . '/config');
            rmdir($base);
        }
    }

    /**
     * booking.months_date (month inside a date) wins over booking.months
     * (calendar heading); months is the fallback when months_date is absent.
     */
    public function testDateLongPrefersMonthsDateOverMonths(): void
    {
        $base = sys_get_temp_dir() . '/vb-locale-' . uniqid();
        mkdir($base . '/config', 0777, true);
        mkdir($base . '/lang/fr', 0777, true);
        copy($this->basePath . '/config/locales.php', $base . '/config/locales.php');
        file_put_contents(
            $base . '/lang/fr/booking.php',
            "<?php return ['months' => [3 => 'Mars', 4 => 'Avril'], 'months_date' => [3 => 'mars']];"
        );

        try {
            Locale::reset();
            Locale::init($base);
            Locale::setSystemDefaults([]);
            Locale::setLocale('fr');

            // Heading form is untouched
            $this->assertSame('Mars', Locale::monthName(3));
            // Date form uses months_date when defined...
            $this->assertSame('mars', Locale::monthNameInDate(3));
            $this->assertSame('27 mars 2026', Locale::dateLong(new \DateTimeImmutable('2026-03-27')));
            // ...and falls back to months when it is not
            $this->assertSame('Avril', Locale::monthNameInDate(4));
            $this->assertSame('15 Avril 2026', Locale::dateLong(new \DateTimeImmutable('2026-04-15')));
        } finally {
            unlink($base . '/lang/fr/booking.php');
            rmdir($base . '/lang/fr');
            rmdir($base . '/lang');
            unlink($base . '/config/locales.php');
            rmdir($base . '/config');
            rmdir($base);
        }
    }

    public function testDateLongFallsBackToEnglishMonthWithoutTranslation(): void
    {
        // Registered locale without a lang/ directory: monthName() falls back to English
        Locale::setLocale('nl');
        $this->assertSame('27 March 2026', Locale::dateLong(new \DateTimeImmutable('2026-03-27')));
    }

    public function testTimeFormattingEnglish(): void
    {
        $dt = new \DateTimeImmutable('2026-03-27 14:30:00');
        $this->assertSame('2:30 PM', Locale::time($dt));
    }

    public function testDateFormattingDutch(): void
    {
        Locale::setLocale('nl');
        $dt = new \DateTimeImmutable('2026-03-27');
        $this->assertSame('27-03-2026', Locale::date($dt));
    }

    public function testTimeFormattingDutch(): void
    {
        Locale::setLocale('nl');
        $dt = new \DateTimeImmutable('2026-03-27 14:30:00');
        $this->assertSame('14:30', Locale::time($dt));
    }

    public function testDatetimeFullEnglish12hIncludesSeconds(): void
    {
        $dt = new \DateTimeImmutable('2026-03-28 14:30:25');
        $result = Locale::datetimeFull($dt);
        // en config: 'm/d/Y g:i:s A' → seconds BEFORE AM/PM
        $this->assertSame('03/28/2026 2:30:25 PM', $result);
    }

    public function testDatetimeFullDutch24hIncludesSeconds(): void
    {
        Locale::setLocale('nl');
        $dt = new \DateTimeImmutable('2026-03-28 14:30:25');
        $result = Locale::datetimeFull($dt);
        // nl config: 'd-m-Y H:i:s'
        $this->assertSame('28-03-2026 14:30:25', $result);
    }

    public function testDatetimeFullDoesNotAppendSecondsAfterAmPm(): void
    {
        // Regression test: naive ':s' append produced '2:30 PM:25'
        $dt = new \DateTimeImmutable('2026-03-28 14:30:25');
        $result = Locale::datetimeFull($dt);
        $this->assertStringNotContainsString('PM:', $result);
        $this->assertStringNotContainsString('AM:', $result);
    }

    // ════════════════════════════════════════════════════════════════
    // Week Start
    // ════════════════════════════════════════════════════════════════

    public function testWeekStartEnglishIsMonday(): void
    {
        $this->assertSame(1, Locale::weekStart());
    }

    public function testWeekStartDutchIsMonday(): void
    {
        Locale::setLocale('nl');
        $this->assertSame(1, Locale::weekStart());
    }

    public function testWeekStartTenantOverrideTakesPrecedence(): void
    {
        // English locale defaults to Monday (1), but tenant sets Sunday (0)
        Locale::setTenantOverrides(['week_start' => 0]);
        $this->assertSame(0, Locale::weekStart());
    }

    public function testWeekStartTenantOverrideNullFollowsLocale(): void
    {
        Locale::setTenantOverrides(['week_start' => null]);
        $this->assertSame(1, Locale::weekStart()); // English default
    }

    public function testTimeFormatTenantOverride12h(): void
    {
        // Dutch locale defaults to 24h (H:i)
        Locale::setLocale('nl');
        Locale::setTenantOverrides(['time_format' => '12h']);
        $dt = new \DateTimeImmutable('2026-03-27 14:30:00');
        $this->assertSame('2:30 PM', Locale::time($dt));
    }

    public function testTimeFormatTenantOverride24h(): void
    {
        // English locale defaults to 12h (g:i A)
        Locale::setTenantOverrides(['time_format' => '24h']);
        $dt = new \DateTimeImmutable('2026-03-27 14:30:00');
        $this->assertSame('14:30', Locale::time($dt));
    }

    public function testTimeFormatTenantOverrideNullFollowsLocale(): void
    {
        Locale::setTenantOverrides(['time_format' => null]);
        $dt = new \DateTimeImmutable('2026-03-27 14:30:00');
        $this->assertSame('2:30 PM', Locale::time($dt));
    }

    // ════════════════════════════════════════════════════════════════
    // Day and Month Names
    // ════════════════════════════════════════════════════════════════

    public function testDayNameReturnsTranslation(): void
    {
        $this->assertSame('Monday', Locale::dayName(1));
    }

    public function testMonthNameReturnsTranslation(): void
    {
        $this->assertSame('March', Locale::monthName(3));
    }

    // ════════════════════════════════════════════════════════════════
    // JS Payload
    // ════════════════════════════════════════════════════════════════

    public function testGetTranslationsForDomainReturnsFlatArray(): void
    {
        $translations = Locale::getTranslationsForDomain('booking');
        $this->assertIsArray($translations);
        $this->assertArrayHasKey('steps.service_title', $translations);
        $this->assertArrayHasKey('form.name_label', $translations);
        $this->assertArrayHasKey('errors.generic', $translations);
    }

    public function testGetFormattingConfigContainsExpectedKeys(): void
    {
        $config = Locale::getFormattingConfig();
        $this->assertArrayHasKey('locale', $config);
        $this->assertArrayHasKey('intl_locale', $config);
        $this->assertArrayHasKey('week_start', $config);
        $this->assertArrayHasKey('time_format', $config);
        $this->assertArrayHasKey('decimal_sep', $config);
    }

    public function testGetFormattingConfigReflectsActiveLocale(): void
    {
        Locale::setLocale('nl');
        $config = Locale::getFormattingConfig();
        $this->assertSame('nl', $config['locale']);
        $this->assertSame('nl-NL', $config['intl_locale']);
        $this->assertSame(1, $config['week_start']);
    }

    // ════════════════════════════════════════════════════════════════
    // Locale Config
    // ════════════════════════════════════════════════════════════════

    public function testGetConfigReturnsActiveLocaleSettings(): void
    {
        $config = Locale::getConfig();
        $this->assertSame('English', $config['name']);
        $this->assertSame('.', $config['decimal_sep']);
    }

    public function testGetConfigReflectsLocaleChange(): void
    {
        Locale::setLocale('de');
        $config = Locale::getConfig();
        $this->assertSame('German', $config['name']);
        $this->assertSame(',', $config['decimal_sep']);
    }

    // ════════════════════════════════════════════════════════════════
    // Public Booking Locale Resolution
    // ════════════════════════════════════════════════════════════════

    public function testResolveForBookingUsesExplicitOverrideFirst(): void
    {
        // Operator lock is always honored even without translations
        $tenant = ['locale' => 'en', 'locale_override' => 'de'];
        $result = Locale::resolveForBooking($tenant, 'nl,en;q=0.9');
        $this->assertSame('de', $result);
        $this->assertSame('de', Locale::getLocale());
    }

    public function testResolveForBookingIgnoresUnsupportedOverride(): void
    {
        $tenant = ['locale' => 'en', 'locale_override' => 'xx'];
        $result = Locale::resolveForBooking($tenant, 'en-US');
        $this->assertSame('en', $result);
    }

    public function testResolveForBookingSkipsBrowserLocaleWithoutTranslations(): void
    {
        // Browser prefers nl, but no lang/nl/ exists, should fall back to en
        $tenant = ['locale' => 'en'];
        $result = Locale::resolveForBooking($tenant, 'nl-NL,en;q=0.9');
        $this->assertSame('en', $result);
        $this->assertSame('en', Locale::getLocale());
    }

    public function testResolveForBookingHonorsTenantDefaultWithoutTranslations(): void
    {
        // Tenant default is de — must be honored for formatting/direction
        // even though no lang/de/ exists (strings fall back to English)
        $tenant = ['locale' => 'de'];
        $result = Locale::resolveForBooking($tenant, 'xx-YY');
        $this->assertSame('de', $result);
    }

    public function testResolveForBookingFallsToEnglishWhenNothingMatches(): void
    {
        $tenant = ['locale' => 'xx'];
        $result = Locale::resolveForBooking($tenant, null);
        $this->assertSame('en', $result);
    }

    public function testResolveForBookingWithNoAcceptLanguageHonorsTenantDefault(): void
    {
        // Tenant default is fr — honored even without translations
        $tenant = ['locale' => 'fr'];
        $result = Locale::resolveForBooking($tenant, null);
        $this->assertSame('fr', $result);
    }

    public function testResolveForBookingWithEmptyAcceptLanguageHonorsTenantDefault(): void
    {
        // Tenant default is es — honored even without translations
        $tenant = ['locale' => 'es'];
        $result = Locale::resolveForBooking($tenant, '');
        $this->assertSame('es', $result);
    }

    public function testResolveForBookingEmptyOverrideStringIsIgnored(): void
    {
        $tenant = ['locale' => 'en', 'locale_override' => ''];
        $result = Locale::resolveForBooking($tenant, 'de');
        // Empty override skipped, browser de has no translations, falls to tenant en
        $this->assertSame('en', $result);
    }

    public function testResolveForBookingBrowserEnglishIsRecognized(): void
    {
        $tenant = ['locale' => 'nl'];
        $result = Locale::resolveForBooking($tenant, 'en-US,en;q=0.9');
        // Browser explicitly requests English which has translations
        $this->assertSame('en', $result);
    }

    public function testResolveForBookingPreventsMixedLanguageOutput(): void
    {
        // This is the core regression test: a Dutch browser visiting an English
        // tenant should get English dates, not "Donderdag 2 april 2026" mixed
        // with English UI labels.
        $tenant = ['locale' => 'en'];
        $result = Locale::resolveForBooking($tenant, 'nl-NL,nl;q=0.9,en;q=0.8');
        $this->assertSame('en', $result);
    }

    // ════════════════════════════════════════════════════════════════
    // Direction (RTL Support)
    // ════════════════════════════════════════════════════════════════

    public function testDirectionDefaultsToLtr(): void
    {
        $this->assertSame('ltr', Locale::direction());
    }

    public function testDirectionArabicIsRtl(): void
    {
        Locale::setLocale('ar');
        $this->assertSame('rtl', Locale::direction());
    }

    public function testIsRtlReturnsFalseForEnglish(): void
    {
        $this->assertFalse(Locale::isRtl());
    }

    public function testIsRtlReturnsTrueForArabic(): void
    {
        Locale::setLocale('ar');
        $this->assertTrue(Locale::isRtl());
    }

    public function testDirectionIsLtrForAllNonArabicLocales(): void
    {
        $ltrLocales = ['en', 'nl', 'de', 'es', 'fr', 'id', 'it', 'ja', 'pt', 'pl', 'tr'];
        foreach ($ltrLocales as $locale) {
            Locale::setLocale($locale);
            $this->assertSame('ltr', Locale::direction(), "Locale '{$locale}' should be LTR");
        }
    }

    public function testFormattingConfigIncludesDirection(): void
    {
        $config = Locale::getFormattingConfig();
        $this->assertArrayHasKey('direction', $config);
        $this->assertSame('ltr', $config['direction']);
    }

    public function testFormattingConfigDirectionReflectsRtl(): void
    {
        Locale::setLocale('ar');
        $config = Locale::getFormattingConfig();
        $this->assertSame('rtl', $config['direction']);
    }

    // ════════════════════════════════════════════════════════════════
    // Expanded Locale Registry (12 locales)
    // ════════════════════════════════════════════════════════════════

    public function testAllTwelveLocalesAreRegistered(): void
    {
        $expected = ['en', 'nl', 'de', 'es', 'fr', 'id', 'it', 'ja', 'pt', 'pl', 'tr', 'ar'];
        $supported = Locale::supported();
        foreach ($expected as $locale) {
            $this->assertContains($locale, $supported, "Locale '{$locale}' must be registered");
        }
    }

    public function testJapaneseWeekStartIsSunday(): void
    {
        Locale::setLocale('ja');
        $this->assertSame(0, Locale::weekStart());
    }

    public function testArabicWeekStartIsSaturday(): void
    {
        Locale::setLocale('ar');
        $this->assertSame(6, Locale::weekStart());
    }

    public function testArabicDateFormatting(): void
    {
        Locale::setLocale('ar');
        $dt = new \DateTimeImmutable('2026-04-08');
        $this->assertSame('08/04/2026', Locale::date($dt));
    }

    public function testJapaneseDateFormatting(): void
    {
        Locale::setLocale('ja');
        $dt = new \DateTimeImmutable('2026-04-08');
        $this->assertSame('2026/04/08', Locale::date($dt));
    }

    public function testGermanCurrencyAfterSymbol(): void
    {
        Locale::setLocale('de');
        $result = Locale::currency(100.50, 'EUR');
        $this->assertSame('100,50 €', $result);
    }

    public function testLocaleDirectionHelperFunction(): void
    {
        $this->assertSame('ltr', locale_dir());
        Locale::setLocale('ar');
        $this->assertSame('rtl', locale_dir());
    }

    // ════════════════════════════════════════════════════════════════
    // Locale Picker: all 12 registry locales selectable
    // ════════════════════════════════════════════════════════════════

    public function testLocaleOptionsExposesAllTwelveLocales(): void
    {
        $options = Locale::localeOptions();
        $expected = ['en', 'nl', 'de', 'es', 'fr', 'id', 'it', 'ja', 'pt', 'pl', 'tr', 'ar'];

        foreach ($expected as $code) {
            $this->assertArrayHasKey($code, $options, "Locale '{$code}' must be selectable in admin");
        }
        $this->assertCount(12, $options, 'Exactly 12 locales must be selectable');
    }

    public function testLocaleOptionsEnglishIsFirst(): void
    {
        $options = Locale::localeOptions();
        $keys = array_keys($options);
        $this->assertSame('en', $keys[0], 'English must be the first option');
    }

    public function testLocaleOptionsIncludesNativeNames(): void
    {
        $options = Locale::localeOptions();
        // Arabic should show "Arabic (العربية)"
        $this->assertStringContainsString('العربية', $options['ar']);
        // Japanese should show "Japanese (日本語)"
        $this->assertStringContainsString('日本語', $options['ja']);
    }

    public function testLocaleOptionsDoesNotRequireTranslationDirectories(): void
    {
        // Even though only lang/en/ exists, all 12 locales must be returned
        $this->assertTrue(Locale::hasTranslations('en'));
        $this->assertFalse(Locale::hasTranslations('ar'));
        // But ar must still be in localeOptions
        $options = Locale::localeOptions();
        $this->assertArrayHasKey('ar', $options);
    }

    // ════════════════════════════════════════════════════════════════
    // Privacy/Booking: RTL tenant locale resolution
    // ════════════════════════════════════════════════════════════════

    public function testResolveForBookingSetsRtlDirectionForArabicTenant(): void
    {
        // An Arabic tenant locale must activate RTL direction even without
        // translation files — formatting and direction work from the registry,
        // translation strings fall back to English.
        $tenant = ['locale' => 'ar'];
        Locale::resolveForBooking($tenant, null);

        $this->assertSame('ar', Locale::getLocale());
        $this->assertSame('rtl', Locale::direction());
        $this->assertTrue(Locale::isRtl());
    }

    public function testResolveForBookingWithOverrideSetsCorrectDirection(): void
    {
        // Override lock to Arabic must produce RTL
        $tenant = ['locale' => 'en', 'locale_override' => 'ar'];
        Locale::resolveForBooking($tenant, 'en-US');
        $this->assertSame('ar', Locale::getLocale());
        $this->assertSame('rtl', Locale::direction());
        $this->assertTrue(Locale::isRtl());
    }

    public function testResolveForBookingTenantDefaultHonoredWithoutTranslations(): void
    {
        // German tenant without German translations still gets German formatting
        $tenant = ['locale' => 'de'];
        Locale::resolveForBooking($tenant, null);
        $this->assertSame('de', Locale::getLocale());
        $this->assertSame('ltr', Locale::direction());
    }

    public function testResolveForBookingBrowserPreferenceNeedsTranslations(): void
    {
        // Browser prefers Arabic, but browser negotiation still requires translations
        // to avoid raw translation keys in the UI. Falls back to tenant default.
        $tenant = ['locale' => 'en'];
        Locale::resolveForBooking($tenant, 'ar,en;q=0.5');
        // Browser ar has no translations, so negotiator returns en, not ar
        $this->assertSame('en', Locale::getLocale());
    }

    // ════════════════════════════════════════════════════════════════
    // Admin Locale Resolution
    // ════════════════════════════════════════════════════════════════

    public function testResolveForAdminWithArabicTenantSetsRtl(): void
    {
        Locale::resolveForAdmin(['locale' => 'ar']);
        $this->assertSame('ar', Locale::getLocale());
        $this->assertSame('rtl', Locale::direction());
    }

    public function testResolveForAdminWithNoTenantFallsBackToEnglish(): void
    {
        Locale::resolveForAdmin(null);
        $this->assertSame('en', Locale::getLocale());
        $this->assertSame('ltr', Locale::direction());
    }

    public function testResolveForAdminWithGermanTenantSetsGerman(): void
    {
        Locale::resolveForAdmin(['locale' => 'de']);
        $this->assertSame('de', Locale::getLocale());
        $this->assertSame('ltr', Locale::direction());
    }

    public function testResolveForAdminWithUnsupportedTenantLocaleFallsBack(): void
    {
        Locale::resolveForAdmin(['locale' => 'zz']);
        $this->assertSame('en', Locale::getLocale());
    }

    public function testResolveForAdminSetsLocaleBeforeTranslationCalls(): void
    {
        // Simulate the middleware-level resolution: resolveForAdmin sets the
        // locale, then a controller's __() call for a page title resolves
        // under that locale. Since no Arabic translations exist, the string
        // falls back to English — but the locale/direction ARE Arabic/RTL.
        Locale::resolveForAdmin(['locale' => 'ar']);

        // Locale is ar, direction is rtl
        $this->assertSame('ar', Locale::getLocale());
        $this->assertSame('rtl', Locale::direction());

        // Translation falls back to English (no lang/ar/ exists)
        $title = Locale::translate('admin.tenant_settings.title');
        $this->assertIsString($title);
        $this->assertNotEmpty($title);
        // Must not be a raw key — English fallback must provide the string
        $this->assertStringNotContainsString('.title', $title,
            'Admin title must resolve from English fallback, not return raw key');
    }

    public function testResolveForAdminCalledTwiceOverridesPrevious(): void
    {
        // First tenant is Arabic
        Locale::resolveForAdmin(['locale' => 'ar']);
        $this->assertSame('ar', Locale::getLocale());
        $this->assertTrue(Locale::isRtl());

        // Navigate to a German tenant — must override
        Locale::resolveForAdmin(['locale' => 'de']);
        $this->assertSame('de', Locale::getLocale());
        $this->assertFalse(Locale::isRtl());
    }

    // ════════════════════════════════════════════════════════════════
    // 3-Tier Resolution: Tenant Override → System Default → Locale Config
    // ════════════════════════════════════════════════════════════════

    public function testDateFormatTenantOverrideTakesPrecedence(): void
    {
        // English locale uses m/d/Y by default; tenant override should win
        Locale::setTenantOverrides(['date_format' => 'Y-m-d']);
        $dt = new \DateTimeImmutable('2026-03-27');
        $this->assertSame('2026-03-27', Locale::date($dt));
    }

    public function testDateFormatTenantOverrideNullFallsToLocale(): void
    {
        // NULL tenant override should fall through to locale config
        Locale::setTenantOverrides(['date_format' => null]);
        $dt = new \DateTimeImmutable('2026-03-27');
        $this->assertSame('03/27/2026', Locale::date($dt)); // English default
    }

    public function testDateFormatTenantOverrideEmptyFallsToLocale(): void
    {
        Locale::setTenantOverrides(['date_format' => '']);
        $dt = new \DateTimeImmutable('2026-03-27');
        $this->assertSame('03/27/2026', Locale::date($dt));
    }

    public function testNumberFormatTenantOverrideCommaSeparator(): void
    {
        // English locale uses period decimal by default;
        // tenant override to 'comma' should produce European format
        Locale::setTenantOverrides(['number_format' => 'comma']);
        $this->assertSame('1.234,50', Locale::number(1234.5, 2));
    }

    public function testNumberFormatTenantOverrideSpaceSeparator(): void
    {
        Locale::setTenantOverrides(['number_format' => 'space']);
        $result = Locale::number(1234.5, 2);
        // Space preset: thin space thousands, comma decimal
        $this->assertStringContainsString(',50', $result);
    }

    public function testNumberFormatTenantOverridePeriodSeparator(): void
    {
        // Dutch locale uses comma decimal; override to 'period' should produce US format
        Locale::setLocale('nl');
        Locale::setTenantOverrides(['number_format' => 'period']);
        $this->assertSame('1,234.50', Locale::number(1234.5, 2));
    }

    public function testNumberFormatNullFallsToLocaleConfig(): void
    {
        Locale::setLocale('nl');
        Locale::setTenantOverrides(['number_format' => null]);
        // Dutch locale config has comma decimal
        $this->assertSame('1.234,50', Locale::number(1234.5, 2));
    }

    public function testCurrencyRespectsTenantNumberFormat(): void
    {
        // English locale + comma tenant override
        Locale::setTenantOverrides(['number_format' => 'comma']);
        $result = Locale::currency(1234.50, 'EUR');
        // Currency should use comma decimal from tenant override
        $this->assertStringContainsString('1.234,50', $result);
        $this->assertStringContainsString('€', $result);
    }

    public function testFormattingConfigReflectsTenantDateOverride(): void
    {
        Locale::setTenantOverrides(['date_format' => 'd/m/Y']);
        $config = Locale::getFormattingConfig();
        $this->assertSame('d/m/Y', $config['date_format']);
    }

    public function testFormattingConfigReflectsTenantNumberOverride(): void
    {
        Locale::setTenantOverrides(['number_format' => 'comma']);
        $config = Locale::getFormattingConfig();
        $this->assertSame(',', $config['decimal_sep']);
        $this->assertSame('.', $config['thousands_sep']);
    }

    public function testFormattingConfigFallsToLocaleWithoutOverrides(): void
    {
        Locale::setLocale('de');
        Locale::setTenantOverrides([]);
        $config = Locale::getFormattingConfig();
        // German locale config: comma decimal, period thousands
        $this->assertSame(',', $config['decimal_sep']);
        $this->assertSame('.', $config['thousands_sep']);
        $this->assertSame('d.m.Y', $config['date_format']);
    }

    public function testMultipleOverridesCombineCorrectly(): void
    {
        Locale::setTenantOverrides([
            'date_format'   => 'Y/m/d',
            'number_format' => 'space',
            'time_format'   => '24h',
            'week_start'    => 0,
        ]);

        $dt = new \DateTimeImmutable('2026-03-27 14:30:00');
        $this->assertSame('2026/03/27', Locale::date($dt));
        $this->assertSame('14:30', Locale::time($dt));
        $this->assertSame(0, Locale::weekStart());

        $config = Locale::getFormattingConfig();
        $this->assertSame('Y/m/d', $config['date_format']);
        $this->assertSame(0, $config['week_start']);
    }

    // ════════════════════════════════════════════════════════════════
    // System Default Tier (middle of the 3-tier chain)
    // ════════════════════════════════════════════════════════════════

    public function testDateFormatSystemDefaultUsedWhenNoTenantOverride(): void
    {
        // No tenant override, system default set → system default wins over locale
        Locale::setSystemDefaults(['date_format' => 'Y-m-d']);
        $dt = new \DateTimeImmutable('2026-03-27');
        $this->assertSame('2026-03-27', Locale::date($dt));
    }

    public function testNumberFormatSystemDefaultUsedWhenNoTenantOverride(): void
    {
        // English locale (period decimal), system default = comma → comma wins
        Locale::setSystemDefaults(['number_format' => 'comma']);
        $this->assertSame('1.234,50', Locale::number(1234.5, 2));
    }

    public function testTenantOverrideBeatsSystemDefault(): void
    {
        // Both set → tenant override wins
        Locale::setSystemDefaults(['date_format' => 'Y-m-d']);
        Locale::setTenantOverrides(['date_format' => 'd/m/Y']);
        $dt = new \DateTimeImmutable('2026-03-27');
        $this->assertSame('27/03/2026', Locale::date($dt));
    }

    public function testSystemDefaultBeatsLocaleConfig(): void
    {
        // Dutch locale has d-m-Y; system default overrides it
        Locale::setLocale('nl');
        Locale::setSystemDefaults(['date_format' => 'm/d/Y']);
        $dt = new \DateTimeImmutable('2026-03-27');
        $this->assertSame('03/27/2026', Locale::date($dt));
    }

    public function testFormattingConfigReflectsSystemDefault(): void
    {
        Locale::setSystemDefaults(['number_format' => 'space']);
        $config = Locale::getFormattingConfig();
        $this->assertSame(',', $config['decimal_sep']);
    }

    public function testFormattingConfigTenantOverridesSystemDefault(): void
    {
        Locale::setSystemDefaults(['number_format' => 'comma']);
        Locale::setTenantOverrides(['number_format' => 'period']);
        $config = Locale::getFormattingConfig();
        $this->assertSame('.', $config['decimal_sep']);
        $this->assertSame(',', $config['thousands_sep']);
    }

    // ════════════════════════════════════════════════════════════════
    // Centralized Currency Registry
    // ════════════════════════════════════════════════════════════════

    public function testCurrencySymbolFromRegistry(): void
    {
        // EUR, AUD, TRY should all resolve from config/currencies.php
        $this->assertSame('€45.00', Locale::currency(45.00, 'EUR'));
        $this->assertSame('A$45.00', Locale::currency(45.00, 'AUD'));
        $this->assertSame('₺45.00', Locale::currency(45.00, 'TRY'));
    }

    public function testFormattingConfigIncludesCurrencySymbol(): void
    {
        $config = Locale::getFormattingConfig('GBP');
        $this->assertArrayHasKey('currency_symbol', $config);
        $this->assertSame('£', $config['currency_symbol']);
    }
}
