<?php
declare(strict_types=1);
namespace Tests\Unit\Agent;
use Tests\Support\ParityLedger;
use PHPUnit\Framework\TestCase;
final class ParityLedgerTest extends TestCase
{
    private static function ledger(): array
    {
        return json_decode(file_get_contents(dirname(__DIR__,2).'/Fixtures/agent-parity.json'),true,flags:JSON_THROW_ON_ERROR);
    }
    public function testEveryHumanActionAndOperationHasAnExplicitDecision(): void
    {
        self::assertSame([],ParityLedger::verify(self::ledger()));
    }
    public function testAddingAnUnmappedUiActionFailsTheGate(): void
    {
        $ledger=self::ledger();
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

    public function testCapabilityRowIsSatisfiedOnlyByItsOwnDecision(): void
    {
        $ledger = self::ledger();
        unset($ledger['capabilities']['C05']);

        // bookings_list still names C05, but a mention is not a reviewed verdict.
        self::assertContains('Missing capability decision: C05', ParityLedger::verify($ledger));
    }

    public function testIncompleteCapabilityKeepsTheGateRedAndNamesWhatIsMissing(): void
    {
        $ledger = self::ledger();
        $ledger['capabilities']['C05']['verdict'] = 'incomplete';
        $ledger['capabilities']['C05']['missing'] = ['A booking search by phone number'];

        self::assertContains('Incomplete capability C05: A booking search by phone number', ParityLedger::verify($ledger));
    }

    public function testUnknownVerdictAndMissingRationaleFailTheGate(): void
    {
        $ledger = self::ledger();
        $ledger['capabilities']['C05']['verdict'] = 'probably_fine';
        $ledger['capabilities']['C06']['rationale'] = '';

        $errors = ParityLedger::verify($ledger);

        self::assertContains('Unknown verdict for C05: probably_fine', $errors);
        self::assertContains('Capability C06 needs a rationale', $errors);
    }

    public function testVerifiedCapabilityNeedsOperationsThatExistAndClaimIt(): void
    {
        $ledger = self::ledger();
        $ledger['capabilities']['C05']['operations'] = ['not_an_operation', 'account_get'];

        $errors = ParityLedger::verify($ledger);

        self::assertContains('Unknown operation not_an_operation for C05', $errors);
        self::assertContains('Operation account_get does not claim C05', $errors);
        self::assertContains('Operation bookings_list claims C05 but its decision does not list it', $errors);
    }

    public function testCapabilityTestsMustNameAnExistingTestMethod(): void
    {
        $ledger = self::ledger();
        $self = 'tests/Unit/Agent/ParityLedgerTest.php';
        $ledger['capabilities']['C05']['tests'] = [
            $self,
            $self . '::testThatDoesNotExist',
            $self . '::ledger',
            'tests/Unit/Agent/NoSuchTest.php::testAnything',
            $self . '::testCapabilityTestsMustNameAnExistingTestMethod',
        ];

        $errors = ParityLedger::verify($ledger);

        self::assertContains('Test reference must be path::method: ' . $self, $errors);
        self::assertContains('Missing test method ' . $self . '::testThatDoesNotExist', $errors);
        self::assertContains('Missing test method ' . $self . '::ledger', $errors);
        self::assertContains('Missing test method tests/Unit/Agent/NoSuchTest.php::testAnything', $errors);
        self::assertSame([], preg_grep('/testCapabilityTestsMustNameAnExistingTestMethod/', $errors));
    }

    public function testAttributeMarkedTestMethodsCount(): void
    {
        $reference = 'tests/Unit/Engine/EventCalculatorTest.php::upcoming_events_marks_full_with_waitlist';

        self::assertTrue(ParityLedger::testMethodExists(dirname(__DIR__, 3), $reference));
    }

    public function testTransportEvidenceMustNameExistingTestMethods(): void
    {
        $ledger = self::ledger();
        $ledger['operations']['account_get']['transport_evidence'] = ['http' => ['tests/Unit/Agent/ParityLedgerTest.php::testNope'], 'mcp' => []];
        unset($ledger['operations']['account_update']['transport_evidence']);

        $errors = ParityLedger::verify($ledger);

        self::assertContains('Missing test method tests/Unit/Agent/ParityLedgerTest.php::testNope', $errors);
        self::assertContains('Missing transport evidence for account_update', $errors);
    }

    public function testReviewedSourceIncludesPrivateHelpersTheHandlerCalls(): void
    {
        $source = ParityLedger::reviewedSource([ParityHandlerFixture::class, 'handle']);

        self::assertStringContainsString('direct-helper-body', $source);
        self::assertStringContainsString('transitive-helper-body', $source);
        self::assertStringContainsString('static-helper-body', $source);
        self::assertStringNotContainsString('unrelated-helper-body', $source);
        self::assertStringNotContainsString('public-collaborator-body', $source);
    }

    public function testHandlerWithoutHelpersKeepsItsHandlerOnlyHash(): void
    {
        $method = new \ReflectionMethod(ParityHandlerFixture::class, 'unrelated');
        $lines = file($method->getFileName());
        $own = implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));

