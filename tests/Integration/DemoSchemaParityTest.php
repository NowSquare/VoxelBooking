<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use PHPUnit\Framework\TestCase;

final class DemoSchemaParityTest extends TestCase
{
    public function testFictionalSqliteArtifactMirrorsMigratedMysqlColumns(): void
    {
        \Tests\Support\TestEnvironment::boot();
        \Tests\Support\TestEnvironment::assertDatabase();
        $root = dirname(__DIR__, 2);
        $process = proc_open([PHP_BINARY, $root . '/demo-seed.php'], [1 => ['pipe','w'], 2 => ['pipe','w']], $pipes, $root);
        self::assertIsResource($process);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $output);
        $demo = new \PDO('sqlite:' . $root . '/storage/demo/demo.db');
        $mysqlVersion = Database::query("SELECT value FROM settings WHERE `key`='db_version'")[0]['value'];
        self::assertSame($mysqlVersion, $demo->query("SELECT value FROM settings WHERE `key`='db_version'")->fetchColumn());
        $tables = [];
        foreach (glob($root . '/app/Migrations/*.php') as $file) {
            foreach (require $file as $statement) {
                if (preg_match('/CREATE TABLE IF NOT EXISTS `?([a-z_]+)`?/i', $statement, $match)) $tables[] = $match[1];
            }
        }
        $differences = [];
        foreach (array_unique($tables) as $table) {
            $actual = array_column($demo->query("PRAGMA table_info(`{$table}`)")->fetchAll(\PDO::FETCH_ASSOC), 'name');
            $expected = array_column(Database::query("SHOW COLUMNS FROM `{$table}`"), 'Field');
            sort($actual); sort($expected);
            if ($actual !== $expected) $differences[$table] = ['missing'=>array_values(array_diff($expected,$actual)), 'extra'=>array_values(array_diff($actual,$expected))];
        }
        self::assertSame([], $differences, json_encode($differences, JSON_PRETTY_PRINT));
        foreach (['agent_credentials','oauth_access_tokens','customer_capabilities','delivery_outbox','webhook_subscriptions'] as $table) {
            self::assertSame(0, (int)$demo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn(), 'Demo artifact must not contain live credentials or pending external delivery.');
        }
    }
}
