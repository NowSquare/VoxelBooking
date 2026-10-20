<?php
/**
 * Tenant Settings — Booking Rules tab.
 *
 * Scheduling section: timeslot-only (slot duration, buffer).
 * Constraints section: all patterns (min/max advance, daily limit).
 * No inline styles — all layout via design system classes.
 *
 * Variables: $tenant, $tenantId, $csrfToken, $activeTab, $flash, $old
 */
$tenant   = $tenant ?? [];
$tenantId = $tenantId ?? '';

ob_start();
?>

<?php
$pageHeader = [
    'title' => __('admin.tenant_settings.title'),
    'subtitle' => $tenant['name'] ?? '',
];
include __DIR__ . '/../../partials/page-header.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/settings/booking" class="vb-animate-in">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <?php if (($tenant['booking_pattern'] ?? '') === 'timeslot'): ?>
    <!-- Section 1: Scheduling (timeslot only) -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="clock" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.section_scheduling') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.section_scheduling_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid vb-form-grid-2">
                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-slot-duration"><?= __('admin.tenant_settings.field_slot_duration') ?></label>
                    <input type="number" class="vb-input vb-input-narrow" id="ts-slot-duration" name="slot_duration_minutes"
                           value="<?= e((string) ($tenant['slot_duration_minutes'] ?? '30')) ?>"
                           min="5" step="5">
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_slot_duration_hint') ?></span>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-buffer"><?= __('admin.tenant_settings.field_buffer') ?></label>
                    <input type="number" class="vb-input vb-input-narrow" id="ts-buffer" name="buffer_minutes"
                           value="<?= e((string) ($tenant['buffer_minutes'] ?? '0')) ?>"
                           min="0" step="5">
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_buffer_hint') ?></span>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Section 2: Constraints -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="lock" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.section_constraints') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.section_constraints_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid vb-form-grid-2">
                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-min-advance"><?= __('admin.tenant_settings.field_min_advance') ?></label>
                    <input type="number" class="vb-input vb-input-narrow" id="ts-min-advance" name="min_advance_hours"
                           value="<?= e((string) ($tenant['min_advance_hours'] ?? '1')) ?>"
                           min="0" step="1">
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_min_advance_hint') ?></span>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-max-advance"><?= __('admin.tenant_settings.field_max_advance') ?></label>
                    <input type="number" class="vb-input vb-input-narrow" id="ts-max-advance" name="max_advance_days"
                           value="<?= e((string) ($tenant['max_advance_days'] ?? '90')) ?>"
                           min="1" step="1">
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_max_advance_hint') ?></span>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-max-bookings-day"><?= __('admin.tenant_settings.field_max_bookings_per_day') ?></label>
                    <input type="number" class="vb-input vb-input-narrow" id="ts-max-bookings-day" name="max_bookings_per_customer_per_day"
                           value="<?= e((string) ($tenant['max_bookings_per_customer_per_day'] ?? '3')) ?>"
                           min="0" step="1">
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_max_bookings_per_day_hint') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="vb-form-actions">
        <button type="submit" class="vb-btn vb-btn-primary" id="save-booking-btn">
            <i data-lucide="save"></i>
            <?= __('admin.settings.save_button') ?>
        </button>
    </div>
</form>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
