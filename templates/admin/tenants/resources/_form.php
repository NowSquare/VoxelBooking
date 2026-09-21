<?php
/**
 * Resource form partial — shared between create and edit.
 *
 * Variables: $tenant, $tenantId, $csrfToken, $resource (null for create), $old, $seasonalPricing
 */
$resource = $resource ?? null;
$seasonalPricing = $seasonalPricing ?? [];
$isEdit = $resource !== null;

$name        = e(old('name', $resource['name'] ?? ''));
$description = e(old('description', $resource['description'] ?? ''));
$capacity    = e(old('capacity', $resource['capacity'] ?? '2'));
$price       = e(old('price_per_night', $resource['price_per_night'] ?? ''));
$minStay     = e(old('min_stay_nights', $resource['min_stay_nights'] ?? '1'));
$maxStay     = e(old('max_stay_nights', $resource['max_stay_nights'] ?? '30'));

$amenitiesStr = e(old('amenities', is_array($resource['amenities'] ?? null) ? implode(', ', $resource['amenities']) : ''));

// Day-of-week restrictions: decode JSON array from DB, or empty
$checkInDaysRaw = $resource['check_in_days'] ?? null;
$checkInDaysArray = [];
if ($checkInDaysRaw !== null && $checkInDaysRaw !== '') {
    $decoded = is_string($checkInDaysRaw) ? json_decode($checkInDaysRaw, true) : $checkInDaysRaw;
    if (is_array($decoded)) $checkInDaysArray = $decoded;
}
$checkOutDaysRaw = $resource['check_out_days'] ?? null;
$checkOutDaysArray = [];
if ($checkOutDaysRaw !== null && $checkOutDaysRaw !== '') {
    $decoded = is_string($checkOutDaysRaw) ? json_decode($checkOutDaysRaw, true) : $checkOutDaysRaw;
    if (is_array($decoded)) $checkOutDaysArray = $decoded;
}
$dayLabels = [
    0 => __('admin.resources.day_sun'),
    1 => __('admin.resources.day_mon'),
    2 => __('admin.resources.day_tue'),
    3 => __('admin.resources.day_wed'),
    4 => __('admin.resources.day_thu'),
    5 => __('admin.resources.day_fri'),
    6 => __('admin.resources.day_sat'),
];
?>

    <div class="vb-form-group col-span-full">
        <label for="resource-name" class="vb-label"><?= __('admin.resources.field_name') ?> <span class="vb-required">*</span></label>
        <input type="text" name="name" id="resource-name" value="<?= $name ?>"
               class="vb-input" required placeholder="<?= __('admin.resources.field_name_placeholder') ?>">
    </div>

    <div class="vb-form-group col-span-full">
        <label for="resource-description" class="vb-label"><?= __('admin.resources.field_description') ?></label>
        <textarea name="description" id="resource-description" class="vb-input resize-y" rows="3"
                  placeholder="<?= __('admin.resources.field_description_placeholder') ?>"><?= $description ?></textarea>
    </div>

    <?php
        // Cover image upload
        $uploadFieldName   = 'cover_image';
        $uploadFieldId     = 'resource_cover_image';
        $uploadLabel       = __('admin.resources.label_cover_image');
        $uploadHint        = __('admin.resources.cover_image_hint');
        $uploadCurrentPath = $resource['cover_image_path'] ?? null;
        $uploadShape       = 'rect';
        include __DIR__ . '/../../../partials/upload-field.php';
    ?>

    <div class="vb-form-row">
        <div class="vb-form-group">
            <label for="resource-capacity" class="vb-label"><?= __('admin.resources.field_capacity') ?></label>
            <input type="number" name="capacity" id="resource-capacity" value="<?= $capacity ?>"
                   class="vb-input" min="1" max="50">
        </div>
        <div class="vb-form-group">
            <label for="resource-price" class="vb-label"><?= __('admin.resources.field_price') ?></label>
            <input type="number" name="price_per_night" id="resource-price" value="<?= $price ?>"
                   class="vb-input" min="0" step="0.01" placeholder="0.00">
        </div>
    </div>

    <div class="vb-form-row">
        <div class="vb-form-group">
            <label for="resource-min-stay" class="vb-label"><?= __('admin.resources.field_min_stay') ?></label>
            <input type="number" name="min_stay_nights" id="resource-min-stay" value="<?= $minStay ?>"
                   class="vb-input" min="1" max="365">
        </div>
        <div class="vb-form-group">
            <label for="resource-max-stay" class="vb-label"><?= __('admin.resources.field_max_stay') ?></label>
            <input type="number" name="max_stay_nights" id="resource-max-stay" value="<?= $maxStay ?>"
                   class="vb-input" min="1" max="365">
        </div>
    </div>

    <div class="vb-form-row">
        <div class="vb-form-group">
            <label class="vb-label"><?= __('admin.resources.field_check_in_days') ?></label>
            <p class="vb-hint mb-2"><?= __('admin.resources.field_check_in_days_hint') ?></p>
            <div class="vb-day-checkboxes">
                <?php foreach ($dayLabels as $dow => $label): ?>
                <label class="vb-day-checkbox">
                    <input type="checkbox" name="check_in_days[]" value="<?= $dow ?>"
                        <?= in_array($dow, $checkInDaysArray, false) ? 'checked' : '' ?>>
                    <span><?= $label ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="vb-form-group">
            <label class="vb-label"><?= __('admin.resources.field_check_out_days') ?></label>
            <p class="vb-hint mb-2"><?= __('admin.resources.field_check_out_days_hint') ?></p>
            <div class="vb-day-checkboxes">
                <?php foreach ($dayLabels as $dow => $label): ?>
                <label class="vb-day-checkbox">
                    <input type="checkbox" name="check_out_days[]" value="<?= $dow ?>"
                        <?= in_array($dow, $checkOutDaysArray, false) ? 'checked' : '' ?>>
                    <span><?= $label ?></span>
                </label>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="vb-form-group">
        <label for="resource-amenities" class="vb-label"><?= __('admin.resources.field_amenities') ?></label>
        <input type="text" name="amenities" id="resource-amenities" value="<?= $amenitiesStr ?>"
               class="vb-input" placeholder="<?= __('admin.resources.field_amenities_placeholder') ?>">
        <p class="vb-hint"><?= __('admin.resources.field_amenities_hint') ?></p>
    </div>

