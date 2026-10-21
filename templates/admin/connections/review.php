<?php
ob_start();
$pageHeader=['title'=>__('agent.review.title'),'subtitle'=>__('agent.review.subtitle')];
include __DIR__.'/../partials/page-header.php';
?>
<div class="vb-card vb-card-flush" data-agent-review data-exchange-url="/agent-reviews/<?= e($id) ?>/exchange"
     data-csrf="<?= e($csrfToken) ?>" data-failed="<?= e(__('agent.review.link_failed')) ?>">
    <div class="vb-card-body">
    <?php if ($error): ?>
        <h2 class="vb-card-title"><?= e(__('agent.review.unavailable')) ?></h2>
        <p role="status" aria-live="polite" data-review-status><?= e(__($error==='review_link_required'?'agent.review.open_link':'agent.errors.'.$error)) ?></p>
        <?php if(!in_array($error,['preview_expired','version_conflict'],true)): ?><p class="vb-card-desc"><?= e(__('agent.review.try_again')) ?></p><?php endif; ?>
        <?php if($verificationUrl): ?><a class="vb-btn vb-btn-primary" href="<?= e($verificationUrl) ?>"><?= e(__('agent.review.verify')) ?></a><?php endif; ?>
        <?php if($row): ?><a class="vb-link" href="<?= $public?'/agent-reviews/':'/admin/agent-reviews/' ?><?= e($id) ?>"><?= e(__('agent.review.back')) ?></a><?php endif; ?>
    <?php elseif ($row['consumed_at'] !== null): ?>
        <h2 class="vb-card-title"><?= e(__('agent.review.completed')) ?></h2>
        <p><?= e(__('agent.review.completed_description')) ?></p>
    <?php elseif ($row['approved_at'] !== null): ?>
        <h2 class="vb-card-title" role="status"><?= e(__('agent.review.approved')) ?></h2>
        <p><?= e(__('agent.review.approved_description')) ?></p>
    <?php else: ?>
        <h2 class="vb-card-title"><?= e($operation->title) ?></h2>
        <?php if($business): ?><p class="vb-card-desc"><?= e($business['name']) ?></p><?php endif; ?>
        <p class="vb-card-desc"><?= e(__(str_contains($operation->operationId,'booking')?'agent.review.no_reservation':'agent.review.details_checked')) ?></p>
        <?php
        $review=$row['review'];
        unset($review['business_id'],$review['section'],$review['version'],$review['expected_version']);
        if(isset($review['before'],$review['after']) && is_array($review['before']) && is_array($review['after'])
            && !array_is_list($review['before']) && !array_is_list($review['after'])) {
            $changed=array_filter(array_unique([...array_keys($review['before']),...array_keys($review['after'])]),
                static fn($key)=>($review['before'][$key]??null)!==($review['after'][$key]??null));
            $review['before']=array_intersect_key($review['before'],array_flip($changed));
            $review['after']=array_intersect_key($review['after'],array_flip($changed));
        }
        $references=[];
        $render = static function(array $values) use (&$render,&$references): void {
            foreach($values as $key=>$value) {
                if(in_array($key,['version','expected_version','policy_hash','request_hash'],true)) { continue; }
                if(is_string($key) && ($key==='id'||str_ends_with($key,'_id')) && is_scalar($value)) { $references[$key]=$value;continue; }
                $label=__('agent.fields.'.$key);
                if ($label==='agent.fields.'.$key) { $label=ucfirst(str_replace('_',' ',(string)$key)); }
                if (is_array($value)) {
                    if ($value===[]) { continue; }
                    echo '<details open><summary class="vb-label">'.e($label).'</summary>';
                    $render($value);
                    echo '</details>';
                } else {
                    $display=is_bool($value)?__($value?'agent.review.yes':'agent.review.no'):($value===null?__('agent.review.not_set'):(string)$value);
                    echo '<div class="vb-info-row"><span class="vb-info-label">'.e($label).'</span><span class="vb-info-value">'.e($display).'</span></div>';
                }
            }
        };
        $render($review);
        if($references!==[]) {
            echo '<details><summary class="vb-label">'.e(__('agent.review.references')).'</summary>';
            foreach($references as $key=>$value) { echo '<div class="vb-info-row"><span class="vb-info-label">'.e(ucfirst(str_replace('_',' ',$key))).'</span><code class="vb-info-value">'.e((string)$value).'</code></div>'; }
            echo '</details>';
        }
        $reviewTimezone=$business['timezone']??'UTC';
        $expiry=(new DateTimeImmutable($row['expires_at'],new DateTimeZone('UTC')))->setTimezone(new DateTimeZone($reviewTimezone));
        $expiry=\App\Engine\Locale::time($expiry).' '.$expiry->format('T');
        ?>
        <p class="vb-card-desc"><?= e(__('agent.review.expiry',['time'=>$expiry])) ?></p>
        <form method="post" action="<?= $public?'/agent-reviews/':'/admin/agent-reviews/' ?><?= e($id) ?>">
            <input type="hidden" name="_csrf_token" value="<?= e($csrfToken) ?>">
            <?php if($row['principal_type']==='public' && !empty($row['review']['requires_consent'])): ?>
                <label class="vb-checkbox-label"><input type="checkbox" name="consent" value="1" required><span><?= e($row['review']['consent_text']) ?></span></label>
            <?php endif; ?>
            <button class="vb-btn vb-btn-primary" type="submit"><?= e(__('agent.review.approve')) ?></button>
        </form>
        <p class="vb-card-desc"><?= e(__('agent.review.decline_hint')) ?></p>
    <?php endif; ?>
    </div>
</div>
<?php if($public): ?><script type="module" src="/assets/js/agent-review.js"></script><?php endif; ?>
<?php $content=ob_get_clean();include __DIR__.'/oauth-layout.php'; ?>
