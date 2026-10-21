<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Engine\Database;
use App\Engine\Mailer;
use PHPUnit\Framework\TestCase;
use Tests\Support\TestEnvironment;

final class TestIsolationTest extends TestCase
{
    public function testDatabaseUserCannotReadInstallationOutsideRun(): void
    {
        TestEnvironment::assertDatabase();
        $grants = Database::query('SHOW GRANTS');
        $text = implode("\n", array_map(static fn (array $row): string => (string) reset($row), $grants));
        self::assertStringContainsString($_ENV['DB_DATABASE'], str_replace('\\_', '_', $text));
        self::assertStringNotContainsString('ALL PRIVILEGES ON *.*', $text);
        $this->expectException(\PDOException::class);
        Database::query('SELECT * FROM mysql.user');
    }

    public function testDatabaseGuardRejectsChangedDestinationBeforePdo(): void
    {
        $original = $_ENV['DB_DATABASE'];
        Database::reset();
        try {
            $_ENV['DB_DATABASE'] = 'voxelbooking';
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Unsafe test database configuration: DB_DATABASE');
            Database::connect();
        } finally {
            $_ENV['DB_DATABASE'] = $original;
            Database::reset();
        }
    }

    public function testServerAndTestsUseTheSameRun(): void
    {
        $manifest = TestEnvironment::manifest();
        $context = stream_context_create(['http' => ['header' => 'X-Test-Run: ' . $manifest['run_id']]]);
        self::assertSame($manifest['run_id'], file_get_contents($_ENV['APP_TEST_URL'] . '/__test/ready', false, $context));
        self::assertSame(realpath($manifest['app_root']), realpath(dirname(__DIR__, 2)));
    }

    public function testMailCaptureCannotBeBypassedByChangingTransport(): void
    {
        $rows = Database::query("SELECT `value` FROM settings WHERE `key` = 'mail_transport'");
        try {
            Database::upsertSetting('mail_transport', 'mailpit');
            Mailer::clearConfigCache();
            $subject = 'Isolation probe ' . bin2hex(random_bytes(6));
            $result = Mailer::send('synthetic@example.test', $subject, '<p>synthetic content</p>');
            self::assertTrue($result['sent']);
            $capture = file_get_contents(TestEnvironment::manifest()['run_root'] . '/mail.jsonl');
            self::assertStringContainsString($subject, $capture);
        } finally {
            Database::upsertSetting('mail_transport', $rows[0]['value'] ?? 'log');
            Mailer::clearConfigCache();
        }
    }
}
