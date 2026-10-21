<?php
/**
 * Customer card shared by every manual booking form.
 *
 * Variables: $tenant
 *
 * Field errors come from the booking operation after a failed submission,
 * keyed by input name, and render under the input they belong to.
 */
$phoneRequired = (int) ($tenant['require_phone'] ?? 0) === 1;
$customerInputs = [
    ['customer_name', 'text', 'admin.bookings.label_customer_name', true],
    ['customer_email', 'email', 'admin.bookings.label_customer_email', true],
    ['customer_phone', 'tel', 'admin.bookings.label_customer_phone', $phoneRequired],
];
?>
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="contact" class="vb-card-icon"></i>
                    <div class="vb-card-title"><?= __('admin.bookings.customer') ?></div>
                </div>
            </div>

            <div class="vb-form-grid">
                <?php foreach (array_chunk($customerInputs, 2) as $row): ?>
                <div class="vb-form-row">
                    <?php foreach ($row as [$name, $type, $label, $required]): ?>
                    <div class="vb-form-group">
                        <label for="create_<?= e($name) ?>" class="vb-label">
                            <?= __($label) ?>
                            <?php if ($required): ?><span class="vb-required">*</span><?php endif; ?>
                        </label>
                        <input type="<?= e($type) ?>" id="create_<?= e($name) ?>" name="<?= e($name) ?>" class="vb-input<?= error_class($name) ?>"
                               value="<?= e(old($name, '')) ?>"
                               <?php if ($required): ?>required<?php endif; ?>
                               <?php if (has_error($name)): ?>aria-invalid="true" aria-describedby="create_<?= e($name) ?>_error"<?php endif; ?>
                               autocomplete="off">
                        <?php if (has_error($name)): ?>
                            <div class="vb-form-error" id="create_<?= e($name) ?>_error" role="alert"><?= e(field_error($name)) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                    <?php if (count($row) === 1): ?>
                    <div class="vb-form-group">
                        <!-- Intentional: reserved for future fields -->
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
