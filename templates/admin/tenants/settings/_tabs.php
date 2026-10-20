<?php
/**
 * Settings tab navigation — uses design system .vb-tabs pill component.
 *
 * Matches the operator settings tab pattern (settings-tabs.php).
 * See Visual Design §7.2.
 *
 * Variables: $tenantId, $activeTab, $tenant
 */
$tenantId  = $tenantId ?? '';
$activeTab = $activeTab ?? 'general';
$tenant    = $tenant ?? [];

$tabs = [
    'general'  => ['label' => __('admin.tenant_settings.tab_general'),  'icon' => 'building-2', 'href' => "/admin/tenants/{$tenantId}/settings"],
    'branding' => ['label' => __('admin.tenant_settings.tab_branding'), 'icon' => 'palette',    'href' => "/admin/tenants/{$tenantId}/settings/branding"],
    'bookingpage' => ['label' => __('admin.tenant_settings.tab_bookingpage'), 'icon' => 'file-text', 'href' => "/admin/tenants/{$tenantId}/settings/bookingpage"],
];

// Booking Rules tab — timeslot always, resource per Phase R.
// Capacity and event remain gated until their respective phases.
$bookingRulesPatterns = ['timeslot', 'resource'];
if (in_array($tenant['booking_pattern'] ?? '', $bookingRulesPatterns, true)) {
    $tabs['booking'] = [
        'label' => __('admin.tenant_settings.tab_booking'),
        'icon'  => 'clock',
        'href'  => "/admin/tenants/{$tenantId}/settings/booking",
    ];
}

$tabs['privacy']       = ['label' => __('admin.tenant_settings.tab_privacy'),       'icon' => 'shield-check', 'href' => "/admin/tenants/{$tenantId}/settings/privacy"];
$tabs['notifications'] = ['label' => __('admin.tenant_settings.tab_notifications'), 'icon' => 'bell',         'href' => "/admin/tenants/{$tenantId}/settings/notifications"];
$tabs['emails']        = ['label' => __('admin.tenant_settings.tab_emails'),        'icon' => 'mail',         'href' => "/admin/tenants/{$tenantId}/settings/emails"];
$tabs['embed']         = ['label' => __('admin.tenant_settings.tab_embed'),         'icon' => 'code-2',       'href' => "/admin/tenants/{$tenantId}/settings/embed"];
?>

<nav class="vb-tabs" aria-label="<?= __('admin.tenant_settings.title') ?>">
    <?php foreach ($tabs as $key => $tab): ?>
    <a href="<?= htmlspecialchars($tab['href'], ENT_QUOTES, 'UTF-8') ?>"
       class="vb-tab <?= $activeTab === $key ? 'active' : '' ?>" <?= $activeTab === $key ? 'aria-current="page"' : '' ?>
       id="tab-<?= $key ?>">
        <i data-lucide="<?= $tab['icon'] ?>"></i>
        <?= $tab['label'] ?>
    </a>
    <?php endforeach; ?>
</nav>
