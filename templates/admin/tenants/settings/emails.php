<?php
/**
 * Tenant Settings — Email Templates tab.
 *
 * Per PRD §VII — tenant-scoped transactional email customization.
 * Tenant customizes subject, heading, intro, outro, CTA label per email type.
 * When a row doesn't exist, system defaults are shown as placeholders.
 *
 * No inline styles — all layout via design system classes.
 *
 * Variables: $tenant, $tenantId, $csrfToken, $activeTab, $flash, $old, $templates
 */
$tenant    = $tenant ?? [];
$tenantId  = $tenantId ?? '';
$templates = $templates ?? [];
// Index templates by type for easy lookup
$byType = [];
foreach ($templates as $tpl) {
    $byType[$tpl['type']] = $tpl;
}

// Define the 6 customer-facing email types exposed in the editor (PRD §VII).
// staff_notification is operational (not tenant-customizable).
// Placeholders mirror the system defaults in lang/*/email.php (what is really
// sent when a field is left empty); tenant tokens are injected in place of the
// :param markers so they read as {service_name}, {business_name}, etc.
$emailTypes = [
    'confirmation' => [
        'label'   => __('admin.tenant_settings.email_type_confirmation_label'),
        'desc'    => __('admin.tenant_settings.email_type_confirmation_desc'),
        'defaults' => [
            'subject'    => __('email.booking_confirmation.subject', ['service' => '{service_name}', 'date' => '{booking_date}']),
            'heading'    => __('email.booking_confirmation.heading'),
            'body_intro' => __('email.booking_confirmation.body'),
            'body_outro' => __('email.booking_confirmation.footer'),
            'cta_label'  => __('email.common.manage_booking'),
        ],
    ],
    'reminder' => [
        'label'   => __('admin.tenant_settings.email_type_reminder_label'),
        'desc'    => __('admin.tenant_settings.email_type_reminder_desc'),
        'defaults' => [
            'subject'    => __('email.booking_reminder.subject', ['service' => '{service_name}', 'time' => '{booking_time}']),
            'heading'    => __('email.booking_reminder.heading'),
            'body_intro' => __('email.booking_reminder.body'),
            'body_outro' => __('email.booking_reminder.footer'),
            'cta_label'  => __('email.common.manage_booking'),
        ],
    ],
    'cancellation' => [
        'label'   => __('admin.tenant_settings.email_type_cancellation_label'),
        'desc'    => __('admin.tenant_settings.email_type_cancellation_desc'),
        'defaults' => [
            'subject'    => __('email.cancellation.subject', ['business' => '{business_name}']),
            'heading'    => __('email.cancellation.heading'),
            'body_intro' => __('email.cancellation.body'),
            'body_outro' => __('email.cancellation.footer'),
            'cta_label'  => __('email.cancellation.book_again'),
        ],
    ],
    'reschedule_confirmation' => [
        'label'   => __('admin.tenant_settings.email_type_reschedule_confirmation_label'),
        'desc'    => __('admin.tenant_settings.email_type_reschedule_confirmation_desc'),
        'defaults' => [
            'subject'    => __('email.reschedule_confirmation.subject', ['business' => '{business_name}']),
            'heading'    => __('email.reschedule_confirmation.heading'),
            'body_intro' => __('email.reschedule_confirmation.body'),
            'body_outro' => __('email.reschedule_confirmation.footer'),
            'cta_label'  => __('email.common.manage_booking'),
        ],
    ],
    'approval_request' => [
        'label'   => __('admin.tenant_settings.email_type_approval_request_label'),
        'desc'    => __('admin.tenant_settings.email_type_approval_request_desc'),
        'defaults' => [
            'subject'    => __('email.approval_request.subject', ['business' => '{business_name}']),
            'heading'    => __('email.approval_request.heading'),
            'body_intro' => __('email.approval_request.body'),
            'body_outro' => __('email.approval_request.footer'),
            'cta_label'  => __('email.common.manage_booking'),
        ],
    ],
    'approval_confirmed' => [
        'label'   => __('admin.tenant_settings.email_type_approval_confirmed_label'),
        'desc'    => __('admin.tenant_settings.email_type_approval_confirmed_desc'),
        'defaults' => [
            'subject'    => __('email.approval_confirmed.subject', ['business' => '{business_name}']),
            'heading'    => __('email.approval_confirmed.heading'),
            'body_intro' => __('email.approval_confirmed.body'),
            'body_outro' => __('email.approval_confirmed.footer'),
            'cta_label'  => __('email.common.manage_booking'),
        ],
    ],
];

