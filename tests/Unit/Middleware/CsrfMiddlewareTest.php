<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Engine\Request;
use App\Middleware\CsrfMiddleware;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the CSRF proofs the public booking API accepts.
 *
 * The standalone booking page proves itself with the session token.
 * The embedded page runs in a cross-site iframe without cookies, so it
 * proves itself with the Origin header the browser attaches to every POST.
 * Either proof is enough; a request with neither is rejected.
 */
final class CsrfMiddlewareTest extends TestCase
{
    private const SERVER_KEYS = ['HTTP_HOST', 'HTTP_ORIGIN', 'HTTP_X_CSRF_TOKEN'];

    /** @var array<string, string|null> */
    private array $originalServer = [];

    protected function setUp(): void
    {
        foreach (self::SERVER_KEYS as $key) {
            $this->originalServer[$key] = $_SERVER[$key] ?? null;
            unset($_SERVER[$key]);
        }
        $_SESSION = [];
        $_POST = [];
    }

    protected function tearDown(): void
    {
        foreach (self::SERVER_KEYS as $key) {
            if ($this->originalServer[$key] === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $this->originalServer[$key];
            }
        }
        $_SESSION = [];
        $_POST = [];
    }

    // ── Same-origin attestation ──

    public function testSameOriginAcceptsMatchingHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'voxelbooking-app.test';
        $_SERVER['HTTP_ORIGIN'] = 'https://voxelbooking-app.test';

        $this->assertTrue(CsrfMiddleware::isSameOrigin(new Request()));
    }

    public function testSameOriginIgnoresCaseSchemeAndPort(): void
    {
        $_SERVER['HTTP_HOST'] = 'Booking.Example.com:8443';
        $_SERVER['HTTP_ORIGIN'] = 'http://booking.example.com';

        $this->assertTrue(CsrfMiddleware::isSameOrigin(new Request()));
    }

    public function testSameOriginRejectsForeignHost(): void
    {
        $_SERVER['HTTP_HOST'] = 'voxelbooking-app.test';
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';

        $this->assertFalse(CsrfMiddleware::isSameOrigin(new Request()));
    }

    public function testSameOriginRequiresAnExactHostMatch(): void
    {
        $_SERVER['HTTP_HOST'] = 'voxelbooking-app.test';

        $_SERVER['HTTP_ORIGIN'] = 'https://evil.voxelbooking-app.test';
        $this->assertFalse(CsrfMiddleware::isSameOrigin(new Request()), 'A subdomain is not the same origin');

        $_SERVER['HTTP_ORIGIN'] = 'https://app.test';
        $this->assertFalse(CsrfMiddleware::isSameOrigin(new Request()), 'A parent domain is not the same origin');

        $_SERVER['HTTP_ORIGIN'] = 'https://voxelbooking-app.test@evil.example';
        $this->assertFalse(CsrfMiddleware::isSameOrigin(new Request()), 'Userinfo must not masquerade as the host');
    }

    public function testSameOriginRejectsMissingOrigin(): void
    {
        $_SERVER['HTTP_HOST'] = 'voxelbooking-app.test';

        $this->assertFalse(CsrfMiddleware::isSameOrigin(new Request()));
    }

    public function testSameOriginRejectsOpaqueOrigin(): void
    {
        $_SERVER['HTTP_HOST'] = 'voxelbooking-app.test';
        $_SERVER['HTTP_ORIGIN'] = 'null';

        $this->assertFalse(CsrfMiddleware::isSameOrigin(new Request()));
    }

    public function testSameOriginRejectsWhenOwnHostIsUnknown(): void
    {
        $_SERVER['HTTP_ORIGIN'] = 'https://voxelbooking-app.test';

        $this->assertFalse(CsrfMiddleware::isSameOrigin(new Request()));
    }

    // ── Session token ──

    public function testTokenMatchesWhenHeaderEchoesSession(): void
    {
        $_SESSION['_csrf_token'] = 'a1b2c3';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'a1b2c3';

        $this->assertTrue(CsrfMiddleware::tokenMatches(new Request()));
    }

    public function testTokenMatchesAcceptsTheFormField(): void
    {
        $_SESSION['_csrf_token'] = 'a1b2c3';
        $_POST['_csrf_token'] = 'a1b2c3';

        $this->assertTrue(CsrfMiddleware::tokenMatches(new Request()));
    }

    public function testTokenRejectsMismatch(): void
    {
        $_SESSION['_csrf_token'] = 'a1b2c3';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'totally-wrong';

        $this->assertFalse(CsrfMiddleware::tokenMatches(new Request()));
    }

    public function testTokenRejectsWhenSessionHasNoToken(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'a1b2c3';

        $this->assertFalse(CsrfMiddleware::tokenMatches(new Request()));
    }

    public function testTokenRejectsWhenNothingWasSubmitted(): void
    {
        $_SESSION['_csrf_token'] = 'a1b2c3';

        $this->assertFalse(CsrfMiddleware::tokenMatches(new Request()));
    }

    /**
     * The embed iframe carries no session cookie. Starting a session for it
     * would set a cookie the embed must never set, so the check reads the
     * session only when the browser presented one.
     */
    public function testTokenCheckDoesNotStartASessionWithoutACookie(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'a1b2c3';

        CsrfMiddleware::tokenMatches(new Request());

        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    // ── Public submission: either proof is enough ──

    public function testPublicSubmissionAcceptsTheSessionToken(): void
    {
        $_SESSION['_csrf_token'] = 'a1b2c3';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'a1b2c3';
        $_SERVER['HTTP_HOST'] = 'voxelbooking-app.test';

        $this->assertTrue(CsrfMiddleware::verifyPublicSubmission(new Request()));
    }

    public function testPublicSubmissionAcceptsSameOriginWithoutASession(): void
    {
        $_SERVER['HTTP_HOST'] = 'voxelbooking-app.test';
        $_SERVER['HTTP_ORIGIN'] = 'https://voxelbooking-app.test';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = '';

        $this->assertTrue(CsrfMiddleware::verifyPublicSubmission(new Request()));
    }

    public function testPublicSubmissionRejectsWithoutEitherProof(): void
    {
        $_SERVER['HTTP_HOST'] = 'voxelbooking-app.test';
        $_SERVER['HTTP_ORIGIN'] = 'https://evil.example';
        $_SESSION['_csrf_token'] = 'a1b2c3';
        $_SERVER['HTTP_X_CSRF_TOKEN'] = 'totally-wrong';

        $this->assertFalse(CsrfMiddleware::verifyPublicSubmission(new Request()));
    }
}
