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
 * Usage: php scripts/check-format-equivalence.php [--ast] <git-revision> <file>...
 *
 * --ast compares syntax trees instead of tokens, so it also accepts changes
 * that only alter how the same program is written, such as braces around a
 * single-statement body or a trailing comma. The trees ignore whitespace,
 * comments and positions; any change to what the code does still fails.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

$arguments = array_slice($argv, 1);
$ast = ($arguments[0] ?? '') === '--ast';
if ($ast) {
    array_shift($arguments);
}
if (count($arguments) < 2) {
    fwrite(STDERR, "Usage: php scripts/check-format-equivalence.php [--ast] <git-revision> <file>...\n");
    exit(2);
}

$revision = $arguments[0];
$files = array_slice($arguments, 1);
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

    if ($ast) {
        $same = syntaxTree($baseline) === syntaxTree($current);
        fwrite(STDOUT, ($same ? 'same' : 'CHANGED') . ": {$file}\n");
        $failures += $same ? 0 : 1;
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

/** The program's syntax tree, without positions, formatting or comments. */
function syntaxTree(string $source): string
{
    $parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();

    return (new PhpParser\NodeDumper(['dumpComments' => false]))->dump($parser->parse($source) ?? []);
}

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
