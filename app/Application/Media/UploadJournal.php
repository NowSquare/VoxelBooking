<?php

declare(strict_types=1);

namespace App\Application\Media;

use App\Agent\OperationException;
use App\Application\Shared\Access;
use App\Engine\{Database, ImageUpload, Ulid};

/**
 * Filesystem write-ahead intent for the filesystem/database boundary.
 * A lost commit acknowledgment must never cause deletion of committed media.
 */
final class UploadJournal
{
    private function __construct(private string $file, private mixed $handle)
    {
    }

    public static function begin(string $businessId, string $mediaId, string $path): self
    {
        if (!self::validPath($path)) {
            throw new OperationException('upload_grant_invalid', 422);
        }
        $directory = self::directory();
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new OperationException('image_processing_unavailable', 503);
        }

        $file = $directory . '/' . Ulid::generate() . '.json';
        $handle = @fopen($file, 'x+b');
        if ($handle === false) {
            throw new OperationException('image_processing_unavailable', 503);
        }
        try {
            if (!chmod($file, 0600) || !flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot protect upload intent');
            }
            $json = json_encode(
                [
                    'version' => 1,
                    'business_id' => $businessId,
                    'media_id' => $mediaId,
                    'path' => $path,
                    'created_at' => time()
                ],
                JSON_THROW_ON_ERROR
            );
            if (fwrite($handle, $json) !== strlen($json) || !fflush($handle) || !fsync($handle)) {
                throw new \RuntimeException('Cannot persist upload intent');
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($file);
            throw new OperationException('image_processing_unavailable', 503);
        }

        return new self($file, $handle);
    }

    /** Call only after a known successful outer commit. */
    public function finish(): void
    {
        @unlink($this->file);
        $this->release();
    }

    /** Keep the intent on uncertainty; reconciliation decides using durable state. */
    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }

        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }

    public static function reconcile(int $minimumAgeSeconds = 3600): array
    {
        $counts = ['preserved' => 0, 'removed' => 0, 'deferred' => 0];
        $directory = self::directory();
        if (!is_dir($directory)) {
            return $counts;
        }
        $examined = 0;
        foreach (new \DirectoryIterator($directory) as $entry) {
            if (
                !$entry->isFile()
                || $entry->isLink()
                || $entry->getExtension() !== 'json'
                || $entry->getMTime() > time() - max(0, $minimumAgeSeconds)
            ) {
                continue;
            }
            if (++$examined > 100) {
                break;
            }
            $handle = @fopen($entry->getPathname(), 'r+b');
            if ($handle === false) {
                continue;
            }
            try {
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $raw = stream_get_contents($handle, 4097);
                if (strlen($raw) > 4096) {
                    throw new \RuntimeException('Invalid upload intent');
                }
                $intent = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
                if (
                    ($intent['version'] ?? null) !== 1
                    || !is_string($intent['business_id'] ?? null)
                    || !is_string($intent['media_id'] ?? null)
                    || !self::validPath($intent['path'] ?? null)
                ) {
                    throw new \RuntimeException('Invalid upload intent');
                }

                $outcome = Access::transaction(static function () use ($intent): string {
                    // Serialize with receive/consume and catalog updates. If a
                    // writer still owns its transaction, this read waits, then
                    // examines the actual committed state rather than its age.
                    Database::query('SELECT id FROM tenants WHERE id=? FOR UPDATE', [$intent['business_id']]);
                    $grant = Database::query(
                        'SELECT path FROM agent_media_grants WHERE id=? FOR UPDATE',
                        [$intent['media_id']]
                    )[0] ?? null;
                    if (($grant['path'] ?? null) === $intent['path'] || self::referenced($intent['path'])) {
                        return 'preserved';
                    }
                    ImageUpload::delete($intent['path']);
                    if (is_file(self::absolute($intent['path']))) {
                        throw new \RuntimeException('Upload cleanup failed');
                    }
                    return 'removed';
                });
                if (!@unlink($entry->getPathname())) {
                    throw new \RuntimeException('Upload intent cleanup failed');
                }
                $counts[$outcome]++;
            } catch (\Throwable) {
                // Database/storage unavailable or malformed intent: keep both
                // files. The next cron run can safely retry without data loss.
                $counts['deferred']++;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        }

        return $counts;
    }

    private static function referenced(string $path): bool
    {
        foreach ([
            'tenants' => 'logo_path',
            'services' => 'cover_image_path',
            'staff' => 'avatar_path',
            'resources' => 'cover_image_path',
            'events' => 'cover_image_path'
        ] as $table => $column) {
            if (Database::query("SELECT id FROM `$table` WHERE `$column`=? LIMIT 1", [$path]) !== []) {
                return true;
            }
        }

        return false;
    }

    private static function validPath(mixed $path): bool
    {
        return is_string($path)
            && preg_match(
                '#\Auploads/[a-z0-9-]+/(?:logo|avatar|cover|event|resource|service-cover)/[A-Za-z0-9]{26}\.(?:png|jpe?g|webp|gif)\z#D',
                $path
            ) === 1;
    }

    private static function absolute(string $path): string
    {
        return rtrim(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 3) . '/public', '/') . '/' . $path;
    }

    private static function directory(): string
    {
        return dirname(__DIR__, 3) . '/storage/media-journal';
    }
}
