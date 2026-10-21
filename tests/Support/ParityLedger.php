<?php
declare(strict_types=1);
namespace Tests\Support;

use App\Agent\{CanonicalJson,Operations};
use App\Engine\Router;

/** Build-time coverage gate. No application boot or database access is needed. */
final class ParityLedger
{
    /** Verdicts that close a capability row. `incomplete` is recorded honestly but keeps the gate red. */
    public const VERDICTS = ['verified', 'justified_handoff', 'excluded'];

    /** Legacy v1, OAuth, discovery metadata and cron are protocol plumbing, not product actions. */
    private const PROTOCOL_PREFIXES = ['/api/agent/v1/', '/oauth/', '/.well-known/', '/cron/'];

    public static function routes(): array
    {
        $router=new Router();
        (require dirname(__DIR__,2).'/app/routes.php')($router);
        return (new \ReflectionProperty(Router::class,'routes'))->getValue($router);
    }
    public static function humanRoutes(array $routes): array
    {
        return array_values(array_filter($routes,static fn($route)=>
            !str_starts_with($route['path'],'/api/agent/v2')
            &&!str_starts_with($route['path'],'/mcp')
            &&!str_starts_with($route['path'],'/.well-known/')
            &&$route['method']!=='OPTIONS'));
    }
    public static function key(array $route): string { return $route['method'].' '.$route['path']; }

    /**
     * A reviewed human action is its handler plus the private and protected helpers
     * of the same class that the handler reaches. A change inside such a helper
     * changes behaviour as surely as a change in the handler, so it needs review too.
     */
    public static function sourceHash(array $route): string
    {
        return hash('sha256', self::reviewedSource($route['handler']));
    }

    public static function reviewedSource(array $handler): string
    {
        $class = new \ReflectionClass($handler[0]);
        $entry = $class->getMethod($handler[1]);
        $sources = [];
        $pending = [$entry->getName()];
        while ($pending !== []) {
            $name = array_shift($pending);
            if (isset($sources[$name])) {
                continue;
            }
            $source = self::methodSource($class->getMethod($name));
            $sources[$name] = $source;
            foreach (self::helpersCalledBy($class, $source) as $helper) {
                $pending[] = $helper;
            }
        }
        $handlerSource = $sources[$entry->getName()];
        unset($sources[$entry->getName()]);
        ksort($sources);

        // Without helpers this equals the handler's own lines, so helper-free hashes stay stable.
        return implode('', [$handlerSource, ...array_values($sources)]);
    }

    private static function methodSource(\ReflectionMethod $method): string
    {
        $lines = file($method->getFileName());

        return implode('', array_slice($lines, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1));
    }

