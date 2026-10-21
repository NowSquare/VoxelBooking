<?php

declare(strict_types=1);
namespace Tests\Integration;
use App\Controllers\AgentApi\{ResourceController,SchemaController};
use App\Engine\Request;
use PHPUnit\Framework\TestCase;
final class AgentV1ContractTest extends TestCase
{
    protected function setUp():void { TestFixtures::provision(); $_GET=[];$_POST=[];$_SERVER['REQUEST_METHOD']='GET'; }
    public function testNegativeLimitsAreRejectedBeforeSql():void
    {
        $_GET=['tenant_id'=>TestFixtures::BUSINESS_TENANT_ID,'limit'=>'-1'];$r=new Request();$r->setAttribute('api_scopes',['bookings:read']);
        self::assertSame(422,(new ResourceController())->bookings($r)->getStatusCode());
    }
    public function testScheduleSchemaDoesNotPromiseComputedAvailabilityOrUtcDatetimes():void
    {
        $schema=json_decode((new SchemaController())->index(new Request())->getBody(),true);
        self::assertStringContainsString('schedule',$schema['paths']['/availability']['get']['summary']);
        self::assertNotContains('date',array_column($schema['paths']['/availability']['get']['parameters'],'name'));
        self::assertArrayNotHasKey('format',$schema['components']['schemas']['Booking']['properties']['start_datetime']);
        self::assertContains('waitlisted',$schema['paths']['/bookings']['get']['parameters'][1]['schema']['enum']);
        self::assertSame('string',$schema['components']['schemas']['Service']['properties']['price']['type']);
    }
}
