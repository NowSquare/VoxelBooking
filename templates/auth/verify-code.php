<?php
/**
 * OTP verify-code page — 6-digit code entry.
 *
 * Variables: $csrfToken, $email, $error, $success
 */
$csrfToken = $csrfToken ?? '';
$email = $email ?? '';
$error = $error ?? null;
$success = $success ?? null;
?>
<!DOCTYPE html>
<html lang="<?= \App\Engine\Locale::getLocale() ?>" dir="<?= \App\Engine\Locale::direction() ?>">
<head>
    <script src="/js/theme.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= __('auth.enter_code_heading') ?> — <?= app_name() ?></title>
    <?php include dirname(__DIR__) . '/partials/admin-head.php'; ?>
    <link rel="stylesheet" href="/assets/css/admin-css.css">
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
            <a href="/" class="login-hero">
                <svg class="login-hero-cube" width="56" height="60" viewBox="0 0 48 52" xmlns="http://www.w3.org/2000/svg">
                    <polygon points="24,2 46,14 24,26 2,14" fill="var(--vb-accent)" opacity="1.0"/>
                    <polygon points="2,14 24,26 24,50 2,38" fill="var(--vb-accent)" opacity="0.7"/>
                    <polygon points="46,14 24,26 24,50 46,38" fill="var(--vb-accent)" opacity="0.4"/>
                </svg>
                <span class="login-hero-name"><?= app_name() ?></span>
            </a>

            <div class="login-form-card<?= $error ? ' vb-shake' : '' ?>">
                <h2><?= __('auth.enter_code_heading') ?></h2>
                <p style="color: var(--vb-text-muted); font-size: 14px; margin-bottom: 20px;">
                    <?= __('auth.enter_code_sub', ['email' => htmlspecialchars($email, ENT_QUOTES, 'UTF-8')]) ?>
                </p>

                <?php if ($error): ?>
                    <div class="vb-alert vb-alert-error login-error">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="vb-alert vb-alert-success login-error">
                        <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="/admin/login/verify-code" id="verify-code-form">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="vb-form-group">
                        <div class="otp-inputs" id="otp-inputs">
                            <input type="text" name="d1" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" autocomplete="one-time-code" autofocus required id="otp-d1">
                            <input type="text" name="d2" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" required id="otp-d2">
                            <input type="text" name="d3" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" required id="otp-d3">
                            <input type="text" name="d4" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" required id="otp-d4">
                            <input type="text" name="d5" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" required id="otp-d5">
                            <input type="text" name="d6" class="otp-digit" maxlength="1" pattern="[0-9]" inputmode="numeric" required id="otp-d6">
                        </div>
                        <!-- Assembled code for submission -->
                        <input type="hidden" name="code" id="otp-code-hidden" value="">
                    </div>

                    <div class="vb-form-group">
                        <label class="vb-checkbox-label">
                            <input type="checkbox" name="remember_me" value="1">
                            <?= __('auth.remember_me') ?>
                        </label>
                    </div>

                    <button type="submit" class="vb-btn vb-btn-primary login-submit" id="verify-submit"><?= __('auth.verify_button') ?></button>
                </form>

                <div style="text-align: center; margin-top: 16px;">
                    <a href="/admin/login" style="color: var(--vb-text-muted); font-size: 13px; text-decoration: none;"><?= __('auth.back_to_login') ?></a>
                </div>
            </div>

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

        // OTP digit auto-advance
        var inputs = document.querySelectorAll('.otp-digit');
        inputs.forEach(function(input, i) {
            input.addEventListener('input', function() {
                // Only allow digits
                this.value = this.value.replace(/[^0-9]/g, '');
                if (this.value.length === 1 && i < inputs.length - 1) {
                    inputs[i + 1].focus();
                }
            });
            input.addEventListener('keydown', function(e) {
                if (e.key === 'Backspace' && this.value === '' && i > 0) {
                    inputs[i - 1].focus();
                }
            });
            // Handle paste of full code
            input.addEventListener('paste', function(e) {
                e.preventDefault();
                var data = (e.clipboardData || window.clipboardData).getData('text').replace(/[^0-9]/g, '');
                for (var j = 0; j < Math.min(data.length, inputs.length); j++) {
                    inputs[j].value = data[j];
                }
                var lastIdx = Math.min(data.length, inputs.length) - 1;
                if (lastIdx >= 0) inputs[lastIdx].focus();
            });
        });

        // Assemble code on submit
        var form = document.getElementById('verify-code-form');
        if (form) {
            form.addEventListener('submit', function() {
                var code = '';
                inputs.forEach(function(input) { code += input.value; });
                document.getElementById('otp-code-hidden').value = code;
            });
        }
    })();
    </script>
</body>
</html>
