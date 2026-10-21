<?php
/**
 * Cron Settings — token, status display, and "Run now" action.
 *
 * Variables: $user, $version, $csrfToken, $cronToken, $lastRun, $flash, $pageTitle
 */
$activePage = 'settings.cron';
$activeTab = 'cron';

ob_start();

include dirname(__DIR__, 2) . '/partials/settings-tabs.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../partials/alert.php'; ?>
<?php endif; ?>

<div class="vb-grid vb-grid-2">
    <!-- Cron Configuration -->
    <div class="vb-card vb-fade-in-up stagger-1">
        <div class="vb-card-header">
            <div class="vb-card-title-row">
                <i data-lucide="clock" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.cron.job_title') ?></div>
                    <div class="vb-card-desc"><?= __('admin.cron.job_desc') ?></div>
                </div>
            </div>
        </div>

        <div class="vb-form-group">
            <label class="vb-label"><?= __('admin.cron.command_label') ?></label>
            <input type="text" class="vb-input vb-input-mono" readonly value="*/5 * * * * curl -s <?= htmlspecialchars(app_url(), ENT_QUOTES, 'UTF-8') ?>/cron/run?token=<?= htmlspecialchars($cronToken, ENT_QUOTES, 'UTF-8') ?> > /dev/null 2>&1" onclick="this.select()">
            <div class="vb-hint"><?= __('admin.cron.command_hint') ?></div>
        </div>

        <div class="vb-form-group">
            <label class="vb-label"><?= __('admin.cron.token_label') ?></label>
            <input type="text" class="vb-input vb-input-mono" readonly value="<?= htmlspecialchars($cronToken, ENT_QUOTES, 'UTF-8') ?>" onclick="this.select()">
            <div class="vb-hint"><?= __('admin.cron.token_hint') ?></div>
        </div>

        <div class="vb-form-group">
            <label class="vb-label"><?= __('admin.cron.tasks_title') ?></label>
            <div class="vb-hint">
                <ul class="vb-bullet-list vb-text-sm">
                    <li><?= __('admin.cron.task_retention') ?></li>
                    <li><?= __('admin.cron.task_reminders') ?></li>
                    <li><?= __('admin.cron.task_audit') ?></li>
                    <li><?= __('admin.cron.task_email_log') ?></li>
                    <li><?= __('admin.cron.task_rate_limits') ?></li>
                    <li><?= __('agent-system.task_deliveries') ?></li>
                    <li><?= __('agent-system.task_access_cleanup') ?></li>
                    <li><?= __('agent-system.task_media_webhook_cleanup') ?></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Status -->
    <div class="vb-card vb-fade-in-up stagger-2">
        <div class="vb-card-header">
            <div class="vb-card-title-row">
                <i data-lucide="activity" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.cron.status_title') ?></div>
                    <div class="vb-card-desc"><?= __('admin.cron.status_desc') ?></div>
                </div>
            </div>
        </div>

        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.cron.last_run') ?></span>
            <span class="vb-info-value">
                <?php if ($lastRun): ?>
                    <code><?= htmlspecialchars($lastRun, ENT_QUOTES, 'UTF-8') ?></code>
                <?php else: ?>
                    <span class="vb-text-tertiary"><?= __('admin.cron.never_run') ?></span>
                <?php endif; ?>
            </span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('admin.cron.status_label') ?></span>
            <span class="vb-info-value">
                <span class="vb-badge vb-badge-inline <?= in_array($jobStatus['state'], ['failed','partial'], true) ? 'vb-badge-warning' : 'vb-badge-default' ?>">
                    <?= htmlspecialchars(__('agent-system.state_'.$jobStatus['state']), ENT_QUOTES, 'UTF-8') ?>
                </span>
            </span>
        </div>

        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('agent-system.next_expected') ?></span>
            <span class="vb-info-value"><?= htmlspecialchars($jobStatus['next_expected_at'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="vb-info-row">
            <span class="vb-info-label"><?= __('agent-system.queued') ?></span>
            <span class="vb-info-value"><?= (int)$jobStatus['queued_requests'] ?></span>
        </div>
        <p class="vb-hint"><?= __('agent-system.scheduler_note') ?></p>
        <?php if (!empty($jobStatus['latest']['results'])): ?>
            <h3 class="vb-card-title"><?= __('agent-system.results') ?></h3>
            <?php foreach ($jobStatus['latest']['results'] as $job): ?>
                <div class="vb-info-row">
                    <span class="vb-info-label"><?= htmlspecialchars(__('agent-system.task_'.$job['job']), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="vb-info-value"><?= htmlspecialchars(__('agent-system.state_'.$job['status']), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                <?php if ($job['error_code']): ?><p class="vb-hint"><?= __('agent-system.failure') ?></p><?php endif; ?>
                <details><summary><?= __('agent-system.effects') ?></summary>
                    <?php foreach ($job['effects'] as $effect => $count): ?>
                        <div class="vb-info-row"><span class="vb-info-label"><?= htmlspecialchars(__('agent-system.effect_'.$effect), ENT_QUOTES, 'UTF-8') ?></span><span class="vb-info-value"><?= (int)$count ?></span></div>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
        <?php endif; ?>

        <div class="vb-card-separator">
            <form method="POST" action="/admin/settings/cron/run" style="display: inline;">
                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="vb-btn vb-btn-secondary" id="cron-run-now-btn">
                    <i data-lucide="play" style="width: 15px; height: 15px;"></i>
                    <?= __('admin.cron.run_now') ?>
                </button>
            </form>
            <div class="vb-hint" style="margin-top: 0.5rem;"><?= __('agent-system.run_hint') ?></div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 2) . '/admin/layout.php';
