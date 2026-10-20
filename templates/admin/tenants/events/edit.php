<?php
/**
 * Edit event — admin page for event-pattern tenants.
 *
 * Variables: $user, $version, $csrfToken, $tenant, $tenantId, $event, $flash, $old
 */
$activePage = 'events';
ob_start();
?>

<?php if ($flash ?? null): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<?php
$pageHeader = [
    'title' => __('admin.events.edit_title'),
    'subtitle' => $event['name'] ?? '',
    'backUrl' => '/admin/tenants/' . $tenantId . '/events',
    'backLabel' => __('admin.events.title'),
];
include __DIR__ . '/../../partials/page-header.php';
?>

<div class="vb-card p-6 vb-animate-in">
    <form method="POST"
          action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/events/<?= htmlspecialchars($event['id'], ENT_QUOTES, 'UTF-8') ?>"
          enctype="multipart/form-data"
          id="event-edit-form">
        <?php include __DIR__ . '/_form.php'; ?>

        <div class="vb-form-actions mt-8">
            <button type="submit" class="vb-btn vb-btn-primary" id="btn-update-event">
                <i data-lucide="save" class="w-4 h-4"></i>
                <?= __('admin.events.edit_title') ?>
            </button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
