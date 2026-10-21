<?php
ob_start();
$pageHeader=['title'=>__('agent.connections.title'),'subtitle'=>__('agent.connections.subtitle')];
include __DIR__.'/../partials/page-header.php';
?>
<?php if($flash): include __DIR__.'/../../partials/alert.php'; endif; ?>
<div class="vb-agent-panel space-y-6">
<?php if($secret): ?>
<section class="vb-card vb-card-flush" role="status">
    <div class="vb-card-header"><h3 class="vb-card-title"><?= e(__('agent.connections.secret_title')) ?></h3></div>
    <div class="vb-card-body space-y-4">
        <p><?= e(__('agent.connections.secret_description')) ?></p>
        <label class="vb-label" for="connection-secret"><?= e(__('agent.connections.token')) ?></label>
        <input class="vb-input" id="connection-secret" value="<?= e($secret['token']) ?>" readonly spellcheck="false" autocomplete="off">
        <p class="vb-card-desc"><?= e(__('agent.connections.endpoint')) ?> <code><?= e($secret['audience']) ?></code></p>
    </div>
</section>
<?php endif; ?>
<section class="vb-card vb-card-flush">
    <div class="vb-card-header"><h3 class="vb-card-title"><?= e(__('agent.connections.client_title')) ?></h3></div>
    <div class="vb-card-body space-y-4">
        <p><?= e(__('agent.connections.client_description')) ?></p>
        <label class="vb-label" for="mcp-url"><?= e(__('agent.connections.mcp_url')) ?></label>
        <input class="vb-input" id="mcp-url" value="<?= e($mcpUrl) ?>" readonly>
        <a class="vb-link" href="https://voxelbooking.com/docs/agent-api/connect-your-client" target="_blank" rel="noopener noreferrer"><?= e(__('agent.connections.guide')) ?></a>
    </div>
</section>
<section class="vb-card vb-card-flush">
    <div class="vb-card-header"><h3 class="vb-card-title"><?= e(__('agent.connections.connected')) ?></h3></div>
    <div class="vb-card-body">
    <?php if($connections===[]): ?><p class="vb-card-desc"><?= e(__('agent.connections.empty')) ?></p><?php endif; ?>
    <?php foreach($connections as $connection): ?>
        <article class="vb-form-group space-y-2">
            <div class="vb-info-row"><h4 class="vb-card-title"><?= e($connection['name']) ?></h4><span class="vb-badge"><?= e(__('agent.connections.states.'.$connection['state'])) ?></span></div>
            <p class="vb-card-desc"><?= e(__('agent.connections.expires',['time'=>$connection['expires_at'].' UTC'])) ?></p>
            <details><summary class="vb-label"><?= e(__('agent.connections.access_details')) ?></summary>
                <p><?= e($connection['purpose']) ?></p>
                <?php $grantedBusinesses=array_filter($businesses,static fn($business)=>in_array($business['id'],$connection['business_ids'],true)); ?>
                <?php if($grantedBusinesses): ?><p><span class="vb-label"><?= e(__('agent.connections.businesses')) ?></span> <?= e(implode(', ',array_column($grantedBusinesses,'name'))) ?></p><?php endif; ?>
                <ul><?php foreach($connection['scopes'] as $scope): ?><li><code><?= e($scope) ?></code></li><?php endforeach; ?></ul>
                <p class="vb-card-desc"><?= e(__('agent.connections.last_used',['time'=>$connection['last_used_at']??__('agent.connections.never')])) ?></p>
            </details>
            <a class="vb-link" href="/admin/connections/<?= e($connection['id']) ?>/activity"><?= e(__('agent.connections.activity')) ?></a>
            <?php if($connection['state']==='active'): ?>
            <div class="vb-form-actions">
                <form method="post" action="/admin/connections/<?= e($connection['id']) ?>/revoke">
                    <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
                    <button class="vb-btn vb-btn-secondary" type="submit"><?= e(__('agent.connections.revoke')) ?></button>
                </form>
                <?php if($connection['client_id']===null): ?>
                <form method="post" action="/admin/connections/<?= e($connection['id']) ?>/rotate">
                    <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
                    <button class="vb-btn vb-btn-secondary" type="submit"><?= e(__('agent.connections.rotate')) ?></button>
                </form>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </article>
    <?php endforeach; ?>
    <?php if($nextCursor): ?><a class="vb-btn vb-btn-secondary" href="/admin/connections?cursor=<?= e(rawurlencode($nextCursor)) ?>"><?= e(__('agent.pagination.next')) ?></a><?php endif; ?>
    </div>
</section>
<details class="vb-card vb-card-flush">
    <summary class="vb-card-header"><span class="vb-card-title"><?= e(__('agent.connections.manual_title')) ?></span></summary>
    <form method="post" action="/admin/connections" class="vb-card-body space-y-5">
        <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
        <p class="vb-card-desc"><?= e(__('agent.connections.manual_description')) ?></p>
        <div class="vb-form-group"><label class="vb-label" for="connection-name"><?= e(__('agent.connections.name')) ?></label>
            <input class="vb-input" id="connection-name" name="name" maxlength="120" placeholder="<?= e(__('agent.connections.default_name')) ?>">
        </div>
        <fieldset class="vb-form-group"><legend class="vb-label"><?= e(__('agent.connections.access')) ?></legend>
            <?php foreach($presets as $id=>$preset): ?>
                <label class="vb-checkbox-label"><input type="radio" name="preset" value="<?= e($id) ?>"<?= $id===array_key_first($presets)?' checked':'' ?>><span><?= e($preset['title']) ?><span class="vb-card-desc"> · <?= e($preset['description']) ?></span></span></label>
            <?php endforeach; ?>
        </fieldset>
        <?php if(count($businesses)>1): ?>
        <fieldset class="vb-form-group"><legend class="vb-label"><?= e(__('agent.connections.businesses')) ?></legend>
            <?php foreach($businesses as $business): ?><label class="vb-checkbox-label"><input type="checkbox" name="business_ids[]" value="<?= e($business['id']) ?>"><span><?= e($business['name']) ?></span></label><?php endforeach; ?>
        </fieldset>
        <?php endif; ?>
        <div class="vb-form-group"><label class="vb-label" for="connection-transport"><?= e(__('agent.connections.usage')) ?></label>
            <select class="vb-select" id="connection-transport" name="transport"><option value="http"><?= e(__('agent.connections.http')) ?></option><option value="mcp"><?= e(__('agent.connections.mcp')) ?></option></select>
        </div>
        <div class="vb-form-group"><label class="vb-label" for="connection-days"><?= e(__('agent.connections.duration')) ?></label>
            <select class="vb-select" id="connection-days" name="days"><?php foreach([7,30,90] as $days): ?><option value="<?= $days ?>"<?= $days===30?' selected':'' ?>><?= e(__('agent.connections.days',['days'=>$days])) ?></option><?php endforeach; ?></select>
        </div>
        <div class="vb-form-actions"><button type="submit" class="vb-btn vb-btn-primary"><?= e(__('agent.connections.create')) ?></button></div>
    </form>
</details>
</div>
<?php $content=ob_get_clean();include __DIR__.'/../layout.php'; ?>
