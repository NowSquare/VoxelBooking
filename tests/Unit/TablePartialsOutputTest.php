<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Output tests for shared table partials.
 *
 * Renders table-pagination.php and table-result-count.php with controlled
 * variables and asserts the correct HTML output.
 */
final class TablePartialsOutputTest extends TestCase
{
    private string $partialsDir;
    private static bool $helpersReady = false;

    public static function setUpBeforeClass(): void
    {
        $root = dirname(__DIR__, 2);
        require_once $root . '/vendor/autoload.php';

        if (file_exists($root . '/.env')) {
            \Tests\Support\TestEnvironment::boot();
        }

        // Initialize locale so __() and __p() resolve translation keys
        if (class_exists(\App\Engine\Locale::class)) {
            \App\Engine\Locale::init($root);
            \App\Engine\Locale::setLocale('en');
        }

        self::$helpersReady = function_exists('__') && function_exists('__p');
    }

    protected function setUp(): void
    {
        if (!self::$helpersReady) {
            $this->markTestSkipped('Translation/app helpers not available');
        }

        $this->partialsDir = dirname(__DIR__, 2) . '/templates/partials';
    }

    private function renderPartial(string $partial, array $vars): string
    {
        extract($vars);
        ob_start();
        include $this->partialsDir . '/' . $partial;
        return ob_get_clean();
    }

    // ── table-pagination.php ──────────────────────────────────────────

    public function test_pagination_renders_nothing_for_single_page(): void
    {
        $html = $this->renderPartial('table-pagination.php', [
            'paginationPage' => 1,
            'paginationTotalPages' => 1,
            'paginationBaseUrl' => '/admin/bookings',
            'paginationParams' => '',
            'paginationI18nPrefix' => 'admin.bookings',
        ]);
        $this->assertEmpty(trim($html), 'Pagination must render nothing for single page');
    }

    public function test_pagination_renders_controls_for_multi_page(): void
    {
        $html = $this->renderPartial('table-pagination.php', [
            'paginationPage' => 2,
            'paginationTotalPages' => 5,
            'paginationBaseUrl' => '/admin/bookings',
            'paginationParams' => '&status=confirmed',
            'paginationI18nPrefix' => 'admin.bookings',
        ]);
        $this->assertStringContainsString('vb-pagination', $html, 'Must render vb-pagination wrapper');
        $this->assertStringContainsString('vb-pagination-info', $html, 'Must render page info span');
        $this->assertStringContainsString('Page 2 of 5', $html, 'Must show current page info');
        $this->assertStringContainsString('page=1', $html, 'Must have prev link to page 1');
        $this->assertStringContainsString('page=3', $html, 'Must have next link to page 3');
        $this->assertStringContainsString('status=confirmed', $html, 'Must preserve filter params in links');
        $this->assertStringContainsString('chevron-left', $html, 'Must render prev icon');
        $this->assertStringContainsString('chevron-right', $html, 'Must render next icon');
    }

    public function test_pagination_hides_prev_on_first_page(): void
    {
        $html = $this->renderPartial('table-pagination.php', [
            'paginationPage' => 1,
            'paginationTotalPages' => 3,
            'paginationBaseUrl' => '/admin/bookings',
            'paginationParams' => '',
            'paginationI18nPrefix' => 'admin.bookings',
        ]);
        $this->assertStringNotContainsString('page=0', $html, 'Must not link to page 0');
        $this->assertStringContainsString('page=2', $html, 'Must have next link to page 2');
    }

    public function test_pagination_hides_next_on_last_page(): void
    {
        $html = $this->renderPartial('table-pagination.php', [
            'paginationPage' => 3,
            'paginationTotalPages' => 3,
            'paginationBaseUrl' => '/admin/bookings',
            'paginationParams' => '',
            'paginationI18nPrefix' => 'admin.bookings',
        ]);
        $this->assertStringContainsString('page=2', $html, 'Must have prev link to page 2');
        $this->assertStringNotContainsString('page=4', $html, 'Must not link to page 4');
    }

    public function test_pagination_escapes_base_url(): void
    {
        $html = $this->renderPartial('table-pagination.php', [
            'paginationPage' => 1,
            'paginationTotalPages' => 2,
            'paginationBaseUrl' => '/admin/tenants/abc&def',
            'paginationParams' => '',
            'paginationI18nPrefix' => 'admin.common',
        ]);
        $this->assertStringContainsString('abc&amp;def', $html, 'Base URL must be HTML-escaped');
    }

    // ── table-result-count.php ────────────────────────────────────────

    public function test_result_count_renders_nothing_when_inactive(): void
    {
        $html = $this->renderPartial('table-result-count.php', [
            'resultCountKey' => 'admin.tenants.showing_count',
            'resultCountValue' => 5,
            'resultCountActive' => false,
        ]);
        $this->assertEmpty(trim($html), 'Result count must render nothing when filters are not active');
    }

    public function test_result_count_renders_wrapper_and_dot_when_active(): void
    {
        $html = $this->renderPartial('table-result-count.php', [
            'resultCountKey' => 'admin.tenants.showing_count',
            'resultCountValue' => 3,
            'resultCountActive' => true,
        ]);
        $this->assertStringContainsString('vb-table-result-count', $html, 'Must render result count wrapper');
        $this->assertStringContainsString('vb-table-active-filter-dot', $html, 'Must render filter dot');
    }

    public function test_result_count_renders_pluralized_text(): void
    {
        $html = $this->renderPartial('table-result-count.php', [
            'resultCountKey' => 'admin.tenants.showing_count',
            'resultCountValue' => 1,
            'resultCountActive' => true,
        ]);
        // ICU plural: singular "1 business"
        $this->assertStringContainsString('1 business', $html, 'Must show singular count');
        $this->assertStringNotContainsString('1 businesss', $html, 'Must not show broken plural');
    }

    public function test_result_count_renders_zero_count(): void
    {
        $html = $this->renderPartial('table-result-count.php', [
            'resultCountKey' => 'admin.tenants.showing_count',
            'resultCountValue' => 0,
            'resultCountActive' => true,
        ]);
        $this->assertStringContainsString('vb-table-result-count', $html, 'Must render for zero results');
        $this->assertStringContainsString('No businesses', $html, 'Must show zero plural form');
    }
}
