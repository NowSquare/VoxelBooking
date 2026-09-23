<?php
/**
 * Capacity slots management — tenant-scoped, capacity-pattern only.
 *
 * Weekly grid of time windows with max capacity and party size.
 *
 * Variables: $tenant, $slots, $tenantId, $dayNames, $csrfToken, $flash
 */
$tenant = $tenant ?? [];
$slots = $slots ?? [];
$tenantId = $tenantId ?? '';
$dayNames = [];
for ($i = 0; $i < 7; $i++) {
    $dayNames[$i] = __('admin.capacity_slots.day_' . $i);
}

ob_start();
?>

<div class="vb-page-header">
    <div>
        <h2 class="vb-page-title"><?= __('admin.capacity_slots.title') ?></h2>
        <p class="vb-page-subtitle"><?= __('admin.capacity_slots.subtitle') ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<!-- Add Slot Form -->
<div class="vb-card vb-mb-lg">
    <div class="vb-card-header">
        <div class="vb-card-title-row">
            <i data-lucide="plus" class="vb-card-icon"></i>
            <div>
                <h3 class="vb-card-title"><?= __('admin.capacity_slots.add_slot') ?></h3>
                <div class="vb-card-desc"><?= __('admin.capacity_slots.add_slot_desc') ?></div>
            </div>
        </div>
    </div>
    <div class="vb-card-body">
        <form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/capacity-slots" id="add-slot-form" novalidate>
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <div class="vb-form-grid vb-form-grid-4">
                <div class="vb-form-group">
                    <label class="vb-label" for="slot-day"><?= __('admin.capacity_slots.label_day') ?></label>
                    <select name="day_of_week" id="slot-day" class="vb-select<?= error_class('day_of_week') ?>" required>
                        <?php for ($d = 0; $d < 7; $d++): ?>
                            <option value="<?= $d ?>" <?= old('day_of_week', '0') === (string) $d ? 'selected' : '' ?>><?= $dayNames[$d] ?></option>
                        <?php endfor; ?>
                    </select>
                    <?php if (has_error('day_of_week')): ?>
                        <div class="vb-form-error" role="alert"><?= field_error('day_of_week') ?></div>
                    <?php endif; ?>
                </div>
                <div class="vb-form-group">
                    <label class="vb-label" for="slot-start"><?= __('admin.capacity_slots.label_start_time') ?></label>
                    <input type="time" name="start_time" id="slot-start" class="vb-input<?= error_class('start_time') ?>" required value="<?= e(old('start_time', '18:00')) ?>">
                    <?php if (has_error('start_time')): ?>
                        <div class="vb-form-error" role="alert"><?= field_error('start_time') ?></div>
                    <?php endif; ?>
                </div>
                <div class="vb-form-group">
                    <label class="vb-label" for="slot-end"><?= __('admin.capacity_slots.label_end_time') ?></label>
                    <input type="time" name="end_time" id="slot-end" class="vb-input<?= error_class('end_time') ?>" required value="<?= e(old('end_time', '20:00')) ?>">
                    <?php if (has_error('end_time')): ?>
                        <div class="vb-form-error" role="alert"><?= field_error('end_time') ?></div>
                    <?php endif; ?>
                </div>
                <div class="vb-form-group">
                    <label class="vb-label" for="slot-capacity"><?= __('admin.capacity_slots.label_capacity') ?></label>
                    <input type="number" name="max_capacity" id="slot-capacity" class="vb-input<?= error_class('max_capacity') ?>" required min="1" max="10000" value="<?= e(old('max_capacity', '20')) ?>">
                    <?php if (has_error('max_capacity')): ?>
                        <div class="vb-form-error" role="alert"><?= field_error('max_capacity') ?></div>
                    <?php endif; ?>
                </div>
                <div class="vb-form-group">
                    <label class="vb-label" for="slot-min-party"><?= __('admin.capacity_slots.label_min_party_size') ?></label>
                    <input type="number" name="min_party_size" id="slot-min-party" class="vb-input<?= error_class('min_party_size') ?>" required min="1" max="1000" value="<?= e(old('min_party_size', '1')) ?>">
                    <?php if (has_error('min_party_size')): ?>
                        <div class="vb-form-error" role="alert"><?= field_error('min_party_size') ?></div>
                    <?php endif; ?>
                </div>
                <div class="vb-form-group">
                    <label class="vb-label" for="slot-party"><?= __('admin.capacity_slots.label_party_size') ?></label>
                    <input type="number" name="max_party_size" id="slot-party" class="vb-input<?= error_class('max_party_size') ?>" required min="1" max="1000" value="<?= e(old('max_party_size', '8')) ?>">
                    <?php if (has_error('max_party_size')): ?>
                        <div class="vb-form-error" role="alert"><?= field_error('max_party_size') ?></div>
                    <?php endif; ?>
                </div>
                <div class="vb-form-group">
                    <label class="vb-label" for="slot-label"><?= __('admin.capacity_slots.label_label') ?></label>
                    <input type="text" name="label" id="slot-label" class="vb-input" placeholder="<?= __('admin.capacity_slots.placeholder_label') ?>" value="<?= e(old('label')) ?>">
                </div>
                <div class="vb-form-group">
                    <button type="submit" class="vb-btn vb-btn-primary" id="add-slot-btn">
                        <i data-lucide="plus" class="vb-icon-md"></i>
                        <?= __('admin.capacity_slots.add_slot') ?>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Slots Table -->
