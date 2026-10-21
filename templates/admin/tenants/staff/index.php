<?php
/**
 * Staff list — tenant-scoped.
 *
 * Columns: Order, Name/Title, Email, Services (count), Status, Actions.
 * Active staff first, inactive grayed out.
 *
 * Variables: $tenant, $staff, $tenantId, $csrfToken, $flash
 */
$tenant = $tenant ?? [];
$staff = $staff ?? [];
$tenantId = $tenantId ?? '';

ob_start();
?>

<?php
ob_start();
?>
<a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/staff/create"
           class="vb-btn vb-btn-primary" id="new-staff-btn">
            <i data-lucide="plus" class="vb-icon-md"></i>
            <?= __('admin.staff.new') ?>
        </a>
<?php
$pageHeader = [
    'title' => __('admin.staff.title'),
    'subtitle' => __p('admin.staff.showing_count', count($staff)),
    'actions' => ob_get_clean(),
];
include __DIR__ . '/../../partials/page-header.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<?php if (empty($staff)): ?>
    <div class="vb-empty-state vb-animate-in">
        <i data-lucide="users" class="vb-empty-icon"></i>
        <h3><?= __('admin.staff.empty_title') ?></h3>
        <p><?= __('admin.staff.empty_desc') ?></p>
        <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/staff/create"
           class="vb-btn vb-btn-primary">
            <i data-lucide="plus" class="vb-icon-md"></i>
            <?= __('admin.staff.new') ?>
        </a>
    </div>
<?php else: ?>
    <div class="vb-table-container">
        <div class="vb-table-wrap">
            <table class="vb-table" id="staff-table">
                <thead>
                    <tr>
                        <th class="vb-text-center"><?= __('admin.common.col_order') ?></th>
                        <th><?= __('admin.staff.col_name') ?></th>
                        <th><?= __('admin.staff.col_email') ?></th>
                        <th class="vb-text-center"><?= __('admin.staff.col_services') ?></th>
                        <th><?= __('admin.staff.col_status') ?></th>
                        <th class="vb-text-right"><?= __('admin.staff.col_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Pre-compute group boundaries for chevron disabled state.
                    // Table sorts: is_active DESC, sort_order ASC — active rows first.
                    $groupFirst = $groupLast = [];
                    foreach ($staff as $idx => $s) {
                        $g = (int) $s['is_active'];
                        if (!isset($groupFirst[$g])) $groupFirst[$g] = $idx;
                        $groupLast[$g] = $idx;
                    }
                    ?>
                    <?php foreach ($staff as $i => $m): ?>
                    <?php $grp = (int) $m['is_active']; ?>
                    <tr class="vb-fade-in-up stagger-<?= min($i + 1, 6) ?> <?= !$m['is_active'] ? 'vb-row-inactive' : '' ?>">
                        <td class="vb-text-center">
                            <div class="vb-reorder-group">
                                <form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/staff/<?= htmlspecialchars($m['id'], ENT_QUOTES, 'UTF-8') ?>/reorder" class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="_record_version" value="<?= htmlspecialchars($recordVersions[$m['id']] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="vb-reorder-btn" title="<?= __('admin.common.move_up') ?>" <?= $i === $groupFirst[$grp] ? 'disabled' : '' ?>>
                                        <i data-lucide="chevron-up"></i>
                                    </button>
                                </form>
                                <form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/staff/<?= htmlspecialchars($m['id'], ENT_QUOTES, 'UTF-8') ?>/reorder" class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="_record_version" value="<?= htmlspecialchars($recordVersions[$m['id']] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="vb-reorder-btn" title="<?= __('admin.common.move_down') ?>" <?= $i === $groupLast[$grp] ? 'disabled' : '' ?>>
                                        <i data-lucide="chevron-down"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                        <td>
                            <div class="vb-user-cell">
                                <span class="vb-avatar vb-avatar-xs"><?= mb_strtoupper(mb_substr($m['name'], 0, 1)) ?></span>
                                <div>
                                    <span class="vb-cell-name"><?= htmlspecialchars($m['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php if ($m['title']): ?>
                                        <span class="vb-cell-meta"><?= htmlspecialchars($m['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </td>
                        <td class="vb-text-secondary"><?= htmlspecialchars($m['email'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="vb-text-center">
                            <?php $sc = (int) $m['service_count']; ?>
                            <?php if ($sc > 0): ?>
                                <span class="vb-badge vb-badge-neutral"><?= $sc ?></span>
                            <?php else: ?>
                                <span class="vb-text-tertiary">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($m['is_active']): ?>
                                <span class="vb-badge vb-badge-success"><?= __('admin.staff.status_active') ?></span>
                            <?php else: ?>
                                <span class="vb-badge vb-badge-default"><?= __('admin.staff.status_inactive') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="vb-text-right">
                            <div class="vb-action-group">
                                <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/staff/<?= htmlspecialchars($m['id'], ENT_QUOTES, 'UTF-8') ?>/edit"
                                   class="vb-btn vb-btn-ghost vb-btn-sm" title="<?= __('admin.common.edit') ?>">
                                    <i data-lucide="pencil" class="vb-icon-sm"></i>
                                </a>
                                <?php if ($m['is_active']): ?>
                                <form method="POST"
                                      action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/staff/<?= htmlspecialchars($m['id'], ENT_QUOTES, 'UTF-8') ?>/deactivate"
                                      class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="_record_version" value="<?= htmlspecialchars($recordVersions[$m['id']] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm vb-btn-destructive"
                                            title="<?= __('admin.staff.deactivate') ?>">
                                        <i data-lucide="user-minus" class="vb-icon-sm"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST"
                                      action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/staff/<?= htmlspecialchars($m['id'], ENT_QUOTES, 'UTF-8') ?>/activate"
                                      class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="_record_version" value="<?= htmlspecialchars($recordVersions[$m['id']] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm"
                                            title="<?= __('admin.staff.activate') ?>">
                                        <i data-lucide="user-check" class="vb-icon-sm"></i>
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
