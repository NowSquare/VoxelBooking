<?php
/**
 * Admin shell layout — Visual Design §16: Admin Shell Composition.
 *
 * Sidebar (248px) + glass topbar (52px) + content area.
 * All admin pages extend this layout via ob_start() + $content.
 *
 * Icons: Lucide via data-lucide (rendered by admin/app.js createIcons).
 * State: Alpine.js (CSP build) — x-data="adminShell" registered in app.js.
 *        All @click handlers reference method names (no inline JS expressions).
 *        Sidebar class toggling done via $refs in JS (CSP-safe).
 * Styles: admin.css (Tailwind 4) + admin-head.php (design tokens).
 *
 * Variables: $user, $version, $pageTitle, $content (HTML), $activePage, $csrfToken
 */
$user = $user ?? [];
$version = $version ?? '0.0.0';
$pageTitle = $pageTitle ?? __('admin.layout.default_title');
$activePage = $activePage ?? 'dashboard';
$csrfToken = $csrfToken ?? '';

$isImpersonating = \App\Engine\Auth::isImpersonating();
$impersonatedTenantId = $isImpersonating ? \App\Engine\Auth::impersonatedTenantId() : null;
$impersonatedTenantName = $isImpersonating ? \App\Engine\Auth::impersonatedTenantName() : null;

