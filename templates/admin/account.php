<?php
/**
 * Account page — standalone personal surface.
 *
 * Full credential and profile management for any authenticated user
 * (operator or business user). Sits outside the settings namespace
 * to provide a calm, first-class personal workspace.
 *
 * Variables: $user, $version, $csrfToken, $flash, $pageTitle
 */
$activePage = 'account';
$currentUser = $user ?? [];

ob_start();
?>

<?php
$pageHeader = [
    'title' => __('admin.account.page_title'),
    'subtitle' => __('admin.account.page_subtitle'),
];
include __DIR__ . '/partials/page-header.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../partials/alert.php'; ?>
<?php endif; ?>

<div class="vb-grid vb-grid-2">
    <!-- Profile Details (Editable) -->
    <div class="vb-card vb-fade-in-up stagger-1">
        <div class="vb-card-header">
            <div class="vb-card-title-row">
                <i data-lucide="user" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.account.profile_title') ?></div>
                    <div class="vb-card-desc"><?= __('admin.account.profile_desc') ?></div>
                </div>
            </div>
        </div>
        <form method="POST" action="/admin/account" autocomplete="off" novalidate>
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="_action" value="profile">

            <div class="vb-form-group">
                <label for="account_name" class="vb-label"><?= __('admin.account.name_label') ?></label>
                <input type="text" id="account_name" name="name" class="vb-input<?= error_class('name') ?>"
                       value="<?= e(old('name', $currentUser['name'] ?? '')) ?>"
                       required autocomplete="name">
                <?php if (has_error('name')): ?>
                    <div class="vb-form-error" role="alert"><?= field_error('name') ?></div>
                <?php endif; ?>
            </div>
            <div class="vb-form-group">
                <label for="account_email" class="vb-label"><?= __('admin.account.email_label') ?></label>
                <input type="email" id="account_email" name="email" class="vb-input<?= error_class('email') ?>"
                       value="<?= e(old('email', $currentUser['email'] ?? '')) ?>"
                       required autocomplete="email">
                <?php if (has_error('email')): ?>
                    <div class="vb-form-error" role="alert"><?= field_error('email') ?></div>
                <?php endif; ?>
            </div>
            <div class="vb-info-row">
                <span class="vb-info-label"><?= __('admin.account.role_label') ?></span>
                <span class="vb-info-value">
                    <span class="vb-badge vb-badge-primary vb-capitalize">
                        <?= htmlspecialchars($currentUser['type'] ?? 'operator', ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </span>
            </div>
            <div class="vb-form-actions">
                <button type="submit" class="vb-btn vb-btn-primary">
                    <i data-lucide="save"></i>
                    <?= __('admin.account.save_profile_button') ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Change Password -->
    <div class="vb-card vb-fade-in-up stagger-2">
        <div class="vb-card-header">
            <div class="vb-card-title-row">
                <i data-lucide="key" class="vb-card-icon"></i>
                <div>
                    <div class="vb-card-title"><?= __('admin.account.change_pw_title') ?></div>
                    <div class="vb-card-desc"><?= __('admin.account.change_pw_desc') ?></div>
                </div>
            </div>
        </div>
        <form method="POST" action="/admin/account" autocomplete="off" novalidate>
            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="_action" value="password">

            <div class="vb-form-group">
                <label for="current_password" class="vb-label"><?= __('admin.account.current_pw_label') ?></label>
                <input type="password" id="current_password" name="current_password" class="vb-input<?= error_class('current_password') ?>" required autocomplete="current-password">
                <?php if (has_error('current_password')): ?>
                    <div class="vb-form-error" role="alert"><?= field_error('current_password') ?></div>
                <?php endif; ?>
            </div>
            <div class="vb-form-group">
                <label for="new_password" class="vb-label"><?= __('admin.account.new_pw_label') ?></label>
                <input type="password" id="new_password" name="new_password" class="vb-input<?= error_class('new_password') ?>" required autocomplete="new-password" minlength="8">
                <?php if (has_error('new_password')): ?>
                    <div class="vb-form-error" role="alert"><?= field_error('new_password') ?></div>
                <?php endif; ?>
                <div class="vb-pw-track" id="pw-track">
                    <div class="vb-pw-fill" id="pw-fill"></div>
                </div>
            </div>
            <div class="vb-form-group">
                <label for="confirm_password" class="vb-label"><?= __('admin.account.confirm_pw_label') ?></label>
                <input type="password" id="confirm_password" name="confirm_password" class="vb-input<?= error_class('confirm_password') ?>" required autocomplete="new-password">
                <?php if (has_error('confirm_password')): ?>
                    <div class="vb-form-error" role="alert"><?= field_error('confirm_password') ?></div>
                <?php endif; ?>
            </div>
            <div class="vb-form-actions">
                <button type="submit" class="vb-btn vb-btn-primary">
                    <i data-lucide="lock"></i>
                    <?= __('admin.account.update_pw_button') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function() {
    var pw = document.getElementById('new_password');
    var fill = document.getElementById('pw-fill');
    if (pw && fill) {
        pw.addEventListener('input', function() {
            var s = 0, v = this.value;
            if (v.length >= 8) s++;
            if (v.length >= 12) s++;
            if (/[A-Z]/.test(v) && /[a-z]/.test(v)) s++;
            if (/\d/.test(v)) s++;
            if (/[^A-Za-z0-9]/.test(v)) s++;
            var pct = Math.min(s * 20, 100);
            fill.style.width = pct + '%';
            fill.style.background = pct <= 20 ? 'var(--vb-error)' : pct <= 60 ? 'var(--vb-warning)' : 'var(--vb-success)';
        });
    }
})();
</script>

<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
