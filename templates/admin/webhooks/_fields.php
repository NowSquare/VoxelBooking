<div class="vb-form-group"><label class="vb-label" for="<?= e($prefix) ?>-name"><?= e(__('agent-webhooks.name')) ?></label>
    <input class="vb-input" id="<?= e($prefix) ?>-name" name="name" value="<?= e($item['name']) ?>" maxlength="100" required>
</div>
<div class="vb-form-group"><label class="vb-label" for="<?= e($prefix) ?>-endpoint"><?= e(__('agent-webhooks.endpoint')) ?></label>
    <input class="vb-input" id="<?= e($prefix) ?>-endpoint" name="endpoint" type="url" inputmode="url" value="<?= e($item['endpoint']) ?>" maxlength="2048" placeholder="https://example.com/webhooks" required>
</div>
<fieldset class="vb-form-group"><legend class="vb-label"><?= e(__('agent-webhooks.events')) ?></legend>
    <?php foreach(\App\Application\Integrations\WebhookService::EVENTS as $event): ?>
        <label class="vb-checkbox-label"><input type="checkbox" name="event_types[]" value="<?= e($event) ?>"<?= in_array($event,$item['event_types'],true)?' checked':'' ?>><span><?= e(__('agent-webhooks.event_labels.'.$event)) ?></span></label>
    <?php endforeach; ?>
</fieldset>