$operatorName = htmlspecialchars($user['name'] ?? __('admin.layout.operator'), ENT_QUOTES, 'UTF-8');
$operatorInitials = mb_strtoupper(mb_substr($operatorName, 0, 1));
?>
<!DOCTYPE html>
<html lang="<?= \App\Engine\Locale::getLocale() ?>" dir="<?= \App\Engine\Locale::direction() ?>">
<head>
    <script>
    // Theme bootstrap: must run before CSS paints to prevent FOUC.
    // Reads stored preference, falls back to system prefers-color-scheme.
    (function(){
        var t = localStorage.getItem('vb-theme');
        if (!t) t = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', t);
    })();
    </script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($documentTitle ?? $pageTitle, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></title>
    <?php include dirname(__DIR__) . '/partials/admin-head.php'; ?>
    <style>
        /* Critical reset — prevents layout shift before external CSS loads */
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        html { height: 100%; overflow: hidden; }
        body { margin: 0; padding: 0; }
        .vb-main { scrollbar-gutter: stable; scrollbar-width: thin; }
        [x-cloak] { display: none !important; }
    </style>
    <link rel="stylesheet" href="/assets/css/admin-css.css?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/css/admin-css.css') ?>">
</head>
<body x-data="adminShell">

    <!-- Mobile overlay: hidden until Alpine init via x-cloak -->
    <div class="vb-sidebar-overlay"
         x-show="sidebarOpen"
         x-transition:enter="vb-overlay-enter"
         x-transition:enter-start="vb-overlay-enter-start"
         x-transition:enter-end="vb-overlay-enter-end"
         x-transition:leave="vb-overlay-leave"
         x-transition:leave-start="vb-overlay-leave-start"
         x-transition:leave-end="vb-overlay-leave-end"
         @click="closeSidebar"
         x-cloak></div>



    <!-- Shell: sidebar + main -->
    <div class="vb-shell">

    <!-- Sidebar -->
    <aside class="vb-sidebar" x-ref="sidebar">
        <a href="/admin" class="vb-sidebar-brand">
            <svg class="vb-sidebar-logo" width="24" height="26" viewBox="0 0 48 52" xmlns="http://www.w3.org/2000/svg">
                <polygon points="24,2 46,14 24,26 2,14" fill="currentColor" opacity="1.0"/>
                <polygon points="2,14 24,26 24,50 2,38" fill="currentColor" opacity="0.7"/>
                <polygon points="46,14 24,26 24,50 46,38" fill="currentColor" opacity="0.4"/>
            </svg>
            <span class="vb-sidebar-name"><?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></span>
        </a>

        <nav class="vb-sidebar-nav">
                <?php
                // Determine if we're in a tenant context
                $sidebarTenantId = $impersonatedTenantId ?? ($tenantId ?? ($_SESSION['auth_tenant_id'] ?? null));
                $inTenantContext = $sidebarTenantId !== null && $sidebarTenantId !== '';
                $canManage = \App\Engine\Auth::isOperator() || \App\Engine\Auth::isOwner();

                // Resolve tenant booking pattern for pattern-specific nav
                $tenantPattern = null;
                if ($inTenantContext) {
                    $tenantPattern = $tenant['booking_pattern']
                        ?? (\App\Engine\Database::query(
                            'SELECT `booking_pattern` FROM `tenants` WHERE `id` = ? LIMIT 1',
                            [$sidebarTenantId]
                        )[0]['booking_pattern'] ?? null);
                }
                $isTimeslot = $tenantPattern === 'timeslot';
                ?>

                <?php if ($inTenantContext): ?>
                <?php if (\App\Engine\Auth::isOperator() && !$isImpersonating): ?>
                <a href="/admin/tenants" class="vb-sidebar-link vb-sidebar-back">
                    <i data-lucide="chevron-left"></i>
                    <?= __('admin.nav.all_tenants') ?>
                </a>
                <?php endif; ?>

                <!-- Overview -->
                <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>"
                   class="vb-sidebar-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">
                    <i data-lucide="layout-dashboard"></i>
                    <?= __('admin.nav.dashboard') ?>
                </a>
                <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/bookings"
                   class="vb-sidebar-link <?= $activePage === 'bookings' ? 'active' : '' ?>">
                    <i data-lucide="list"></i>
                    <?= __('admin.nav.bookings') ?>
                </a>
                <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/calendar"
                   class="vb-sidebar-link <?= $activePage === 'calendar' ? 'active' : '' ?>">
                    <i data-lucide="calendar-days"></i>
                    <?= __('admin.nav.calendar') ?>
                </a>

                <!-- Scheduling -->
                <div class="vb-sidebar-section">
                    <div class="vb-sidebar-section-label"><?= __('admin.nav.section_scheduling') ?></div>
                    <?php if ($canManage && $isTimeslot): ?>
                    <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/services"
                       class="vb-sidebar-link <?= $activePage === 'services' ? 'active' : '' ?>">
                        <i data-lucide="layers"></i>
                        <?= __('admin.nav.services') ?>
                    </a>
                    <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/staff"
                       class="vb-sidebar-link <?= $activePage === 'staff' ? 'active' : '' ?>">
                        <i data-lucide="users"></i>
                        <?= __('admin.nav.staff') ?>
                    </a>
                    <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/availability"
                       class="vb-sidebar-link <?= $activePage === 'availability' ? 'active' : '' ?>">
                        <i data-lucide="clock"></i>
                        <?= __('admin.nav.availability') ?>
                    </a>
                    <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/blocked-dates"
                       class="vb-sidebar-link <?= $activePage === 'blocked-dates' ? 'active' : '' ?>">
                        <i data-lucide="calendar-x"></i>
                        <?= __('admin.nav.blocked_dates') ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($canManage && $tenantPattern === 'resource'): ?>
                    <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/resources"
                       class="vb-sidebar-link <?= $activePage === 'resources' ? 'active' : '' ?>">
                        <i data-lucide="bed"></i>
                        <?= __('admin.nav.resources') ?>
                    </a>
                    <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/blocked-dates"
                       class="vb-sidebar-link <?= $activePage === 'blocked-dates' ? 'active' : '' ?>">
                        <i data-lucide="calendar-x"></i>
                        <?= __('admin.nav.blocked_dates') ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($canManage && $tenantPattern === 'capacity'): ?>
                    <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/capacity-slots"
                       class="vb-sidebar-link <?= $activePage === 'capacity-slots' ? 'active' : '' ?>">
                        <i data-lucide="layout-grid"></i>
                        <?= __('admin.nav.capacity_slots') ?>
                    </a>
                    <?php endif; ?>
                    <?php if ($canManage && $tenantPattern === 'event'): ?>
                    <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/events"
                       class="vb-sidebar-link <?= $activePage === 'events' ? 'active' : '' ?>">
                        <i data-lucide="ticket"></i>
                        <?= __('admin.nav.events') ?>
                    </a>
                    <?php endif; ?>
                </div>

                <!-- People -->
                <div class="vb-sidebar-section">
                    <div class="vb-sidebar-section-label"><?= __('admin.nav.section_people') ?></div>
                    <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/customers"
                       class="vb-sidebar-link <?= $activePage === 'customers' ? 'active' : '' ?>">
                        <i data-lucide="contact"></i>
                        <?= __('admin.nav.customers') ?>
                    </a>
                    <?php if ($canManage): ?>
                    <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/users"
                       class="vb-sidebar-link <?= $activePage === 'users' ? 'active' : '' ?>">
                        <i data-lucide="user-cog"></i>
                        <?= __('admin.nav.team') ?>
                    </a>
                    <?php endif; ?>
                </div>

                <!-- System -->
                <?php if ($canManage): ?>
                <div class="vb-sidebar-section">
                    <div class="vb-sidebar-section-label"><?= __('admin.nav.section_system') ?></div>
                    <?php
                    $tenantSlug = \App\Engine\Database::query(
                        'SELECT `slug` FROM `tenants` WHERE `id` = ? LIMIT 1',
                        [$sidebarTenantId]
                    )[0]['slug'] ?? '';
                    ?>
                    <a href="/book/<?= htmlspecialchars($tenantSlug, ENT_QUOTES, 'UTF-8') ?>"
                       target="_blank" rel="noopener"
                       class="vb-sidebar-link vb-sidebar-external <?= $activePage === 'booking-page' ? 'active' : '' ?>">
                        <i data-lucide="external-link"></i>
                        <?= __('admin.nav.booking_page') ?>
                    </a>
                    <a href="/admin/tenants/<?= htmlspecialchars($sidebarTenantId, ENT_QUOTES, 'UTF-8') ?>/settings"
                       class="vb-sidebar-link <?= $activePage === 'settings' ? 'active' : '' ?>">
                        <i data-lucide="settings"></i>
                        <?= __('admin.nav.settings') ?>
                    </a>
                </div>
                <?php endif; ?>

                <?php elseif (\App\Engine\Auth::isOperator()): ?>
                <a href="/admin" class="vb-sidebar-link <?= $activePage === 'dashboard' ? 'active' : '' ?>">
                    <i data-lucide="layout-dashboard"></i>
                    <?= __('admin.nav.dashboard') ?>
                </a>
                <a href="/admin/tenants" class="vb-sidebar-link <?= $activePage === 'tenants' ? 'active' : '' ?>">
                    <i data-lucide="building-2"></i>
                    <?= __('admin.nav.tenants') ?>
                </a>
                <a href="/admin/bookings" class="vb-sidebar-link <?= $activePage === 'bookings' ? 'active' : '' ?>">
                    <i data-lucide="calendar"></i>
                    <?= __('admin.nav.all_bookings') ?>
                </a>
                <?php
                $appsEnabled = (\App\Engine\Database::query(
                    "SELECT `value` FROM `settings` WHERE `key` = 'enable_applications' LIMIT 1"
                )[0]['value'] ?? '0') === '1';
                if ($appsEnabled): ?>
                <a href="/admin/applications" class="vb-sidebar-link <?= $activePage === 'applications' ? 'active' : '' ?>">
                    <i data-lucide="inbox"></i>
                    <?= __('admin.nav.applications') ?>
                    <?php
                    $pendingAppCount = \App\Engine\Database::query(
                        "SELECT COUNT(*) AS `cnt` FROM `business_applications` WHERE `status` = 'pending'"
                    )[0]['cnt'] ?? 0;
                    if ((int) $pendingAppCount > 0): ?>
                    <span class="vb-badge vb-badge-warning" style="margin-left: auto;"><?= $pendingAppCount ?></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
                <?php endif; ?>
            <?php if (\App\Engine\Auth::isOperator() && !$isImpersonating): ?>
            <div class="vb-sidebar-section">
                <div class="vb-sidebar-section-label"><?= __('admin.nav.system') ?></div>
                <a href="/admin/settings" class="vb-sidebar-link <?= str_starts_with($activePage, 'settings') ? 'active' : '' ?>">
                    <i data-lucide="settings"></i>
                    <?= __('admin.nav.settings') ?>
                </a>
                <a href="/admin/deletion-queue" class="vb-sidebar-link <?= $activePage === 'deletion-queue' ? 'active' : '' ?>">
                    <i data-lucide="shield"></i>
                    <?= __('admin.nav.deletion_queue') ?>
                </a>
                <a href="/admin/updates" class="vb-sidebar-link <?= $activePage === 'updates' ? 'active' : '' ?>">
                    <i data-lucide="download"></i>
                    <?= __('admin.nav.updates') ?>
                </a>
            </div>
            <?php endif; ?>
        </nav>

        <div class="vb-sidebar-footer">
            <?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?> v<?= htmlspecialchars($version, ENT_QUOTES, 'UTF-8') ?>
        </div>
    </aside>

    <!-- Main -->
    <div class="vb-main">
        <header class="vb-topbar">
            <div class="vb-topbar-left">
                <button type="button"
                        class="vb-hamburger"
                        @click="toggleSidebar"
                        aria-label="<?= __('admin.nav.toggle_sidebar') ?>">
                    <i data-lucide="menu"></i>
                </button>
                <h1 class="vb-topbar-title"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
            </div>
            <div class="vb-topbar-right">
                <?php if (\App\Engine\DemoMode::isActive()): ?>
                <div class="vb-demo-pill" id="vb-demo-pill">
                    <i data-lucide="flask-conical" class="vb-demo-pill-icon"></i>
                    <span class="vb-demo-pill-text"><?= __('admin.demo.banner_title') ?></span>
                    <button type="button" class="vb-demo-pill-close" id="vb-demo-pill-close"
                            title="<?= __('admin.common.dismiss') ?>">
                        <i data-lucide="x"></i>
                    </button>
                </div>
                <?php endif; ?>
                <?php if ($isImpersonating): ?>
                <div class="vb-impersonation-pill">
                    <i data-lucide="eye" class="vb-impersonation-pill-icon"></i>
                    <span class="vb-impersonation-pill-text">
                        <?= __('admin.impersonation.banner_prefix') ?>
                        <strong><?= htmlspecialchars($impersonatedTenantName ?? '', ENT_QUOTES, 'UTF-8') ?></strong>
                    </span>
                    <form method="POST" action="/admin/impersonate/exit" class="vb-form-flush">
                        <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="vb-impersonation-exit-btn" title="<?= __('admin.impersonation.exit') ?>">
                            <i data-lucide="x"></i>
                        </button>
                    </form>
                </div>
                <?php endif; ?>
                <div class="vb-profile-menu" x-ref="profileMenu">
                    <button type="button"
                            class="vb-profile-trigger"
                            @click="toggleProfile"
                            aria-haspopup="true"
                            :aria-expanded="profileOpen"
                            aria-label="<?= __('admin.nav.profile_menu') ?>">
                        <span class="vb-avatar vb-avatar-sm"><?= $operatorInitials ?></span>
                        <span class="vb-profile-trigger-name"><?= htmlspecialchars($operatorName, ENT_QUOTES, 'UTF-8') ?></span>
                        <i data-lucide="chevron-down" class="vb-profile-chevron" :class="profileOpen && 'is-open'"></i>
                    </button>

                    <div class="vb-profile-dropdown" x-show="profileOpen"
                         x-transition:enter="vb-dropdown-enter"
                         x-transition:enter-start="vb-dropdown-enter-start"
                         x-transition:enter-end="vb-dropdown-enter-end"
                         x-transition:leave="vb-dropdown-leave"
                         x-transition:leave-start="vb-dropdown-leave-start"
                         x-transition:leave-end="vb-dropdown-leave-end"
                         x-cloak>
                        <div class="vb-profile-dropdown-header">
                            <span class="vb-avatar"><?= $operatorInitials ?></span>
                            <div>
                                <div class="vb-profile-dropdown-name"><?= $operatorName ?></div>
                                <div class="vb-profile-dropdown-role"><?= htmlspecialchars(ucfirst($user['type'] ?? 'operator'), ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                        <div class="vb-profile-dropdown-sep"></div>
                        <div class="vb-profile-dropdown-group">
                            <button type="button" class="vb-profile-dropdown-item" @click="switchTheme">
                                <span class="vb-profile-dropdown-icon">
                                    <i data-lucide="sun" class="icon-sun"></i>
                                    <i data-lucide="moon" class="icon-moon"></i>
                                </span>
                                <span class="vb-profile-dropdown-label"><?= __('admin.nav.toggle_theme') ?></span>
                                <span class="vb-profile-dropdown-hint icon-sun"><?= __('admin.nav.theme_light') ?></span>
                                <span class="vb-profile-dropdown-hint icon-moon"><?= __('admin.nav.theme_dark') ?></span>
                            </button>
                            <?php if (!$isImpersonating): ?>
                            <a href="/admin/account" class="vb-profile-dropdown-item" @click="closeProfile">
                                <span class="vb-profile-dropdown-icon">
                                    <i data-lucide="user-cog"></i>
                                </span>
                                <span class="vb-profile-dropdown-label"><?= __('admin.nav.account') ?></span>
                            </a>
                            <?php endif; ?>
                        </div>
                        <div class="vb-profile-dropdown-sep"></div>
                        <div class="vb-profile-dropdown-group">
                            <form method="POST" action="/auth/logout" class="vb-form-flush">
                                <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="vb-profile-dropdown-item vb-profile-dropdown-danger">
                                    <span class="vb-profile-dropdown-icon">
                                        <i data-lucide="log-out"></i>
                                    </span>
                                    <span class="vb-profile-dropdown-label"><?= __('admin.nav.sign_out') ?></span>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </header>



        <main class="vb-content">
            <?= $content ?? '' ?>
        </main>
    </div>
    </div><!-- /.vb-shell -->

    <?php /* Modal slot: rendered at body level, outside .vb-content transform container */ ?>
    <?= $modals ?? '' ?>

    <!-- Global System Confirm Modal (vanilla JS, CSP-safe) -->
    <div id="vb-confirm-overlay" class="vb-modal-overlay" style="display: none;">
        <div class="vb-modal vb-modal-sm">
            <div class="vb-modal-header">
                <h3 class="vb-modal-title"><?= __('admin.confirm.title') ?? 'Confirm Action' ?></h3>
                <button type="button" class="vb-modal-close" data-confirm-close aria-label="Close">
                    <i data-lucide="x"></i>
                </button>
            </div>
            <div class="vb-modal-body">
                <p class="vb-modal-desc" data-confirm-message></p>
            </div>
            <div class="vb-modal-footer">
                <button type="button" class="vb-btn vb-btn-secondary" data-confirm-cancel>
                    <?= __('admin.settings.cancel_button') ?? 'Cancel' ?>
                </button>
                <button type="button" class="vb-btn vb-btn-destructive" data-confirm-ok>
                    <span data-confirm-btn-text><?= __('admin.confirm.ok') ?></span>
                </button>
            </div>
        </div>
    </div>

    <?php if (\App\Engine\DemoMode::isActive()): ?>
    <script>window.VB_DEMO = true;</script>
    <span data-demo-toast style="display:none"><?= __('admin.demo.toast_message') ?></span>
    <?php endif; ?>
    <script src="/assets/js/admin.js" type="module"></script>
</body>
</html>