    /** @return list<string> */
    private static function helpersCalledBy(\ReflectionClass $class, string $source): array
    {
        preg_match_all('/(?:\$this->|self::|static::)([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $source, $matches);
        $helpers = [];
        foreach (array_unique($matches[1]) as $name) {
            if (!$class->hasMethod($name)) {
                continue;
            }
            $method = $class->getMethod($name);
            $sameClass = $method->getDeclaringClass()->getName() === $class->getName();
            if ($sameClass && ($method->isPrivate() || $method->isProtected())) {
                $helpers[] = $method->getName();
            }
        }

        return $helpers;
    }

    /**
     * A contract is its structure and policy. Schema descriptions and titles are
     * documentation: rewording or translating them must not demand a parity
     * review, and the hash must not depend on whether translations are loaded.
     * Only string annotations are dropped, so a property named "title" stays.
     */
    public static function contractHash(\App\Agent\OperationDefinition $operation): string
    {
        return CanonicalJson::hash([self::structure($operation->inputSchema),self::structure($operation->resultSchema()),$operation->requiredScopes,$operation->actorPolicy,
            $operation->confirmationPolicy,$operation->patterns,$operation->httpMethod,$operation->httpPath]);
    }
    private static function structure(array $schema): array
    {
        $structure=[];
        foreach($schema as $key=>$value) {
            if(in_array($key,['description','title'],true)&&is_string($value)) { continue; }
            $structure[$key]=is_array($value)?self::structure($value):$value;
        }
        return $structure;
    }
    public static function verify(array $ledger,?array $routes=null): array
    {
        $errors=[];$root=dirname(__DIR__,2);$registry=Operations::registry();
        $known=$ledger['human_actions']??[];
        foreach(self::humanRoutes($routes??self::routes()) as $route) {
            $key=self::key($route);
            if(!isset($known[$key])) { $errors[]='Unmapped human action: '.$key;continue; }
            $action=$known[$key];
            if(($action['handler']??'')!==implode('::',$route['handler'])) { $errors[]='Changed handler needs a parity decision: '.$key; }
            if(($action['source_hash']??'')!==self::sourceHash($route)) { $errors[]='Changed human action needs parity review: '.$key; }
            if(!in_array($action['disposition']??'',['operation','trusted_browser','transport','presentation'],true)
                ||empty($action['rationale'])||empty($action['tests'])) { $errors[]='Incomplete action decision: '.$key; }
            if(($action['disposition']??'')==='operation'&&empty($action['operations'])) { $errors[]='Action has no operation: '.$key; }
            foreach($action['operations']??[] as $name) {
                if(!$registry->get($name)) { $errors[]='Unknown operation '.$name.' for '.$key; }
            }
            foreach($action['tests']??[] as $test) { if(!is_file($root.'/'.$test)) { $errors[]='Missing test '.$test.' for '.$key; } }
        }
        foreach($registry->all() as $operation) {
            $entry=$ledger['operations'][$operation->operationId]??null;
            if(!$entry) { $errors[]='Unmapped operation: '.$operation->operationId;continue; }
            if(($entry['contract_hash']??'')!==self::contractHash($operation)) { $errors[]='Changed contract needs parity review: '.$operation->operationId; }
            if(empty($entry['tests'])||empty($operation->documentationPage)) { $errors[]='Missing evidence for '.$operation->operationId; }
            foreach($entry['tests']??[] as $test) { if(!is_file($root.'/'.$test)) { $errors[]='Missing operation test '.$test; } }
            array_push($errors, ...self::transportErrors($root, $operation->operationId, $entry));
        }
        array_push($errors, ...self::capabilityErrors($root, $ledger['capabilities'] ?? []));
        return array_values(array_unique($errors));
    }

    private static function transportErrors(string $root, string $operationId, array $entry): array
    {
        $evidence = $entry['transport_evidence'] ?? null;
        if (!is_array($evidence) || !is_array($evidence['http'] ?? null) || !is_array($evidence['mcp'] ?? null)) {
            return ['Missing transport evidence for ' . $operationId];
        }
        $errors = [];
        foreach ([...$evidence['http'], ...$evidence['mcp']] as $reference) {
            $error = self::testReferenceError($root, $reference);
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return $errors;
    }

    /**
     * Each C-row is closed only by its own reviewed decision. An operation naming
     * a row is a claim to be checked against that decision, never a substitute for it.
     */
    private static function capabilityErrors(string $root, array $capabilities): array
    {
        $registry = Operations::registry();
        $errors = [];
        for ($number = 1; $number <= 42; $number++) {
            $id = sprintf('C%02d', $number);
            $decision = $capabilities[$id] ?? null;
            if (!is_array($decision)) {
                $errors[] = 'Missing capability decision: ' . $id;
                continue;
            }
            $verdict = $decision['verdict'] ?? '';
            if ($verdict === 'incomplete') {
                $errors[] = 'Incomplete capability ' . $id . ': ' . implode('; ', $decision['missing'] ?? ['no missing work recorded']);
            } elseif (!in_array($verdict, self::VERDICTS, true)) {
                $errors[] = 'Unknown verdict for ' . $id . ': ' . $verdict;
            }
            if (trim((string) ($decision['rationale'] ?? '')) === '') {
                $errors[] = 'Capability ' . $id . ' needs a rationale';
            }
            if ($verdict === 'verified' && empty($decision['operations'])) {
                $errors[] = 'Verified capability ' . $id . ' names no operation';
            }
            if ($verdict !== 'excluded' && empty($decision['tests'])) {
                $errors[] = 'Capability ' . $id . ' names no test';
            }
            foreach ($decision['operations'] ?? [] as $name) {
                $operation = $registry->get($name);
                if ($operation === null) {
                    $errors[] = 'Unknown operation ' . $name . ' for ' . $id;
                } elseif (!in_array($id, $operation->capabilityIds, true)) {
                    $errors[] = 'Operation ' . $name . ' does not claim ' . $id;
                }
            }
            foreach ($decision['tests'] ?? [] as $reference) {
                $error = self::testReferenceError($root, $reference);
                if ($error !== null) {
                    $errors[] = $error;
                }
            }
        }
        foreach ($registry->all() as $operation) {
            foreach ($operation->capabilityIds as $id) {
                if (isset($capabilities[$id]) && !in_array($operation->operationId, $capabilities[$id]['operations'] ?? [], true)) {
                    $errors[] = 'Operation ' . $operation->operationId . ' claims ' . $id . ' but its decision does not list it';
                }
            }
        }

        return $errors;
    }

    private static function testReferenceError(string $root, mixed $reference): ?string
    {
        if (!is_string($reference) || preg_match('/\Atests\/[A-Za-z0-9_\/.-]+\.php::[A-Za-z_][A-Za-z0-9_]*\z/D', $reference) !== 1) {
            return 'Test reference must be path::method: ' . (is_string($reference) ? $reference : json_encode($reference));
        }
        if (!self::testMethodExists($root, $reference)) {
            return 'Missing test method ' . $reference;
        }

        return null;
    }

    /**
     * A token-level check, so test classes need not be loaded: the method must be
     * public and either carry the `test` prefix or a #[Test] attribute or @test tag.
     */
    public static function testMethodExists(string $root, string $reference): bool
    {
        static $sources = [];
        [$path, $method] = explode('::', $reference, 2);
        if (!array_key_exists($path, $sources)) {
            $sources[$path] = is_file($root . '/' . $path) ? file_get_contents($root . '/' . $path) : null;
        }
        $source = $sources[$path];
        if ($source === null) {
            return false;
        }
        $pattern = '/(?<lead>(?:\s*(?:#\[[^\]]*\]|\/\*\*(?:(?!\*\/).)*\*\/))*)\s*(?<modifiers>(?:(?:public|protected|private|final|static|abstract)\s+)*)function\s+'
            . preg_quote($method, '/') . '\s*\(/s';
        if (preg_match($pattern, $source, $match) !== 1) {
            return false;
        }
        if (preg_match('/\b(?:private|protected)\b/', $match['modifiers']) === 1) {
            return false;
        }

        return str_starts_with($method, 'test')
            || preg_match('/#\[\s*(?:\\\\?PHPUnit\\\\Framework\\\\Attributes\\\\)?Test\s*[\](]/', $match['lead']) === 1
            || str_contains($match['lead'], '@test');
    }

    /** @return list<string> every `path::method` the ledger relies on for capability and transport evidence */
    public static function referencedTests(array $ledger): array
    {
        $references = [];
        foreach ($ledger['capabilities'] ?? [] as $decision) {
            array_push($references, ...($decision['tests'] ?? []));
        }
        foreach ($ledger['operations'] ?? [] as $entry) {
            array_push($references, ...($entry['transport_evidence']['http'] ?? []), ...($entry['transport_evidence']['mcp'] ?? []));
        }

        return array_values(array_unique($references));
    }

    /**
     * Existence is not execution. Given a PHPUnit JUnit report, every referenced
     * method must have run and passed; each data-provider case counts.
     */
    public static function junitErrors(array $ledger, string $xml): array
    {
        $document = new \DOMDocument();
        if (@$document->loadXML($xml) === false) {
            return ['JUnit report is not valid XML'];
        }
        $outcomes = [];
        foreach ($document->getElementsByTagName('testcase') as $case) {
            $file = $case->getAttribute('file');
            $offset = strrpos($file, '/tests/');
            if ($offset === false) {
                continue;
            }
            $method = preg_replace('/ with data set .*\z/s', '', $case->getAttribute('name'));
            $key = substr($file, $offset + 1) . '::' . $method;
            $outcome = 'passed';
            if ($case->getElementsByTagName('failure')->length > 0 || $case->getElementsByTagName('error')->length > 0) {
                $outcome = 'failed';
            } elseif ($case->getElementsByTagName('skipped')->length > 0) {
                $outcome = 'skipped';
            }
            // The worst outcome of any data set stands for the method.
            $rank = ['passed' => 0, 'skipped' => 1, 'failed' => 2];
            if (!isset($outcomes[$key]) || $rank[$outcome] > $rank[$outcomes[$key]]) {
                $outcomes[$key] = $outcome;
            }
        }
        $errors = [];
        foreach (self::referencedTests($ledger) as $reference) {
            $outcome = $outcomes[$reference] ?? null;
            if ($outcome === null) {
                $errors[] = 'Referenced test did not run: ' . $reference;
            } elseif ($outcome === 'failed') {
                $errors[] = 'Referenced test failed: ' . $reference;
            } elseif ($outcome === 'skipped') {
                $errors[] = 'Referenced test was skipped: ' . $reference;
            }
        }

        return $errors;
    }

    public static function actionClass(string $key): string
    {
        $path = substr($key, (int) strpos($key, ' ') + 1);
        foreach (self::PROTOCOL_PREFIXES as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return 'protocol';
            }
        }

        return 'product';
    }

    /** @return array{product:int,protocol:int,dispositions:array<string,array<string,int>>} */
    public static function actionCounts(array $ledger): array
    {
        $counts = ['product' => 0, 'protocol' => 0, 'dispositions' => ['product' => [], 'protocol' => []]];
        foreach ($ledger['human_actions'] ?? [] as $key => $action) {
            $class = self::actionClass($key);
            $disposition = $action['disposition'] ?? 'undecided';
            $counts[$class]++;
            $counts['dispositions'][$class][$disposition] = ($counts['dispositions'][$class][$disposition] ?? 0) + 1;
        }
        foreach ($counts['dispositions'] as &$dispositions) {
            ksort($dispositions);
        }
        unset($dispositions);

        return $counts;
    }

    /** @return array<string,int> */
    private static function verdictCounts(array $ledger): array
    {
        $counts = ['verified' => 0, 'justified_handoff' => 0, 'excluded' => 0, 'incomplete' => 0];
        foreach ($ledger['capabilities'] ?? [] as $decision) {
            $verdict = $decision['verdict'] ?? 'incomplete';
            $counts[$verdict] = ($counts[$verdict] ?? 0) + 1;
        }

        return $counts;
    }

    /** @return array{http:int,mcp:int,total:int} */
    private static function transportCounts(array $ledger): array
    {
        $counts = ['http' => 0, 'mcp' => 0, 'total' => 0];
        foreach (Operations::registry()->all() as $operation) {
            $evidence = $ledger['operations'][$operation->operationId]['transport_evidence'] ?? [];
            $counts['total']++;
            $counts['http'] += empty($evidence['http']) ? 0 : 1;
            $counts['mcp'] += empty($evidence['mcp']) ? 0 : 1;
        }

        return $counts;
    }

    public static function summary(array $ledger): string
    {
        $actions = self::actionCounts($ledger);
        $verdicts = self::verdictCounts($ledger);
        $transports = self::transportCounts($ledger);

        return $actions['product'] . ' product actions and ' . $actions['protocol'] . ' protocol routes (v1, OAuth, discovery, cron); '
            . $transports['total'] . ' application operations (' . $transports['http'] . ' with HTTP and ' . $transports['mcp'] . ' with native MCP success evidence); '
            . count($ledger['capabilities'] ?? []) . ' capability rows (' . $verdicts['verified'] . ' verified, ' . $verdicts['justified_handoff'] . ' justified handoff, '
            . $verdicts['excluded'] . ' excluded, ' . $verdicts['incomplete'] . ' incomplete).';
    }

    private static function testLink(string $reference): string
    {
        [$path, $method] = array_pad(explode('::', $reference, 2), 2, '');

        return '[' . basename($path, '.php') . ($method === '' ? '' : '::' . $method) . '](../../' . $path . ')';
    }

    private static function cell(string $text): string
    {
        return str_replace(['|', "\n"], ['\\|', ' '], $text);
    }

    public static function render(array $ledger): string
    {
        $verdicts = self::verdictCounts($ledger);
        $transports = self::transportCounts($ledger);
        $lines=['# Agent action parity ledger','',
            'Generated from reviewed human-action decisions and the live operation registry by `php scripts/check-agent-parity.php --render`. Every capability and transport test is named as `path::method`; `--junit=<report>` proves each one ran and passed.','',
            '## Capabilities','',
            $verdicts['verified'] . ' verified, ' . $verdicts['justified_handoff'] . ' justified handoff, ' . $verdicts['excluded'] . ' excluded, ' . $verdicts['incomplete'] . ' incomplete.','',
            '| Row | Verdict | Operations | Rationale | Missing | Tests |',
            '| --- | --- | --- | --- | --- | --- |'];
        foreach ($ledger['capabilities'] ?? [] as $id => $decision) {
            $operations = implode(', ', array_map(static fn ($name) => '`' . $name . '`', $decision['operations'] ?? []));
            $tests = implode('<br>', array_map(self::testLink(...), $decision['tests'] ?? []));
            $lines[] = '| ' . $id . ' | ' . str_replace('_', ' ', $decision['verdict'] ?? '') . ' | ' . $operations . ' | ' . self::cell($decision['rationale'] ?? '')
                . ' | ' . self::cell(implode('; ', $decision['missing'] ?? [])) . ' | ' . $tests . ' |';
        }
        $lines = [...$lines, '', '## Operations', '',
            $transports['http'] . ' of ' . $transports['total'] . ' operations have an HTTP success test and ' . $transports['mcp'] . ' have a native MCP success test. "Contract only" means the transport serves the shared operation but no test has run it there yet.', '',
            '| Capability | Operation | Actor | Scopes | HTTP | HTTP run | Native MCP | Review | Tests | Guide |',
            '| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |'];
        foreach(Operations::registry()->all() as $op) {
            $entry = $ledger['operations'][$op->operationId];
            $tests=implode(', ',array_map(static fn($file)=>'['.basename($file).'](../../'.$file.')',$entry['tests']));
            $http = empty($entry['transport_evidence']['http']) ? 'contract only' : 'verified';
            $mcp = empty($entry['transport_evidence']['mcp']) ? 'contract only' : 'verified';
            $lines[]='| '.implode(', ',$op->capabilityIds).' | `'.$op->operationId.'` | '.$op->actorPolicy.' | '.implode(', ',$op->requiredScopes).' | `'.$op->httpMethod.' /api/agent/v2'.$op->httpPath.'` | '.$http.' | '.$mcp.' | '.$op->confirmationPolicy.' | '.$tests.' | ['.$op->documentationPage.'](https://voxelbooking.com/docs/agent-api/'.$op->documentationPage.') |';
        }
        $actions = self::actionCounts($ledger);
        $describe = static fn (array $dispositions): string => implode(', ', array_map(
            static fn ($disposition, $count) => $count . ' ' . str_replace('_', ' ', $disposition),
            array_keys($dispositions),
            $dispositions
        ));
        $lines=[...$lines,'','## Human actions','',
            'Product actions: ' . $actions['product'] . ' (' . $describe($actions['dispositions']['product']) . '). Protocol routes for legacy v1, OAuth, discovery and cron are counted separately: ' . $actions['protocol'] . ' (' . $describe($actions['dispositions']['protocol']) . ').','',
            '| Human action | Class | Handler | Decision | Operations | Rationale |','| --- | --- | --- | --- | --- | --- |'];
        foreach($ledger['human_actions'] as $key=>$action) {
            $lines[]='| `'.$key.'` | '.self::actionClass($key).' | `'.$action['handler'].'` | '.$action['disposition'].' | '.implode(', ',$action['operations']).' | '.$action['rationale'].' |';
        }
        return implode("\n",$lines)."\n";
    }
}
