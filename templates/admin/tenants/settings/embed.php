<?php
/**
 * Tenant Settings — Embed tab.
 *
 * Per PRD §IX — embed widget configuration.
 * Tenant configures allowed embed domains, button position, button label.
 * Shows copyable embed code snippet with live preview.
 *
 * No inline styles except dynamic brand color on the preview button
 * (allowed per design system: dynamic values only).
 *
 * Variables: $tenant, $tenantId, $csrfToken, $activeTab, $flash
 */
$tenant    = $tenant ?? [];
$tenantId  = $tenantId ?? '';
// Format allowed domains for textarea (stored comma-separated, displayed one per line)
$domainsForTextarea = '';
$raw = $tenant['allowed_embed_domains'] ?? '';
if ($raw !== '' && $raw !== null) {
    $domainsForTextarea = htmlspecialchars(
        implode("\n", array_map('trim', explode(',', $raw))),
        ENT_QUOTES, 'UTF-8'
    );
}

// Build embed code snippet
$proto  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$origin = $proto . '://' . $host;
$slug   = $tenant['slug'] ?? '';

$brandColor     = $tenant['brand_color'] ?? '#4F46E5';
$brandColorText = \App\Engine\BrandColorHelper::derive($brandColor)['brand_text'];
$buttonLabel    = $tenant['embed_button_label'] ?? 'Book Now';
$buttonPosition = $tenant['embed_button_position'] ?? 'bottom-right';

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

<form method="POST" action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/settings/embed" class="vb-animate-in">
    <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

    <!-- Embed Code Snippet -->
    <div class="vb-settings-section">
        <div class="vb-card vb-embed-hero-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="code" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.embed_code_title') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.embed_code_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-card-body">
                <div class="vb-embed-code-wrap">
                    <div class="vb-embed-code-content">
                        <code id="embed-code-snippet" class="vb-embed-code">&lt;script src="<?= htmlspecialchars($origin, ENT_QUOTES, 'UTF-8') ?>/embed/<?= htmlspecialchars($slug, ENT_QUOTES, 'UTF-8') ?>.js" defer&gt;&lt;/script&gt;</code>
                    </div>
                    <button type="button" class="vb-btn vb-btn-secondary vb-btn-sm vb-embed-copy-btn" id="btn-copy-embed"
                            data-copy-target="embed-code-snippet">
                        <i data-lucide="copy" class="vb-btn-icon-sm"></i>
                        <span class="vb-embed-copy-label"><?= __('admin.tenant_settings.embed_copy_btn') ?></span>
                    </button>
                </div>
                <p class="vb-embed-code-hint">
                    <i data-lucide="info" class="vb-hint-icon"></i>
                    <?= __('admin.tenant_settings.embed_code_hint') ?? 'Paste this script tag before the closing &lt;/body&gt; of any page where you want the booking button.' ?>
                </p>
            </div>
        </div>
    </div>

    <!-- Button Preview + Settings -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="mouse-pointer-click" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.embed_button_title') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.embed_button_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-card-body">
                <div class="vb-embed-settings-grid">
                    <div class="vb-embed-settings-fields">
                        <div class="vb-settings-field">
                            <label class="vb-label" for="ts-embed-label"><?= __('admin.tenant_settings.embed_label') ?></label>
                            <input type="text" class="vb-input" id="ts-embed-label" name="embed_button_label"
                                   value="<?= e(old('embed_button_label', $tenant['embed_button_label'] ?? '')) ?>"
                                   placeholder="Book Now" maxlength="50">
                        </div>
                        <div class="vb-settings-field">
                            <label class="vb-label" for="ts-embed-position"><?= __('admin.tenant_settings.embed_position') ?></label>
                            <select class="vb-input" id="ts-embed-position" name="embed_button_position">
                                <option value="bottom-right" <?= old('embed_button_position', $tenant['embed_button_position'] ?? 'bottom-right') === 'bottom-right' ? 'selected' : '' ?>>
                                    <?= __('admin.tenant_settings.embed_pos_right') ?>
                                </option>
                                <option value="bottom-left" <?= old('embed_button_position', $tenant['embed_button_position'] ?? 'bottom-right') === 'bottom-left' ? 'selected' : '' ?>>
                                    <?= __('admin.tenant_settings.embed_pos_left') ?>
                                </option>
                            </select>
                        </div>
                    </div>
                    <div class="vb-embed-preview">
                        <div class="vb-embed-preview-label"><?= __('admin.tenant_settings.embed_preview_label') ?? 'Preview' ?></div>
                        <div class="vb-embed-preview-pane" id="embed-preview-pane">
                            <div class="vb-embed-preview-site">
                                <div class="vb-embed-preview-site-bar"></div>
                                <div class="vb-embed-preview-site-line vb-embed-preview-site-line--wide"></div>
                                <div class="vb-embed-preview-site-line vb-embed-preview-site-line--medium"></div>
                                <div class="vb-embed-preview-site-line vb-embed-preview-site-line--short"></div>
                            </div>
                            <button type="button" class="vb-embed-preview-btn" id="embed-preview-btn"
                                    style="background: <?= htmlspecialchars($brandColor, ENT_QUOTES, 'UTF-8') ?>; color: <?= htmlspecialchars($brandColorText, ENT_QUOTES, 'UTF-8') ?>;"
                                    data-pos="<?= htmlspecialchars($buttonPosition, ENT_QUOTES, 'UTF-8') ?>">
                                <i data-lucide="calendar" class="vb-btn-icon-sm"></i>
                                <span id="embed-preview-label-text"><?= htmlspecialchars($buttonLabel, ENT_QUOTES, 'UTF-8') ?></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Domain allow-list -->
    <div class="vb-settings-section">
        <div class="vb-card">
            <div class="vb-card-header">
                <div class="vb-card-title-row">
                    <i data-lucide="shield-check" class="vb-card-icon"></i>
                    <div>
                        <div class="vb-card-title"><?= __('admin.tenant_settings.embed_domains_title') ?></div>
                        <div class="vb-card-desc"><?= __('admin.tenant_settings.embed_domains_desc') ?></div>
                    </div>
                </div>
            </div>
            <div class="vb-card-body">
                <div class="vb-settings-field">
                    <label class="vb-label" for="ts-embed-domains"><?= __('admin.tenant_settings.embed_domains_label') ?></label>
                    <textarea class="vb-input vb-textarea vb-embed-domains-input" id="ts-embed-domains" name="allowed_embed_domains"
                              rows="2" placeholder="example.com&#10;shop.mydomain.com"><?= $domainsForTextarea ?></textarea>
                    <div class="vb-settings-hint">
                        <i data-lucide="info" class="vb-hint-icon"></i>
                        <?= __('admin.tenant_settings.embed_domains_hint') ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="vb-form-actions">
        <button type="submit" class="vb-btn vb-btn-primary" id="save-embed-btn">
            <i data-lucide="save" class="vb-btn-icon-sm"></i>
            <?= __('admin.settings.save_button') ?>
        </button>
    </div>
