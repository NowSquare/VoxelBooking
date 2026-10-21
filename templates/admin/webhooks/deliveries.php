<?php ob_start();$pageHeader=['title'=>__('agent-webhooks.deliveries'),'subtitle'=>$subscription['name']];include __DIR__.'/../partials/page-header.php'; ?>
<section class="vb-card vb-card-flush vb-agent-panel"><div class="vb-card-body space-y-4">
    <?php if($deliveries['items']===[]): ?><p class="vb-card-desc"><?= e(__('agent-webhooks.delivery_empty')) ?></p><?php endif; ?>
    <?php foreach($deliveries['items'] as $delivery): ?>
    <article class="vb-form-group">
        <h3 class="vb-card-title"><?= e(__('agent-webhooks.event_labels.'.$delivery['event_type'])) ?></h3>
        <p class="vb-card-desc"><?php $createdAt=new DateTimeImmutable($delivery['created_at'],new DateTimeZone('UTC')); ?><time datetime="<?= e($createdAt->format('Y-m-d\\TH:i:s\\Z')) ?>"><?= e(\App\Engine\Locale::datetime($createdAt)) ?> UTC</time> · <?= e(__('agent-webhooks.states.'.$delivery['status'])) ?> · <?= e(__('agent-webhooks.attempts')) ?> <?= (int)$delivery['attempts'] ?></p>
        <code><?= e($delivery['id']) ?></code>
        <?php if($delivery['status']==='failed'): ?>
        <form method="post" action="/admin/tenants/<?= e($tenantId) ?>/webhook-deliveries/<?= e($delivery['id']) ?>/retry">
            <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>"><button class="vb-btn vb-btn-secondary" type="submit"><?= e(__('agent-webhooks.retry')) ?></button>
        </form>
        <?php endif; ?>
    </article>
    <?php endforeach; ?>
    <?php if($deliveries['next_after_id']): ?><a class="vb-link" href="?after_id=<?= e($deliveries['next_after_id']) ?>"><?= e(__('agent-webhooks.next')) ?></a><?php endif; ?>
    <a class="vb-link" href="/admin/tenants/<?= e($tenantId) ?>/webhooks"><?= e(__('agent-webhooks.back')) ?></a>
</div></section>
<?php $content=ob_get_clean();include __DIR__.'/../layout.php'; ?>
