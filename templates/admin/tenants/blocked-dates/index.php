<?php
/**
 * Admin — Blocked Dates Management
 *
 * Lists all blocked periods (tenant-level + staff/resource-level),
 * with an inline form to add new entries.
 *
 * @var array  $tenant
 * @var array  $upcoming     Upcoming/active blocked dates
 * @var array  $past         Past blocked dates
 * @var array  $staff        Active staff for scope selector (timeslot)
 * @var array  $resources    Active resources for scope selector (resource)
 * @var string $tenantId
 * @var string $csrfToken
 * @var array|null $flash
 */
$activePage  = 'blocked-dates';
$tenant = $tenant ?? [];
$tenantId = $tenantId ?? '';
$upcoming = $upcoming ?? [];
$past = $past ?? [];
$staff = $staff ?? [];
$resources = $resources ?? [];
$isResourcePattern = ($tenant['booking_pattern'] ?? '') === 'resource';

ob_start();
?>

<div class="vb-page-header">
    <div>
        <h2 class="vb-page-title"><?= __('admin.blocked_dates.title') ?></h2>
        <p class="vb-page-subtitle"><?= __('admin.blocked_dates.subtitle') ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<!-- Add blocked date form -->
<div class="vb-card vb-card-clip vb-animate-in vb-mb-xl">
    <div class="vb-card-section-header">
        <h3 class="vb-card-section-title">
            <i data-lucide="calendar-plus" class="vb-icon-md"></i>
            <?= __('admin.blocked_dates.add_title') ?>
        </h3>
    </div>
<?php
// Compute composite scope value for Alpine hydration
$initialScope = 'tenant';
$oldStaffId = old('staff_id');
$oldResourceId = old('resource_id');
if ($oldStaffId !== '' && $oldStaffId !== null) {
    $initialScope = 'staff:' . $oldStaffId;
} elseif ($oldResourceId !== '' && $oldResourceId !== null) {
    $initialScope = 'resource:' . $oldResourceId;
}
?>
    <form method="POST"
          action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/blocked-dates"
          x-data="blockedDateScope"
          data-initial-scope="<?= e($initialScope) ?>"
          novalidate>
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="vb-card-body">
            <div class="vb-form-grid vb-form-grid-4">
                
                <div class="vb-form-group vb-mb-0">
                    <label class="vb-label" for="bd-start"><?= __('admin.blocked_dates.start_date') ?></label>
                    <input type="date" class="vb-input<?= error_class('start_date') ?>" id="bd-start" name="start_date"
                           required min="<?= date('Y-m-d') ?>" value="<?= e(old('start_date')) ?>">
                    <?php if (has_error('start_date')): ?>
                        <div class="vb-form-error" role="alert"><?= field_error('start_date') ?></div>
                    <?php endif; ?>
                </div>

                <div class="vb-form-group vb-mb-0">
                    <label class="vb-label" for="bd-end"><?= __('admin.blocked_dates.end_date') ?></label>
                    <input type="date" class="vb-input<?= error_class('end_date') ?>" id="bd-end" name="end_date"
                           required min="<?= date('Y-m-d') ?>" value="<?= e(old('end_date')) ?>">
                    <?php if (has_error('end_date')): ?>
                        <div class="vb-form-error" role="alert"><?= field_error('end_date') ?></div>
                    <?php endif; ?>
                </div>

                <div class="vb-form-group vb-mb-0">
                    <label class="vb-label" for="bd-reason"><?= __('admin.blocked_dates.reason') ?></label>
                    <input type="text" class="vb-input" id="bd-reason" name="reason"
                           placeholder="<?= __('admin.blocked_dates.reason_placeholder') ?>" maxlength="255"
                           value="<?= e(old('reason')) ?>">
                </div>

                <div class="vb-form-group vb-mb-0">
                    <label class="vb-label" for="bd-scope"><?= __('admin.blocked_dates.scope') ?></label>
                    <select class="vb-input" id="bd-scope" x-model="selectedScope" @change="onScopeChange()">
                        <option value="tenant"><?= __('admin.blocked_dates.scope_tenant') ?></option>
                        
                        <?php if (!empty($staff)): ?>
                        <optgroup label="<?= __('admin.blocked_dates.scope_staff') ?>">
                            <?php foreach ($staff as $s): ?>
                            <option value="staff:<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>
                                <?php if ($s['title']): ?>(<?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?>)<?php endif; ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>

                        <?php if (!empty($resources)): ?>
                        <optgroup label="<?= __('admin.blocked_dates.scope_resource') ?>">
                            <?php foreach ($resources as $r): ?>
                            <option value="resource:<?= htmlspecialchars($r['id'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($r['name'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                            <?php endforeach; ?>
                        </optgroup>
                        <?php endif; ?>
                    </select>
                    
                    <!-- Dynamic payload generation -->
                    <input type="hidden" name="scope" :value="selectedScope === 'tenant' ? 'tenant' : selectedScope.split(':')[0]">
                    <input type="hidden" name="staff_id" :value="selectedScope.startsWith('staff:') ? selectedScope.split(':')[1] : ''">
                    <input type="hidden" name="resource_id" :value="selectedScope.startsWith('resource:') ? selectedScope.split(':')[1] : ''">
                </div>
            </div>
        </div>

        <div class="vb-card-section-footer">
            <button type="submit" class="vb-btn vb-btn-primary" id="add-blocked-date-btn">
                <i data-lucide="plus" class="vb-icon-md"></i>
                <?= __('admin.blocked_dates.add') ?>
            </button>
        </div>
    </form>
