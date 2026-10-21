<?php ob_start();$pageHeader=['title'=>__('agent-webhooks.title'),'subtitle'=>__('agent-webhooks.subtitle')];include __DIR__.'/../partials/page-header.php'; ?>
<?php if($flash):include __DIR__.'/../../partials/alert.php';endif; ?>
<div class="vb-agent-panel">
<p class="vb-card-desc mb-6"><?= e(__('agent-webhooks.disclosure')) ?></p>
<?php if($items===[]): ?><p class="vb-card-desc mb-6"><?= e(__('agent-webhooks.empty')) ?></p><?php endif; ?>
<?php foreach($items as $item): ?>
<details class="vb-card vb-card-flush mb-6">
    <summary class="vb-card-header"><span class="vb-card-title"><?= e($item['name']) ?></span><span class="vb-card-desc"><?= e($item['endpoint']) ?></span></summary>
    <form method="post" action="/admin/tenants/<?= e($tenantId) ?>/webhooks/<?= e($item['id']) ?>" class="vb-card-body space-y-4">
        <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
        <input type="hidden" name="expected_version" value="<?= e($item['version']) ?>">
        <?php $prefix=$item['id'];include __DIR__.'/_fields.php'; ?>
        <label class="vb-checkbox-label"><input type="checkbox" name="is_active" value="1"<?= $item['is_active']?' checked':'' ?>><span><?= e(__('agent-webhooks.active')) ?></span></label>
        <button class="vb-btn vb-btn-primary" type="submit"><?= e(__('agent-webhooks.save')) ?></button>
        <a class="vb-btn vb-btn-secondary" href="/admin/tenants/<?= e($tenantId) ?>/webhooks/<?= e($item['id']) ?>/deliveries"><?= e(__('agent-webhooks.deliveries')) ?></a>
        <a class="vb-link" href="/admin/tenants/<?= e($tenantId) ?>/webhooks/<?= e($item['id']) ?>/secret"><?= e(__('agent-webhooks.secret_title')) ?></a>
    </form>
</details>
<?php endforeach; ?>
<section class="vb-card vb-card-flush">
    <div class="vb-card-header"><h3 class="vb-card-title"><?= e(__('agent-webhooks.create')) ?></h3></div>
    <form method="post" action="/admin/tenants/<?= e($tenantId) ?>/webhooks" class="vb-card-body space-y-4">
        <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
        <?php $prefix='new';$item=['name'=>'','endpoint'=>'','event_types'=>['booking.created']];include __DIR__.'/_fields.php'; ?>
        <button class="vb-btn vb-btn-primary" type="submit"><?= e(__('agent-webhooks.create')) ?></button>
    </form>
</section>
</div>
<?php $content=ob_get_clean();include __DIR__.'/../layout.php'; ?>
