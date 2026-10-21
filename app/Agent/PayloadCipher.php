<?php

declare(strict_types=1);

namespace App\Agent;

/** Encrypted short-lived reviews/results. Key stays outside the public directory. */
final class PayloadCipher
{
    public function __construct(private ?string $key = null, private readonly ?string $keyPath = null) {}

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

    private function key(): string
    {
        if ($this->key !== null) {
            if (strlen($this->key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
                throw new \RuntimeException('Invalid storage key.');
            }
            return $this->key;
        }
        $path = $this->keyPath ?? dirname(__DIR__, 2) . '/storage/keys/agent-payload.key';
        if (!is_dir(dirname($path)) && !mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) {
            throw new \RuntimeException('Cannot create protected key directory.');
        }
        $lock = @fopen($path . '.lock', 'c');
        if ($lock === false) {
            throw new \RuntimeException('Storage key lock unavailable.');
        }
        @chmod($path . '.lock', 0600);
        try {
            if (!flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Storage key lock unavailable.');
            }
            if (!is_file($path)) {
                // All readers hold this lock, including the losing initializer.
                $handle = @fopen($path, 'x');
                if ($handle === false) {
                    throw new \RuntimeException('Storage key unavailable.');
                }
                try {
                    chmod($path, 0600);
                    if (fwrite($handle, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES
                        || !fflush($handle)) {
                        throw new \RuntimeException('Cannot persist storage key.');
                    }
                } finally {
                    fclose($handle);
                }
            }
            $key = file_get_contents($path);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new \RuntimeException('Storage key unavailable.');
        }
        return $this->key = $key;
    }
}
