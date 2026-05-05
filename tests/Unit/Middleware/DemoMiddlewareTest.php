<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Engine\DemoMode;
use App\Engine\Request;
use App\Engine\Response;
use App\Middleware\DemoMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DemoMiddleware.
 *
 * Verifies the interactive demo's denylist enforcement:
 * - Blocked routes: /api/* → JSON 403, others → 302 redirect with toast
 * - Allowed routes (operational workflows): pass through to handler
 */
final class DemoMiddlewareTest extends TestCase
{
    private string $basePath;

    protected function setUp(): void
    {
        $this->basePath = sys_get_temp_dir() . '/vb-demo-mw-' . mt_rand();
        @mkdir($this->basePath, 0755, true);
        touch($this->basePath . '/.demo');
        DemoMode::reset();
        DemoMode::init($this->basePath);

        // Ensure session is active (FormState uses sessions)
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->basePath . '/.demo');
        @rmdir($this->basePath);
        DemoMode::reset();
        unset($_SERVER['HTTP_REFERER']);
    }

    private function passthrough(): callable
    {
        return fn(Request $r) => Response::html('OK', 200);
    }

    // ════════════════════════════════════════════════════════════════
    // Blocked routes: system settings → redirect
    // ════════════════════════════════════════════════════════════════

    public function testSystemSettingsPostIsBlocked(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/settings';

        $middleware = new DemoMiddleware();
        $response = $middleware->handle(new Request(), $this->passthrough());

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testEmailSettingsPostIsBlocked(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/settings/email';

        $middleware = new DemoMiddleware();
        $response = $middleware->handle(new Request(), $this->passthrough());

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testTenantSettingsPostIsBlocked(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/tenants/t1/settings/general';

        $middleware = new DemoMiddleware();
        $response = $middleware->handle(new Request(), $this->passthrough());

        $this->assertSame(302, $response->getStatusCode());

        $ref = new \ReflectionProperty($response, 'headers');
        $headers = $ref->getValue($response);
        $this->assertSame('/admin/settings', $headers['Location'] ?? '');
    }

    public function testTenantSettingsUsesRefererWhenAvailable(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/tenants/t1/settings/branding';
        $_SERVER['HTTP_REFERER'] = 'https://demo.voxelbooking.com/admin/tenants/t1/settings/branding';

        $middleware = new DemoMiddleware();
        $response = $middleware->handle(new Request(), $this->passthrough());

        $this->assertSame(302, $response->getStatusCode());

        $ref = new \ReflectionProperty($response, 'headers');
        $headers = $ref->getValue($response);
        $this->assertSame(
            'https://demo.voxelbooking.com/admin/tenants/t1/settings/branding',
            $headers['Location'] ?? ''
        );
    }

    // ════════════════════════════════════════════════════════════════
    // Allowed routes: operational workflows pass through
    // ════════════════════════════════════════════════════════════════

    public function testBookingPostPassesThrough(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/demo-studio/bookings';

        $middleware = new DemoMiddleware();
        $response = $middleware->handle(new Request(), $this->passthrough());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getBody());
    }

    public function testBookingCancelPassesThrough(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/demo-studio/bookings/abc/cancel';

        $middleware = new DemoMiddleware();
        $response = $middleware->handle(new Request(), $this->passthrough());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testBookingReschedulePassesThrough(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/api/demo-studio/bookings/abc/reschedule';

        $middleware = new DemoMiddleware();
        $response = $middleware->handle(new Request(), $this->passthrough());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAllowedLoginPostPassesThrough(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/login';

        $middleware = new DemoMiddleware();
        $response = $middleware->handle(new Request(), $this->passthrough());

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('OK', $response->getBody());
    }

    public function testGetRequestsPassThrough(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REQUEST_URI'] = '/admin/bookings';

        $middleware = new DemoMiddleware();
        $response = $middleware->handle(new Request(), $this->passthrough());

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAdminBookingStatusPassesThrough(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/bookings/abc/status';

        $middleware = new DemoMiddleware();
        $response = $middleware->handle(new Request(), $this->passthrough());

        $this->assertSame(200, $response->getStatusCode());
    }

    // ════════════════════════════════════════════════════════════════
    // Demo mode inactive: everything passes through
    // ════════════════════════════════════════════════════════════════

    public function testAllPassesThroughWhenDemoInactive(): void
    {
        @unlink($this->basePath . '/.demo');
        DemoMode::reset();
        DemoMode::init($this->basePath);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REQUEST_URI'] = '/admin/settings';

        $middleware = new DemoMiddleware();
        $response = $middleware->handle(new Request(), $this->passthrough());

        $this->assertSame(200, $response->getStatusCode());
    }
}
