<?php

declare(strict_types=1);
namespace App\Agent\OAuth;

/** Key creation is locked so concurrent PHP requests cannot read a partial key. */
final class KeyStore
{
    public function __construct(private readonly string $directory) {}

    public function load(): array
    {
        if (!is_dir($this->directory) && !mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new \RuntimeException('OAuth key directory unavailable.');
        }
        $lock = fopen($this->directory . '/oauth.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException('OAuth key lock unavailable.');
        }
        chmod($this->directory . '/oauth.lock', 0600);
        try {
            $path = $this->directory . '/oauth.json';
            if (!is_file($path)) {
                $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
                if ($key === false || !openssl_pkey_export($key, $pem)) {
                    throw new \RuntimeException('OAuth key generation failed.');
                }
                $payload = json_encode(['private_key' => $pem, 'encryption_key' => bin2hex(random_bytes(32))], JSON_THROW_ON_ERROR);
                $handle = fopen($path, 'x');
                if ($handle === false) {
                    throw new \RuntimeException('OAuth key write failed.');
                }
                chmod($path, 0600);
                $written = fwrite($handle, $payload);
                fflush($handle);
                fclose($handle);
                if ($written !== strlen($payload)) {
                    throw new \RuntimeException('OAuth key write incomplete.');
                }
            }
            $keys = json_decode(file_get_contents($path) ?: '', true, flags: JSON_THROW_ON_ERROR);
            if (!is_string($keys['private_key'] ?? null) || !is_string($keys['encryption_key'] ?? null) || strlen($keys['encryption_key']) < 64) {
                throw new \RuntimeException('OAuth keys invalid.');
            }
            return $keys;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
