<!DOCTYPE html>
<html lang="<?= \App\Engine\Locale::getLocale() ?>" dir="<?= \App\Engine\Locale::direction() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title><?= __('booking.empty.404_title') ?></title>
    <link rel="icon" href="/favicon.ico" type="image/png">
    <style>
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url('/fonts/inter-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url('/fonts/inter-latin.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
    </style>
    <link rel="stylesheet" href="/assets/css/booking-css.css?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/css/booking-css.css') ?>">
    <style>[x-cloak] { display: none !important; }</style>
    <script>
        (function() {
            var s = localStorage.getItem('vb-theme');
            var t = s || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>
<body>
    <div class="vb-book-app is-ready" id="vb-book-app">
        <main class="vb-book-flow vb-book-error-center">
            <div class="vb-book-error-content">
                <div class="vb-book-error-icon">
                    <svg width="48" height="48" viewBox="0 0 48 48" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <circle cx="24" cy="24" r="22" stroke="currentColor" stroke-width="1.5"/>
                        <path d="M24 16v10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <circle cx="24" cy="32" r="1.5" fill="currentColor"/>
                    </svg>
                </div>
                <h1 class="vb-book-business-name vb-book-error-title"><?= __('booking.empty.404_title') ?></h1>
                <p class="vb-book-business-desc vb-book-error-desc">
                    <?= __('booking.empty.404_desc') ?>
                </p>
                <p class="vb-book-business-desc vb-book-error-help">
                    <?= __('booking.empty.404_help') ?>
                </p>
            </div>
        </main>

        <!-- Theme Toggle (vanilla JS — no Alpine on this page) -->
        <button type="button" class="vb-book-theme-toggle" id="vb-theme-toggle"
                aria-label="<?= __('booking.theme.toggle') ?>" title="<?= __('booking.theme.toggle') ?>">
            <svg id="vb-theme-sun" class="vb-book-theme-icon" style="display:none" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="5"/><line x1="12" y1="1" x2="12" y2="3"/><line x1="12" y1="21" x2="12" y2="23"/><line x1="4.22" y1="4.22" x2="5.64" y2="5.64"/><line x1="18.36" y1="18.36" x2="19.78" y2="19.78"/><line x1="1" y1="12" x2="3" y2="12"/><line x1="21" y1="12" x2="23" y2="12"/><line x1="4.22" y1="19.78" x2="5.64" y2="18.36"/><line x1="18.36" y1="5.64" x2="19.78" y2="4.22"/></svg>
            <svg id="vb-theme-moon" class="vb-book-theme-icon" xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"/></svg>
        </button>

        <footer class="vb-book-footer">
            <span><?= __('booking.footer.powered_by') ?></span>
            <a href="<?= htmlspecialchars(brand_url(), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></a>
        </footer>
    </div>
    <script>
        (function() {
            var btn = document.getElementById('vb-theme-toggle');
            var sun = document.getElementById('vb-theme-sun');
            var moon = document.getElementById('vb-theme-moon');
            function sync() {
                var d = document.documentElement.getAttribute('data-theme') === 'dark';
                sun.style.display = d ? '' : 'none';
                moon.style.display = d ? 'none' : '';
            }
            sync();
            btn.addEventListener('click', function() {
                var d = document.documentElement.getAttribute('data-theme') !== 'dark';
                document.documentElement.setAttribute('data-theme', d ? 'dark' : 'light');
                localStorage.setItem('vb-theme', d ? 'dark' : 'light');
                sync();
            });
        })();
    </script>
</body>
</html>