$getVal = function (string $type, string $field) use ($byType): string {
    $key = "{$type}_{$field}";
    $fallback = (string) ($byType[$type][$field] ?? '');
    return e(old($key, $fallback));
};

$isTypeEnabled = function (string $type) use ($byType): bool {
    $key = "{$type}_is_enabled";
    $fallback = isset($byType[$type]) ? ((int) ($byType[$type]['is_enabled'] ?? 1) === 1 ? '1' : '0') : '1';
    return old($key, $fallback) === '1';
};

ob_start();
?>

<div class="vb-page-header">
    <div>
        <h2 class="vb-page-title"><?= __('admin.tenant_settings.title') ?></h2>
        <p class="vb-page-subtitle"><?= e($tenant['name'] ?? '') ?></p>
    </div>
</div>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<?php include __DIR__ . '/_tabs.php'; ?>

<form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/settings/emails" class="vb-animate-in">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="vb-settings-info">
        <i data-lucide="info"></i>
        <div>
            <?= __('admin.tenant_settings.emails_section_desc') ?>
            <div class="vb-settings-info-subtle"><?= __('admin.tenant_settings.emails_placeholders_hint') ?></div>
        </div>
    </div>

    <?php foreach ($emailTypes as $type => $meta): ?>
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header vb-card-header-toggle">
                <div class="vb-card-title-row">
                    <i data-lucide="mail" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="vb-card-desc"><?= htmlspecialchars($meta['desc'], ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
                <label class="vb-settings-toggle-item vb-settings-toggle-inline">
                    <input type="hidden" name="<?= $type ?>_is_enabled" value="0">
                    <input type="checkbox" name="<?= $type ?>_is_enabled" value="1" <?= $isTypeEnabled($type) ? 'checked' : '' ?>>
                </label>
            </div>
            <div class="vb-form-grid">
                <div class="vb-settings-field">
                    <label class="vb-label" for="tpl-<?= $type ?>-subject"><?= __('admin.tenant_settings.email_field_subject') ?></label>
                    <input type="text" class="vb-input" id="tpl-<?= $type ?>-subject" name="<?= $type ?>_subject"
                           value="<?= $getVal($type, 'subject') ?>"
                           placeholder="<?= htmlspecialchars($meta['defaults']['subject'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="vb-settings-field">
                    <label class="vb-label" for="tpl-<?= $type ?>-heading"><?= __('admin.tenant_settings.email_field_heading') ?></label>
                    <input type="text" class="vb-input" id="tpl-<?= $type ?>-heading" name="<?= $type ?>_heading"
                           value="<?= $getVal($type, 'heading') ?>"
                           placeholder="<?= htmlspecialchars($meta['defaults']['heading'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="vb-settings-field">
                    <label class="vb-label" for="tpl-<?= $type ?>-intro"><?= __('admin.tenant_settings.email_field_intro') ?></label>
                    <textarea class="vb-input vb-textarea" id="tpl-<?= $type ?>-intro" name="<?= $type ?>_body_intro"
                              rows="2" placeholder="<?= htmlspecialchars($meta['defaults']['body_intro'], ENT_QUOTES, 'UTF-8') ?>"><?= $getVal($type, 'body_intro') ?></textarea>
                </div>
                <div class="vb-settings-field">
                    <label class="vb-label" for="tpl-<?= $type ?>-outro"><?= __('admin.tenant_settings.email_field_outro') ?></label>
                    <textarea class="vb-input vb-textarea" id="tpl-<?= $type ?>-outro" name="<?= $type ?>_body_outro"
                              rows="2" placeholder="<?= htmlspecialchars($meta['defaults']['body_outro'], ENT_QUOTES, 'UTF-8') ?>"><?= $getVal($type, 'body_outro') ?></textarea>
                </div>
                <div class="vb-settings-field">
                    <label class="vb-label" for="tpl-<?= $type ?>-cta"><?= __('admin.tenant_settings.email_field_cta') ?></label>
                    <input type="text" class="vb-input vb-input-medium" id="tpl-<?= $type ?>-cta" name="<?= $type ?>_cta_label"
                           value="<?= $getVal($type, 'cta_label') ?>"
                           placeholder="<?= htmlspecialchars($meta['defaults']['cta_label'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>

    <div class="vb-form-actions">
        <button type="submit" class="vb-btn vb-btn-primary" id="save-emails-btn">
            <i data-lucide="save"></i>
            <?= __('admin.settings.save_button') ?>
        </button>
    </div>
</form>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
