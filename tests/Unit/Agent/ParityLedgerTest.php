<?php
declare(strict_types=1);
namespace Tests\Unit\Agent;
use Tests\Support\ParityLedger;
use PHPUnit\Framework\TestCase;
final class ParityLedgerTest extends TestCase
{
    public function testEveryHumanActionAndOperationHasAnExplicitDecision(): void
    {
        $ledger=json_decode(file_get_contents(dirname(__DIR__,2).'/Fixtures/agent-parity.json'),true,flags:JSON_THROW_ON_ERROR);
        self::assertSame([],ParityLedger::verify($ledger));
    }
    public function testAddingAnUnmappedUiActionFailsTheGate(): void
    {
        $ledger=json_decode(file_get_contents(dirname(__DIR__,2).'/Fixtures/agent-parity.json'),true,flags:JSON_THROW_ON_ERROR);
        $routes=ParityLedger::routes();
        $routes[]=['method'=>'POST','path'=>'/admin/new-unmapped-action','handler'=>[\App\Controllers\Admin\AccountController::class,'save'],'middleware'=>[]];
        self::assertContains('Unmapped human action: POST /admin/new-unmapped-action',ParityLedger::verify($ledger,$routes));
    }
    public function testEveryRouteCompilesTogetherWithoutShadowing(): void
    {
        $dispatcher=\FastRoute\simpleDispatcher(static function(\FastRoute\RouteCollector $collector):void {
            foreach(ParityLedger::routes() as $route) { $collector->addRoute($route['method'],$route['path'],$route['handler']); }
        });
        self::assertSame(\FastRoute\Dispatcher::NOT_FOUND,$dispatcher->dispatch('GET','/not-a-real-route')[0]);
    }
}