<?php if ($isEdit): ?>
<div class="vb-section-divider"></div>
    <label class="vb-label vb-icon-label mb-1">
        <i data-lucide="sun"></i>
        <?= __('admin.resources.seasonal_title') ?>
    </label>

    <div id="seasonal-pricing-rows">
        <?php if (empty($seasonalPricing)): ?>
            <p class="vb-text-secondary" id="seasonal-empty"><?= __('admin.resources.seasonal_empty') ?></p>
        <?php endif; ?>
        <?php foreach ($seasonalPricing as $sp): ?>
        <div class="vb-form-row vb-seasonal-row">
            <div class="vb-form-group">
                <label class="vb-label"><?= __('admin.resources.seasonal_start') ?></label>
                <input type="date" name="seasonal_start[]" value="<?= htmlspecialchars($sp['start_date'], ENT_QUOTES, 'UTF-8') ?>" class="vb-input">
            </div>
            <div class="vb-form-group">
                <label class="vb-label"><?= __('admin.resources.seasonal_end') ?></label>
                <input type="date" name="seasonal_end[]" value="<?= htmlspecialchars($sp['end_date'], ENT_QUOTES, 'UTF-8') ?>" class="vb-input">
            </div>
            <div class="vb-form-group">
                <label class="vb-label"><?= __('admin.resources.seasonal_price') ?></label>
                <input type="number" name="seasonal_price[]" value="<?= htmlspecialchars((string) $sp['price_per_night'], ENT_QUOTES, 'UTF-8') ?>" class="vb-input" min="0" step="0.01">
            </div>
            <div class="vb-form-group">
                <label class="vb-label"><?= __('admin.resources.seasonal_label') ?></label>
                <input type="text" name="seasonal_label[]" value="<?= htmlspecialchars($sp['label'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="vb-input" placeholder="<?= __('admin.resources.seasonal_label_hint') ?>">
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="mt-4">
        <button type="button" class="vb-btn vb-btn-ghost vb-btn-sm" id="add-seasonal-btn">
            <i data-lucide="plus" class="w-4 h-4"></i>
            <?= __('admin.resources.seasonal_add') ?>
        </button>
    </div>
<?php endif; ?>
