<?php
/**
 * Service form partial — shared between create and edit.
 *
 * Variables expected:
 *   $service        — existing record (null for create)
 *   $staff          — active staff list
 *   $linkedStaffIds — currently linked staff IDs (edit only)
 *   $formAction     — POST target URL
 *   $tenantId
 *   $csrfToken
 *
 * Old input and field errors are read from session via helpers:
 *   old(), has_error(), field_error(), error_class()
 *
 * Form hierarchy:
 *   1. Identity — name, description
 *   2. Pricing & scheduling — duration, price, price label, category
 *   3. Appearance — color picker
 *   4. Media — cover image (full-width section)
 *   5. Staff — assignment checkboxes
 */
$service = $service ?? null;
$linkedStaffIds = $linkedStaffIds ?? [];
$currentStaffIds = old('staff_ids', $linkedStaffIds);

?>

<form method="POST" action="<?= htmlspecialchars($formAction, ENT_QUOTES, 'UTF-8') ?>" class="vb-animate-in" enctype="multipart/form-data" novalidate>
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <input type="hidden" name="_record_version" value="<?= e($recordVersion ?? '') ?>">

    <div class="vb-card p-6">

        <!-- ── 1. Identity ── -->
        <div class="vb-form-group">
            <label class="vb-label" for="svc-name"><?= __('admin.services.field_name') ?> <span class="vb-required">*</span></label>
            <input type="text" class="vb-input" id="svc-name" name="name"
                   value="<?= e(old('name')) ?>" required maxlength="255" autofocus>
        </div>

        <div class="vb-form-group">
            <label class="vb-label" for="svc-desc"><?= __('admin.services.field_description') ?></label>
            <textarea class="vb-input vb-textarea" id="svc-desc" name="description" rows="2"><?= e(old('description')) ?></textarea>
        </div>

        <!-- ── 2. Pricing & Scheduling ── -->
        <div class="vb-form-row pt-4">
            <div class="vb-form-group">
                <label class="vb-label" for="svc-duration"><?= __('admin.services.field_duration') ?> <span class="vb-required">*</span></label>
                <input type="number" class="vb-input" id="svc-duration" name="duration_minutes"
                       value="<?= e(old('duration_minutes', '30')) ?>" required min="1" step="1">
            </div>

            <div class="vb-form-group">
                <label class="vb-label" for="svc-price"><?= __('admin.services.field_price') ?></label>
                <input type="number" class="vb-input" id="svc-price" name="price"
                       value="<?= e(old('price')) ?>" min="0" step="0.01">
            </div>
        </div>

        <div class="vb-form-row">
            <div class="vb-form-group">
                <label class="vb-label" for="svc-price-label">
                    <?= __('admin.services.field_price_label') ?>
                    <span class="vb-label-hint"><?= __('admin.services.field_price_label_hint') ?></span>
                </label>
                <input type="text" class="vb-input" id="svc-price-label" name="price_label"
                       value="<?= e(old('price_label')) ?>" maxlength="100">
            </div>

            <div class="vb-form-group">
                <label class="vb-label" for="svc-category">
                    <?= __('admin.services.field_category') ?>
                    <span class="vb-label-hint"><?= __('admin.services.field_category_hint') ?></span>
                </label>
                <input type="text" class="vb-input" id="svc-category" name="category"
                       value="<?= e(old('category')) ?>" maxlength="100">
            </div>
        </div>

        <!-- ── 3. Appearance — compact controls only ── -->
        <div class="vb-form-row">
            <div class="vb-form-group" x-data="colorSync">
                <label class="vb-label vb-icon-label" for="svc-color-text">
                    <i data-lucide="palette"></i>
                    <?= __('admin.services.field_color') ?>
                </label>
                <div class="vb-color-field">
                    <input type="color" x-ref="colorPicker" value="<?= e(old('color', '#6366F1')) ?>" class="vb-color-input"
                           @input="onPickerChange">
                    <input type="text" x-ref="colorText" id="svc-color-text" name="color" value="<?= e(old('color', '#6366F1')) ?>" class="vb-input"
                           maxlength="7" placeholder="#6366F1" @input="onTextChange">
                </div>
            </div>
        </div>

        <!-- ── 4. Media — cover image (own full-width surface) ── -->
        <div class="vb-section-divider"></div>

        <?php
        $uploadFieldName   = 'cover_image';
        $uploadFieldId     = 'service_cover';
        $uploadLabel       = __('admin.services.label_cover_image');
        $uploadHint        = __('admin.services.cover_image_hint');
        $uploadCurrentPath = $service['cover_image_path'] ?? null;
        $uploadShape       = 'rect';
        include __DIR__ . '/../../../partials/upload-field.php';
        ?>

        <!-- ── 5. Staff assignment ── -->
        <?php if (!empty($staff)): ?>
        <div class="vb-section-divider"></div>
        <div class="vb-form-group">
            <label class="vb-label vb-icon-label mb-1">
                <i data-lucide="users"></i>
                <?= __('admin.services.field_staff') ?>
            </label>
            <p class="vb-text-secondary text-sm mb-4"><?= __('admin.services.field_staff_hint') ?></p>
            <div class="vb-checkbox-grid">
                <?php foreach ($staff as $s): ?>
                <label class="vb-checkbox-label">
                    <input type="checkbox" name="staff_ids[]"
                           value="<?= htmlspecialchars($s['id'], ENT_QUOTES, 'UTF-8') ?>"
                           <?= in_array($s['id'], $currentStaffIds, true) ? 'checked' : '' ?>>
                    <span class="vb-checkbox-text">
                        <?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if ($s['title']): ?>
                            <span class="vb-checkbox-meta"><?= htmlspecialchars($s['title'], ENT_QUOTES, 'UTF-8') ?></span>
                        <?php endif; ?>
                    </span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="vb-form-actions mt-8">
            <button type="submit" class="vb-btn vb-btn-primary" id="save-service-btn">
                <i data-lucide="save" class="w-4 h-4"></i>
                <?= $service ? __('admin.common.save') : __('admin.common.create') ?>
            </button>
            <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/services"
               class="vb-btn vb-btn-ghost"><?= __('admin.common.cancel') ?></a>
        </div>
    </div>
</form>
