<?php

declare(strict_types=1);

namespace App\Agent;

use App\Engine\{Database, Request, Ulid};

final class PublicTransport
{
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
        self::budget($request, $business['id'], $write);
        $continuation ??= $request->header('X-Booking-Continuation');
        if ($continuation !== null && $continuation !== '') {
            return (new PublicContinuation())->resolve($continuation, $business['id']);
        }
        if ($write) {
            throw new OperationException('continuation_required', 401, nextAction: 'start_public_session');
        }
        return new ActorContext(
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
        self::budget($request, $business['id'], true);
        return (new PublicContinuation())->issue($business['id']);
    }

    private static function budget(Request $request, string $businessId, bool $write): void
    {
        $window = $write ? 3600 : 60;
        $prefix = $write ? 'public-write:' : 'public-read:';
        try {
            $budget = (new RateBudget())->consume([
                $prefix . 'ip:' . $request->ip() => ($write ? 40 : 120),
                $prefix . 'business:' . $businessId => ($write ? 1000 : 1200),
            ], window: $window);
        } catch (\Throwable) {
            throw new OperationException('rate_limiter_unavailable', 503, retryable: true);
        }
        $request->setAttribute('agent_budget', $budget);
        if (!$budget['allowed']) {
            throw new OperationException('rate_limited', 429, retryable: true);
        }
    }
}
