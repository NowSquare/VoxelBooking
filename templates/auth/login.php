<?php
/**
 * Login page — Visual Design §15: Authentication Surface.
 *
 * Split layout: environmental panel (left) + form (right).
 * Gradient mesh background. Staggered entrance animation.
 * Shake on error. No visible card border in light mode.
 *
 * Method selector: Password / Login Code / Magic Link tabs.
 *
 * All styles in admin.css (compiled by Vite).
 * Inline SVGs: voxel logo (brand mark), mail/lock field icons (Lucide
 * not available on login — no app.js loaded), sun/moon theme toggle.
 *
 * Variables: $csrfToken, $error, $success
 */
$error = $error ?? null;
$success = $success ?? null;
$csrfToken = $csrfToken ?? '';
?>
<!DOCTYPE html>
<html lang="<?= \App\Engine\Locale::getLocale() ?>" dir="<?= \App\Engine\Locale::direction() ?>">
<head>
    <script src="/js/theme.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <meta name="description" content="<?= __('auth.meta_description', ['app_name' => app_name()]) ?>">
    <title><?= __('auth.page_title', ['app_name' => app_name()]) ?></title>
    <?php include dirname(__DIR__) . '/partials/admin-head.php'; ?>
    <link rel="stylesheet" href="/assets/css/admin-css.css?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/css/admin-css.css') ?>">
    <style>body { background: var(--vb-bg-base); min-height: 100vh; display: flex; overflow: hidden; }</style>
