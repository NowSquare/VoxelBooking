<?php ob_start();$pageHeader=['title'=>__('agent-webhooks.secret_title'),'subtitle'=>$subscription['name']];include __DIR__.'/../partials/page-header.php'; ?>
<section class="vb-card vb-card-flush vb-agent-panel"><div class="vb-card-body space-y-4">
    <p><?= e(__('agent-webhooks.secret_description')) ?></p>
    <?php if($secret!==null): ?>
        <label class="vb-label" for="signing-secret"><?= e(__('agent-webhooks.secret_title')) ?></label>
        <input class="vb-input" id="signing-secret" value="<?= e($secret) ?>" readonly spellcheck="false" autocomplete="off">
    <?php elseif($subscription['secret_available']): ?>
        <form method="post"><input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>"><button class="vb-btn vb-btn-primary" type="submit"><?= e(__('agent-webhooks.reveal')) ?></button></form>
    <?php else: ?><p><?= e(__('agent-webhooks.secret_unavailable')) ?></p><?php endif; ?>
    <form method="post" action="/admin/tenants/<?= e($tenantId) ?>/webhooks/<?= e($subscription['id']) ?>/rotate" class="space-y-4">
        <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>"><input type="hidden" name="expected_version" value="<?= e($subscription['version']) ?>">
        <p class="vb-card-desc"><?= e(__('agent-webhooks.rotated')) ?></p>
        <button class="vb-btn vb-btn-secondary" type="submit"><?= e(__('agent-webhooks.rotate')) ?></button>
    </form>
    <a class="vb-link" href="/admin/tenants/<?= e($tenantId) ?>/webhooks"><?= e(__('agent-webhooks.back')) ?></a>
</div></section>
<?php $content=ob_get_clean();include __DIR__.'/../layout.php'; ?>
