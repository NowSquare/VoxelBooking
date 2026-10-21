<?php

declare(strict_types=1);

namespace App\Agent;

use App\Engine\{Database, Request, Ulid};

/**
 * Anonymous agent access to one business's public booking surface.
 *
 * Budgets belong to the actor that spends them: the sending address and the public
 * session (continuation). The business-wide numbers are server-protection ceilings,
 * set far above real booking traffic, never a small pool one actor can fill.
 */
final class PublicTransport
{
    /**
     * Continuations one address may mint per hour (all businesses together).
     * A booking task starts one session that lives two hours; 30 covers an office
     * or carrier NAT with many agents while capping how many live sessions, and
     * so how many per-session write budgets, one address can hold.
     */
    public const SESSION_STARTS_PER_ADDRESS = 30;

    /** Continuations one business may hand out per hour: a server ceiling, about one every second. */
    public const SESSION_STARTS_PER_BUSINESS = 3600;

    /**
     * Writes one continuation may make per hour. A booking is a hold, a preview
     * and a commit, plus receipt checks and a reschedule or cancel; 30 leaves
     * room for retries without letting one session hammer the booking engine.
     */
    public const WRITES_PER_CONTINUATION = 30;

    /**
     * Writes one address may make per hour (all businesses together): four fully
     * used sessions, enough for a shared NAT, and the cap on a single host however
     * many sessions it mints.
     */
    public const WRITES_PER_ADDRESS = 120;

    /**
     * Writes one business accepts per hour from anonymous agents: a server ceiling
     * (about five per second). Only admitted writes count, so reaching it takes
     * more than 160 addresses each spending its whole budget.
     */
    public const WRITES_PER_BUSINESS = 20000;

    /** Reads one address may make per minute: two per second for availability browsing. */
    public const READS_PER_ADDRESS = 120;

    /** Reads one business serves per minute to anonymous agents: a server ceiling of 100 per second. */
    public const READS_PER_BUSINESS = 6000;

    private const WRITE_WINDOW = 3600;
    private const READ_WINDOW = 60;
    private const SESSION_WINDOW = 3600;

    public static function business(Request $request): array
    {
        if ($request->getAttribute('slug') === null) {
            return ['id' => 'platform', 'slug' => null];
        }
        $row = Database::query(
            "SELECT id, slug FROM tenants WHERE slug = ? AND status = 'active'",
            [$request->getAttribute('slug')]
        )[0] ?? null;
        if ($row === null) {
            throw new OperationException('not_found', 404);
        }
        return $row;
    }

    public static function actor(Request $request, ?string $continuation = null, bool $write = false): ActorContext
    {
        $business = self::business($request);
        $continuation ??= $request->header('X-Booking-Continuation');
        $session = null;
        $rejection = null;
        if ($continuation !== null && $continuation !== '') {
            try {
                $session = (new PublicContinuation())->resolve($continuation, $business['id']);
            } catch (OperationException $error) {
                $rejection = $error;
            }
        } elseif ($write) {
            $rejection = new OperationException('continuation_required', 401, nextAction: 'start_public_session');
        }

        $window = $write ? self::WRITE_WINDOW : self::READ_WINDOW;
        $prefix = $write ? 'public-write:' : 'public-read:';
        $address = $prefix . 'address:' . self::addressBucket($request);
        $addressLimit = $write ? self::WRITES_PER_ADDRESS : self::READS_PER_ADDRESS;

        if ($rejection !== null) {
            // A forged or missing continuation spends only its sender's budget, so junk
            // from many addresses cannot fill the ceiling that real sessions rely on.
            self::admit($request, [$address => $addressLimit], $window);
            throw $rejection;
        }

        $budgets = [
            $address => $addressLimit,
            $prefix . 'business:' . $business['id'] => ($write ? self::WRITES_PER_BUSINESS : self::READS_PER_BUSINESS),
        ];
        if ($write) {
            // $session is set here: a write without a valid continuation was rejected above.
            $budgets['public-write:continuation:' . $session->principalId] = self::WRITES_PER_CONTINUATION;
        }
        self::admit($request, $budgets, $window);

        return $session ?? new ActorContext(
            'public',
            Ulid::generate(),
            'prospective_customer',
            [$business['id']],
            ['public:read'],
            authMode: 'public'
        );
    }

    public static function start(Request $request): array
    {
        $business = self::business($request);
        self::admit($request, [
            'public-session:address:' . self::addressBucket($request) => self::SESSION_STARTS_PER_ADDRESS,
            'public-session:business:' . $business['id'] => self::SESSION_STARTS_PER_BUSINESS,
        ], self::SESSION_WINDOW);
        return (new PublicContinuation())->issue($business['id']);
    }

    /** @param array<string, int> $budgets */
    private static function admit(Request $request, array $budgets, int $window): void
    {
        try {
            $budget = (new RateBudget())->consume($budgets, window: $window);
        } catch (\Throwable) {
            throw new OperationException('rate_limiter_unavailable', 503, nextAction: 'retry_later', retryable: true);
        }
        // TransportAuth turns this into X-RateLimit-* and, on refusal, Retry-After.
        $request->setAttribute('agent_budget', $budget);
        if (!$budget['allowed']) {
            throw new OperationException('rate_limited', 429, nextAction: 'wait_and_retry', retryable: true);
        }
    }

    /**
     * The sender as the app already trusts it (Request::ip, never a forwarded header).
     * IPv6 collapses to its /64: one host routinely owns the whole prefix and could
     * otherwise rotate addresses to mint fresh budgets.
     */
    private static function addressBucket(Request $request): string
    {
        $address = $request->ip();
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return $address;
        }
        return bin2hex(substr((string) inet_pton($address), 0, 8)) . '::/64';
    }
}
