<?php
/**
 * Admin — Weekly Availability Grid Editor
 *
 * Shared between tenant-defaults and staff-override views.
 * Uses Alpine.js CSP-safe component (registered in admin/app.js).
 *
 * @var array  $tenant
 * @var array  $schedule       7-element array, each is list of ['start' => 'HH:MM', 'end' => 'HH:MM']
 * @var array  $staff          Active staff list for selector
 * @var array  $staffOverrides Staff IDs that have custom overrides
 * @var string|null $currentStaffId  Currently editing staff, null = tenant defaults
 * @var array|null  $currentStaff    Staff record if editing override
 * @var bool   $hasOverride    Whether current staff has their own rows (only set when currentStaffId)
 * @var string $tenantId
 * @var string $csrfToken
 * @var array|null $flash
 */
$activePage = 'availability';
$tenant = $tenant ?? [];
$staff = $staff ?? [];
$tenantId = $tenantId ?? '';
$schedule = $schedule ?? array_fill(0, 7, []);

$dayLabels = [
    __('admin.availability.day_mon'),
    __('admin.availability.day_tue'),
    __('admin.availability.day_wed'),
    __('admin.availability.day_thu'),
    __('admin.availability.day_fri'),
    __('admin.availability.day_sat'),
    __('admin.availability.day_sun'),
];

$formAction = $currentStaffId
    ? "/admin/tenants/{$tenantId}/availability/staff/{$currentStaffId}"
    : "/admin/tenants/{$tenantId}/availability";

$subtitle = $currentStaffId
    ? __('admin.availability.staff_subtitle', ['name' => $currentStaff['name'] ?? ''])
    : __('admin.availability.subtitle');

ob_start();
?>

<?php
$pageHeader = [
    'title' => __('admin.availability.title'),
    'subtitle' => $subtitle,
];
include __DIR__ . '/../../partials/page-header.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<!-- Staff selector -->
<?php if (!empty($staff)): ?>
<div class="vb-card vb-animate-in vb-mb-lg">
    <div class="vb-selector-strip">
        <label class="vb-label" for="availability-staff-selector">
            <i data-lucide="users" class="vb-icon-sm"></i>
            <?= __('admin.availability.staff_selector_label') ?>
        </label>
        <select id="availability-staff-selector"
                class="vb-input"
                data-navigate-select
                data-navigate-base="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/availability/staff"
                data-navigate-default="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/availability">
            <option value="" <?= !$currentStaffId ? 'selected' : '' ?>>
                <?= __('admin.availability.tenant_defaults') ?>
            </option>
            <?php foreach ($staff as $s): ?>
            <option value="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>"
                    <?= $currentStaffId === $s['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>
                <?php if ($s['title']): ?>(<?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?>)<?php endif; ?>
                <?php if (in_array($s['id'], $staffOverrides ?? [], true)): ?> ★<?php endif; ?>
            </option>
            <?php endforeach; ?>
        </select>

        <?php if ($currentStaffId): ?>
        <span class="vb-badge <?= ($hasOverride ?? false) ? 'vb-badge-accent' : 'vb-badge-muted' ?>">
            <?= ($hasOverride ?? false) ? __('admin.availability.using_custom') : __('admin.availability.using_defaults') ?>
        </span>
        <?php endif; ?>

        <?php if ($currentStaffId && ($hasOverride ?? false)): ?>
        <form method="POST"
              action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/availability/staff/<?= htmlspecialchars($currentStaffId, ENT_QUOTES, 'UTF-8') ?>/reset"
              data-confirm="<?= __('admin.availability.reset_confirm') ?>" data-confirm-text="<?= __('admin.availability.reset_to_defaults') ?>"
              class="vb-ml-auto">
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="vb-btn vb-btn-ghost vb-btn-sm vb-btn-danger">
                <i data-lucide="rotate-ccw" class="vb-icon-sm"></i>
                <?= __('admin.availability.reset_to_defaults') ?>
            </button>
        </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Weekly grid form -->
<form method="POST"
      action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>"
      x-data="availabilityGrid"
      data-schedule="<?= htmlspecialchars(json_encode($schedule, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
      data-day-labels="<?= htmlspecialchars(json_encode($dayLabels, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>"
      class="vb-animate-in"
      novalidate>
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="vb-card vb-card-clip">
        <?php if (has_error('schedule')): ?>
            <div class="vb-form-error vb-form-error-banner" role="alert">
                <i data-lucide="alert-circle" class="vb-icon-sm"></i>
                <?= field_error('schedule') ?>
            </div>
        <?php endif; ?>
        <div class="vb-avail-list">
            <template x-for="(daySlots, dayIndex) in days" :key="dayIndex">
            <div class="vb-avail-day-row"
                 :class="daySlots.length === 0 ? 'is-closed' : ''">
                 
                <!-- Day Label -->
                <div class="vb-avail-label">
                    <label class="vb-toggle">
                        <input type="checkbox" 
                               class="vb-toggle-input"
                               :checked="daySlots.length > 0"
                               @change="$el.checked ? addWindow(dayIndex) : daySlots.splice(0, daySlots.length)">
                        <div class="vb-toggle-track"></div>
                    </label>
                    <span class="vb-avail-day-name" 
                          x-text="dayLabels[dayIndex]"></span>
                </div>

                <!-- Time Windows -->
                <div class="vb-avail-windows">
                    <template x-if="daySlots.length === 0">
                        <div class="vb-avail-closed">
                            <?= __('admin.availability.closed') ?>
                        </div>
                    </template>

                    <template x-for="(window, idx) in daySlots" :key="idx">
                        <div class="vb-avail-window">
                            <input type="time"
                                   class="vb-input"
                                   :name="'schedule[' + dayIndex + '][' + idx + '][start]'"
                                   x-model="window.start"
                                   required>
                            <span class="vb-avail-separator"><?= __('admin.availability.separator') ?></span>
                            <input type="time"
                                   class="vb-input"
                                   :name="'schedule[' + dayIndex + '][' + idx + '][end]'"
                                   x-model="window.end"
                                   required>
                            <button type="button"
                                    class="vb-avail-remove"
                                    @click="removeWindow(dayIndex, idx)"
                                    title="<?= __('admin.availability.remove_window') ?>">
                                <i data-lucide="x" class="vb-icon-md"></i>
                            </button>
                        </div>
                    </template>
                </div>

                <!-- Actions -->
                <div class="vb-avail-actions">
                    <button type="button"
                            class="vb-avail-add"
                            x-show="daySlots.length > 0"
                            @click="addWindow(dayIndex)"
                            title="<?= __('admin.availability.add_window') ?>">
                        <i data-lucide="plus" class="vb-icon-md"></i>
                    </button>
                </div>

            </div>
            </template>
        </div>
    </div>

    <div class="vb-form-actions">
        <button type="submit" class="vb-btn vb-btn-primary" id="save-availability-btn">
            <i data-lucide="save" class="vb-icon-md"></i>
            <?= __('admin.availability.save') ?>
        </button>
    </div>
</form>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
