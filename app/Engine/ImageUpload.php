<?php

declare(strict_types=1);

namespace App\Engine;

/**
 * Reusable image upload primitive.
 *
 * Handles validation, storage, replacement, and removal of tenant-scoped
 * images. All files are stored under `public/uploads/{tenant_slug}/{type}/`.
 *
 * Usage:
 *   $result = ImageUpload::store('avatar', $_FILES['avatar'], $tenantSlug);
 *   if ($result['error']) { ... }
 *   $relativePath = $result['path']; // "uploads/demo-studio/avatar/01abc123.webp"
 *
 *   ImageUpload::delete($oldPath); // removes old file from disk
 */
final class ImageUpload
{
    /** Max file size in bytes (2 MB). */
    private const MAX_SIZE = 2 * 1024 * 1024;

    /** Allowed MIME types. */
    private const ALLOWED_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
    ];

    /** Allowed extensions (lowercase). */
    private const ALLOWED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Store an uploaded image file.
     *
     * @param string $type      Subdirectory type (e.g. 'avatar', 'cover', 'event')
     * @param array  $file      $_FILES entry (must have tmp_name, error, size, name, type)
     * @param string $tenantSlug  Tenant slug for directory scoping
     * @param string|null $oldPath  Optional path of previous file to delete on success
     * @param string|null $fileId  Preallocated ULID for a durable upload intent
     * @return array{path: string|null, error: string|null}
     */
    public static function store(string $type, array $file, string $tenantSlug, ?string $oldPath = null, ?string $fileId = null): array
    {
        // No file uploaded
        if (!isset($file['tmp_name']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
            return ['path' => null, 'error' => null];
        }

        // PHP upload error
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['path' => null, 'error' => self::uploadErrorMessage($file['error'])];
        }

        // Size check
        if ($file['size'] > self::MAX_SIZE) {
            return ['path' => null, 'error' => __('admin.upload.error_too_large')];
        }

        // Extension check
        $originalName = $file['name'] ?? '';
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            return ['path' => null, 'error' => __('admin.upload.error_invalid_type')];
        }

        // MIME type check (from actual file content)
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo->file($file['tmp_name']);
        if (!in_array($detectedMime, self::ALLOWED_MIMES, true)) {
            return ['path' => null, 'error' => __('admin.upload.error_invalid_type')];
        }

        // Validate it's actually an image
        $imageInfo = @getimagesize($file['tmp_name']);
        if ($imageInfo === false) {
            return ['path' => null, 'error' => __('admin.upload.error_invalid_type')];
        }

        // Build target directory
        $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower($tenantSlug));
        $dir = rtrim(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 2) . '/public', '/');
        $relativeDir = "uploads/{$slug}/{$type}";
        $absoluteDir = "{$dir}/{$relativeDir}";

        if (!is_dir($absoluteDir)) {
            mkdir($absoluteDir, 0755, true);
        }

        // Generate unique filename
        if ($fileId !== null && !preg_match('/\A[0-9A-Z]{26}\z/D', $fileId)) {
            return ['path' => null, 'error' => __('admin.upload.error_invalid_type')];
        }
        $filename = ($fileId ?? Ulid::generate()) . '.' . $extension;
        $absolutePath = "{$absoluteDir}/{$filename}";
        $relativePath = "{$relativeDir}/{$filename}";

        // Move uploaded file
        if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
            return ['path' => null, 'error' => __('admin.upload.error_move_failed')];
        }

        // Delete old file on successful replacement
        if ($oldPath !== null && $oldPath !== '') {
            self::delete($oldPath);
        }

        return ['path' => $relativePath, 'error' => null];
    }

    /**
     * Delete an image file from disk.
     *
     * @param string $relativePath  Path relative to public/ (e.g. "uploads/demo-studio/avatar/abc.webp")
     */
    public static function delete(string $relativePath): void
    {
        if ($relativePath === '') {
            return;
        }

        $dir = rtrim(($_SERVER['DOCUMENT_ROOT'] ?? '') ?: dirname(__DIR__, 2) . '/public', '/');
        $absolutePath = "{$dir}/{$relativePath}";

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }

    /**
     * Map PHP upload error code to a translatable message.
     */
    private static function uploadErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => __('admin.upload.error_too_large'),
            UPLOAD_ERR_PARTIAL   => __('admin.upload.error_partial'),
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => __('admin.upload.error_server'),
            default              => __('admin.upload.error_generic'),
        };
    }
}
