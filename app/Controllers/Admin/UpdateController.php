<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Engine\AuditLog;
use App\Engine\Auth;
use App\Engine\DemoMode;
use App\Engine\FormState;
use App\Engine\GitUpdater;
use App\Engine\Logger;
use App\Engine\Migrator;
use App\Engine\Request;
use App\Engine\Response;
use App\Engine\Version;
use App\Engine\View;
use App\Middleware\CsrfMiddleware;

/**
 * In-admin updater — apply VoxelBooking updates via ZIP upload or server packages.
 *
 * GET  /admin/updates           → Show update page (current version, upload form, dist packages)
 * POST /admin/updates/upload    → Upload + apply a ZIP
 * POST /admin/updates/apply     → Apply a ZIP from /dist/
 *
 * Operator-only. All mutations are audit-logged.
 * Demo mode blocks all POST routes.
 *
 * Update strategy: stage-then-commit.
 * Files are extracted to a temporary staging directory first. Only after
 * ALL files extract cleanly are they moved into the live project tree.
 * If staging fails, the live tree is untouched.
 */
final class UpdateController
{
    /**
     * Show the updates page.
     */
    public function index(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return Response::redirect('/admin');
        }

        // Local-only Git status on initial load — fast, no network fetch.
        return $this->renderUpdatesPage(false);
    }

    /**
     * Re-render the updates page after checking the remote (git fetch).
     *
     * Wired to GET /admin/updates/git-status — the "Check for updates" button.
     * Kept separate from index() so the page loads instantly without a network
     * round-trip; the operator opts into the remote check.
     */
    public function gitStatus(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return Response::redirect('/admin');
        }

        return $this->renderUpdatesPage(true);
    }

    /**
     * Pull the latest changes via Git (operator-only, blocked in demo mode).
     *
     * Fetches, hard-resets to the upstream (tracked files only — never touches
     * .env, storage/, or public/uploads/), then runs schema migrations. Refuses
     * to run on a dirty or local-ahead tree. CSRF is enforced by the global
     * CsrfMiddleware on this POST route.
     */
    public function gitUpdate(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return Response::redirect('/admin');
        }

        if (DemoMode::isActive()) {
            FormState::toast('error', __('admin.updates.demo_blocked'));
            return Response::redirect('/admin/updates');
        }

        $projectRoot = dirname(__DIR__, 3);
        $updater = $this->gitUpdater($projectRoot);
        $currentVersion = Version::get();

        AuditLog::log('system.update_started', 'system', null, [
            'method'       => 'git',
            'from_version' => $currentVersion,
        ]);

        $result = $updater->update(static function () use ($projectRoot) {
            // Run schema migrations against the freshly-updated code. Both the
            // Git and ZIP update paths run this, so the schema never lags the code.
            return ['count' => (new Migrator($projectRoot . '/app/Migrations'))->migrate()];
        });

        // The VERSION file may have changed; drop the request-cached value.
        Version::reset();

        if ($result['ok']) {
            $migCount = (int) ($result['migrations']['result']['count'] ?? 0);

            AuditLog::log('system.update_completed', 'system', null, [
                'method'       => 'git',
                'from_version' => $currentVersion,
                'to_version'   => $result['to_version'],
                'from_sha'     => $result['from_sha'],
                'to_sha'       => $result['to_sha'],
                'migrations'   => $migCount,
            ]);

            Logger::info('Git update applied', [
                'from' => $result['from_sha'],
                'to'   => $result['to_sha'],
                'migrations' => $migCount,
            ]);

            if ($result['state'] === 'up_to_date' && $migCount === 0) {
                FormState::toast('success', __('admin.updates.git_already_current'));
            } else {
                FormState::toast('success', str_replace(
                    [':version', ':count'],
                    [(string) $result['to_version'], (string) $migCount],
                    __('admin.updates.git_success')
                ));
            }
        } else {
            AuditLog::log('system.update_failed', 'system', null, [
                'method'          => 'git',
                'state'           => $result['state'],
                'from_version'    => $currentVersion,
                'to_version'      => $result['to_version'],
                'from_sha'        => $result['from_sha'],
                'to_sha'          => $result['to_sha'],
                'migration_error' => $result['migrations']['error'] ?? null,
                'message'         => $result['message'],
            ]);

            Logger::error('Git update failed', [
                'state'   => $result['state'],
                'message' => $result['message'],
            ]);

            FormState::toast('error', $result['message'] ?: __('admin.updates.git_failed'));
        }

        return Response::redirect('/admin/updates');
    }

    /**
     * Render the updates page. Shared by index() (local status) and
     * gitStatus() (status after a remote fetch).
     */
    private function renderUpdatesPage(bool $fetchGit): Response
    {
        $projectRoot = dirname(__DIR__, 3);
        $git = $this->gitUpdater($projectRoot)->status($fetchGit, (new Migrator($projectRoot . '/app/Migrations'))->hasPending());

        // ext-zip is required for the ZIP flow, but Git updates work without it.
        if (!class_exists(\ZipArchive::class)) {
            return View::response('admin.updates', [
                'pageTitle'       => __('admin.updates.page_title'),
                'currentVersion'  => Version::get(),
                'packages'        => [],
                'lastUpdate'      => null,
                'phpVersion'      => PHP_VERSION,
                'flash'           => ['type' => 'error', 'message' => __('admin.updates.error_ext_zip_missing')],
                'zipAvailable'    => false,
                'git'             => $git,
            ]);
        }

        return View::response('admin.updates', [
            'pageTitle'       => __('admin.updates.page_title'),
            'currentVersion'  => Version::get(),
            'packages'        => $this->scanDistPackages($projectRoot),
            'lastUpdate'      => $this->getLastUpdateTimestamp(),
            'phpVersion'      => PHP_VERSION,
            'flash'           => FormState::getToast(),
            'zipAvailable'    => true,
            'git'             => $git,
        ]);
    }

    /**
     * Build a GitUpdater rooted at the project, with its lock file in the
     * git-ignored storage/cache/ directory.
     */
    private function gitUpdater(string $projectRoot): GitUpdater
    {
        return new GitUpdater($projectRoot, $projectRoot . '/storage/cache/.git-update.lock');
    }

    /**
     * Upload and apply a ZIP update.
     */
    public function upload(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return Response::redirect('/admin');
        }

        if (DemoMode::isActive()) {
            FormState::toast('error', __('admin.updates.demo_blocked'));
            return Response::redirect('/admin/updates');
        }

        if (!class_exists(\ZipArchive::class)) {
            FormState::toast('error', __('admin.updates.error_ext_zip_missing'));
            return Response::redirect('/admin/updates');
        }

        $projectRoot = dirname(__DIR__, 3);

        // Validate upload
        if (empty($_FILES['update_zip']) || $_FILES['update_zip']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['update_zip']['error'] ?? UPLOAD_ERR_NO_FILE;
            $errorMessages = [
                UPLOAD_ERR_INI_SIZE   => __('admin.updates.error_ini_size'),
                UPLOAD_ERR_FORM_SIZE  => __('admin.updates.error_form_size'),
                UPLOAD_ERR_PARTIAL    => __('admin.updates.error_partial'),
                UPLOAD_ERR_NO_FILE    => __('admin.updates.error_no_file'),
                UPLOAD_ERR_NO_TMP_DIR => __('admin.updates.error_no_tmp'),
                UPLOAD_ERR_CANT_WRITE => __('admin.updates.error_cant_write'),
            ];

            FormState::toast('error', $errorMessages[$errorCode] ?? __('admin.updates.error_upload_generic'));
            return Response::redirect('/admin/updates');
        }

        $tmpFile = $_FILES['update_zip']['tmp_name'];

        // Verify it's actually a zip
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($tmpFile);
        if (!in_array($mimeType, ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'])) {
            FormState::toast('error', __('admin.updates.error_not_zip'));
            return Response::redirect('/admin/updates');
        }

        // Open and validate
        $zip = new \ZipArchive();
        if ($zip->open($tmpFile) !== true) {
            FormState::toast('error', __('admin.updates.error_corrupt_zip'));
            return Response::redirect('/admin/updates');
        }

        $result = $this->validateAndApply($zip, $projectRoot, 'upload');
        $zip->close();

        // Clean up temp file
        @unlink($tmpFile);

        if ($result['ok']) {
            FormState::toast('success', $result['message']);
        } else {
            FormState::toast('error', $result['message']);
        }

        return Response::redirect('/admin/updates');
    }

    /**
     * Apply an update from a ZIP in /dist/.
     */
    public function applyLocal(Request $request): Response
    {
        if (!Auth::isOperator()) {
            return Response::redirect('/admin');
        }

        if (DemoMode::isActive()) {
            FormState::toast('error', __('admin.updates.demo_blocked'));
            return Response::redirect('/admin/updates');
        }

        if (!class_exists(\ZipArchive::class)) {
            FormState::toast('error', __('admin.updates.error_ext_zip_missing'));
            return Response::redirect('/admin/updates');
        }

        $filename = $request->string('filename');
        $projectRoot = dirname(__DIR__, 3);

        if (empty($filename) || !preg_match('/^[\w.\-]+\.zip$/i', $filename)) {
            FormState::toast('error', __('admin.updates.error_invalid_filename'));
            return Response::redirect('/admin/updates');
        }

        $zipPath = $projectRoot . '/dist/' . $filename;

        // Security: ensure the resolved path is inside dist/
        $realPath = realpath($zipPath);
        $realDistDir = realpath($projectRoot . '/dist');
        if ($realPath === false || $realDistDir === false || !str_starts_with($realPath, $realDistDir . '/')) {
            FormState::toast('error', __('admin.updates.error_not_found'));
            return Response::redirect('/admin/updates');
        }

        $zip = new \ZipArchive();
        if ($zip->open($realPath) !== true) {
            FormState::toast('error', __('admin.updates.error_corrupt_zip'));
            return Response::redirect('/admin/updates');
        }

        $result = $this->validateAndApply($zip, $projectRoot, 'dist/' . $filename);
        $zip->close();

        if ($result['ok']) {
            FormState::toast('success', $result['message']);
        } else {
            FormState::toast('error', $result['message']);
        }

        return Response::redirect('/admin/updates');
    }

    // ── Private helpers ──

    /**
     * Validate a ZIP is a VoxelBooking package and apply the update.
     *
     * Strategy: stage-then-commit.
     *
     * 1. Extract ALL files to a temporary staging directory
     * 2. If staging succeeds (zero errors), move files to the live tree
     * 3. If staging fails, clean up and report failure — live tree is untouched
     *
     * Content-based validation (never relies on filename):
     * - VERSION file must exist inside the ZIP
     * - app/Engine/Version.php must exist (proves it's VoxelBooking)
     *
     * @return array{ok: bool, message: string}
     */
    private function validateAndApply(\ZipArchive $zip, string $projectRoot, string $sourceLabel): array
    {
        $prefix = $this->detectZipPrefix($zip);

        // Validate: VERSION file must exist
        $versionContent = $zip->getFromName($prefix . 'VERSION');
        if ($versionContent === false) {
            return ['ok' => false, 'message' => __('admin.updates.error_not_voxelbooking')];
        }

        // Validate: app/Engine/Version.php must exist (proves it's VoxelBooking, not VoxelSite)
        $versionPhp = $zip->getFromName($prefix . 'app/Engine/Version.php');
        if ($versionPhp === false) {
            return ['ok' => false, 'message' => __('admin.updates.error_not_voxelbooking')];
        }

        $newVersion = trim($versionContent);
        $currentVersion = Version::get();

        AuditLog::log('system.update_started', 'system', null, [
            'from_version' => $currentVersion,
            'to_version'   => $newVersion,
            'source'       => $sourceLabel,
            'zip_entries'  => $zip->numFiles,
        ]);

        // Define protected paths — never overwritten
        $protectedPrefixes = [
            '.env',
            'storage/logs/',
            'storage/cache/',
            'storage/sessions/',
            'storage/demo/',
            '.ai/',
            '.agent/',
            '.git/',
            '.gemini/',
            'node_modules/',
            'dist/',
            'tests/',
            'public/uploads/',
        ];

        // ── Phase 1: Stage — extract all files to a temp directory ──
        $stagingDir = $projectRoot . '/storage/cache/.update_staging_' . time();

        if (!mkdir($stagingDir, 0755, true)) {
            return ['ok' => false, 'message' => __('admin.updates.error_staging_failed')];
        }

        $staged = 0;
        $skipped = 0;
        $stagedFiles = []; // relativePath => staging path, for commit phase
        $errors = [];

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entryName = $zip->getNameIndex($i);
            if ($entryName === false) {
                continue;
            }

            // Strip zip prefix
            $relativePath = $prefix ? substr($entryName, strlen($prefix)) : $entryName;

            // Skip empty paths and directory entries
            if ($relativePath === '' || $relativePath === false || str_ends_with($relativePath, '/')) {
                continue;
            }

            // Skip protected paths
            $isProtected = false;
            foreach ($protectedPrefixes as $protectedPrefix) {
                if ($relativePath === $protectedPrefix || str_starts_with($relativePath, $protectedPrefix)) {
                    $isProtected = true;
                    break;
                }
            }

            if ($isProtected) {
                $skipped++;
                continue;
            }

            // Security: prevent path traversal
            if (str_contains($relativePath, '..') || str_starts_with($relativePath, '/')) {
                $skipped++;
                continue;
            }

            // Read the file content from ZIP
            $content = $zip->getFromIndex($i);
            if ($content === false) {
                $errors[] = $relativePath . ' (read failed)';
                continue;
            }

            // Write to staging directory
            $stagingPath = $stagingDir . '/' . $relativePath;
            $stagingSubDir = dirname($stagingPath);
            if (!is_dir($stagingSubDir) && !mkdir($stagingSubDir, 0755, true)) {
                $errors[] = $relativePath . ' (mkdir failed)';
                continue;
            }

            if (file_put_contents($stagingPath, $content) === false) {
                $errors[] = $relativePath . ' (write failed)';
                continue;
            }

            $stagedFiles[$relativePath] = $stagingPath;
            $staged++;
        }

        // ── Staging gate: abort if ANY file failed ──
        if (!empty($errors)) {
            // Clean up staging directory
            $this->removeDirectory($stagingDir);

            AuditLog::log('system.update_failed', 'system', null, [
                'from_version' => $currentVersion,
                'to_version'   => $newVersion,
                'source'       => $sourceLabel,
                'staged'       => $staged,
                'errors'       => count($errors),
                'error_files'  => array_slice($errors, 0, 10), // Log first 10
            ]);

            Logger::error('Update staging failed', [
                'version' => $newVersion,
                'staged'  => $staged,
                'errors'  => $errors,
            ]);

            return [
                'ok'      => false,
                'message' => str_replace(
                    ':count',
                    (string) count($errors),
                    __('admin.updates.error_staging_partial')
                ),
            ];
        }

        if ($staged === 0) {
            $this->removeDirectory($stagingDir);
            return ['ok' => false, 'message' => __('admin.updates.error_extraction_failed')];
        }

        // ── Phase 2: Commit — back up originals, then move staged files to live tree ──
        // Strategy: before overwriting each file, copy the original to a backup dir.
        // If any write fails, restore all backed-up files → live tree returns to its
        // original state. This eliminates mixed-version risk.
        $backupDir = $projectRoot . '/storage/cache/.update_backup_' . time();
        if (!mkdir($backupDir, 0755, true)) {
            $this->removeDirectory($stagingDir);
            return ['ok' => false, 'message' => __('admin.updates.error_staging_failed')];
        }

        $committed = 0;
        $backedUp = [];   // relativePath => backupPath (existing files, for restore)
        $createdNew = []; // relativePath => targetPath (new files, for deletion)
        $commitErrors = [];

        foreach ($stagedFiles as $relativePath => $stagingPath) {
            $targetPath = $projectRoot . '/' . $relativePath;
            $targetDir = dirname($targetPath);

            // Ensure target directory exists
            if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
                $commitErrors[] = $relativePath . ' (mkdir failed)';
                break; // Stop on first error — will rollback
            }

            // Back up existing file before overwriting, or mark as new
            if (file_exists($targetPath)) {
                $backupPath = $backupDir . '/' . $relativePath;
                $backupSubDir = dirname($backupPath);
                if (!is_dir($backupSubDir)) {
                    mkdir($backupSubDir, 0755, true);
                }
                if (!copy($targetPath, $backupPath)) {
                    $commitErrors[] = $relativePath . ' (backup failed)';
                    break; // Stop — cannot guarantee rollback without backup
                }
                $backedUp[$relativePath] = $backupPath;
            } else {
                // File doesn't exist yet — track it so rollback can remove it
                $createdNew[$relativePath] = $targetPath;
            }

            // Write the new file
            if (@rename($stagingPath, $targetPath)) {
                $committed++;
            } elseif (copy($stagingPath, $targetPath)) {
                @unlink($stagingPath);
                $committed++;
            } else {
                // Remove from createdNew since the write itself failed
                unset($createdNew[$relativePath]);
                $commitErrors[] = $relativePath . ' (write failed)';
                break; // Stop on first error — will rollback
            }
        }

        // ── Rollback on error ──
        if (!empty($commitErrors)) {
            // Restore all backed-up files to their original locations
            foreach ($backedUp as $relativePath => $backupPath) {
                $targetPath = $projectRoot . '/' . $relativePath;
                @copy($backupPath, $targetPath);
            }

            // Delete files that were newly created by this update
            foreach ($createdNew as $relativePath => $targetPath) {
                @unlink($targetPath);
            }

            $this->removeDirectory($stagingDir);
            $this->removeDirectory($backupDir);

            AuditLog::log('system.update_failed', 'system', null, [
                'from_version' => $currentVersion,
                'to_version'   => $newVersion,
                'source'       => $sourceLabel,
                'reason'       => 'commit_rolled_back',
                'committed_before_rollback' => $committed,
                'restored'     => count($backedUp),
                'deleted_new'  => count($createdNew),
                'error_files'  => array_slice($commitErrors, 0, 10),
            ]);

            Logger::error('Update commit failed, rolled back', [
                'version'  => $newVersion,
                'restored' => count($backedUp),
                'errors'   => $commitErrors,
            ]);

            return [
                'ok'      => false,
                'message' => __('admin.updates.error_commit_rolled_back'),
            ];
        }

        // ── Success — clean up temporary directories ──
        $this->removeDirectory($stagingDir);
        $this->removeDirectory($backupDir);

        // Clear OPcache if available
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        // Reset cached version so the new version is read
        Version::reset();

        // ── Run schema migrations against the freshly-updated code ──
        // The Git update path does this too; keep ZIP in parity so the database
        // never lags the new code. A migration failure is a FAILED update — never
        // report success when the schema step throws.
        try {
            $migrationCount = (new Migrator($projectRoot . '/app/Migrations'))->migrate();
        } catch (\Throwable $e) {
            AuditLog::log('system.update_failed', 'system', null, [
                'from_version'  => $currentVersion,
                'to_version'    => $newVersion,
                'source'        => $sourceLabel,
                'reason'        => 'migration_failed',
                'files_updated' => $committed,
                'error'         => $e->getMessage(),
            ]);

            Logger::error('Migrations failed after ZIP update', [
                'version' => $newVersion,
                'error'   => $e->getMessage(),
            ]);

            return [
                'ok'      => false,
                'message' => str_replace(
                    [':version', ':error'],
                    [$newVersion, $e->getMessage()],
                    __('admin.updates.error_migration_failed')
                ),
            ];
        }

        // Full success
        AuditLog::log('system.update_completed', 'system', null, [
            'from_version'  => $currentVersion,
            'to_version'    => $newVersion,
            'source'        => $sourceLabel,
            'files_updated' => $committed,
            'files_skipped' => $skipped,
            'migrations'    => $migrationCount,
        ]);

        Logger::info('Update applied', [
            'from'       => $currentVersion,
            'to'         => $newVersion,
            'committed'  => $committed,
            'skipped'    => $skipped,
            'migrations' => $migrationCount,
        ]);

        return [
            'ok'      => true,
            'message' => str_replace(
                [':version', ':count'],
                [$newVersion, (string) $committed],
                __('admin.updates.success')
            ),
        ];
    }

    /**
     * Detect if the zip has a single wrapper directory.
     *
     * Many zip tools wrap everything in a top-level directory like
     * "voxelbooking-v1.1.0/". We detect this and strip it during extraction.
     */
    private function detectZipPrefix(\ZipArchive $zip): string
    {
        if ($zip->numFiles === 0) {
            return '';
        }

        $firstName = $zip->getNameIndex(0);
        if ($firstName === false) {
            return '';
        }

        // Check if the first entry is a directory
        if (str_ends_with($firstName, '/')) {
            $prefix = $firstName;
            for ($i = 1; $i < min($zip->numFiles, 20); $i++) {
                $name = $zip->getNameIndex($i);
                if ($name !== false && !str_starts_with($name, $prefix)) {
                    return ''; // Not all files have this prefix
                }
            }
            return $prefix;
        }

        return '';
    }

    /**
     * Scan /dist/ for available update packages.
     *
     * Content-based validation: reads VERSION from inside each ZIP.
     * Never relies on filename for version detection.
     *
     * @return list<array{filename: string, version: string, size: int, modified: string}>
     */
    private function scanDistPackages(string $projectRoot): array
    {
        if (!class_exists(\ZipArchive::class)) {
            return [];
        }

        $distDir = $projectRoot . '/dist';
        $packages = [];

        if (!is_dir($distDir)) {
            return [];
        }

        $files = glob($distDir . '/*.zip');
        if ($files === false) {
            return [];
        }

        foreach ($files as $zipPath) {
            $filename = basename($zipPath);
            $size = filesize($zipPath);

            // Read VERSION from inside the zip (content-based, not filename-based)
            $version = null;
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) === true) {
                $prefix = $this->detectZipPrefix($zip);
                $versionContent = $zip->getFromName($prefix . 'VERSION');
                $versionPhp = $zip->getFromName($prefix . 'app/Engine/Version.php');

                // Only include if it's a valid VoxelBooking package
                if ($versionContent !== false && $versionPhp !== false) {
                    $version = trim($versionContent);
                }
                $zip->close();
            }

            if ($version === null) {
                continue; // Not a valid VoxelBooking package
            }

            $packages[] = [
                'filename' => $filename,
                'version'  => $version,
                'size'     => (int) $size,
                'modified' => date('Y-m-d H:i', filemtime($zipPath)),
            ];
        }

        // Sort by version descending (newest first)
        usort($packages, function ($a, $b) {
            return version_compare($b['version'], $a['version']);
        });

        return $packages;
    }

    /**
     * Get the timestamp of the last successful update from audit log.
     */
    private function getLastUpdateTimestamp(): ?string
    {
        try {
            $rows = \App\Engine\Database::query(
                "SELECT `created_at` FROM `audit_log`
                 WHERE `action` = 'system.update_completed'
                 ORDER BY `created_at` DESC
                 LIMIT 1"
            );

            return $rows[0]['created_at'] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Recursively remove a directory and all its contents.
     */
    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }

        @rmdir($dir);
    }
}
