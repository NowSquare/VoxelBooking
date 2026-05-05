#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Demo reset script — reseeds the MySQL demo database.
 *
 * Designed for daily cron execution on the demo server:
 *   0 3 * * * /usr/bin/php /path/to/voxelbooking/scripts/reset-demo.php >> /var/log/vb-demo-reset.log 2>&1
 *
 * What it does:
 *   1. Verifies the .demo sentinel exists (safety: never runs on production).
 *   2. Drops all tables, re-runs migrations, re-seeds the showcase dataset.
 *   3. Preserves the .demo sentinel file (never touches it).
 *   4. Clears PHP session files so demo visitors start fresh.
 *
 * Usage:
 *   php scripts/reset-demo.php          # normal execution (cron or manual)
 *   php scripts/reset-demo.php --dry    # preview what would happen
 *
 * Exit codes:
 *   0 — success
 *   1 — not a demo environment (missing .demo sentinel)
 *   2 — seed script failed
 */

$basePath = dirname(__DIR__);
$sentinel = $basePath . '/.demo';
$seedScript = $basePath . '/demo-seed-mysql.php';

$isDryRun = in_array('--dry', $argv, true);
$timestamp = date('Y-m-d H:i:s');

echo "[{$timestamp}] VoxelBooking demo reset\n";

// ── Safety gate: require .demo sentinel ──
if (!file_exists($sentinel)) {
    echo "✗ ABORT: .demo sentinel not found at {$sentinel}\n";
    echo "  This script must only run on demo environments.\n";
    exit(1);
}

echo "  ✓ Demo sentinel confirmed\n";

if ($isDryRun) {
    echo "  [DRY RUN] Would drop all tables, re-run migrations, and re-seed.\n";
    echo "  [DRY RUN] Would clear session files.\n";
    echo "  [DRY RUN] No changes made.\n";
    exit(0);
}

// ── Re-seed the database ──
// demo-seed-mysql.php reads .env for DB credentials,
// drops all tables, runs migrations, inserts showcase data.
// We pipe 'yes' to stdin to skip the interactive confirmation.
echo "  Reseeding database...\n";

$descriptorSpec = [
    0 => ['pipe', 'r'],  // stdin
    1 => ['pipe', 'w'],  // stdout
    2 => ['pipe', 'w'],  // stderr
];

$process = proc_open(
    [PHP_BINARY, $seedScript],
    $descriptorSpec,
    $pipes,
    $basePath
);

if (!is_resource($process)) {
    echo "✗ Failed to start seed script: {$seedScript}\n";
    exit(2);
}

// Send Enter to skip the confirmation prompt
fwrite($pipes[0], "\n");
fclose($pipes[0]);

// Capture output
$stdout = stream_get_contents($pipes[1]);
fclose($pipes[1]);
$stderr = stream_get_contents($pipes[2]);
fclose($pipes[2]);

$exitCode = proc_close($process);

if ($exitCode !== 0) {
    echo "✗ Seed script failed (exit code {$exitCode})\n";
    if ($stderr) {
        echo "  stderr: {$stderr}\n";
    }
    if ($stdout) {
        echo "  stdout: {$stdout}\n";
    }
    exit(2);
}

// Show seed output (indented for log readability)
foreach (explode("\n", trim($stdout)) as $line) {
    echo "  {$line}\n";
}

// ── Clear session files ──
$sessionPath = $basePath . '/storage/sessions';
if (is_dir($sessionPath)) {
    $cleared = 0;
    foreach (glob($sessionPath . '/sess_*') as $sessionFile) {
        @unlink($sessionFile);
        $cleared++;
    }
    echo "  ✓ Cleared {$cleared} session files\n";
}

// ── Verify .demo sentinel survived ──
if (!file_exists($sentinel)) {
    echo "✗ WARNING: .demo sentinel was deleted during reset! Restoring...\n";
    touch($sentinel);
}

$endTime = date('Y-m-d H:i:s');
echo "[{$endTime}] ✓ Demo reset complete\n";
exit(0);
