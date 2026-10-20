<?php
/**
 * Shared admin page identity and navigation.
 * $pageHeader: title, optional subtitle, backUrl/backLabel, and actions.
 * Text and URLs are escaped here. Actions contain trusted template markup.
 * Pages retain their existing role, CSRF and demo guards around that markup.
 */
?>
<div class="vb-page-header">
    <div class="vb-page-heading">
        <?php if (!empty($pageHeader['backUrl'])): ?>
        <a href="<?= htmlspecialchars($pageHeader['backUrl'], ENT_QUOTES, 'UTF-8') ?>" class="vb-back-link">
            <i data-lucide="chevron-left" aria-hidden="true"></i>
            <?= htmlspecialchars($pageHeader['backLabel'], ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php endif; ?>
        <h2 class="vb-page-title"><?= htmlspecialchars($pageHeader['title'], ENT_QUOTES, 'UTF-8') ?></h2>
        <?php if (($pageHeader['subtitle'] ?? '') !== ''): ?>
        <p class="vb-page-subtitle"><?= htmlspecialchars($pageHeader['subtitle'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
    </div>
    <?php if (!empty($pageHeader['actions'])): ?>
    <div class="vb-page-actions"><?= $pageHeader['actions'] ?></div>
    <?php endif; ?>
</div>
<?php unset($pageHeader); ?>
