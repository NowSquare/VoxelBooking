<?php
/**
 * Business applications list — operator-only review surface.
 *
 * Status-filtered table with approve/reject actions.
 * Uses the admin layout shell via View::response().
 *
 * Variables: $applications, $statusFilter, $pendingCount, $csrfToken, $flash
 */
$applications  = $applications ?? [];
$statusFilter  = $statusFilter ?? 'pending';
$pendingCount  = $pendingCount ?? 0;
$csrfToken     = $csrfToken ?? '';
?>
<?php ob_start(); ?>

<?php if ($flash): ?>
<?php include dirname(__DIR__, 2) . '/partials/alert.php'; ?>
<?php endif; ?>

<!-- Page header (same pattern as Bookings / Businesses) -->
<div class="vb-page-header">
    <div>
        <h2 class="vb-page-title"><?= __('admin.applications.title') ?></h2>
        <p class="vb-page-subtitle"><?= __('admin.applications.subtitle') ?></p>
    </div>
</div>

<!-- Status filter tabs -->
<div class="vb-table-container">
    <div class="vb-table-toolbar">
        <div class="vb-filter-tabs">
            <a href="/admin/applications?status=pending"
               class="vb-filter-tab <?= $statusFilter === 'pending' ? 'active' : '' ?>"
               id="tab-pending">
                <?= __('admin.applications.status_pending') ?>
                <?php if ($pendingCount > 0): ?>
                <span class="vb-filter-tab-count"><?= $pendingCount ?></span>
                <?php endif; ?>
            </a>
            <a href="/admin/applications?status=approved"
               class="vb-filter-tab <?= $statusFilter === 'approved' ? 'active' : '' ?>"
               id="tab-approved">
                <?= __('admin.applications.status_approved') ?>
            </a>
            <a href="/admin/applications?status=rejected"
               class="vb-filter-tab <?= $statusFilter === 'rejected' ? 'active' : '' ?>"
               id="tab-rejected">
                <?= __('admin.applications.status_rejected') ?>
            </a>
            <a href="/admin/applications?status=all"
               class="vb-filter-tab <?= $statusFilter === 'all' ? 'active' : '' ?>"
               id="tab-all">
                <?= __('admin.applications.status_all') ?>
            </a>
        </div>
    </div>

<?php if (empty($applications)): ?>
    <div class="vb-empty vb-animate-in">
        <i data-lucide="inbox" class="vb-empty-icon"></i>
        <div class="vb-empty-title"><?= __('admin.applications.empty_title') ?></div>
        <div class="vb-empty-desc"><?= __('admin.applications.empty_desc') ?></div>
    </div>
<?php else: ?>

    <div class="vb-table-wrap">
        <table class="vb-table">
            <thead>
                <tr>
                    <th><?= __('admin.applications.col_business') ?></th>
                    <th><?= __('admin.applications.col_contact') ?></th>
                    <th><?= __('admin.applications.col_email') ?></th>
                    <th><?= __('admin.applications.col_date') ?></th>
                    <th><?= __('admin.applications.col_status') ?></th>
                    <?php if ($statusFilter === 'pending' || $statusFilter === 'all'): ?>
                    <th><?= __('admin.applications.col_actions') ?></th>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($applications as $i => $app): ?>
                <tr class="vb-fade-in-up stagger-<?= min($i + 1, 6) ?>">
                    <td>
                        <div class="vb-cell-primary"><?= htmlspecialchars($app['business_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($app['message']): ?>
                        <div class="vb-cell-secondary" title="<?= htmlspecialchars($app['message'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(mb_strimwidth($app['message'], 0, 60, '…'), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($app['contact_name'], ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <a href="mailto:<?= htmlspecialchars($app['email'], ENT_QUOTES, 'UTF-8') ?>" class="vb-link">
                            <?= htmlspecialchars($app['email'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <?php if ($app['phone']): ?>
                        <div class="vb-cell-secondary"><?= htmlspecialchars($app['phone'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                        <?php if ($app['website']): ?>
                        <div class="vb-cell-secondary"><a href="<?= htmlspecialchars($app['website'], ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="vb-link"><?= htmlspecialchars($app['website'], ENT_QUOTES, 'UTF-8') ?></a></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="vb-cell-secondary">
                            <?= htmlspecialchars(date('M j, Y', strtotime($app['created_at'])), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td>
                        <?php
                        $badgeClass = match ($app['status']) {
                            'approved' => 'vb-status vb-status-confirmed',
                            'rejected' => 'vb-status vb-status-cancelled',
                            default    => 'vb-status vb-status-pending',
                        };
                        ?>
                        <span class="<?= $badgeClass ?>">
                            <?= __('admin.applications.status_' . $app['status']) ?>
                        </span>
                    </td>
                    <?php if ($statusFilter === 'pending' || $statusFilter === 'all'): ?>
                    <td>
                        <?php if ($app['status'] === 'pending'): ?>
                        <div class="vb-action-group">
                            <form method="POST" action="/admin/applications/<?= htmlspecialchars($app['id'], ENT_QUOTES, 'UTF-8') ?>/approve" class="vb-inline-form">
                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="vb-btn vb-btn-sm vb-btn-success" title="<?= __('admin.applications.approve_btn') ?>">
                                    <i data-lucide="check"></i>
                                </button>
                            </form>
                            <form method="POST" action="/admin/applications/<?= htmlspecialchars($app['id'], ENT_QUOTES, 'UTF-8') ?>/reject" class="vb-inline-form">
                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="vb-btn vb-btn-sm vb-btn-destructive" title="<?= __('admin.applications.reject_btn') ?>">
                                    <i data-lucide="x"></i>
                                </button>
                            </form>
                        </div>
                        <?php endif; ?>
                    </td>
                    <?php endif; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
</div>
<?php
$content = ob_get_clean();
include dirname(__DIR__) . '/layout.php';
