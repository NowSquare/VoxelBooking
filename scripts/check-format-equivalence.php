<?php

declare(strict_types=1);

/**
 * Prove that a reformatting change preserves behaviour.
 *
 * Compares the PHP token stream of each file with its version at a Git
 * revision, ignoring whitespace and comments. Any other difference, even a
 * reordered array key or an extracted variable, fails the check. Use it when a
 * change claims to be formatting only, then run the test suite as usual.
 *
 * Usage: php scripts/check-format-equivalence.php <git-revision> <file>...
 */

if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/check-format-equivalence.php <git-revision> <file>...\n");
    exit(2);
}

$revision = $argv[1];
$files = array_slice($argv, 2);
$failures = 0;

foreach ($files as $file) {
    $current = @file_get_contents($file);
    if ($current === false) {
        fwrite(STDERR, "missing: {$file}\n");
        $failures++;
        continue;
    }

    $baseline = shell_exec('git show ' . escapeshellarg($revision . ':' . $file) . ' 2>/dev/null');
    if (!is_string($baseline) || $baseline === '') {
        fwrite(STDERR, "not in {$revision}: {$file}\n");
        $failures++;
        continue;
    }

    $difference = firstDifference(significantTokens($baseline), significantTokens($current));
    if ($difference === null) {
        fwrite(STDOUT, "same: {$file}\n");
        continue;
    }

    $failures++;
    fwrite(STDOUT, "CHANGED: {$file} at token {$difference['index']}: "
        . "baseline `{$difference['baseline']}` vs current `{$difference['current']}` (line {$difference['line']})\n");
}

exit($failures === 0 ? 0 : 1);

/**
 * @return list<array{0: string, 1: int}> token text and current line
 */
function significantTokens(string $source): array
{
    $ignored = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];
    $tokens = [];
    $line = 1;

    foreach (token_get_all($source) as $token) {
        if (is_array($token)) {
            $line = $token[2];
            if (in_array($token[0], $ignored, true)) {
                continue;
            }
            $tokens[] = [$token[1], $line];
            continue;
        }
        $tokens[] = [$token, $line];
    }

    return $tokens;
}

/**
 * @param list<array{0: string, 1: int}> $baseline
 * @param list<array{0: string, 1: int}> $current
 * @return array{index: int, baseline: string, current: string, line: int}|null
 */
function firstDifference(array $baseline, array $current): ?array
{
    $length = max(count($baseline), count($current));
    for ($index = 0; $index < $length; $index++) {
        $before = $baseline[$index][0] ?? '<end>';
        $after = $current[$index][0] ?? '<end>';
        if ($before !== $after) {
            return [
                'index' => $index,
                'baseline' => $before,
                'current' => $after,
                'line' => $current[$index][1] ?? 0,
            ];
        }
    }

    return null;
}
