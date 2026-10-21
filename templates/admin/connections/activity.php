<?php
ob_start();
$pageHeader=['title'=>__('agent.connections.activity_title'),'subtitle'=>$page['connection']['name']];
include __DIR__.'/../partials/page-header.php';
?>
<div class="vb-agent-panel">
    <a class="vb-link" href="/admin/connections"><?= e(__('agent.connections.back')) ?></a>
    <section class="vb-card vb-card-flush">
        <div class="vb-card-header"><p class="vb-card-desc"><?= e(__('agent.connections.activity_description')) ?></p></div>
        <div class="vb-card-body">
        <?php if($page['items']===[]): ?><p><?= e(__('agent.connections.activity_empty')) ?></p><?php endif; ?>
        <?php foreach($page['items'] as $item): ?>
            <article class="vb-form-group">
                <h3 class="vb-card-title"><?= e($item['title']) ?></h3>
                <p class="vb-card-desc"><?= e($item['business_name']) ?> · <time datetime="<?= e(str_replace(' ','T',$item['created_at']).'Z') ?>"><?= e($item['local_time']) ?></time></p>
                <details>
                    <summary class="vb-label"><?= e(__('agent.review.references')) ?></summary>
                    <div class="vb-info-row"><span class="vb-info-label"><?= e(__('agent.connections.receipt')) ?></span><code class="vb-info-value"><?= e($item['receipt_id']??'') ?></code></div>
                    <div class="vb-info-row"><span class="vb-info-label"><?= e(__('agent.connections.request')) ?></span><code class="vb-info-value"><?= e($item['request_id']??'') ?></code></div>
                </details>
            </article>
        <?php endforeach; ?>
        <?php if($page['next_cursor']): ?><a class="vb-btn vb-btn-secondary" href="/admin/connections/<?= e($page['connection']['id']) ?>/activity?cursor=<?= e(rawurlencode($page['next_cursor'])) ?>"><?= e(__('agent.pagination.next')) ?></a><?php endif; ?>
        </div>
    </section>
</div>
<?php $content=ob_get_clean();include __DIR__.'/../layout.php'; ?>
