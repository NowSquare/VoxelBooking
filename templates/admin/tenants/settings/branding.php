<?php
/**
 * Tenant Settings — Branding tab.
 *
 * Uses the shared `colorSync` Alpine component (x-data="colorSync")
 * with `.vb-color-field` for bidirectional picker ↔ hex sync.
 * No inline styles — all layout via design system classes.
 *
 * Variables: $tenant, $tenantId, $csrfToken, $activeTab, $flash
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

<form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/settings/branding" class="vb-animate-in" enctype="multipart/form-data">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Section 1: Colors -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="palette" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.section_colors') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.section_colors_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid">
                <div class="vb-settings-field" x-data="colorSync">
                    <label class="vb-label vb-icon-label" for="ts-brand-color-text">
                        <i data-lucide="palette"></i>
                        <?= __('admin.tenant_settings.field_brand_color') ?>
                    </label>
                    <div class="vb-color-field">
                        <input type="color" x-ref="colorPicker"
                               value="<?= e(old('brand_color', $tenant['brand_color'] ?? '#2563EB')) ?>"
                               class="vb-color-input"
                               @input="onPickerChange">
                        <input type="text" x-ref="colorText" id="ts-brand-color-text"
                               name="brand_color"
                               value="<?= e(old('brand_color', $tenant['brand_color'] ?? '#2563EB')) ?>"
                               class="vb-input"
                               maxlength="7" placeholder="#2563EB"
                               @input="onTextChange">
                    </div>
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_brand_color_hint') ?></span>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 1b: Logo -->
    <div class="vb-settings-section">
        <div class="vb-card p-6">
            <?php
            $uploadFieldName   = 'logo';
            $uploadFieldId     = 'tenant_logo';
            $uploadLabel       = __('admin.tenant_settings.field_logo');
            $uploadHint        = __('admin.tenant_settings.field_logo_hint');
            $uploadCurrentPath = $tenant['logo_path'] ?? null;
            $uploadShape       = 'rect';
            include __DIR__ . '/../../../partials/upload-field.php';
            ?>
        </div>
    </div>

    <!-- Section 1c: Powered-by -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="badge-check" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.section_powered_by') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.section_powered_by_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid">
                <div class="vb-settings-field">
                    <div class="vb-toggle-row">
                        <label class="vb-toggle" for="ts-show-powered-by">
                            <input type="hidden" name="show_powered_by" value="0">
                            <input type="checkbox" name="show_powered_by" id="ts-show-powered-by" value="1"
                                   <?= (int) ($tenant['show_powered_by'] ?? 1) ? 'checked' : '' ?>>
                            <span class="vb-toggle-slider"></span>
                        </label>
                        <div class="vb-toggle-label-group">
                            <span class="vb-label"><?= __('admin.tenant_settings.field_show_powered_by') ?></span>
                            <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_show_powered_by_hint') ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Section 2: Booking Page Content -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="file-text" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.section_page_content') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.section_page_content_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-form-grid">
                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-heading"><?= __('admin.tenant_settings.field_heading') ?></label>
                    <input type="text" class="vb-input" id="ts-heading" name="booking_page_heading"
                           value="<?= e(old('booking_page_heading', $tenant['booking_page_heading'] ?? '')) ?>" maxlength="255">
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_heading_hint') ?></span>
                </div>

                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-desc"><?= __('admin.tenant_settings.field_description') ?></label>
                    <textarea class="vb-input vb-textarea" id="ts-desc" name="booking_page_description"
                              rows="2"><?= e(old('booking_page_description', $tenant['booking_page_description'] ?? '')) ?></textarea>
                    <span class="vb-settings-hint"><?= __('admin.tenant_settings.field_description_hint') ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="vb-form-actions">
        <button type="submit" class="vb-btn vb-btn-primary" id="save-branding-btn">
            <i data-lucide="save"></i>
            <?= __('admin.settings.save_button') ?>
        </button>
    </div>
</form>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