</div>

<!-- Upcoming / Active -->
<?php if (!empty($upcoming)): ?>
<div class="vb-card vb-card-clip vb-animate-in vb-mb-xl">
    <div class="vb-card-section-header">
        <h3 class="vb-card-section-title"><?= __('admin.blocked_dates.upcoming') ?></h3>
        <span class="vb-badge vb-badge-accent"><?= count($upcoming) ?></span>
    </div>
    <div class="vb-table-wrap">
        <table class="vb-table" id="blocked-dates-upcoming">
            <thead>
                <tr>
                    <th><?= __('admin.blocked_dates.col_dates') ?></th>
                    <th><?= __('admin.blocked_dates.col_scope') ?></th>
                    <th><?= __('admin.blocked_dates.col_reason') ?></th>
                    <th class="vb-text-right"><?= __('admin.blocked_dates.col_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($upcoming as $i => $bd): ?>
                <tr class="vb-fade-in-up stagger-<?= min($i + 1, 6) ?>">
                    <td>
                        <span class="vb-cell-name vb-tabular">
                            <?php if ($bd['start_date'] === $bd['end_date']): ?>
                                <?= htmlspecialchars(date('M j, Y', strtotime($bd['start_date'])), ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                <?= htmlspecialchars(date('M j', strtotime($bd['start_date'])), ENT_QUOTES, 'UTF-8') ?>
                                — <?= htmlspecialchars(date('M j, Y', strtotime($bd['end_date'])), ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($bd['resource_id']): ?>
                            <span class="vb-badge vb-badge-muted">
                                <i data-lucide="bed" class="vb-icon-xs"></i>
                                <?= htmlspecialchars($bd['resource_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php elseif ($bd['staff_id']): ?>
                            <span class="vb-badge vb-badge-muted">
                                <i data-lucide="user" class="vb-icon-xs"></i>
                                <?= htmlspecialchars($bd['staff_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php else: ?>
                            <span class="vb-badge vb-badge-accent">
                                <i data-lucide="building-2" class="vb-icon-xs"></i>
                                <?= __('admin.blocked_dates.tenant_level') ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="vb-text-secondary">
                        <?= $bd['reason'] ? htmlspecialchars($bd['reason'], ENT_QUOTES, 'UTF-8') : '<span class="vb-text-ghost">—</span>' ?>
                    </td>
                    <td class="vb-text-right">
                        <form method="POST"
                              action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/blocked-dates/<?= htmlspecialchars($bd['id'], ENT_QUOTES, 'UTF-8') ?>/delete"
                              class="vb-form-flush"
                              data-confirm="<?= __('admin.blocked_dates.delete_confirm') ?>" data-confirm-text="<?= __('admin.common.delete') ?>">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm vb-btn-destructive"
                                    title="<?= __('admin.blocked_dates.delete') ?>">
                                <i data-lucide="trash-2" class="vb-icon-sm"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Past (collapsed by default) -->
<?php if (!empty($past)): ?>
<details class="vb-card vb-card-clip vb-animate-in vb-details-accordion vb-mb-md">
    <summary class="vb-card-section-header vb-details-summary">
        <h3 class="vb-card-section-title vb-card-section-title--muted">
            <i data-lucide="chevron-right" class="vb-icon-md vb-details-chevron"></i>
            <?= __('admin.blocked_dates.past') ?>
        </h3>
        <span class="vb-badge vb-badge-muted"><?= count($past) ?></span>
    </summary>
    <div class="vb-table-wrap">
        <table class="vb-table">
            <thead>
                <tr>
                    <th><?= __('admin.blocked_dates.col_dates') ?></th>
                    <th><?= __('admin.blocked_dates.col_scope') ?></th>
                    <th><?= __('admin.blocked_dates.col_reason') ?></th>
                    <th class="vb-text-right"><?= __('admin.blocked_dates.col_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($past as $bd): ?>
                <tr class="vb-row-inactive">
                    <td>
                        <span class="vb-cell-name vb-tabular">
                            <?php if ($bd['start_date'] === $bd['end_date']): ?>
                                <?= htmlspecialchars(date('M j, Y', strtotime($bd['start_date'])), ENT_QUOTES, 'UTF-8') ?>
                            <?php else: ?>
                                <?= htmlspecialchars(date('M j', strtotime($bd['start_date'])), ENT_QUOTES, 'UTF-8') ?>
                                — <?= htmlspecialchars(date('M j, Y', strtotime($bd['end_date'])), ENT_QUOTES, 'UTF-8') ?>
                            <?php endif; ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($bd['resource_id']): ?>
                            <span class="vb-badge vb-badge-muted">
                                <?= htmlspecialchars($bd['resource_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php elseif ($bd['staff_id']): ?>
                            <span class="vb-badge vb-badge-muted">
                                <?= htmlspecialchars($bd['staff_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        <?php else: ?>
                            <span class="vb-badge vb-badge-muted">
                                <?= __('admin.blocked_dates.tenant_level') ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td class="vb-text-secondary">
                        <?= $bd['reason'] ? htmlspecialchars($bd['reason'], ENT_QUOTES, 'UTF-8') : '<span class="vb-text-ghost">—</span>' ?>
                    </td>
                    <td class="vb-text-right">
                        <form method="POST"
                              action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/blocked-dates/<?= htmlspecialchars($bd['id'], ENT_QUOTES, 'UTF-8') ?>/delete"
                              class="vb-form-flush"
                              data-confirm="<?= __('admin.blocked_dates.delete_confirm') ?>" data-confirm-text="<?= __('admin.common.delete') ?>">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm vb-btn-destructive"
                                    title="<?= __('admin.blocked_dates.delete') ?>">
                                <i data-lucide="trash-2" class="vb-icon-sm"></i>
                            </button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</details>
<?php endif; ?>

<!-- Empty state -->
<?php if (empty($upcoming) && empty($past)): ?>
<div class="vb-empty-state vb-animate-in">
    <i data-lucide="calendar-x" class="vb-empty-icon"></i>
    <h3><?= __('admin.blocked_dates.empty_title') ?></h3>
    <p><?= __('admin.blocked_dates.empty_desc') ?></p>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