<?php if (empty($slots)): ?>
    <div class="vb-empty-state vb-animate-in">
        <i data-lucide="grid-3x3" class="vb-empty-icon"></i>
        <h3><?= __('admin.capacity_slots.empty_title') ?></h3>
        <p><?= __('admin.capacity_slots.empty_description') ?></p>
    </div>
<?php else: ?>
    <div class="vb-table-container">
        <div class="vb-table-wrap">
            <table class="vb-table" id="capacity-slots-table">
                <thead>
                    <tr>
                        <th><?= __('admin.capacity_slots.label_day') ?></th>
                        <th><?= __('admin.capacity_slots.label_start_time') ?></th>
                        <th><?= __('admin.capacity_slots.label_end_time') ?></th>
                        <th class="vb-text-center"><?= __('admin.capacity_slots.label_capacity') ?></th>
                        <th class="vb-text-center"><?= __('admin.capacity_slots.label_min_party_size') ?></th>
                        <th class="vb-text-center"><?= __('admin.capacity_slots.label_party_size') ?></th>
                        <th><?= __('admin.capacity_slots.label_label') ?></th>
                        <th><?= __('admin.capacity_slots.label_status') ?></th>
                        <th class="vb-text-right"><?= __('admin.capacity_slots.label_actions') ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($slots as $i => $slot): ?>
                    <tr class="vb-fade-in-up stagger-<?= min($i + 1, 6) ?> <?= !(int) $slot['is_active'] ? 'vb-row-inactive' : '' ?>">
                        <td class="vb-cell-name"><?= $dayNames[(int) $slot['day_of_week']] ?></td>
                        <td class="vb-text-secondary"><?= substr($slot['start_time'], 0, 5) ?></td>
                        <td class="vb-text-secondary"><?= substr($slot['end_time'], 0, 5) ?></td>
                        <td class="vb-text-center">
                            <span class="vb-badge vb-badge-neutral"><?= (int) $slot['max_capacity'] ?></span>
                        </td>
                        <td class="vb-text-center">
                            <span class="vb-badge vb-badge-neutral"><?= (int) ($slot['min_party_size'] ?? 1) ?></span>
                        </td>
                        <td class="vb-text-center">
                            <span class="vb-badge vb-badge-neutral"><?= (int) $slot['max_party_size'] ?></span>
                        </td>
                        <td class="vb-text-secondary">
                            <?= $slot['label'] ? htmlspecialchars($slot['label'], ENT_QUOTES, 'UTF-8') : '<span class="vb-text-ghost">—</span>' ?>
                        </td>
                        <td>
                            <?php if ((int) $slot['is_active']): ?>
                                <span class="vb-badge vb-badge-success"><?= __('admin.capacity_slots.status_active') ?></span>
                            <?php else: ?>
                                <span class="vb-badge vb-badge-default"><?= __('admin.capacity_slots.status_inactive') ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="vb-text-right">
                            <div class="vb-action-group">
                                <form method="POST"
                                      action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/capacity-slots/<?= htmlspecialchars($slot['id'], ENT_QUOTES, 'UTF-8') ?>/toggle"
                                      class="vb-form-flush">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm"
                                            title="<?= (int) $slot['is_active'] ? __('admin.capacity_slots.btn_deactivate') : __('admin.capacity_slots.btn_activate') ?>">
                                        <i data-lucide="<?= (int) $slot['is_active'] ? 'eye-off' : 'eye' ?>" class="vb-icon-sm"></i>
                                    </button>
                                </form>
                                <form method="POST"
                                      action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/capacity-slots/<?= htmlspecialchars($slot['id'], ENT_QUOTES, 'UTF-8') ?>/delete"
                                      class="vb-form-flush"
                                      data-confirm="<?= __('admin.capacity_slots.confirm_delete') ?>" data-confirm-text="<?= __('admin.common.delete') ?>">
                                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm vb-btn-destructive"
                                            title="<?= __('admin.capacity_slots.btn_delete') ?>">
                                        <i data-lucide="trash-2" class="vb-icon-sm"></i>
                                    </button>
                                </form>
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