        self::assertSame($own, ParityLedger::reviewedSource([ParityHandlerFixture::class, 'unrelated']));
    }

    public function testProtocolRoutesAreCountedSeparatelyFromProductActions(): void
    {
        self::assertSame('protocol', ParityLedger::actionClass('GET /api/agent/v1/bookings'));
        self::assertSame('protocol', ParityLedger::actionClass('POST /oauth/token'));
        self::assertSame('protocol', ParityLedger::actionClass('GET /.well-known/oauth-authorization-server'));
        self::assertSame('protocol', ParityLedger::actionClass('GET /cron/run'));
        self::assertSame('product', ParityLedger::actionClass('POST /admin/connections/{id}/revoke'));
        self::assertSame('product', ParityLedger::actionClass('GET /api/{slug}/availability'));

        $counts = ParityLedger::actionCounts(self::ledger());

        self::assertSame(count(self::ledger()['human_actions']), $counts['product'] + $counts['protocol']);
        self::assertGreaterThan(0, $counts['protocol']);
        self::assertStringContainsString('product actions', ParityLedger::summary(self::ledger()));
    }

    public function testNativeMcpColumnSaysVerifiedOnlyWithMcpSuccessEvidence(): void
    {
        $ledger = self::ledger();
        $ledger['operations']['services_list']['transport_evidence']['mcp'] = ['tests/Integration/OperationContractCoverageTest.php::testEveryCatalogActionReturnsItsPublishedSuccessContract'];
        $ledger['operations']['account_get']['transport_evidence'] = ['http' => [], 'mcp' => []];

        $rows = preg_grep('/^\| [^|]* \| `(services_list|account_get)` \|/', explode("\n", ParityLedger::render($ledger)));
        $byOperation = [];
        foreach ($rows as $row) {
            $cells = array_map('trim', explode('|', $row));
            $byOperation[trim($cells[2], '`')] = $cells;
        }

        self::assertContains('verified', $byOperation['services_list']);
        self::assertNotContains('verified', $byOperation['account_get']);
        self::assertContains('contract only', $byOperation['account_get']);
    }

    public function testJunitEvidenceRequiresEveryReferencedMethodToHaveRunAndPassed(): void
    {
        $ledger = [
            'capabilities' => ['C01' => ['tests' => ['tests/Integration/A.php::testPasses', 'tests/Integration/A.php::testFails', 'tests/Integration/A.php::testNeverRan']]],
            'operations' => ['x' => ['transport_evidence' => ['http' => ['tests/Integration/B.php::testProvider', 'tests/Integration/B.php::testSkipped'], 'mcp' => []]]],
        ];
        $xml = '<?xml version="1.0"?><testsuites><testsuite name="all">'
            . '<testcase name="testPasses" file="/tmp/run/app/tests/Integration/A.php" class="A"/>'
            . '<testcase name="testFails" file="/tmp/run/app/tests/Integration/A.php" class="A"><failure>no</failure></testcase>'
            . '<testcase name="testProvider with data set &quot;one&quot;" file="/tmp/run/app/tests/Integration/B.php" class="B"/>'
            . '<testcase name="testProvider with data set #1" file="/tmp/run/app/tests/Integration/B.php" class="B"/>'
            . '<testcase name="testSkipped" file="/tmp/run/app/tests/Integration/B.php" class="B"><skipped/></testcase>'
            . '</testsuite></testsuites>';

        self::assertSame([
            'Referenced test failed: tests/Integration/A.php::testFails',
            'Referenced test did not run: tests/Integration/A.php::testNeverRan',
            'Referenced test was skipped: tests/Integration/B.php::testSkipped',
        ], ParityLedger::junitErrors($ledger, $xml));
    }
}

/** Source fixture for helper-aware review hashing. Never executed. */
final class ParityHandlerFixture
{
    public function handle(): string
    {
        return $this->direct() . self::viaStatic() . $this->collaborator();
    }

    public function unrelated(): string
    {
        return 'unrelated-helper-body';
    }

    public function collaborator(): string
    {
        return 'public-collaborator-body';
    }

    private function direct(): string
    {
        return 'direct-helper-body' . static::transitive();
    }

    private static function transitive(): string
    {
        return 'transitive-helper-body';
    }

    protected static function viaStatic(): string
    {
        return 'static-helper-body';
    }
}
