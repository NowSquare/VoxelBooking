<?php

declare(strict_types=1);

namespace App\Agent;

use App\Engine\Ulid;

/** An anonymous task identity, not authority to access an existing customer. */
final class PublicContinuation
{
    public function __construct(private ?PayloadCipher $cipher = null) {}

    public function issue(string $businessId, ?int $now = null): array
    {
        $now ??= time();
        $payload = [
            'purpose' => 'public_booking',
            'id' => Ulid::generate(),
            'business_id' => $businessId,
            'expires' => $now + 7200
        ];
        $token = rtrim(strtr(($this->cipher ?? new PayloadCipher())->encrypt($payload), '+/', '-_'), '=');
        return [
            'continuation' => $token,
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $payload['expires']),
            'business_id' => $businessId,
            'permissions' => ['public:read', 'public:book']
        ];
    }

    public function resolve(string $token, string $businessId, ?int $now = null): ActorContext
    {
        try {
            if (strlen($token) > 2048 || !preg_match('/\A[A-Za-z0-9_-]+\z/D', $token)) {
                throw new \UnexpectedValueException('Invalid continuation.');
            }
            $payload = ($this->cipher ?? new PayloadCipher())->decrypt(strtr($token, '-_', '+/'));
            if (($payload['purpose'] ?? null) !== 'public_booking'
                || ($payload['business_id'] ?? null) !== $businessId
                || !is_string($payload['id'] ?? null) || ($payload['expires'] ?? 0) <= ($now ?? time())) {
                throw new \UnexpectedValueException('Invalid continuation.');
            }
        } catch (\Throwable) {
            throw new OperationException('continuation_expired', 401, nextAction: 'start_public_session');
        }
        return new ActorContext(
            'public',
            $payload['id'],
            'prospective_customer',
            [$businessId],
            ['public:read', 'public:book'],
            authMode: 'public'
        );
    }
}
