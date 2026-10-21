<?php
declare(strict_types=1);
namespace Tests\Support;

use App\Agent\{CanonicalJson,Operations};
use App\Engine\Router;

/** Build-time coverage gate. No application boot or database access is needed. */
final class ParityLedger
{
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
    public static function sourceHash(array $route): string
    {
        $method=new \ReflectionMethod(...$route['handler']);
        $lines=file($method->getFileName());
        return hash('sha256',implode('',array_slice($lines,$method->getStartLine()-1,$method->getEndLine()-$method->getStartLine()+1)));
    }
    public static function contractHash(\App\Agent\OperationDefinition $operation): string
    {
        return CanonicalJson::hash([$operation->inputSchema,$operation->resultSchema(),$operation->requiredScopes,$operation->actorPolicy,
            $operation->confirmationPolicy,$operation->patterns,$operation->httpMethod,$operation->httpPath]);
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
        $capabilities=[];
        foreach($registry->all() as $operation) {
            $entry=$ledger['operations'][$operation->operationId]??null;
            if(!$entry) { $errors[]='Unmapped operation: '.$operation->operationId;continue; }
            if(($entry['contract_hash']??'')!==self::contractHash($operation)) { $errors[]='Changed contract needs parity review: '.$operation->operationId; }
            if(empty($entry['tests'])||empty($operation->documentationPage)) { $errors[]='Missing evidence for '.$operation->operationId; }
            foreach($entry['tests']??[] as $test) { if(!is_file($root.'/'.$test)) { $errors[]='Missing operation test '.$test; } }
            foreach($operation->capabilityIds as $id) { $capabilities[$id]=true; }
        }
        for($number=1;$number<=42;$number++) {
            $id=sprintf('C%02d',$number);
            if(!isset($capabilities[$id])) { $errors[]='Missing capability '.$id; }
        }
        return array_values(array_unique($errors));
    }
    public static function render(array $ledger): string
    {
        $lines=['# Agent action parity ledger','',
            'Generated from reviewed human-action decisions and the live operation registry. Test links identify coverage; execution results are recorded separately in release evidence.','',
            '| Capability | Operation | Actor | Scopes | HTTP | Native MCP | Review | Tests | Guide |',
            '| --- | --- | --- | --- | --- | --- | --- | --- | --- |'];
        foreach(Operations::registry()->all() as $op) {
            $tests=implode(', ',array_map(static fn($file)=>'['.basename($file).'](../../'.$file.')',$ledger['operations'][$op->operationId]['tests']));
            $lines[]='| '.implode(', ',$op->capabilityIds).' | `'.$op->operationId.'` | '.$op->actorPolicy.' | '.implode(', ',$op->requiredScopes).' | `'.$op->httpMethod.' /api/agent/v2'.$op->httpPath.'` | `'.$op->operationId.'` | '.$op->confirmationPolicy.' | '.$tests.' | ['.$op->documentationPage.'](https://voxelbooking.com/docs/agent-api/'.$op->documentationPage.') |';
        }
        $lines=[...$lines,'','## Human actions','','| Human action | Handler | Decision | Operations | Rationale |','| --- | --- | --- | --- | --- |'];
        foreach($ledger['human_actions'] as $key=>$action) {
            $lines[]='| `'.$key.'` | `'.$action['handler'].'` | '.$action['disposition'].' | '.implode(', ',$action['operations']).' | '.$action['rationale'].' |';
        }
        return implode("\n",$lines)."\n";
    }
}
