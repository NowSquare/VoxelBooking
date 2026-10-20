<?php
/**
 * Operator deletion queue — GDPR Art. 17 request management.
 *
 * Shows pending deletion requests for operator review.
 * Operator can confirm (anonymize) or dismiss each request.
 *
 * Uses design system components exclusively — zero inline style= attributes.
 * Icons: Lucide via data-lucide.
 *
 * Variables: $pendingRequests, $processedRequests, $pageTitle
 */
use App\Engine\View;

$pageTitle = $pageTitle ?? __('admin.deletion.title');
$activePage = 'deletion-queue';
$csrfToken = \App\Middleware\CsrfMiddleware::generateToken();
$pendingRequests = $pendingRequests ?? [];
$processedRequests = $processedRequests ?? [];

ob_start();
?>

<!-- Page Header -->
<?php
$pageHeader = [
    'title' => __('admin.deletion.title'),
    'subtitle' => __('admin.deletion.subtitle'),
];
include __DIR__ . '/partials/page-header.php';
?>

<!-- Pending Requests -->
<div class="vb-card vb-animate-in stagger-2">
    <div class="vb-card-header">
        <div class="vb-card-title-row">
            <i data-lucide="trash-2" class="vb-card-icon"></i>
            <div>
                <div class="vb-card-title"><?= __('admin.deletion.pending_title') ?></div>
                <div class="vb-card-desc"><?= __('admin.deletion.pending_desc') ?></div>
            </div>
        </div>
    </div>

    <?php if (empty($pendingRequests)): ?>
        <div class="vb-table-empty">
            <i data-lucide="shield-check" class="vb-table-empty-icon"></i>
            <div class="vb-table-empty-title"><?= __('admin.deletion.empty_title') ?></div>
            <div class="vb-table-empty-desc"><?= __('admin.deletion.empty_desc') ?></div>
        </div>
    <?php else: ?>
        <table class="vb-table">
            <thead>
                <tr>
                    <th><?= __('admin.deletion.th_customer') ?></th>
                    <th><?= __('admin.deletion.th_tenant') ?></th>
                    <th><?= __('admin.deletion.th_requested') ?></th>
                    <th><?= __('admin.deletion.th_bookings') ?></th>
                    <th class="vb-th-actions"><?= __('admin.deletion.th_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pendingRequests as $i => $req): ?>
                <tr class="vb-animate-in stagger-<?= min($i + 3, 6) ?>">
                    <td>
                        <div class="vb-cell-primary"><?= View::e($req['name']) ?></div>
                        <div class="vb-mono vb-cell-sub"><?= View::e($req['email']) ?></div>
                    </td>
                    <td>
                        <span class="vb-cell-dim"><?= View::e($req['tenant_name']) ?></span>
                    </td>
                    <td>
                        <span class="vb-mono vb-cell-sub"><?= View::e($req['deletion_requested_at']) ?></span>
                    </td>
                    <td>
                        <span class="vb-nums vb-text-xs"><?= (int) ($req['booking_count'] ?? 0) ?></span>
                    </td>
                    <td>
                        <div class="vb-table-actions">
                            <input type="checkbox" id="dq-confirm-<?= View::e($req['id']) ?>" class="vb-confirm-toggle">
                            <span class="vb-confirm-step-1">
                                <label for="dq-confirm-<?= View::e($req['id']) ?>" class="vb-btn vb-btn-danger vb-btn-sm vb-label-clickable">
                                    <?= __('admin.deletion.btn_anonymize') ?>
                                </label>
                            </span>
                            <span class="vb-confirm-step-2">
                                <form method="post" action="/admin/deletion-queue/confirm" class="vb-inline-form">
                                    <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                                    <input type="hidden" name="customer_id" value="<?= View::e($req['id']) ?>">
                                    <button type="submit" class="vb-btn vb-btn-danger vb-btn-sm"><?= __('admin.deletion.btn_confirm') ?></button>
                                </form>
                                <label for="dq-confirm-<?= View::e($req['id']) ?>" class="vb-btn vb-btn-ghost vb-btn-sm vb-label-clickable">
                                    <?= __('admin.deletion.btn_cancel') ?>
                                </label>
                            </span>

                            <form method="post" action="/admin/deletion-queue/dismiss" class="vb-inline-form">
                                <input type="hidden" name="_csrf_token" value="<?= View::e($csrfToken) ?>">
                                <input type="hidden" name="customer_id" value="<?= View::e($req['id']) ?>">
                                <button type="submit" class="vb-btn vb-btn-secondary vb-btn-sm"><?= __('admin.deletion.btn_dismiss') ?></button>
                            </form>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<!-- Recently Processed -->
<?php if (!empty($processedRequests)): ?>
<div class="vb-section-muted vb-animate-in stagger-5">
    <div class="vb-table-container">
        <div class="vb-card-header">
            <div class="vb-card-title-row">
                <i data-lucide="check" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.deletion.recently_processed') ?></div>
                    <div class="vb-card-desc"><?= __('admin.deletion.recently_processed_desc') ?></div>
                </div>
            </div>
        </div>
        <table class="vb-table">
            <thead>
                <tr>
                    <th><?= __('admin.deletion.th_customer') ?></th>
                    <th><?= __('admin.deletion.th_tenant') ?></th>
                    <th><?= __('admin.deletion.th_requested') ?></th>
                    <th><?= __('admin.deletion.th_processed') ?></th>
                    <th><?= __('admin.deletion.th_status') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($processedRequests as $req): ?>
                <tr>
                    <td>
                        <div class="vb-cell-primary"><?= View::e($req['name']) ?></div>
                        <div class="vb-mono vb-cell-sub"><?= View::e($req['email']) ?></div>
                    </td>
                    <td><span class="vb-cell-dim"><?= View::e($req['tenant_name']) ?></span></td>
                    <td><span class="vb-mono vb-cell-sub"><?= View::e($req['deletion_requested_at']) ?></span></td>
                    <td><span class="vb-mono vb-cell-sub"><?= View::e($req['anonymized_at'] ?? '') ?></span></td>
                    <td><span class="vb-badge vb-badge-success"><?= __('admin.deletion.status_anonymized') ?></span></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include dirname(__DIR__) . '/admin/layout.php';
