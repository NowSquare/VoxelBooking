<?php
/**
 * Tenant Settings — Notifications tab.
 *
 * Structure: card-header → toggle group + detail field.
 * No inline styles — all layout via design system classes.
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

<form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/settings/notifications" class="vb-animate-in">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="expected_version" value="<?= htmlspecialchars(\App\Application\Settings\SettingsService::projection($tenant, 'notifications')['version'], ENT_QUOTES, 'UTF-8') ?>">

    <!-- Section 1: Delivery -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="bell" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.section_delivery') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.section_delivery_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid">
                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-notif-email"><?= __('admin.tenant_settings.field_notif_email') ?></label>
                    <input type="email" class="vb-input" id="ts-notif-email" name="notification_email"
                           value="<?= e(old('notification_email', $tenant['notification_email'] ?? '')) ?>"
                           placeholder="<?= e($tenant['email'] ?? '') ?>">
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_notif_email_hint') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Triggers -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="zap" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.section_triggers') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.section_triggers_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid">
                <div class="vb-settings-toggles">
                    <label class="vb-settings-toggle-item">
                        <input type="hidden" name="notify_on_booking" value="0">
                        <input type="checkbox" name="notify_on_booking" value="1" <?= $isChecked('notify_on_booking') ?>>
                        <div>
                            <div class="vb-settings-toggle-label"><?= __('admin.tenant_settings.field_notify_booking') ?></div>
                        </div>
                    </label>
                    <label class="vb-settings-toggle-item">
                        <input type="hidden" name="notify_on_cancellation" value="0">
                        <input type="checkbox" name="notify_on_cancellation" value="1" <?= $isChecked('notify_on_cancellation') ?>>
                        <div>
                            <div class="vb-settings-toggle-label"><?= __('admin.tenant_settings.field_notify_cancel') ?></div>
                        </div>
                    </label>
                    <label class="vb-settings-toggle-item">
                        <input type="hidden" name="send_reminders" value="0">
                        <input type="checkbox" name="send_reminders" value="1" <?= $isChecked('send_reminders') ?>>
                        <div>
                            <div class="vb-settings-toggle-label"><?= __('admin.tenant_settings.field_send_reminders') ?></div>
                        </div>
                    </label>
                </div>

                <div class="vb-settings-toggle-detail">
                    <label class="vb-label" for="ts-reminder-hrs"><?= __('admin.tenant_settings.field_reminder_hours') ?></label>
                    <input type="number" class="vb-input vb-input-narrow" id="ts-reminder-hrs" name="reminder_hours_before"
                           value="<?= e(old('reminder_hours_before', $tenant['reminder_hours_before'] ?? '24')) ?>"
                           min="1" step="1">
                </div>
            </div>
        </div>
    </div>

    <div class="vb-form-actions">
        <button type="submit" class="vb-btn vb-btn-primary" id="save-notifications-btn">
            <i data-lucide="save"></i>
            <?= __('admin.settings.save_button') ?>
        </button>
    </div>
</form>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