</head>
<body>
    <!-- Environmental Panel -->
    <div class="login-env">
        <svg class="login-env-voxel" width="240" height="260" viewBox="0 0 48 52" xmlns="http://www.w3.org/2000/svg">
            <polygon points="24,2 46,14 24,26 2,14" fill="currentColor" opacity="1.0"/>
            <polygon points="2,14 24,26 24,50 2,38" fill="currentColor" opacity="0.7"/>
            <polygon points="46,14 24,26 24,50 46,38" fill="currentColor" opacity="0.4"/>
        </svg>
    </div>

    <!-- Form Panel -->
    <div class="login-panel">
        <button type="button" class="login-theme-toggle" id="theme-toggle" aria-label="<?= __('auth.toggle_theme') ?>">
            <svg class="icon-sun" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            <svg class="icon-moon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </button>

        <div class="login-inner">
            <!-- Hero Logo — links back to homepage -->
            <a href="/" class="login-hero">
                <svg class="login-hero-cube" width="56" height="60" viewBox="0 0 48 52" xmlns="http://www.w3.org/2000/svg">
                    <polygon points="24,2 46,14 24,26 2,14" fill="var(--vb-accent)" opacity="1.0"/>
                    <polygon points="2,14 24,26 24,50 2,38" fill="var(--vb-accent)" opacity="0.7"/>
                    <polygon points="46,14 24,26 24,50 46,38" fill="var(--vb-accent)" opacity="0.4"/>
                </svg>
                <span class="login-hero-name"><?= app_name() ?></span>
                <span class="login-hero-sub"><?= __('auth.hero_sub') ?></span>
            </a>

            <!-- Login Form -->
            <div class="login-form-card<?= $error ? ' vb-shake' : '' ?>">
                <h2><?= __('auth.login_heading') ?></h2>

                <?php if ($error): ?>
                    <div class="vb-alert vb-alert-error login-error">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="vb-alert vb-alert-success login-error">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="16 12 12 8 8 12"/><line x1="12" y1="16" x2="12" y2="8"/></svg>
                        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if (!\App\Engine\DemoMode::isActive()): ?>
                <!-- Method tabs (hidden in demo mode — only password login is available) -->
                <div class="login-method-tabs" id="login-method-tabs" role="tablist">
                    <button type="button" role="tab" class="login-tab active" data-method="password" id="tab-password" aria-selected="true" aria-controls="panel-password"><?= __('auth.tab_password') ?></button>
                    <button type="button" role="tab" class="login-tab" data-method="otp" id="tab-otp" aria-selected="false" aria-controls="panel-otp"><?= __('auth.tab_otp') ?></button>
                    <button type="button" role="tab" class="login-tab" data-method="magic_link" id="tab-magic-link" aria-selected="false" aria-controls="panel-magic-link"><?= __('auth.tab_magic_link') ?></button>
                </div>
                <?php endif; ?>

                <!-- Password form -->
                <form method="POST" action="/admin/login" id="panel-password" class="login-method-panel" role="tabpanel" aria-labelledby="tab-password">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="vb-form-group login-field-1">
                        <label for="email" class="vb-label"><?= __('auth.email_label') ?></label>
                        <div class="vb-input-wrap">
                            <svg class="vb-icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                            <input type="email" id="email" name="email" class="vb-input vb-input-icon" placeholder="<?= __('auth.email_placeholder') ?>" required autocomplete="email" autofocus value="<?= e(old('email')) ?>">
                        </div>
                    </div>

                    <div class="vb-form-group login-field-2">
                        <label for="password" class="vb-label"><?= __('auth.password_label') ?></label>
                        <div class="vb-input-wrap">
                            <svg class="vb-icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" id="password" name="password" class="vb-input vb-input-icon" placeholder="<?= __('auth.password_placeholder') ?>" required autocomplete="current-password" minlength="8">
                        </div>
                    </div>

                    <div class="vb-form-group login-field-3">
                        <label class="vb-checkbox-label">
                            <input type="checkbox" name="remember_me" value="1">
                            <?= __('auth.remember_me') ?>
                        </label>
                    </div>

                    <button type="submit" class="vb-btn vb-btn-primary login-submit"><?= __('auth.login_button') ?></button>
                </form>

                <?php if (!\App\Engine\DemoMode::isActive()): ?>
                <div class="login-aux-links">
                    <a href="/admin/forgot-password" class="login-aux-link"><?= __('auth.forgot_password_link') ?></a>
                </div>
                <?php endif; ?>

                <?php if (!\App\Engine\DemoMode::isActive()): ?>
                <!-- OTP form -->
                <form method="POST" action="/admin/login/request-code" id="panel-otp" class="login-method-panel" role="tabpanel" aria-labelledby="tab-otp" style="display: none;">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="method" value="otp">

                    <div class="vb-form-group">
                        <label for="otp-email" class="vb-label"><?= __('auth.email_label') ?></label>
                        <div class="vb-input-wrap">
                            <svg class="vb-icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                            <input type="email" id="otp-email" name="email" class="vb-input vb-input-icon" placeholder="<?= __('auth.email_placeholder') ?>" required autocomplete="email" value="<?= e(old('email')) ?>">
                        </div>
                    </div>

                    <button type="submit" class="vb-btn vb-btn-primary login-submit"><?= __('auth.send_code_button') ?></button>
                </form>

                <!-- Magic link form -->
                <form method="POST" action="/admin/login/request-code" id="panel-magic-link" class="login-method-panel" role="tabpanel" aria-labelledby="tab-magic-link" style="display: none;">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="method" value="magic_link">

                    <div class="vb-form-group">
                        <label for="ml-email" class="vb-label"><?= __('auth.email_label') ?></label>
                        <div class="vb-input-wrap">
                            <svg class="vb-icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="20" height="16" x="2" y="4" rx="2"/><path d="m22 7-8.97 5.7a1.94 1.94 0 0 1-2.06 0L2 7"/></svg>
                            <input type="email" id="ml-email" name="email" class="vb-input vb-input-icon" placeholder="<?= __('auth.email_placeholder') ?>" required autocomplete="email" value="<?= e(old('email')) ?>">
                        </div>
                    </div>

                    <div class="vb-form-group">
                        <label class="vb-checkbox-label">
                            <input type="checkbox" name="remember_me" value="1">
                            <?= __('auth.remember_me') ?>
                        </label>
                    </div>

                    <button type="submit" class="vb-btn vb-btn-primary login-submit"><?= __('auth.send_link_button') ?></button>
                </form>
                <?php endif; ?>
            </div>

            <?php if (\App\Engine\DemoMode::isActive()): ?>
            <div class="login-demo-credentials">
                <div class="login-demo-title">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
                    <?= __('admin.demo.banner_title') ?>
                </div>
                <div class="login-demo-accounts">
                    <button type="button" class="login-demo-account" data-email="demo@voxelbooking.com" data-password="welcome3210">
                        <span class="login-demo-role"><?= __('admin.demo.account_operator') ?></span>
                        <span class="login-demo-email">demo@voxelbooking.com</span>
                    </button>
                    <button type="button" class="login-demo-account" data-email="owner@demo-studio.test" data-password="welcome3210">
                        <span class="login-demo-role"><?= __('admin.demo.account_demo_studio') ?></span>
                        <span class="login-demo-email">owner@demo-studio.test</span>
                    </button>
                    <button type="button" class="login-demo-account" data-email="owner@hotel-marina.test" data-password="welcome3210">
                        <span class="login-demo-role"><?= __('admin.demo.account_hotel_marina') ?></span>
                        <span class="login-demo-email">owner@hotel-marina.test</span>
                    </button>
                    <button type="button" class="login-demo-account" data-email="owner@trattoria-roma.test" data-password="welcome3210">
                        <span class="login-demo-role"><?= __('admin.demo.account_trattoria_roma') ?></span>
                        <span class="login-demo-email">owner@trattoria-roma.test</span>
                    </button>
                    <button type="button" class="login-demo-account" data-email="owner@workshop-studio.test" data-password="welcome3210">
                        <span class="login-demo-role"><?= __('admin.demo.account_workshop_studio') ?></span>
                        <span class="login-demo-email">owner@workshop-studio.test</span>
                    </button>
                </div>
            </div>
            <?php endif; ?>

            <div class="login-footer">
                <?= __('auth.footer', ['app_name' => app_name()]) ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
        // Theme toggle
        var toggle = document.getElementById('theme-toggle');
        if (toggle) {
            toggle.addEventListener('click', function() {
                var html = document.documentElement;
                var current = html.getAttribute('data-theme');
                var next = current === 'dark' ? 'light' : 'dark';
                html.setAttribute('data-theme', next);
                localStorage.setItem('vb-theme', next);
            });
        }

        // Method tabs
        var tabs = document.querySelectorAll('.login-tab');
        var panels = document.querySelectorAll('.login-method-panel');

        tabs.forEach(function(tab) {
            tab.addEventListener('click', function() {
                var method = tab.getAttribute('data-method');

                tabs.forEach(function(t) {
                    t.classList.remove('active');
                    t.setAttribute('aria-selected', 'false');
                });
                tab.classList.add('active');
                tab.setAttribute('aria-selected', 'true');

                panels.forEach(function(p) {
                    p.style.display = 'none';
                });

                var targetId = 'panel-' + method.replace('_', '-');
                var target = document.getElementById(targetId);
                if (target) {
                    target.style.display = '';
                }
            });
        });
        // Demo account auto-fill
        var demoAccounts = document.querySelectorAll('.login-demo-account');
        demoAccounts.forEach(function(btn) {
            btn.addEventListener('click', function() {
                var email = btn.getAttribute('data-email');
                var password = btn.getAttribute('data-password');

                // Switch to password tab
                var pwTab = document.getElementById('tab-password');
                if (pwTab) pwTab.click();

                // Fill fields
                var emailField = document.getElementById('email');
                var pwField = document.getElementById('password');
                if (emailField) emailField.value = email;
                if (pwField) pwField.value = password;

                // Visual feedback
                demoAccounts.forEach(function(b) { b.classList.remove('selected'); });
                btn.classList.add('selected');
            });
        });
    })();
    </script>
</body>
</html>
