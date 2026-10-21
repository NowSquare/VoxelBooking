<?php
declare(strict_types=1);
namespace Tests\Unit\Agent;
use PHPUnit\Framework\TestCase;
final class EndpointHostingTest extends TestCase
{
    public function testServerConfiguredNonstandardPortIsPreserved(): void
    {
        $before=$_SERVER;$env=$_ENV;
        try {
            $_SERVER=['SERVER_NAME'=>'127.0.0.1','SERVER_PORT'=>'61234'];$_ENV=[];
            self::assertSame('http://127.0.0.1:61234/cron/run',app_url('/cron/run'));
            $_SERVER['HTTP_HOST']='attacker.example:4444';
            self::assertSame('http://127.0.0.1:61234',app_url());
        }finally { $_SERVER=$before;$_ENV=$env; }
    }
    public function testConfiguredPublicOriginWinsForProxyAndCli(): void
    {
        $before=$_SERVER;$env=$_ENV;
        try {
            $_SERVER=['SERVER_NAME'=>'internal','SERVER_PORT'=>'8080'];$_ENV=['APP_URL'=>'https://booking.example'];
            self::assertSame('https://booking.example/cron/run',app_url('/cron/run'));
        }finally { $_SERVER=$before;$_ENV=$env; }
    }
}
