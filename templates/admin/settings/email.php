<?php
/**
 * Email Settings — mail transport configuration.
 *
 * Variables: $user, $version, $csrfToken, $settings, $flash, $pageTitle
 */
$activePage = 'settings.email';
$activeTab = 'email';

ob_start();

include dirname(__DIR__, 2) . '/partials/settings-tabs.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../partials/alert.php'; ?>
<?php endif; ?>

<form method="POST" action="/admin/settings/email">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <div class="vb-grid vb-grid-2">
        <!-- Email Delivery Configuration -->
        <div class="vb-card vb-fade-in-up stagger-1">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="mail" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.email.smtp_title') ?></div>
                        <div class="vb-card-desc"><?= __('admin.email.smtp_desc') ?></div>
                    </div>
                </div>
            </div>

            <?php
            // A missing setting reads as an empty string, so fall back to SMTP
            // the same way the select does when nothing is stored yet.
            $transport = ($settings['mail_transport'] ?? '') !== '' ? $settings['mail_transport'] : 'smtp';
            ?>

            <div class="vb-form-group">
                <label for="mail_transport" class="vb-label"><?= __('admin.email.transport_label') ?></label>
                <select id="mail_transport" name="mail_transport" class="vb-select">
                    <option value="smtp" <?= $transport === 'smtp' ? 'selected' : '' ?>><?= __('admin.email.transport_smtp') ?></option>
                    <option value="resend" <?= $transport === 'resend' ? 'selected' : '' ?>><?= __('admin.email.transport_resend') ?></option>
                    <option value="mailpit" <?= $transport === 'mailpit' ? 'selected' : '' ?>><?= __('admin.email.transport_mailpit') ?></option>
                    <option value="log" <?= $transport === 'log' ? 'selected' : '' ?>><?= __('admin.email.transport_log') ?></option>
                </select>
            </div>

            <!-- SMTP fields (shown for transport=smtp) -->
            <fieldset id="smtp-config-fields" class="vb-fieldset" data-transport="smtp" <?= $transport === 'smtp' ? '' : 'disabled' ?>>
                <div class="vb-form-row">
                    <div class="vb-form-group">
                        <label for="smtp_host" class="vb-label"><?= __('admin.email.host_label') ?></label>
                        <input type="text" id="smtp_host" name="smtp_host" class="vb-input" value="<?= htmlspecialchars($settings['smtp_host'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="smtp.example.com">
                    </div>
                    <div class="vb-form-group">
                        <label for="smtp_port" class="vb-label"><?= __('admin.email.port_label') ?></label>
                        <input type="number" id="smtp_port" name="smtp_port" class="vb-input" value="<?= htmlspecialchars($settings['smtp_port'] ?? '587', ENT_QUOTES, 'UTF-8') ?>" placeholder="587">
                    </div>
                </div>
                <div class="vb-form-group">
                    <label for="smtp_username" class="vb-label"><?= __('admin.email.username_label') ?></label>
                    <input type="text" id="smtp_username" name="smtp_username" class="vb-input" value="<?= htmlspecialchars($settings['smtp_username'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="noreply@yourdomain.com" autocomplete="off">
                </div>
                <div class="vb-form-group">
                    <label for="smtp_password" class="vb-label"><?= __('admin.email.password_label') ?></label>
                    <input type="password" id="smtp_password" name="smtp_password" class="vb-input" placeholder="<?= __('admin.email.password_hint') ?>" autocomplete="new-password">
                </div>
                <div class="vb-form-group">
                    <label for="smtp_encryption" class="vb-label"><?= __('admin.email.encryption_label') ?></label>
                    <select id="smtp_encryption" name="smtp_encryption" class="vb-select">
                        <option value="tls" <?= ($settings['smtp_encryption'] ?? 'tls') === 'tls' ? 'selected' : '' ?>><?= __('admin.email.tls_recommended') ?></option>
                        <option value="ssl" <?= ($settings['smtp_encryption'] ?? '') === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="none" <?= ($settings['smtp_encryption'] ?? '') === 'none' ? 'selected' : '' ?>>None</option>
                    </select>
                </div>
            </fieldset>

            <!-- Resend fields (shown for transport=resend) -->
            <fieldset id="resend-config-fields" class="vb-fieldset" data-transport="resend" <?= $transport === 'resend' ? '' : 'disabled' ?>>
                <div class="vb-form-group">
                    <label for="resend_api_key" class="vb-label"><?= __('admin.email.resend_api_key_label') ?></label>
                    <input type="password" id="resend_api_key" name="resend_api_key" class="vb-input" placeholder="<?= __('admin.email.resend_api_key_hint') ?>" autocomplete="new-password">
                </div>
                <div class="vb-form-hint" style="margin-top: -0.25rem; margin-bottom: 0.75rem;">
                    <?= __('admin.email.resend_help') ?>
                </div>
            </fieldset>
        </div>

        <!-- Sender Identity -->
        <div class="vb-card vb-fade-in-up stagger-2">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="user" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.email.sender_title') ?></div>
                        <div class="vb-card-desc"><?= __('admin.email.sender_desc') ?></div>
                    </div>
                </div>
            </div>

            <div class="vb-form-group">
                <label for="mail_from_name" class="vb-label"><?= __('admin.email.from_name_label') ?></label>
                <input type="text" id="mail_from_name" name="mail_from_name" class="vb-input" value="<?= htmlspecialchars($settings['mail_from_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="VoxelBooking">
            </div>
            <div class="vb-form-group">
                <label for="mail_from_address" class="vb-label"><?= __('admin.email.from_address_label') ?></label>
                <input type="email" id="mail_from_address" name="mail_from_address" class="vb-input" value="<?= htmlspecialchars($settings['mail_from_address'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="noreply@yourdomain.com">
            </div>
            <div id="resend-domain-hint" class="vb-form-hint" style="margin-top: -0.25rem; margin-bottom: 0.25rem;">
                <?= __('admin.email.resend_domain_hint') ?>
            </div>
        </div>
    </div>

    <div class="vb-form-actions vb-form-actions-spaced">
        <button type="submit" class="vb-btn vb-btn-primary">
            <i data-lucide="check"></i>
            <?= __('admin.email.save_button') ?>
        </button>
    </div>
</form>

<script>
(function() {
    var sel = document.getElementById('mail_transport');
    var smtpFields = document.getElementById('smtp-config-fields');
    var resendFields = document.getElementById('resend-config-fields');
    var resendDomainHint = document.getElementById('resend-domain-hint');
    if (!sel || !smtpFields || !resendFields) return;
    // Hidden inputs still post. Disabling the inactive transport keeps its
    // credentials out of the request body so they cannot shadow the active one.
    function apply(fields, active) {
        fields.style.display = active ? '' : 'none';
        fields.disabled = !active;
    }
    function toggle() {
        apply(smtpFields, sel.value === 'smtp');
        apply(resendFields, sel.value === 'resend');
        if (resendDomainHint) resendDomainHint.style.display = sel.value === 'resend' ? '' : 'none';
    }
    sel.addEventListener('change', toggle);
    toggle();
})();
</script>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 2) . '/admin/layout.php';
