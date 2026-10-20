<?php
/**
 * Services list — tenant-scoped.
 *
 * Columns: Order, Name/Category, Duration, Price, Staff (count), Status, Actions.
 *
 * Variables: $tenant, $services, $tenantId, $csrfToken, $flash
 */
$tenant = $tenant ?? [];
$services = $services ?? [];
$tenantId = $tenantId ?? '';

ob_start();
?>

<?php
ob_start();
?>
<a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/services/create"
           class="vb-btn vb-btn-primary" id="new-service-btn">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <?= __('admin.services.new') ?>
        </a>
<?php
$pageHeader = [
    'title' => __('admin.services.title'),
    'subtitle' => __p('admin.services.showing_count', count($services)),
    'actions' => ob_get_clean(),
];
include __DIR__ . '/../../partials/page-header.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<?php if (empty($services)): ?>
    <div class="vb-empty-state vb-animate-in">
        <i data-lucide="layers" class="vb-empty-icon"></i>
        <h3><?= __('admin.services.empty_title') ?></h3>
        <p><?= __('admin.services.empty_desc') ?></p>
        <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/services/create"
           class="vb-btn vb-btn-primary">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <?= __('admin.services.new') ?>
        </a>
    </div>
<?php else: ?>
    <div class="vb-table-container">
        <div class="vb-table-wrap">
            <table class="vb-table" id="services-table">
                <thead>
                    <tr>
                        <th class="vb-text-center"><?= __('admin.common.col_order') ?></th>
                        <th><?= __('admin.services.col_name') ?></th>
                        <th><?= __('admin.services.col_duration') ?></th>
                        <th><?= __('admin.services.col_price') ?></th>
                        <th class="vb-text-center"><?= __('admin.services.col_staff') ?></th>
                        <th><?= __('admin.services.col_status') ?></th>
                        <th class="vb-text-right"><?= __('admin.services.col_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Pre-compute group boundaries for chevron disabled state.
                    // Table sorts: is_active DESC, sort_order ASC — active rows first.
                    $groupFirst = $groupLast = [];
                    foreach ($services as $idx => $s) {
                        $g = (int) $s['is_active'];
                        if (!isset($groupFirst[$g])) $groupFirst[$g] = $idx;
                        $groupLast[$g] = $idx;
                    }
                    ?>
                    <?php foreach ($services as $i => $svc): ?>
                    <?php $grp = (int) $svc['is_active']; ?>
                    <tr class="vb-fade-in-up stagger-<?= min($i + 1, 6) ?> <?= !$svc['is_active'] ? 'vb-row-inactive' : '' ?>">
                        <td class="vb-text-center">
                            <div class="vb-reorder-group">
                                <form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/services/<?= htmlspecialchars($svc['id'], ENT_QUOTES, 'UTF-8') ?>/reorder" class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="vb-reorder-btn" title="<?= __('admin.common.move_up') ?>" <?= $i === $groupFirst[$grp] ? 'disabled' : '' ?>>
                                        <i data-lucide="chevron-up"></i>
                                    </button>
                                </form>
                                <form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/services/<?= htmlspecialchars($svc['id'], ENT_QUOTES, 'UTF-8') ?>/reorder" class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="vb-reorder-btn" title="<?= __('admin.common.move_down') ?>" <?= $i === $groupLast[$grp] ? 'disabled' : '' ?>>
                                        <i data-lucide="chevron-down"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                        <td>
                            <div class="vb-service-cell">
                                <?php if ($svc['color']): ?>
                                <span class="vb-service-dot" style="background: <?= htmlspecialchars($svc['color'], ENT_QUOTES, 'UTF-8') ?>;"></span>
                                <?php endif; ?>
                                <div>
                                    <span class="vb-cell-name"><?= htmlspecialchars($svc['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($svc['category']): ?>
                                        <span class="vb-cell-meta"><?= htmlspecialchars($svc['category'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="vb-tabular">
                            <?= str_replace(':min', (string) $svc['duration_minutes'], __('admin.services.duration_format')) ?>
                        </td>
                        <td class="vb-text-secondary">
                            <?php if ($svc['price'] !== null): ?>
                                <?php if ($svc['price_label']): ?>
                                    <?= htmlspecialchars($svc['price_label'], ENT_QUOTES, 'UTF-8') ?>
                                <?php else: ?>
                                    <?= __c((float) $svc['price'], $tenant['currency'] ?? 'EUR') ?>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="vb-text-ghost">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="vb-text-center">
                            <?php $sc = (int) $svc['staff_count']; ?>
                            <?php if ($sc > 0): ?>
                                <span class="vb-badge vb-badge-neutral"><?= $sc ?></span>
                            <?php else: ?>
                                <span class="vb-text-tertiary">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($svc['is_active']): ?>
                                <span class="vb-badge vb-badge-success"><?= __('admin.services.status_active') ?></span>
                            <?php else: ?>
                                <span class="vb-badge vb-badge-default"><?= __('admin.services.status_inactive') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="vb-text-right">
                            <div class="vb-action-group">
                                <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/services/<?= htmlspecialchars($svc['id'], ENT_QUOTES, 'UTF-8') ?>/edit"
                                   class="vb-btn vb-btn-ghost vb-btn-sm" title="<?= __('admin.common.edit') ?>">
                                    <i data-lucide="pencil" class="w-3.5 h-3.5"></i>
                                </a>
                                <?php if ($svc['is_active']): ?>
                                <form method="POST"
                                      action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/services/<?= htmlspecialchars($svc['id'], ENT_QUOTES, 'UTF-8') ?>/deactivate"
                                      class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm vb-btn-destructive"
                                            title="<?= __('admin.services.deactivate') ?>">
                                        <i data-lucide="eye-off" class="w-3.5 h-3.5"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST"
                                      action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/services/<?= htmlspecialchars($svc['id'], ENT_QUOTES, 'UTF-8') ?>/activate"
                                      class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm"
                                            title="<?= __('admin.services.activate') ?>">
                                        <i data-lucide="eye" class="w-3.5 h-3.5"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
