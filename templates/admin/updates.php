<?php
/**
 * Updates page — operator-only system update management.
 *
 * Shows current version, upload form for ZIP updates, and
 * server-side packages from /dist/ directory.
 *
 * Uses design system components exclusively — zero inline style= attributes.
 * Layout matches the canonical settings/general.php pattern:
 *   vb-card → vb-card-header → vb-card-title-row (card header)
 *   vb-info-row → vb-info-label / vb-info-value (read-only rows)
 *   vb-form-group → vb-form-actions (form sections)
 * Cards are wrapped in a vb-grid single-column container for consistent gap.
 *
 * Variables: $currentVersion, $packages, $lastUpdate, $phpVersion, $flash,
 *            $pageTitle, $zipAvailable
 */
use App\Engine\View;

$activePage = 'updates';
$csrfToken = \App\Middleware\CsrfMiddleware::generateToken();

// Helper: format bytes to human-readable string
$formatBytes = function (int $bytes): string {
    if ($bytes === 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $i = (int) floor(log($bytes, 1024));
    return round($bytes / pow(1024, $i), 1) . ' ' . $units[$i];
};

ob_start();
?>

<?php if ($flash ?? null): ?>
    <?php include __DIR__ . '/../partials/alert.php'; ?>
<?php endif; ?>

<!-- Page Header -->
<?php
$pageHeader = [
    'title' => __('admin.updates.title'),
    'subtitle' => __('admin.updates.subtitle'),
];
include __DIR__ . '/partials/page-header.php';
?>

<div class="vb-grid">

    <!-- Current Version -->
    <div class="vb-card vb-fade-in-up stagger-1">
        <div class="vb-card-header">
            <div class="vb-card-title-row">
                <i data-lucide="info" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.updates.current_version_title') ?></div>
                    <div class="vb-card-desc"><?= __('admin.updates.current_version_desc') ?></div>
                </div>
            </div>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label">VoxelBooking</span>
            <span class="vb-info-value"><code>v<?= View::e($currentVersion) ?></code></span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label">PHP</span>
            <span class="vb-info-value"><code><?= View::e($phpVersion) ?></code></span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.updates.last_updated') ?></span>
            <span class="vb-info-value">
                <?php if ($lastUpdate): ?>
                    <code><?= View::e($lastUpdate) ?></code>
                <?php else: ?>
                    <span class="vb-text-tertiary"><?= __('admin.updates.never_updated') ?></span>
                <?php endif; ?>
            </span>
        </div>
    </div>

    <?php
    // Git updates — only shown when this install is a Git checkout. The ZIP
    // flow below remains the fallback for shared hosting (no git / no exec).
    $git = $git ?? [];
    if (!empty($git['is_repo'])):
        $gitBadge = match ($git['state'] ?? '') {
            'update_available' => 'vb-badge-success',
            'up_to_date'       => 'vb-badge-neutral',
            'dirty', 'ahead'   => 'vb-badge-warning',
            default            => 'vb-badge-muted',
        };
    ?>
    <!-- Git Updates -->
    <div class="vb-card vb-fade-in-up stagger-2">
        <div class="vb-card-header">
            <div class="vb-card-title-row">
                <i data-lucide="git-branch" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.updates.git_title') ?></div>
                    <div class="vb-card-desc"><?= __('admin.updates.git_desc') ?></div>
                </div>
            </div>
        </div>

        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.updates.git_status_label') ?></span>
            <span class="vb-info-value">
                <span class="vb-badge <?= $gitBadge ?>"><?= View::e($git['message'] ?? '') ?></span>
            </span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.updates.git_branch_label') ?></span>
            <span class="vb-info-value">
                <code><?= View::e($git['branch'] ?? '—') ?></code>
                <?php if (!empty($git['short_sha'])): ?>
                    <span class="vb-text-tertiary">@ <?= View::e($git['short_sha']) ?></span>
                <?php endif; ?>
            </span>
        </div>
        <?php if (!empty($git['fetch_error'])): ?>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.updates.git_remote_label') ?></span>
            <span class="vb-info-value vb-text-tertiary"><?= View::e($git['fetch_error']) ?></span>
        </div>
        <?php endif; ?>

        <div class="vb-form-actions">
            <a href="/admin/updates/git-status" class="vb-btn vb-btn-secondary">
                <i data-lucide="refresh-cw"></i>
                <?= __('admin.updates.git_check') ?>
            </a>
            <?php if (!empty($git['can_update'])): ?>
            <form method="post" action="/admin/updates/git-update" class="vb-inline-form"
                  onsubmit="return confirm('<?= View::e(__('admin.updates.git_confirm')) ?>');">
                <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                <button type="submit"
                        class="vb-btn vb-btn-primary"
                        <?php if (\App\Engine\DemoMode::isActive()): ?>onclick="event.preventDefault(); showDemoToast()"<?php endif; ?>>
                    <i data-lucide="download"></i>
                    <?= __('admin.updates.git_update_btn') ?>
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($zipAvailable ?? true): ?>

    <!-- Upload Update -->
    <div class="vb-card vb-fade-in-up stagger-2">
        <div class="vb-card-header">
            <div class="vb-card-title-row">
                <i data-lucide="upload" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.updates.upload_title') ?></div>
                    <div class="vb-card-desc"><?= __('admin.updates.upload_desc') ?></div>
                </div>
            </div>
        </div>
        <form method="post" action="/admin/updates/upload" enctype="multipart/form-data">
            <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">

            <div class="vb-form-group">
                <label class="vb-label" for="update_zip"><?= __('admin.updates.upload_label') ?></label>
                <input type="file"
                       name="update_zip"
                       id="update_zip"
                       accept=".zip"
                       class="vb-input vb-input-file"
                       required
                       <?php if (\App\Engine\DemoMode::isActive()): ?>disabled<?php endif; ?>>
                <span class="vb-hint"><?= __('admin.updates.upload_hint') ?></span>
            </div>

            <div class="vb-form-actions">
                <button type="submit"
                        class="vb-btn vb-btn-primary"
                        id="btn-apply-upload"
                        <?php if (\App\Engine\DemoMode::isActive()): ?>onclick="event.preventDefault(); showDemoToast()"<?php endif; ?>>
                    <i data-lucide="download"></i>
                    <?= __('admin.updates.apply_update') ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Server Packages -->
    <?php if (!empty($packages)): ?>
    <div class="vb-card vb-fade-in-up stagger-3">
        <div class="vb-card-header">
            <div class="vb-card-title-row">
                <i data-lucide="folder" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.updates.packages_title') ?></div>
                    <div class="vb-card-desc"><?= __('admin.updates.packages_desc') ?></div>
                </div>
            </div>
        </div>
        <table class="vb-table">
            <thead>
                <tr>
                    <th><?= __('admin.updates.th_filename') ?></th>
                    <th><?= __('admin.updates.th_version') ?></th>
                    <th><?= __('admin.updates.th_size') ?></th>
                    <th><?= __('admin.updates.th_date') ?></th>
                    <th class="vb-th-actions"><?= __('admin.updates.th_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($packages as $pkg): ?>
                <tr>
                    <td>
                        <span class="vb-mono vb-cell-primary"><?= View::e($pkg['filename']) ?></span>
                    </td>
                    <td>
                        <code class="vb-mono"><?= View::e($pkg['version']) ?></code>
                        <?php if ($pkg['version'] === $currentVersion): ?>
                            <span class="vb-badge vb-badge-info"><?= __('admin.updates.badge_current') ?></span>
                        <?php elseif (version_compare($pkg['version'], $currentVersion, '>')): ?>
                            <span class="vb-badge vb-badge-success"><?= __('admin.updates.badge_newer') ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="vb-cell-dim"><?= $formatBytes((int) $pkg['size']) ?></span>
                    </td>
                    <td>
                        <span class="vb-mono vb-cell-sub"><?= View::e($pkg['modified']) ?></span>
                    </td>
                    <td>
                        <?php if ($pkg['version'] !== $currentVersion): ?>
                        <form method="post" action="/admin/updates/apply" class="vb-inline-form">
                            <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                            <input type="hidden" name="filename" value="<?= View::e($pkg['filename']) ?>">
                            <button type="submit"
                                    class="vb-btn vb-btn-primary vb-btn-sm"
                                    <?php if (\App\Engine\DemoMode::isActive()): ?>onclick="event.preventDefault(); showDemoToast()"<?php endif; ?>>
                                <?= __('admin.updates.btn_apply') ?>
                            </button>
                        </form>
                        <?php else: ?>
                            <span class="vb-text-tertiary vb-text-xs"><?= __('admin.updates.installed') ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <?php endif; ?>

</div>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
