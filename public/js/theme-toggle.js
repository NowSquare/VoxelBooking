/**
 * Theme toggle — click handler for sun/moon toggle buttons.
 *
 * Shared across homepage, and any other public page that embeds
 * a theme toggle with id="lp-theme-toggle".
 * Persists choice to localStorage under the same key used by theme.js.
 */
(function() {
    var toggle = document.getElementById('lp-theme-toggle');
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