</form>

<script>
(function() {
    // Copy to clipboard
    var copyBtn = document.getElementById('btn-copy-embed');
    if (copyBtn) {
        copyBtn.addEventListener('click', function() {
            var code = document.getElementById('embed-code-snippet');
            if (!code) return;
            var text = code.textContent.replace(/</g, '<').replace(/>/g, '>').replace(/&/g, '&');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).then(function() {
                    var label = copyBtn.querySelector('.vb-embed-copy-label');
                    if (label) label.textContent = <?= json_encode(__('admin.common.url_copied'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
                    copyBtn.classList.add('vb-embed-copied');
                    var icon = copyBtn.querySelector('[data-lucide]');
                    if (icon) icon.setAttribute('data-lucide', 'check');
                    if (window.lucide) window.lucide.createIcons({ nodes: [copyBtn] });
                    setTimeout(function() {
                        if (label) label.textContent = '<?= __('admin.tenant_settings.embed_copy_btn') ?>';
                        copyBtn.classList.remove('vb-embed-copied');
                        var icon2 = copyBtn.querySelector('[data-lucide]');
                        if (icon2) icon2.setAttribute('data-lucide', 'copy');
                        if (window.lucide) window.lucide.createIcons({ nodes: [copyBtn] });
                    }, 2000);
                });
            }
        });
    }

    // Live preview: sync label text and position
    var labelInput = document.getElementById('ts-embed-label');
    var positionSelect = document.getElementById('ts-embed-position');
    var previewBtn = document.getElementById('embed-preview-btn');
    var previewLabel = document.getElementById('embed-preview-label-text');

    if (labelInput && previewLabel) {
        labelInput.addEventListener('input', function() {
            previewLabel.textContent = this.value || 'Book Now';
        });
    }
    if (positionSelect && previewBtn) {
        positionSelect.addEventListener('change', function() {
            previewBtn.setAttribute('data-pos', this.value);
        });
    }
})();
</script>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
