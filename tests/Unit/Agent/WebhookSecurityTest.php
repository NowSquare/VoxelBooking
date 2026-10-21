<?php

declare(strict_types=1);
namespace Tests\Unit\Agent;
use App\Application\Integrations\WebhookHttp;
use PHPUnit\Framework\TestCase;

final class WebhookSecurityTest extends TestCase
{
    public function testSignatureCoversExactBytesAndTimestamp(): void
    {
        $body='{"id":"example"}';
        $signature=WebhookHttp::signature($body,1000,'secret');
        self::assertSame('t=1000,v1='.hash_hmac('sha256','1000.'.$body,'secret'),$signature);
        self::assertNotSame($signature,WebhookHttp::signature($body.' ',1000,'secret'));
    }
    public function testRedirectsCredentialsAndPrivateAddressesAreRejectedBeforeRequest(): void
    {
        foreach(['https://user:pass@receiver.example/events','http://receiver.example/events','https://receiver.example:8443/events','https://receiver.example/events#fragment'] as $url) {
            try { (new WebhookHttp(static fn()=>['8.8.8.8']))->destination($url);self::fail('Unsafe URL accepted'); }
            catch(\InvalidArgumentException) { self::assertTrue(true); }
        }
        foreach(['127.0.0.1','10.0.0.1','169.254.169.254','100.64.1.1','::1','::ffff:8.8.8.8','2002:0808:0808::1','2001:db8::1'] as $address) {
            try { (new WebhookHttp(static fn()=>[$address]))->destination('https://receiver.example/events');self::fail('Unsafe IP accepted:'.$address); }
            catch(\InvalidArgumentException) { self::assertTrue(true); }
        }
    }
    public function testEachDeliveryResolvesAgainAndRejectsRebinding(): void
    {
        $calls=0;
        $http=new WebhookHttp(static function()use(&$calls):array { return ++$calls===1?['8.8.8.8']:['127.0.0.1']; });
        self::assertSame('8.8.8.8',$http->destination('https://receiver.example/events')['address']);
        $this->expectException(\InvalidArgumentException::class);
        $http->destination('https://receiver.example/events');
    }
}
