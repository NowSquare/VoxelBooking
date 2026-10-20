<?php
/**
 * Edit service page.
 */
$tenant = $tenant ?? [];
$service = $service ?? [];
$tenantId = $tenantId ?? '';

ob_start();
?>

<?php
$pageHeader = [
    'title' => __('admin.services.edit'),
    'subtitle' => $service['name'] ?? '',
    'backUrl' => '/admin/tenants/' . $tenantId . '/services',
    'backLabel' => __('admin.services.title'),
];
include __DIR__ . '/../../partials/page-header.php';
?>

<?php if ($flash): ?>
    <?php include __DIR__ . '/../../../partials/alert.php'; ?>
<?php endif; ?>

<?php
$formAction = "/admin/tenants/{$tenantId}/services/{$service['id']}";
include __DIR__ . '/_form.php';
?>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
