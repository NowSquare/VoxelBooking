<?php
$activePage = 'connections';
ob_start();
?>
<div class="vb-page-header"><div class="vb-page-heading">
    <h1 class="vb-page-title"><?= e(__('oauth.error_title')) ?></h1>
    <p class="vb-page-subtitle"><?= e(__('oauth.errors.' . $reason)) ?></p>
</div></div>
<div class="vb-card">
    <div class="vb-card-body">
        <p><?= e(__('oauth.recovery')) ?></p>
        <a href="<?= $reason === 'password_required' ? '/admin/account' : '/admin/connections' ?>" class="vb-btn vb-btn-primary">
            <?= e(__($reason === 'password_required' ? 'oauth.open_account' : 'oauth.open_connections')) ?>
        </a>
    </div>
</div>
<?php
$content = ob_get_clean();
include __DIR__ . '/oauth-layout.php';
