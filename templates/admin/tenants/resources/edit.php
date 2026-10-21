<?php
/**
 * Edit resource — tenant-scoped, resource-pattern only.
 *
 * Variables: $tenant, $tenantId, $csrfToken, $resource, $seasonalPricing, $old, $flash
 */
$tenant = $tenant ?? [];
$tenantId = $tenantId ?? '';
$resource = $resource ?? [];

ob_start();
?>

<?php
$pageHeader = [
    'title' => __('admin.resources.edit'),
    'subtitle' => $resource['name'] ?? '',
    'backUrl' => '/admin/tenants/' . $tenantId . '/resources',
    'backLabel' => __('admin.resources.title'),
];
include __DIR__ . '/../../partials/page-header.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<div class="vb-card p-6 vb-animate-in">
    <form method="POST"
          action="/admin/tenants/<?= htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8') ?>/resources/<?= htmlspecialchars($resource['id'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
          id="resource-form"
          enctype="multipart/form-data">
        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="_record_version" value="<?= e($recordVersion ?? '') ?>">

        <?php include __DIR__ . '/_form.php'; ?>

        <div class="vb-form-actions mt-8">
            <button type="submit" class="vb-btn vb-btn-primary" id="save-resource-btn">
                <i data-lucide="check" class="w-4 h-4"></i>
                <?= __('admin.common.save') ?>
            </button>
        </div>
    </form>
</div>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
