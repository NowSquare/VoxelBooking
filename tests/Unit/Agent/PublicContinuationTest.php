<?php

declare(strict_types=1);
namespace Tests\Unit\Agent;
use App\Agent\{OperationException, PayloadCipher, PublicContinuation};
use PHPUnit\Framework\TestCase;

final class PublicContinuationTest extends TestCase
{
    public function testContinuationIsBusinessBoundAndCannotBecomeAStaffActor(): void
    {
        $service = new PublicContinuation(new PayloadCipher(str_repeat('x',32)));
        $grant = $service->issue('business-a', 1000);
        $actor = $service->resolve($grant['continuation'], 'business-a', 1001);
        self::assertSame('prospective_customer', $actor->role);
        self::assertSame(['public:read','public:book'], $actor->scopes);
        self::assertNull($actor->customerId);
        self::assertSame($actor->principalId, $service->resolve($grant['continuation'],'business-a',1002)->principalId);
        $this->expectException(OperationException::class);
        $service->resolve($grant['continuation'], 'business-b', 1002);
    }
    public function testExpiredContinuationRequiresFreshDiscovery(): void
    {
        $service = new PublicContinuation(new PayloadCipher(str_repeat('x',32)));
        $grant = $service->issue('business-a',1000);
        $this->expectException(OperationException::class);
        $service->resolve($grant['continuation'],'business-a',8201);
    }
    public function testTamperedContinuationIsRejected(): void
    {
        $service = new PublicContinuation(new PayloadCipher(str_repeat('x',32)));
        $grant = $service->issue('business-a',1000);
        $this->expectException(OperationException::class);
        $service->resolve('A'.substr($grant['continuation'],1), 'business-a',1001);
    }
}
