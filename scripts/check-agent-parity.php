#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Verify the agent parity ledger, optionally recording reviews and rendering it.
 *
 *   php scripts/check-agent-parity.php
 *   php scripts/check-agent-parity.php --render
 *   php scripts/check-agent-parity.php --reviewed="POST /api/{slug}/bookings"
 *
 * `--reviewed` records that a person re-reviewed a changed human action and
 * its parity decision still holds. Only the named actions are updated, so a
 * changed handler can never pass the gate without someone naming it.
 */

require dirname(__DIR__) . '/vendor/autoload.php';
require_once dirname(__DIR__) . '/tests/Support/ParityLedger.php';

use Tests\Support\ParityLedger;

$path = dirname(__DIR__) . '/tests/Fixtures/agent-parity.json';
$ledger = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

$reviewed = [];
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--reviewed=')) {
        $reviewed[] = substr($argument, strlen('--reviewed='));
    }
}
if ($reviewed !== []) {
    $routes = [];
    foreach (ParityLedger::humanRoutes(ParityLedger::routes()) as $route) {
        $routes[ParityLedger::key($route)] = $route;
    }
    foreach ($reviewed as $key) {
        if (!isset($routes[$key], $ledger['human_actions'][$key])) {
            fwrite(STDERR, "Unknown human action: {$key}\n");
            exit(1);
        }
        $ledger['human_actions'][$key]['source_hash'] = ParityLedger::sourceHash($routes[$key]);
        fwrite(STDOUT, "Recorded review: {$key}\n");
    }
    $json = json_encode($ledger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
    // Keep the fixture's two-space indentation so a review changes only its hashes.
    $json = preg_replace_callback('/^( +)/m', static fn (array $m): string => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);
    file_put_contents($path, $json);
}

$errors = ParityLedger::verify($ledger);
if ($errors !== []) {
    fwrite(STDERR, implode("\n", $errors) . "\n");
    exit(1);
}

if (in_array('--render', $argv, true)) {
    $directory = dirname(__DIR__) . '/docs/agent-api';
    if (!is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    file_put_contents($directory . '/parity-ledger.md', ParityLedger::render($ledger));
}

fwrite(STDOUT, 'Parity verified: ' . count($ledger['human_actions']) . ' human routes; '
    . count($ledger['operations']) . " application operations; 42 capability groups.\n");
