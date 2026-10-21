#!/usr/bin/env php
<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
require_once dirname(__DIR__).'/tests/Support/ParityLedger.php';
use Tests\Support\ParityLedger;

$path=dirname(__DIR__).'/tests/Fixtures/agent-parity.json';
$ledger=json_decode(file_get_contents($path),true,flags:JSON_THROW_ON_ERROR);
$errors=ParityLedger::verify($ledger);
if($errors!==[]) { fwrite(STDERR,implode("\n",$errors)."\n");exit(1); }
if(in_array('--render',$argv,true)) {
    $directory=dirname(__DIR__).'/docs/agent-api';
    if(!is_dir($directory)) { mkdir($directory,0755,true); }
    file_put_contents($directory.'/parity-ledger.md',ParityLedger::render($ledger));
}
fwrite(STDOUT,'Parity verified: '.count($ledger['human_actions']).' human routes; '.count($ledger['operations'])." application operations; 42 capability groups.\n");
