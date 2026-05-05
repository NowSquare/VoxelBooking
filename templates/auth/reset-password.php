<?php
/**
 * Reset-password page — same visual design language as login.
 *
 * Split layout: environmental panel (left) + form (right).
 * New password + confirm password inputs. Hidden token field.
 *
 * Variables: $csrfToken, $token, $error
 */
$error = $error ?? null;
$token = $token ?? '';
$csrfToken = $csrfToken ?? '';
?>
<!DOCTYPE html>
<html lang="<?= \App\Engine\Locale::getLocale() ?>" dir="<?= \App\Engine\Locale::direction() ?>">
<head>
    <script src="/js/theme.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= __('auth.reset_password_page', ['app_name' => app_name()]) ?></title>
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

            <!-- Reset Password Form -->
            <div class="login-form-card<?= $error ? ' vb-shake' : '' ?>">
                <h2><?= __('auth.reset_password_heading') ?></h2>

                <?php if ($error): ?>
                    <div class="vb-alert vb-alert-error login-error">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
                        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="/admin/reset-password">
                    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES, 'UTF-8') ?>">

                    <div class="vb-form-group login-field-1">
                        <label for="new-password" class="vb-label"><?= __('auth.reset_password_label') ?></label>
                        <div class="vb-input-wrap">
                            <svg class="vb-icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" id="new-password" name="password" class="vb-input vb-input-icon" placeholder="<?= __('auth.password_placeholder') ?>" required autocomplete="new-password" minlength="8" autofocus>
                        </div>
                    </div>

                    <div class="vb-form-group login-field-2">
                        <label for="confirm-password" class="vb-label"><?= __('auth.reset_confirm_label') ?></label>
                        <div class="vb-input-wrap">
                            <svg class="vb-icon-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                            <input type="password" id="confirm-password" name="password_confirmation" class="vb-input vb-input-icon" placeholder="<?= __('auth.password_placeholder') ?>" required autocomplete="new-password" minlength="8">
                        </div>
                    </div>

                    <button type="submit" class="vb-btn vb-btn-primary login-submit"><?= __('auth.reset_password_button') ?></button>
                </form>

                <div class="login-aux-links">
                    <a href="/admin/login" class="login-aux-link">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                        <?= __('auth.back_to_login') ?>
                    </a>
                </div>
            </div>

            <div class="login-footer">
                <?= __('auth.footer', ['app_name' => app_name()]) ?>
            </div>
        </div>
    </div>

    <script>
    (function() {
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
    })();
    </script>
</body>
</html>
