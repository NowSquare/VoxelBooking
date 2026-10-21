<?php
/**
 * Resources list — tenant-scoped, resource-pattern only.
 *
 * Columns: Order, Name, Capacity, Price/night, Stay range, Status, Active bookings, Actions.
 *
 * Variables: $tenant, $resources, $tenantId, $csrfToken, $flash
 */
$tenant = $tenant ?? [];
$resources = $resources ?? [];
$tenantId = $tenantId ?? '';

ob_start();
?>

<?php
ob_start();
?>
<a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/resources/create"
           class="vb-btn vb-btn-primary" id="new-resource-btn">
            <i data-lucide="plus" class="vb-icon-md"></i>
            <?= __('admin.resources.new') ?>
        </a>
<?php
$pageHeader = [
    'title' => __('admin.resources.title'),
    'subtitle' => __p('admin.resources.showing_count', count($resources)),
    'actions' => ob_get_clean(),
];
include __DIR__ . '/../../partials/page-header.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<?php if (empty($resources)): ?>
    <div class="vb-empty-state vb-animate-in">
        <i data-lucide="bed" class="vb-empty-icon"></i>
        <h3><?= __('admin.resources.empty_title') ?></h3>
        <p><?= __('admin.resources.empty_desc') ?></p>
        <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/resources/create"
           class="vb-btn vb-btn-primary">
            <i data-lucide="plus" class="vb-icon-md"></i>
            <?= __('admin.resources.new') ?>
        </a>
    </div>
<?php else: ?>
    <div class="vb-table-container">
        <div class="vb-table-wrap">
            <table class="vb-table" id="resources-table">
                <thead>
                    <tr>
                        <th class="vb-text-center"><?= __('admin.common.col_order') ?></th>
                        <th><?= __('admin.resources.col_name') ?></th>
                        <th class="vb-text-center"><?= __('admin.resources.col_capacity') ?></th>
                        <th><?= __('admin.resources.col_price') ?></th>
                        <th><?= __('admin.resources.col_stay') ?></th>
                        <th><?= __('admin.resources.col_status') ?></th>
                        <th class="vb-text-center"><?= __('admin.resources.col_bookings') ?></th>
                        <th class="vb-text-right"><?= __('admin.resources.col_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    // Pre-compute group boundaries for chevron disabled state.
                    // Table sorts: is_active DESC, sort_order ASC — active rows first.
                    $groupFirst = $groupLast = [];
                    foreach ($resources as $idx => $r) {
                        $g = (int) $r['is_active'];
                        if (!isset($groupFirst[$g])) $groupFirst[$g] = $idx;
                        $groupLast[$g] = $idx;
                    }
                    ?>
                    <?php foreach ($resources as $i => $res): ?>
                    <?php $grp = (int) $res['is_active']; ?>
                    <tr class="vb-fade-in-up stagger-<?= min($i + 1, 6) ?> <?= !$res['is_active'] ? 'vb-row-inactive' : '' ?>">
                        <td class="vb-text-center">
                            <div class="vb-reorder-group">
                                <form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/resources/<?= htmlspecialchars($res['id'], ENT_QUOTES, 'UTF-8') ?>/reorder" class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="_record_version" value="<?= htmlspecialchars($recordVersions[$res['id']] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="direction" value="up">
                                    <button type="submit" class="vb-reorder-btn" title="<?= __('admin.common.move_up') ?>" <?= $i === $groupFirst[$grp] ? 'disabled' : '' ?>>
                                        <i data-lucide="chevron-up"></i>
                                    </button>
                                </form>
                                <form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/resources/<?= htmlspecialchars($res['id'], ENT_QUOTES, 'UTF-8') ?>/reorder" class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="_record_version" value="<?= htmlspecialchars($recordVersions[$res['id']] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="direction" value="down">
                                    <button type="submit" class="vb-reorder-btn" title="<?= __('admin.common.move_down') ?>" <?= $i === $groupLast[$grp] ? 'disabled' : '' ?>>
                                        <i data-lucide="chevron-down"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                        <td>
                            <div>
                                <span class="vb-cell-name"><?= htmlspecialchars($res['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <?php if (!empty($res['amenities'])): ?>
                                    <span class="vb-cell-meta"><?= htmlspecialchars(implode(', ', array_slice($res['amenities'], 0, 3)), ENT_QUOTES, 'UTF-8') ?><?= count($res['amenities']) > 3 ? '…' : '' ?></span>
                                <?php endif; ?>
                            </div>
                        </td>
                        <td class="vb-text-center">
                            <span class="vb-badge vb-badge-neutral"><?= (int) $res['capacity'] ?></span>
                        </td>
                        <td class="vb-text-secondary">
                            <?php if ($res['price_per_night'] !== null): ?>
                                <?= __c((float) $res['price_per_night'], $tenant['currency'] ?? 'EUR') ?>/<?= __('admin.bookings.per_night') ?>
                            <?php else: ?>
                                <span class="vb-text-ghost">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="vb-text-secondary">
                            <?= str_replace(
                                [':min', ':max'],
                                [(string) (int) $res['min_stay_nights'], (string) (int) $res['max_stay_nights']],
                                __('admin.resources.stay_range_format')
                            ) ?>
                        </td>
                        <td>
                            <?php if ($res['is_active']): ?>
                                <span class="vb-badge vb-badge-success"><?= __('admin.resources.status_active') ?></span>
                            <?php else: ?>
                                <span class="vb-badge vb-badge-default"><?= __('admin.resources.status_inactive') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="vb-text-center">
                            <?php $bc = (int) ($res['active_bookings'] ?? 0); ?>
                            <?php if ($bc > 0): ?>
                                <span class="vb-badge vb-badge-neutral"><?= $bc ?></span>
                            <?php else: ?>
                                <span class="vb-text-tertiary">—</span>
                            <?php endif; ?>
                        </td>
                        <td class="vb-text-right">
                            <div class="vb-action-group">
                                <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/resources/<?= htmlspecialchars($res['id'], ENT_QUOTES, 'UTF-8') ?>/edit"
                                   class="vb-btn vb-btn-ghost vb-btn-sm" title="<?= __('admin.common.edit') ?>">
                                    <i data-lucide="pencil" class="vb-icon-sm"></i>
                                </a>
                                <?php if ($res['is_active']): ?>
                                <form method="POST"
                                      action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/resources/<?= htmlspecialchars($res['id'], ENT_QUOTES, 'UTF-8') ?>/deactivate"
                                      class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="_record_version" value="<?= htmlspecialchars($recordVersions[$res['id']] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm vb-btn-destructive"
                                            title="<?= __('admin.resources.deactivate') ?>">
                                        <i data-lucide="eye-off" class="vb-icon-sm"></i>
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST"
                                      action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/resources/<?= htmlspecialchars($res['id'], ENT_QUOTES, 'UTF-8') ?>/activate"
                                      class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="_record_version" value="<?= htmlspecialchars($recordVersions[$res['id']] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm"
                                            title="<?= __('admin.resources.activate') ?>">
                                        <i data-lucide="eye" class="vb-icon-sm"></i>
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
