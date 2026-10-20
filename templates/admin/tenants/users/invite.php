<?php
/**
 * Invite business user form.
 *
 * Uses Alpine.js CSP build — all bindings use method/property references.
 * Alpine component: inviteUser (registered in app.js)
 *
 * Variables: $tenant, $tenantId, $flash, $csrfToken
 */
$tenant = $tenant ?? [];
$tenantId = $tenantId ?? '';

ob_start();
?>
<?php
$pageHeader = [
    'title' => __('admin.users.invite_title'),
    'subtitle' => __('admin.users.invite_subtitle'),
];
include __DIR__ . '/../../partials/page-header.php';
?>

<?php if (!empty($flash)): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<div class="vb-card" x-data="inviteUser" data-smtp-configured="<?= \App\Engine\Mailer::isConfigured() ? '1' : '0' ?>" data-initial-role="<?= e(old('role', 'owner')) ?>">
    <form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/users/invite" novalidate>
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken ?? '', ENT_QUOTES, 'UTF-8') ?>">

        <!-- Name -->
        <div class="vb-form-group">
            <label for="invite_name" class="vb-label"><?= __('admin.users.invite_name') ?> <span class="vb-required">*</span></label>
            <input type="text" id="invite_name" name="name" class="vb-input<?= error_class('name') ?>"
                   placeholder="Jane Doe" required value="<?= e(old('name')) ?>">
            <?php if (has_error('name')): ?>
                <div class="vb-form-error" role="alert"><?= field_error('name') ?></div>
            <?php endif; ?>
        </div>

        <!-- Email -->
        <div class="vb-form-group">
            <label for="invite_email" class="vb-label"><?= __('admin.users.invite_email') ?> <span class="vb-required">*</span></label>
            <input type="email" id="invite_email" name="email" class="vb-input<?= error_class('email') ?>"
                   placeholder="owner@example.com" required value="<?= e(old('email')) ?>">
            <?php if (has_error('email')): ?>
                <div class="vb-form-error" role="alert"><?= field_error('email') ?></div>
            <?php endif; ?>
        </div>

        <!-- Role -->
        <div class="vb-form-group">
            <label class="vb-label"><?= __('admin.users.invite_role') ?> <span class="vb-required">*</span></label>
            <div class="vb-role-cards">
                <label class="vb-role-card" :class="roleOwnerClass">
                    <input type="radio" name="role" value="owner" :checked="isOwner" @change="selectOwner" class="vb-sr-only">
                    <i data-lucide="shield-check" class="vb-icon-lg vb-mb-0"></i>
                    <span class="vb-role-card-title"><?= __('admin.users.invite_role_owner_title') ?></span>
                    <span class="vb-role-card-desc"><?= __('admin.users.invite_role_owner_desc') ?></span>
                </label>
                <label class="vb-role-card" :class="roleManagerClass">
                    <input type="radio" name="role" value="manager" :checked="isManager" @change="selectManager" class="vb-sr-only">
                    <i data-lucide="briefcase" class="vb-icon-lg vb-mb-0"></i>
                    <span class="vb-role-card-title"><?= __('admin.users.invite_role_manager_title') ?></span>
                    <span class="vb-role-card-desc"><?= __('admin.users.invite_role_manager_desc') ?></span>
                </label>
            </div>
            <?php if (has_error('role')): ?>
                <div class="vb-form-error" role="alert"><?= field_error('role') ?></div>
            <?php endif; ?>
        </div>

        <!-- Password -->
        <div class="vb-form-group">
            <label for="invite_password" class="vb-label"><?= __('admin.users.invite_password') ?> <span class="vb-required">*</span></label>
            <div class="vb-password-field">
                <input type="password" id="invite_password" name="password" class="vb-input<?= error_class('password') ?>"
                       x-ref="passwordField" autocomplete="new-password" required minlength="8">
                <button type="button" class="vb-btn vb-btn-ghost vb-btn-sm" @click="togglePasswordVisibility"
                        title="<?= __('admin.users.invite_toggle_visibility') ?>">
                    <i data-lucide="eye" x-show="!showPassword"></i>
                    <i data-lucide="eye-off" x-show="showPassword"></i>
                </button>
                <button type="button" class="vb-btn vb-btn-ghost vb-btn-sm" @click="generatePassword"
                        title="<?= __('admin.users.invite_generate') ?>">
                    <i data-lucide="refresh-cw"></i>
                </button>
            </div>
            <?php if (has_error('password')): ?>
                <div class="vb-form-error" role="alert"><?= field_error('password') ?></div>
            <?php endif; ?>
        </div>

        <!-- Send email toggle -->
        <div class="vb-form-group">
            <div class="vb-toggle-row">
                <input type="checkbox" id="invite_send_email" name="send_email" value="1"
                       :checked="sendEmail"
                       @change="onToggleSendEmail"
                       :disabled="smtpNotConfigured"
                       class="vb-checkbox">
                <label for="invite_send_email" class="vb-toggle-label">
                    <span class="vb-label vb-mb-0">
                        <i data-lucide="mail" class="vb-icon-sm"></i>
                        <?= __('admin.users.invite_send_email') ?>
                    </span>
                    <?php if (!\App\Engine\Mailer::isConfigured()): ?>
                        <span class="vb-hint vb-hint-warning">
                            <i data-lucide="alert-triangle" class="vb-icon-xs"></i>
                            <?= __('admin.users.invite_smtp_hint') ?>
                        </span>
                    <?php endif; ?>
                </label>
            </div>
        </div>

        <div class="vb-form-actions">
            <button type="submit" class="vb-btn vb-btn-primary">
                <i data-lucide="user-plus" class="vb-icon-md"></i>
                <?= __('admin.users.invite_submit') ?>
            </button>
            <a href="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/users" class="vb-btn vb-btn-ghost">
                <?= __('admin.common.cancel') ?>
            </a>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
