<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Engine\Auth;
use App\Engine\Database;
use App\Engine\EnvLoader;
use App\Engine\Request;
use App\Engine\Response;
use App\Middleware\AuthMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Tests for AuthMiddleware.
 *
 * Verifies that unauthenticated requests are redirected to /admin/login,
 * authenticated requests pass through, and operator-only routes
 * return 403 for business users.
 */
final class AuthMiddlewareTest extends TestCase
{
    private string $originalRequestUri;

    protected function setUp(): void
    {
        $_SESSION = [];
        Auth::reset();
        $this->originalRequestUri = $_SERVER['REQUEST_URI'] ?? '/';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Auth::reset();
        $_SERVER['REQUEST_URI'] = $this->originalRequestUri;
    }

    /**
     * Skip tests that trigger Database::query() via resolveAdminLocale()
     * when the test process has no direct DB access.
     */
    private function requireDatabase(): void
    {
        try {
            \Tests\Support\TestEnvironment::boot();
            Database::connect();
            Database::query('SELECT 1');
        } catch (\Throwable) {
            $this->markTestSkipped('Database not available');
        }
    }

    public function testUnauthenticatedRequestRedirectsToLogin(): void
    {
        $_SERVER['REQUEST_URI'] = '/admin';

        $middleware = new AuthMiddleware();
        $request = new Request();
        $response = $middleware->handle($request, fn($r) => Response::html('Dashboard', 200));

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testAuthenticatedOperatorPassesThrough(): void
    {
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['auth_name'] = 'Test Op';
        $_SESSION['auth_email'] = 'op@test.com';
        $_SESSION['_last_activity'] = time();

        $_SERVER['REQUEST_URI'] = '/admin';

        $middleware = new AuthMiddleware();
        $request = new Request();
        $response = $middleware->handle($request, fn($r) => Response::html('Dashboard', 200));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Dashboard', $response->getBody());
    }

    public function testAuthenticatedBusinessUserPassesOnTenantRoute(): void
    {
        $this->requireDatabase();
        $_SESSION['auth_type'] = 'business_user';
        $_SESSION['auth_id'] = '01HABC5678901234UVWXYZ';
        $_SESSION['auth_name'] = 'Bob';
        $_SESSION['auth_email'] = 'bob@salon.com';
        $_SESSION['auth_tenant_id'] = '01HTENANT123456789ABC';
        $_SESSION['auth_role'] = 'owner';
        $_SESSION['_last_activity'] = time();

        $_SERVER['REQUEST_URI'] = '/admin/tenants/01HTENANT123456789ABC';

        $middleware = new AuthMiddleware();
        $request = new Request();
        $request->setAttribute('tenant_id', '01HTENANT123456789ABC');
        $response = $middleware->handle($request, fn($r) => Response::html('Tenant Page', 200));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testBusinessUserOnOperatorRouteGets403(): void
    {
        $_SESSION['auth_type'] = 'business_user';
        $_SESSION['auth_id'] = '01HABC5678901234UVWXYZ';
        $_SESSION['auth_name'] = 'Bob';
        $_SESSION['auth_email'] = 'bob@salon.com';
        $_SESSION['auth_tenant_id'] = '01HTENANT123456789ABC';
        $_SESSION['auth_role'] = 'owner';
        $_SESSION['_last_activity'] = time();

        $_SERVER['REQUEST_URI'] = '/admin/settings';

        $middleware = new AuthMiddleware();
        $request = new Request();
        $response = $middleware->handle($request, fn($r) => Response::html('Settings', 200));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testBusinessUserOnWrongTenantGets403(): void
    {
        $this->requireDatabase();
        $_SESSION['auth_type'] = 'business_user';
        $_SESSION['auth_id'] = '01HABC5678901234UVWXYZ';
        $_SESSION['auth_name'] = 'Bob';
        $_SESSION['auth_email'] = 'bob@salon.com';
        $_SESSION['auth_tenant_id'] = '01HTENANT123456789ABC';
        $_SESSION['auth_role'] = 'owner';
        $_SESSION['_last_activity'] = time();

        $_SERVER['REQUEST_URI'] = '/admin/tenants/01HDIFFERENT_TENANT999';

        $middleware = new AuthMiddleware();
        $request = new Request();
        $request->setAttribute('tenant_id', '01HDIFFERENT_TENANT999');
        $response = $middleware->handle($request, fn($r) => Response::html('Other Tenant', 200));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testExpiredSessionRedirectsToLogin(): void
    {
        $_SESSION['auth_type'] = 'operator';
        $_SESSION['auth_id'] = '01HXYZ1234567890ABCDEF';
        $_SESSION['_last_activity'] = time() - (9 * 3600);

        $_SERVER['REQUEST_URI'] = '/admin';

        $middleware = new AuthMiddleware();
        $request = new Request();
        $response = $middleware->handle($request, fn($r) => Response::html('Dashboard', 200));

        $this->assertSame(302, $response->getStatusCode());
    }
}
