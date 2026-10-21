<?php

declare(strict_types=1);

namespace Tests\Unit\Agent;

use App\Agent\PayloadCipher;
use App\Engine\Logger;
use PHPUnit\Framework\TestCase;

final class PayloadCipherTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/vb-key-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }

    protected function tearDown(): void
    {
        // Point the logger back at the application before its temporary directory disappears.
        Logger::init(dirname(__DIR__, 3) . '/storage');
        $this->deleteTree($this->directory);
    }

    public function testConcurrentInstallersProvisionOneCompleteKey(): void
    {
        if (!function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the key creation race.');
        }
        $path = $this->directory . '/keys/payload.key';
        $children = [];
        for ($i = 0; $i < 8; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                try {
                    PayloadCipher::provision($path);
                    $cipher = (new PayloadCipher(keyPath: $path))->encrypt(['index' => $i]);
                    file_put_contents($this->directory . '/cipher-' . $i, $cipher);
                    exit(0);
                } catch (\Throwable) {
                    exit(1);
                }
            }
            self::assertGreaterThan(0, $pid);
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }

        $cipher = new PayloadCipher(keyPath: $path);
        for ($i = 0; $i < 8; $i++) {
            self::assertSame(['index' => $i], $cipher->decrypt(file_get_contents($this->directory . '/cipher-' . $i)));
        }
        self::assertSame(0600, fileperms($path) & 0777);
    }

    public function testProvisioningKeepsAnExistingKey(): void
    {
        $path = $this->directory . '/payload.key';
        PayloadCipher::provision($path);
        $sealed = (new PayloadCipher(keyPath: $path))->encrypt(['kept' => true]);

        PayloadCipher::provision($path);

        self::assertSame(['kept' => true], (new PayloadCipher(keyPath: $path))->decrypt($sealed));
    }

    public function testLostKeyBesideSealedDataIsNeverReplaced(): void
    {
        \Tests\Support\TestEnvironment::assertDatabase();
        $path = $this->directory . '/agent-payload.key';
        $id = \App\Engine\Ulid::generate();
        \App\Engine\Database::execute(
            "INSERT INTO delivery_outbox (id, topic, dedupe_hash, payload_cipher, available_at, created_at) VALUES (?, 'test.sealed', ?, 'sealed-with-a-lost-key', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$id, hash('sha256', $id)]
        );
        try {
            PayloadCipher::provisionForInstallation($path);

            $this->assertFileDoesNotExist($path, 'A new key could never open the sealed rows and would hide the loss.');
        } finally {
            \App\Engine\Database::execute('DELETE FROM delivery_outbox WHERE id = ?', [$id]);
        }
    }

    public function testMissingKeyAtRuntimeFailsLoudlyWithoutCreatingOne(): void
    {
        Logger::init($this->directory);
        $path = $this->directory . '/keys/payload.key';

        try {
            (new PayloadCipher(keyPath: $path))->encrypt(['secret' => 'never-sealed']);
            self::fail('A missing key was replaced at runtime.');
        } catch (\RuntimeException $error) {
            self::assertSame(PayloadCipher::KEY_MISSING, $error->getMessage());
        }

        self::assertFileDoesNotExist($path);
        self::assertDirectoryDoesNotExist(dirname($path));
        $log = (string) file_get_contents($this->directory . '/logs/app.log');
        self::assertStringContainsString(PayloadCipher::KEY_MISSING, $log);
        self::assertStringNotContainsString('never-sealed', $log);
    }

    public function testTruncatedKeyIsRejectedRatherThanUsed(): void
    {
        Logger::init($this->directory);
        $path = $this->directory . '/payload.key';
        file_put_contents($path, 'short');

        $this->expectExceptionMessage(PayloadCipher::KEY_INVALID);
        (new PayloadCipher(keyPath: $path))->encrypt(['value' => 1]);
    }

    private function deleteTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->deleteTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
