<?php
/**
 * Operator dashboard — Visual Design §16: Dashboard Specification.
 *
 * Four rhythm bands:
 *   1. Greeting (personalized hero, time-of-day greeting)
 *   2. Metrics (4 metric cards with deltas — redesigned with header+value+delta+context)
 *   3. Upcoming bookings (cross-tenant, max 10, with status chips)
 *   4. Activity / Empty (onboarding CTA pointing to tenant creation)
 *
 * Icons: Lucide via data-lucide (rendered by admin/app.js).
 * Styles: admin-head.php design tokens — .vb-metric, .vb-status, .vb-cell-*.
 *
 * Variables: $user, $version, $activeTenants, $tenantCounts, $todayBookings, $weekBookings,
 *            $upcoming24h, $deltaToday, $deltaWeek, $upcoming
 */
$pageTitle = __('admin.dashboard.title');
$activePage = 'dashboard';
$csrfToken = \App\Middleware\CsrfMiddleware::generateToken();

// Time-of-day greeting
$hour = (int) date('H');
if ($hour < 12) {
    $greeting = __('admin.dashboard.good_morning');
} elseif ($hour < 18) {
    $greeting = __('admin.dashboard.good_afternoon');
} else {
    $greeting = __('admin.dashboard.good_evening');
}
$userName = htmlspecialchars($user['name'] ?? __('admin.layout.operator'), ENT_QUOTES, 'UTF-8');

ob_start();
?>

<!-- Greeting Band -->
<div class="vb-greeting vb-fade-in-up stagger-1">
    <div class="vb-greeting-title"><?= $greeting ?>, <?= $userName ?></div>
    <div class="vb-greeting-subtitle"><?= __('admin.dashboard.greeting_subtitle', ['app_name' => app_name()]) ?></div>
</div>

<!-- Metric Band — redesigned with header + delta badges -->
<div class="vb-metrics">
    <div class="vb-metric vb-fade-in-up stagger-1">
        <div class="vb-metric-header">
            <i data-lucide="building-2" class="vb-metric-icon"></i>
            <span class="vb-metric-label"><?= __('admin.dashboard.active_tenants') ?></span>
        </div>
        <div class="vb-metric-value-row">
            <span class="vb-metric-value"><?= (int) ($activeTenants ?? 0) ?></span>
        </div>
        <div class="vb-metric-accent"></div>
    </div>
    <div class="vb-metric vb-fade-in-up stagger-2">
        <div class="vb-metric-header">
            <i data-lucide="calendar-check" class="vb-metric-icon"></i>
            <span class="vb-metric-label"><?= __('admin.dashboard.bookings_today') ?></span>
        </div>
        <div class="vb-metric-value-row">
            <span class="vb-metric-value"><?= (int) ($todayBookings ?? 0) ?></span>
            <?php if (($deltaToday ?? 0) !== 0): ?>
            <span class="vb-metric-delta <?= $deltaToday > 0 ? 'is-up' : 'is-down' ?>">
                <i data-lucide="<?= $deltaToday > 0 ? 'trending-up' : 'trending-down' ?>"></i>
                <?= abs($deltaToday) ?>
            </span>
            <?php endif; ?>
        </div>
        <?php if (($deltaToday ?? 0) !== 0): ?>
        <div class="vb-metric-context"><?= __('admin.dashboard.vs_last_period') ?></div>
        <?php endif; ?>
        <div class="vb-metric-accent"></div>
    </div>
    <div class="vb-metric vb-fade-in-up stagger-3">
        <div class="vb-metric-header">
            <i data-lucide="bar-chart-3" class="vb-metric-icon"></i>
            <span class="vb-metric-label"><?= __('admin.dashboard.this_week') ?></span>
        </div>
        <div class="vb-metric-value-row">
            <span class="vb-metric-value"><?= (int) ($weekBookings ?? 0) ?></span>
            <?php if (($deltaWeek ?? 0) !== 0): ?>
            <span class="vb-metric-delta <?= $deltaWeek > 0 ? 'is-up' : 'is-down' ?>">
                <i data-lucide="<?= $deltaWeek > 0 ? 'trending-up' : 'trending-down' ?>"></i>
                <?= abs($deltaWeek) ?>
            </span>
            <?php endif; ?>
        </div>
        <?php if (($deltaWeek ?? 0) !== 0): ?>
        <div class="vb-metric-context"><?= __('admin.dashboard.vs_last_period') ?></div>
        <?php endif; ?>
        <div class="vb-metric-accent"></div>
    </div>
    <div class="vb-metric vb-fade-in-up stagger-4">
        <div class="vb-metric-header">
            <i data-lucide="clock" class="vb-metric-icon"></i>
            <span class="vb-metric-label"><?= __('admin.dashboard.upcoming_24h') ?></span>
        </div>
        <div class="vb-metric-value-row">
            <span class="vb-metric-value"><?= (int) ($upcoming24h ?? 0) ?></span>
        </div>
        <div class="vb-metric-accent"></div>
    </div>
</div>

