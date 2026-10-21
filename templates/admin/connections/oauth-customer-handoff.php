<?php ob_start(); ?>
<div class="vb-card">
    <div class="vb-card-header">
        <h1 class="vb-card-title"><?= e(__('oauth.verify_title')) ?></h1>
        <p class="vb-card-desc"><?= e(__('oauth.verify_description')) ?></p>
    </div>
    <div class="vb-card-body">
        <div class="vb-form-actions">
            <a class="vb-btn vb-btn-primary" href="<?= e($verificationUrl) ?>" target="_blank" rel="noopener noreferrer"><?= e(__('oauth.verify_action')) ?></a>
            <a class="vb-btn vb-btn-secondary" href="/oauth/authorize?request=<?= e($handle) ?>"><?= e(__('oauth.verify_return')) ?></a>
        </div>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/oauth-layout.php';
