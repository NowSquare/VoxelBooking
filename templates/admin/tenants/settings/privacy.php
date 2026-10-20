<?php
/**
 * Tenant Settings — Privacy tab.
 *
 * Structure: card-header → toggle + text fields.
 * No inline styles — all layout via design system classes.
 *
 * Variables: $tenant, $tenantId, $csrfToken, $activeTab, $flash, $old
 */
$tenant   = $tenant ?? [];
$tenantId = $tenantId ?? '';

$checked = fn(string $field) => ((int) ($tenant[$field] ?? 0)) === 1 ? 'checked' : '';

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

<form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/settings/privacy" class="vb-animate-in">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Section 1: Consent -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="shield-check" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.section_consent') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.section_consent_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid">
                <div class="vb-settings-toggles">
                    <label class="vb-settings-toggle-item">
                        <input type="hidden" name="requires_consent" value="0">
                        <input type="checkbox" name="requires_consent" value="1" <?= $checked('requires_consent') ?>>
                        <div>
                            <div class="vb-settings-toggle-label"><?= __('admin.tenant_settings.field_requires_consent') ?></div>
                        </div>
                    </label>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-privacy-url"><?= __('admin.tenant_settings.field_privacy_url') ?></label>
                    <input type="url" class="vb-input" id="ts-privacy-url" name="privacy_policy_url"
                           value="<?= e($tenant['privacy_policy_url'] ?? '') ?>" maxlength="500"
                           placeholder="https://...">
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-consent-text"><?= __('admin.tenant_settings.field_consent_text') ?></label>
                    <input type="text" class="vb-input" id="ts-consent-text" name="consent_text"
                           value="<?= e($tenant['consent_text'] ?? '') ?>" maxlength="500">
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_consent_hint') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Data Retention -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="archive" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.section_data_retention') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.section_data_retention_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid">
                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-retention"><?= __('admin.tenant_settings.field_data_retention') ?></label>
                    <input type="number" class="vb-input vb-input-narrow" id="ts-retention" name="data_retention_months"
                           value="<?= e((string) ($tenant['data_retention_months'] ?? '24')) ?>"
                           min="1" step="1">
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_data_retention_hint') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="vb-form-actions">
        <button type="submit" class="vb-btn vb-btn-primary" id="save-privacy-btn">
            <i data-lucide="save"></i>
            <?= __('admin.settings.save_button') ?>
        </button>
    </div>
</form>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