<!-- Upcoming Bookings (cross-tenant) -->
<?php if (!empty($upcoming)): ?>
<div class="vb-table-container vb-fade-in-up stagger-5 vb-mb-lg">
    <div class="vb-table-toolbar">
        <div class="vb-table-toolbar-title"><?= __('admin.dashboard.upcoming') ?></div>
        <a href="/admin/bookings" class="vb-btn vb-btn-ghost vb-btn-sm">
            <?= __('admin.dashboard.view_all') ?>
            <i data-lucide="arrow-right"></i>
        </a>
    </div>
    <div class="vb-table-wrap">
        <table class="vb-table">
            <thead>
                <tr>
                    <th><?= __('admin.bookings.customer') ?></th>
                    <th><?= __('admin.bookings.service') ?></th>
                    <th><?= __('admin.bookings.date_time') ?></th>
                    <th><?= __('admin.bookings.status') ?></th>
                    <th><?= __('admin.bookings.tenant') ?></th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($upcoming as $i => $b): ?>
                <tr class="vb-fade-in-up stagger-<?= min($i + 1, 6) ?>">
                    <td>
                        <?php if (!empty($b['customer_id']) && !empty($b['tenant_id'])): ?>
                        <form method="POST" action="/admin/tenants/<?= htmlspecialchars($b['tenant_id'], ENT_QUOTES, 'UTF-8') ?>/impersonate" class="vb-inline-form">
                            <input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                            <input type="hidden" name="redirect_to" value="/admin/tenants/<?= htmlspecialchars($b['tenant_id'], ENT_QUOTES, 'UTF-8') ?>/customers/<?= htmlspecialchars($b['customer_id'], ENT_QUOTES, 'UTF-8') ?>">
                            <button type="submit" class="vb-cell-link vb-btn-reset">
                                <div class="vb-cell-primary"><?= htmlspecialchars($b['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="vb-cell-secondary"><?= htmlspecialchars($b['customer_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                            </button>
                        </form>
                        <?php else: ?>
                        <div class="vb-cell-primary"><?= htmlspecialchars($b['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="vb-cell-secondary"><?= htmlspecialchars($b['customer_email'] ?? '', ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars(booking_display_label($b), ENT_QUOTES, 'UTF-8') ?></td>
                    <td>
                        <div class="vb-cell-primary"><?= \App\Engine\Locale::date(new \DateTimeImmutable($b['start_datetime'])) ?></div>
                        <div class="vb-cell-secondary"><?= date('H:i', strtotime($b['start_datetime'])) ?> – <?= date('H:i', strtotime($b['end_datetime'])) ?></div>
                    </td>
                    <td>
                        <span class="vb-status vb-status-<?= htmlspecialchars($b['status'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= __('admin.bookings.status_' . $b['status']) ?>
                        </span>
                    </td>
                    <td>
                        <span class="vb-cell-secondary"><?= htmlspecialchars($b['tenant_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></span>
                    </td>
                    <td class="vb-text-right">
                        <a href="/admin/bookings/<?= htmlspecialchars($b['id'], ENT_QUOTES, 'UTF-8') ?>"
                           class="vb-btn vb-btn-ghost vb-btn-sm" title="<?= __('admin.bookings.view') ?>">
                            <i data-lucide="eye" class="vb-icon-sm"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<!-- Activity Band / Empty State -->
<?php if (($tenantCounts['total'] ?? 0) === 0): ?>
<div class="vb-empty vb-fade-in-up stagger-5">
    <i data-lucide="building-2" class="vb-empty-icon"></i>
    <div>
        <div class="vb-empty-title"><?= __('admin.dashboard.welcome_title', ['app_name' => app_name()]) ?></div>
        <div class="vb-empty-desc">
            <?= __('admin.dashboard.welcome_desc') ?>
        </div>
        <a href="/admin/tenants/create" class="vb-btn vb-btn-primary vb-btn-lg">
            <i data-lucide="plus"></i>
            <?= __('admin.dashboard.create_first_tenant') ?>
        </a>
        <div class="vb-empty-steps">
            <div class="vb-empty-step">
                <span class="vb-empty-step-num">1</span>
                <?= __('admin.dashboard.step_create') ?>
            </div>
            <i data-lucide="chevron-right" class="vb-empty-step-arrow"></i>
            <div class="vb-empty-step">
                <span class="vb-empty-step-num">2</span>
                <?= __('admin.dashboard.step_configure') ?>
            </div>
            <i data-lucide="chevron-right" class="vb-empty-step-arrow"></i>
            <div class="vb-empty-step">
                <span class="vb-empty-step-num">3</span>
                <?= __('admin.dashboard.step_share') ?>
            </div>
        </div>
    </div>
</div>
<?php else: ?>
<!-- Quick Actions — action rail pattern -->
<div class="vb-card vb-card-flush vb-fade-in-up stagger-5">
    <nav class="vb-action-rail">
        <div class="vb-action-rail-title"><?= __('admin.common.actions') ?></div>
        <a href="/admin/tenants/create" class="vb-action-rail-item">
            <span class="vb-action-rail-icon"><i data-lucide="plus"></i></span>
            <span class="vb-action-rail-text">
                <div class="vb-action-rail-label"><?= __('admin.tenants.create') ?></div>
                <div class="vb-action-rail-hint"><?= __('admin.dashboard.create_desc') ?></div>
            </span>
        </a>
        <a href="/admin/tenants" class="vb-action-rail-item">
            <span class="vb-action-rail-icon"><i data-lucide="building-2"></i></span>
            <span class="vb-action-rail-text">
                <div class="vb-action-rail-label"><?= __('admin.tenants.title') ?></div>
                <div class="vb-action-rail-hint"><?= __('admin.dashboard.tenants_desc') ?></div>
            </span>
        </a>
        <a href="/admin/bookings" class="vb-action-rail-item">
            <span class="vb-action-rail-icon"><i data-lucide="calendar"></i></span>
            <span class="vb-action-rail-text">
                <div class="vb-action-rail-label"><?= __('admin.bookings.title') ?></div>
                <div class="vb-action-rail-hint"><?= __('admin.dashboard.view_all_bookings_desc') ?></div>
            </span>
        </a>
    </nav>
</div>
<?php endif; ?>

<?php
$content = ob_get_clean();
include dirname(__DIR__) . '/admin/layout.php';
