<?php

declare(strict_types=1);

namespace App\Agent;

use App\Engine\Database;

/**
 * Encrypted short-lived reviews/results and stored secrets. Key stays outside the public directory.
 *
 * Only installation provisions the key. Every webhook secret, queued delivery and
 * review is sealed with it, so a key that silently reappeared after being lost
 * would orphan all of them while the site looked healthy. At runtime a missing
 * key is an outage to restore from backup, and it fails loudly.
 */
final class PayloadCipher
{
    /** Stable failure codes: logged and thrown, and safe to show because they carry no key material. */
    public const KEY_MISSING = 'payload_key_missing';
    public const KEY_INVALID = 'payload_key_invalid';

    public function __construct(private ?string $key = null, private readonly ?string $keyPath = null) {}

    /**
     * Create the key when none exists. Called from installation and test setup only.
     *
     * An existing key is always kept: re-running setup must never re-key sealed data.
     */
    /** Columns sealed with the payload key. Data here means a key once existed. */
    private const SEALED_COLUMNS = [
        ['agent_operation_receipts', 'result_cipher'],
        ['agent_previews', 'payload_cipher'],
        ['delivery_outbox', 'payload_cipher'],
        ['webhook_subscriptions', 'secret_cipher'],
        ['webhook_deliveries', 'payload_cipher'],
    ];

    /**
     * Create the key for an installation that has none, after its schema migrates.
     *
     * Installation, both update paths and the test harness migrate through
     * Migrator, so every installation gets its key here. A missing key beside
     * sealed data means the key was lost, not never created: a new key would
     * hide the loss and could never open that data. Then nothing is created, the
     * loss is logged, and encrypted features keep failing loudly until an
     * operator restores the key from the backup it belongs with.
     */
    public static function provisionForInstallation(?string $keyPath = null): void
    {
        $path = $keyPath ?? self::defaultPath();
        if (is_file($path)) {
            return;
        }
        foreach (self::SEALED_COLUMNS as [$table, $column]) {
            $exists = Database::query(
                'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?',
                [$table, $column]
            );
            if ($exists && Database::query("SELECT 1 FROM `{$table}` WHERE `{$column}` IS NOT NULL LIMIT 1")) {
                \App\Engine\Logger::error('payload_key_lost', ['file' => basename($path), 'sealed_table' => $table]);
                return;
            }
        }
        self::provision($path);
    }

    public static function provision(?string $keyPath = null): void
    {
        $path = $keyPath ?? self::defaultPath();
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new \RuntimeException('Cannot create protected key directory.');
        }
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Storage key lock unavailable.');
        }
        @chmod($path . '.lock', 0600);
        try {
            // Concurrent installers serialise here, so the loser sees the winner's complete key.
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Storage key lock unavailable.');
            }
            if (is_file($path)) {
                return;
            }
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                throw new \RuntimeException('Storage key unavailable.');
            }
            try {
                chmod($path, 0600);
                $written = fwrite($handle, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
                if ($written !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES || !fflush($handle)) {
                    throw new \RuntimeException('Cannot persist storage key.');
                }
            } finally {
                fclose($handle);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function encrypt(array $value): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox(
            json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            $nonce,
            $this->key()
        ));
    }

    public function decrypt(string $value): array
    {
        $bytes = base64_decode($value, true);
        if ($bytes === false || strlen($bytes) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new \RuntimeException('Invalid encrypted payload.');
        }
        // Layout: nonce followed by the authenticated ciphertext.
        $plaintext = sodium_crypto_secretbox_open(
            substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $this->key()
        );
        if ($plaintext === false) {
            throw new \RuntimeException('Payload authentication failed.');
        }
        return json_decode($plaintext, true, flags: JSON_THROW_ON_ERROR);
    }

    public function deriveKey(string $context): string
    {
        return hash_hmac('sha256', $context, $this->key());
    }

    private static function defaultPath(): string
    {
        return dirname(__DIR__, 2) . '/storage/keys/agent-payload.key';
    }

    private function key(): string
    {
        if ($this->key !== null) {
            if (strlen($this->key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException('Invalid storage key.');
            }
            return $this->key;
        }
        $path = $this->keyPath ?? self::defaultPath();
        // The file is written whole under the installer's lock and never rewritten, so a plain read is safe.
        if (!is_file($path)) {
            self::fail(self::KEY_MISSING, $path);
        }
        $key = @file_get_contents($path);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            self::fail(self::KEY_INVALID, $path);
        }
        return $this->key = $key;
    }

    /** Log which file is wrong and how to recover; never the key bytes or anything sealed with them. */
    private static function fail(string $code, string $path): never
    {
        \App\Engine\Logger::error($code . ': restore storage/keys/ from the backup that matches this database.', [
            'key_file' => basename($path),
        ]);
        throw new \RuntimeException($code);
    }
}
