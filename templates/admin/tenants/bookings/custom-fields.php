<?php
/**
 * The business's booking questions on a manual booking form.
 *
 * Variables: $tenant
 *
 * Staff may leave a question empty, because a phone booking often lacks the
 * answer. A question customers must answer online says so in its hint. Field
 * errors are keyed `custom_fields.<name>`, as the booking operation reports them.
 */
$customDefinitions = json_decode($tenant['custom_fields'] ?? '[]', true) ?: [];
$oldAnswers = old('custom_fields', []);
if (!is_array($oldAnswers)) {
    $oldAnswers = [];
}
?>
<?php if ($customDefinitions): ?>
<div class="vb-settings-section">
    <div class="vb-card">
        <div class="vb-card-header">
            <div class="vb-card-title"><?= __('agent-booking.custom_fields') ?></div>
        </div>
        <div class="vb-form-grid">
        <?php foreach ($customDefinitions as $customField):
            $fieldKey = $customField['name'] ?? $customField['key'] ?? '';
            if ($fieldKey === '') {
                continue;
            }
            $fieldType = $customField['type'] ?? 'text';
            $fieldValue = $oldAnswers[$fieldKey] ?? '';
            $fieldId = 'booking-custom-' . substr(hash('sha256', $fieldKey), 0, 12);
            $errorKey = 'custom_fields.' . $fieldKey;
            $hasError = has_error($errorKey);
            $describedBy = trim(
                (($customField['required'] ?? false) ? $fieldId . '-hint ' : '') . ($hasError ? $fieldId . '-error' : '')
            );
            $describedByAttribute = $describedBy !== '' ? ' aria-describedby="' . e($describedBy) . '"' : '';
            $invalidAttribute = $hasError ? ' aria-invalid="true"' : '';
        ?>
            <div class="vb-form-group">
                <label class="vb-label" for="<?= e($fieldId) ?>"><?= e($customField['label'] ?? $fieldKey) ?></label>
                <?php if ($fieldType === 'select'): ?>
                    <select id="<?= e($fieldId) ?>" name="custom_fields[<?= e($fieldKey) ?>]" class="vb-input<?= error_class($errorKey) ?>"<?= $describedByAttribute . $invalidAttribute ?>>
                        <option value=""><?= __('agent-booking.choose_option') ?></option>
                        <?php foreach ($customField['options'] ?? [] as $option):
                            $optionValue = is_array($option) ? $option['value'] : $option;
                            $optionLabel = is_array($option) ? ($option['label'] ?? $optionValue) : $option;
                        ?>
                        <option value="<?= e($optionValue) ?>" <?= (string) $fieldValue === (string) $optionValue ? 'selected' : '' ?>><?= e($optionLabel) ?></option>
                        <?php endforeach; ?>
                    </select>
                <?php elseif ($fieldType === 'textarea'): ?>
                    <textarea id="<?= e($fieldId) ?>" name="custom_fields[<?= e($fieldKey) ?>]" class="vb-input<?= error_class($errorKey) ?>" rows="3" maxlength="2000"<?= $describedByAttribute . $invalidAttribute ?>><?= e($fieldValue) ?></textarea>
                <?php else:
                    $inputType = in_array($fieldType, ['email', 'date', 'number', 'checkbox'], true) ? $fieldType : 'text';
                    $isCheckbox = $inputType === 'checkbox';
                ?>
                    <input id="<?= e($fieldId) ?>" name="custom_fields[<?= e($fieldKey) ?>]" type="<?= e($inputType) ?>"
                           class="<?= $isCheckbox ? 'vb-checkbox' : 'vb-input' ?><?= error_class($errorKey) ?>"
                           value="<?= e($isCheckbox ? '1' : $fieldValue) ?>"
                           <?= $isCheckbox && $fieldValue ? 'checked' : '' ?>
                           <?= isset($customField['min']) ? 'min="' . e($customField['min']) . '"' : '' ?>
                           <?= isset($customField['max']) ? 'max="' . e($customField['max']) . '"' : '' ?>
                           <?= $inputType === 'text' ? 'maxlength="2000"' : '' ?><?= $describedByAttribute . $invalidAttribute ?>>
                <?php endif; ?>
                <?php if ($customField['required'] ?? false): ?>
                    <p class="vb-hint" id="<?= e($fieldId) ?>-hint"><?= __('admin.bookings.custom_field_required_online') ?></p>
                <?php endif; ?>
                <?php if ($hasError): ?>
                    <div class="vb-form-error" id="<?= e($fieldId) ?>-error" role="alert"><?= e(field_error($errorKey)) ?></div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>
