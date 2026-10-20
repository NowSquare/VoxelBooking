<?php
/**
 * Tenant Settings — Booking Page tab.
 *
 * Structure: card-header → form-grid / toggle sections.
 * Toggle-detail fields use .vb-settings-toggle-detail for indentation
 * instead of inline margin-left. Number inputs use .vb-input-narrow.
 *
 * Variables: $tenant, $tenantId, $csrfToken, $activeTab, $flash
 */
$tenant   = $tenant ?? [];
$tenantId = $tenantId ?? '';

/** Check a boolean setting: old input overrides tenant default. */
$isChecked = function (string $field) use ($tenant): string {
    $default = ((int) ($tenant[$field] ?? 0)) === 1 ? '1' : '0';
    return old($field, $default) === '1' ? 'checked' : '';
};

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

<form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/settings/bookingpage" class="vb-animate-in">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Section 1: Content -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="file-text" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.bookingpage_section_content') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.bookingpage_section_content_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid">
                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-confirmation-msg"><?= __('admin.tenant_settings.field_confirmation_message') ?></label>
                    <textarea class="vb-input vb-textarea" id="ts-confirmation-msg" name="confirmation_message"
                              rows="2" placeholder="<?= __('admin.tenant_settings.field_confirmation_message_placeholder') ?>"><?= e(old('confirmation_message', $tenant['confirmation_message'] ?? '')) ?></textarea>
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_confirmation_message_hint') ?></span>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-cancel-policy"><?= __('admin.tenant_settings.field_cancellation_policy') ?></label>
                    <textarea class="vb-input vb-textarea" id="ts-cancel-policy" name="cancellation_policy"
                              rows="2" placeholder="<?= __('admin.tenant_settings.field_cancellation_policy_placeholder') ?>"><?= e(old('cancellation_policy', $tenant['cancellation_policy'] ?? '')) ?></textarea>
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_cancellation_policy_hint') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Rules & Policies -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="shield-check" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.bookingpage_section_rules') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.bookingpage_section_rules_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid">
                <!-- Intake toggles -->
                <div class="vb-settings-toggles">
                    <label class="vb-settings-toggle-item">
                        <input type="hidden" name="require_phone" value="0">
                        <input type="checkbox" name="require_phone" value="1" <?= $isChecked('require_phone') ?>>
                        <div>
                            <div class="vb-settings-toggle-label"><?= __('admin.tenant_settings.field_require_phone') ?></div>
                            <div class="vb-settings-toggle-hint"><?= __('admin.tenant_settings.field_require_phone_hint') ?></div>
                        </div>
                    </label>
                    <label class="vb-settings-toggle-item">
                        <input type="hidden" name="booking_requires_approval" value="0">
                        <input type="checkbox" name="booking_requires_approval" value="1" <?= $isChecked('booking_requires_approval') ?>>
                        <div>
                            <div class="vb-settings-toggle-label"><?= __('admin.tenant_settings.field_booking_requires_approval') ?></div>
                            <div class="vb-settings-toggle-hint"><?= __('admin.tenant_settings.field_booking_requires_approval_hint') ?></div>
                        </div>
                    </label>
                </div>

                <!-- Cancellation -->
                <div class="vb-settings-toggles">
                    <label class="vb-settings-toggle-item">
                        <input type="hidden" name="allow_cancellation" value="0">
                        <input type="checkbox" name="allow_cancellation" value="1" <?= $isChecked('allow_cancellation') ?>>
                        <div>
                            <div class="vb-settings-toggle-label"><?= __('admin.tenant_settings.field_allow_cancellation') ?></div>
                            <div class="vb-settings-toggle-hint"><?= __('admin.tenant_settings.field_allow_cancellation_hint') ?></div>
                        </div>
                    </label>
                </div>
                <div class="vb-settings-toggle-detail">
                    <label class="vb-label" for="ts-cancel-hours"><?= __('admin.tenant_settings.field_cancellation_hours_before') ?></label>
                    <input type="number" class="vb-input vb-input-narrow" id="ts-cancel-hours" name="cancellation_hours_before"
                           value="<?= e(old('cancellation_hours_before', $tenant['cancellation_hours_before'] ?? '24')) ?>"
                           min="0" step="1">
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_cancellation_hours_before_hint') ?></span>
                </div>

                <!-- Rescheduling -->
                <div class="vb-settings-toggles">
                    <label class="vb-settings-toggle-item">
                        <input type="hidden" name="allow_rescheduling" value="0">
                        <input type="checkbox" name="allow_rescheduling" value="1" <?= $isChecked('allow_rescheduling') ?>>
                        <div>
                            <div class="vb-settings-toggle-label"><?= __('admin.tenant_settings.field_allow_rescheduling') ?></div>
                            <div class="vb-settings-toggle-hint"><?= __('admin.tenant_settings.field_allow_rescheduling_hint') ?></div>
                        </div>
                    </label>
                </div>
                <div class="vb-settings-toggle-detail">
                    <label class="vb-label" for="ts-reschedule-hours"><?= __('admin.tenant_settings.field_rescheduling_hours_before') ?></label>
                    <input type="number" class="vb-input vb-input-narrow" id="ts-reschedule-hours" name="rescheduling_hours_before"
                           value="<?= e(old('rescheduling_hours_before', $tenant['rescheduling_hours_before'] ?? '24')) ?>"
                           min="0" step="1">
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_rescheduling_hours_before_hint') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="vb-form-actions">
        <button type="submit" class="vb-btn vb-btn-primary" id="save-bookingpage-btn">
            <i data-lucide="save"></i>
            <?= __('admin.settings.save_button') ?>
        </button>
    </div>
</form>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
