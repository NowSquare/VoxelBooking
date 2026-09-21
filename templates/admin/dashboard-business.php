<?php
/**
 * Business user dashboard — tenant-scoped view.
 *
 * Five rhythm bands:
 *   1. Greeting (personalized hero)
 *   2. Metrics (4 cards with deltas)
 *   3. Today's schedule (compact strip with per-service-color pills)
 *   4. Who's working today (timeslot-pattern only)
 *   5. Next Up + Quick Actions (two-column grid)
 *
 * Variables: $user, $version, $csrfToken, $tenant, $todayBookings, $weekBookings,
 *            $monthBookings, $totalCustomers, $upcoming, $pageTitle, $activePage,
 *            $deltaToday, $deltaWeek, $todaySchedule, $staffWorkingToday
 */
$activePage = 'dashboard';

$hour = (int) date('H');
if ($hour < 12) {
    $greeting = __('admin.dashboard.good_morning');
} elseif ($hour < 18) {
    $greeting = __('admin.dashboard.good_afternoon');
} else {
    $greeting = __('admin.dashboard.good_evening');
}
$userName = htmlspecialchars($user['name'] ?? __('admin.layout.operator'), ENT_QUOTES, 'UTF-8');
$tenantName = htmlspecialchars($tenant['name'] ?? '', ENT_QUOTES, 'UTF-8');

/**
 * Derive a pastel tint from a hex color for booking pills.
 * Returns [background, foreground] CSS values.
 */
if (!function_exists('schedulePillColors')) {
    function schedulePillColors(?string $hexColor): array
    {
        if ($hexColor !== null && preg_match('/^#?([0-9A-Fa-f]{6})$/', $hexColor, $m)) {
            $hex = $m[1];
            $r = hexdec(substr($hex, 0, 2));
            $g = hexdec(substr($hex, 2, 2));
            $b = hexdec(substr($hex, 4, 2));
            return [
                "rgba({$r}, {$g}, {$b}, 0.12)",
                "#{$hex}",
            ];
        }
        // Accent fallback
        return ['var(--vb-accent-subtle)', 'var(--vb-accent)'];
    }
}

ob_start();
?>

<!-- Greeting Band -->
<div class="vb-greeting vb-fade-in-up stagger-1">
    <div class="vb-greeting-title"><?= $greeting ?>, <?= $userName ?></div>
    <div class="vb-greeting-subtitle"><?= __('admin.dashboard.business_subtitle', ['tenant_name' => $tenantName]) ?></div>
</div>

<!-- Metric Band — redesigned with header + delta badges -->
<div class="vb-metrics">
    <div class="vb-metric vb-fade-in-up stagger-1">
        <div class="vb-metric-header">
            <i data-lucide="calendar-check" class="vb-metric-icon"></i>
            <span class="vb-metric-label"><?= __('admin.dashboard.bookings_today') ?></span>
        </div>
        <div class="vb-metric-value-row">
            <span class="vb-metric-value"><?= (int) $todayBookings ?></span>
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
    <div class="vb-metric vb-fade-in-up stagger-2">
        <div class="vb-metric-header">
            <i data-lucide="bar-chart-3" class="vb-metric-icon"></i>
            <span class="vb-metric-label"><?= __('admin.dashboard.this_week') ?></span>
        </div>
        <div class="vb-metric-value-row">
            <span class="vb-metric-value"><?= (int) $weekBookings ?></span>
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
    <div class="vb-metric vb-fade-in-up stagger-3">
        <div class="vb-metric-header">
            <i data-lucide="calendar-range" class="vb-metric-icon"></i>
            <span class="vb-metric-label"><?= __('admin.dashboard.this_month') ?></span>
        </div>
        <div class="vb-metric-value-row">
            <span class="vb-metric-value"><?= (int) ($monthBookings ?? 0) ?></span>
        </div>
        <div class="vb-metric-accent"></div>
    </div>
    <div class="vb-metric vb-fade-in-up stagger-4">
        <div class="vb-metric-header">
            <i data-lucide="users" class="vb-metric-icon"></i>
            <span class="vb-metric-label"><?= __('admin.dashboard.total_customers') ?></span>
        </div>
        <div class="vb-metric-value-row">
            <span class="vb-metric-value"><?= (int) ($totalCustomers ?? 0) ?></span>
        </div>
        <div class="vb-metric-accent"></div>
    </div>
</div>

<!-- Today's Schedule Strip -->
<div class="vb-dash-schedule-wrap">
    <div class="vb-card vb-card-flush vb-fade-in-up stagger-5">
        <div class="vb-schedule-header">
            <div class="vb-schedule-title"><?= __('admin.dashboard.schedule_today') ?></div>
        </div>
        <?php if (empty($todaySchedule)): ?>
            <div class="vb-schedule-empty">
                <i data-lucide="calendar-off" class="vb-schedule-empty-icon"></i>
                <div class="vb-schedule-empty-text"><?= __('admin.dashboard.no_schedule') ?></div>
            </div>
        <?php else: ?>
            <?php
            // Group bookings by hour for the schedule grid
            $hourSlots = [];
            $minHour = 23;
            $maxHour = 0;
            foreach ($todaySchedule as $booking) {
                $h = (int) date('G', strtotime($booking['start_datetime']));
                $hourSlots[$h][] = $booking;
                $minHour = min($minHour, $h);
                $maxHour = max($maxHour, $h);
            }
            // Show range from earliest hour to latest hour + 1
            $startHour = max(0, $minHour);
            $endHour = min(23, $maxHour + 1);
            ?>
            <div class="vb-schedule-grid" id="schedule-grid">
                <?php for ($h = $startHour; $h <= $endHour; $h++): ?>
                <div class="vb-schedule-hour-row">
                    <div class="vb-schedule-time-gutter"><?= sprintf('%02d:00', $h) ?></div>
                    <div class="vb-schedule-cells">
                        <?php if (isset($hourSlots[$h])): ?>
                            <?php foreach ($hourSlots[$h] as $booking):
                                [$pillBg, $pillColor] = schedulePillColors($booking['service_color'] ?? null);
                                $startTime = date('H:i', strtotime($booking['start_datetime']));
                                $endTime = date('H:i', strtotime($booking['end_datetime']));
                            ?>
                            <div class="vb-schedule-pill"
                                 style="background: <?= $pillBg ?>; color: <?= $pillColor ?>;">
                                <a href="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>/bookings/<?= htmlspecialchars($booking['id'], ENT_QUOTES, 'UTF-8') ?>" class="vb-schedule-pill-body">
                                    <div class="vb-schedule-pill-title">
                                        <?= htmlspecialchars($booking['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="vb-schedule-pill-time">
                                        <?= $startTime ?> – <?= $endTime ?> · <?= htmlspecialchars(booking_display_label($booking), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <?php if (!empty($booking['staff_name'])): ?>
                                    <div class="vb-schedule-pill-staff">
                                        <?= htmlspecialchars($booking['staff_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <?php endif; ?>
                                </a>
                                <?php if (!empty($booking['customer_id'])): ?>
                                <a href="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>/customers/<?= htmlspecialchars($booking['customer_id'], ENT_QUOTES, 'UTF-8') ?>"
                                   class="vb-crm-link" title="<?= __('admin.customers.view_customer') ?>">
                                    <i data-lucide="user" class="vb-icon-xs"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endfor; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
/**
 * "Who's Working Today" section — timeslot-pattern tenants only.
 *
 * Three states:
 *   1. $staffWorkingToday === null  → No staff exist → CTA to create staff
 *   2. $staffWorkingToday === []    → Staff exist, none working today → calm message
 *   3. $staffWorkingToday is array  → Show compact list with windows
 */
if ($staffWorkingToday !== null || (isset($tenant['booking_pattern']) && $tenant['booking_pattern'] === 'timeslot')):
?>
<div class="vb-dash-staff-today-wrap">
    <div class="vb-card vb-card-flush vb-fade-in-up stagger-5">
        <div class="vb-schedule-header">
            <div class="vb-schedule-title"><?= __('admin.dashboard.staff_working_today') ?></div>
        </div>
        <?php if ($staffWorkingToday === null): ?>
            <!-- No staff members exist yet -->
            <div class="vb-schedule-empty">
                <i data-lucide="user-plus" class="vb-schedule-empty-icon"></i>
                <div class="vb-schedule-empty-text"><?= __('admin.dashboard.staff_none_yet') ?></div>
                <a href="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>/staff/create"
                   class="vb-link-cta"><?= __('admin.dashboard.staff_add_first') ?></a>
            </div>
        <?php elseif (empty($staffWorkingToday)): ?>
            <!-- Staff exist but none are working today -->
            <div class="vb-schedule-empty">
                <i data-lucide="coffee" class="vb-schedule-empty-icon"></i>
                <div class="vb-schedule-empty-text"><?= __('admin.dashboard.staff_none_today') ?></div>
                <a href="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>/availability"
                   class="vb-link-cta"><?= __('admin.dashboard.staff_manage_hours') ?></a>
            </div>
        <?php else: ?>
            <!-- Staff working today -->
            <div class="vb-staff-today-list">
                <?php foreach ($staffWorkingToday as $sw): ?>
                <div class="vb-staff-today-row">
                    <div class="vb-staff-today-avatar">
                        <?= strtoupper(mb_substr($sw['name'], 0, 1)) ?>
                    </div>
                    <div class="vb-staff-today-info">
                        <div class="vb-staff-today-name"><?= htmlspecialchars($sw['name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if (!empty($sw['title'])): ?>
                        <div class="vb-staff-today-title"><?= htmlspecialchars($sw['title'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="vb-staff-today-windows">
                        <?php foreach ($sw['windows'] as $w): ?>
                        <span class="vb-staff-today-window"><?= $w['start'] ?> – <?= $w['end'] ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- Next Up + Quick Actions -->
<div class="vb-dash-grid">
    <!-- Next Up -->
    <div class="vb-card vb-card-flush vb-fade-in-up stagger-5">
        <div class="vb-dash-section-title"><?= __('admin.dashboard.next_up') ?></div>
        <?php if (empty($upcoming)): ?>
            <div class="vb-schedule-empty">
                <i data-lucide="calendar-check" class="vb-schedule-empty-icon"></i>
                <div class="vb-schedule-empty-text"><?= __('admin.dashboard.no_upcoming_bookings') ?></div>
            </div>
        <?php else: ?>
            <div class="vb-upcoming-list">
                <?php foreach ($upcoming as $b): ?>
                <div class="vb-upcoming-row">
                    <a href="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>/bookings/<?= htmlspecialchars($b['id'], ENT_QUOTES, 'UTF-8') ?>" class="vb-upcoming-row-body">
                        <div>
                            <div class="vb-upcoming-name"><?= htmlspecialchars($b['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="vb-upcoming-service"><?= htmlspecialchars(booking_display_label($b), ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                        <span class="vb-status vb-status-<?= htmlspecialchars($b['status'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= __('admin.bookings.status_' . $b['status']) ?>
                        </span>
                        <div class="vb-upcoming-time">
                            <div class="vb-upcoming-date"><?= \App\Engine\Locale::date(new \DateTimeImmutable($b['start_datetime'])) ?></div>
                            <div class="vb-upcoming-clock"><?= date('H:i', strtotime($b['start_datetime'])) ?></div>
                        </div>
                    </a>
                    <?php if (!empty($b['customer_id'])): ?>
                    <a href="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>/customers/<?= htmlspecialchars($b['customer_id'], ENT_QUOTES, 'UTF-8') ?>"
                       class="vb-upcoming-crm" title="<?= __('admin.customers.view_customer') ?>">
                        <i data-lucide="user" class="vb-icon-sm"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Actions -->
    <div class="vb-card vb-card-flush vb-fade-in-up stagger-6">
        <nav class="vb-action-rail">
            <div class="vb-action-rail-title"><?= __('admin.common.actions') ?></div>
            <a href="/admin/tenants/<?= htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8') ?>/bookings" class="vb-action-rail-item">
                <span class="vb-action-rail-icon"><i data-lucide="calendar"></i></span>
                <span class="vb-action-rail-text">
                    <div class="vb-action-rail-label"><?= __('admin.dashboard.view_all_bookings') ?></div>
                    <div class="vb-action-rail-hint"><?= __('admin.dashboard.action_bookings_hint') ?></div>
                </span>
            </a>
            <a href="/book/<?= htmlspecialchars($tenant['slug'] ?? '', ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="vb-action-rail-item">
                <span class="vb-action-rail-icon"><i data-lucide="external-link"></i></span>
                <span class="vb-action-rail-text">
                    <div class="vb-action-rail-label"><?= __('admin.dashboard.view_booking_page') ?></div>
                    <div class="vb-action-rail-hint"><?= __('admin.dashboard.action_booking_page_hint') ?></div>
                </span>
            </a>
            <button type="button" class="vb-action-rail-item"
                    data-copy-url="<?= htmlspecialchars((isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/book/' . ($tenant['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                    @click="copyBookingUrl">
                <span class="vb-action-rail-icon">
                    <i data-lucide="copy" class="vb-copy-icon"></i>
                    <i data-lucide="check" class="vb-copy-check"></i>
                </span>
                <span class="vb-action-rail-text">
                    <div class="vb-action-rail-label"><?= __('admin.common.copy_booking_url') ?></div>
                    <div class="vb-action-rail-hint"><?= __('admin.dashboard.action_copy_hint') ?></div>
                </span>
            </button>
        </nav>
    </div>
</div>

<!-- Now-line updater (standalone, no Alpine) -->
<script>
(function() {
    var grid = document.getElementById('schedule-grid');
    if (!grid) return;
    var rows = grid.querySelectorAll('.vb-schedule-hour-row');
    if (!rows.length) return;

    // Parse start/end hours from the grid
    var firstGutter = rows[0].querySelector('.vb-schedule-time-gutter');
    var lastGutter = rows[rows.length - 1].querySelector('.vb-schedule-time-gutter');
    if (!firstGutter || !lastGutter) return;
    var startHour = parseInt(firstGutter.textContent, 10);
    var endHour = parseInt(lastGutter.textContent, 10) + 1;

    // Create the now-line element
    var nowLine = document.createElement('div');
    nowLine.className = 'vb-schedule-now';
    grid.style.position = 'relative';
    grid.appendChild(nowLine);

    function updateNowLine() {
        var now = new Date();
        var currentMinutes = now.getHours() * 60 + now.getMinutes();
        var startMinutes = startHour * 60;
        var endMinutes = endHour * 60;

        if (currentMinutes < startMinutes || currentMinutes > endMinutes) {
            nowLine.style.display = 'none';
            return;
        }

        nowLine.style.display = '';
        var totalHeight = grid.scrollHeight;
        var fraction = (currentMinutes - startMinutes) / (endMinutes - startMinutes);
        nowLine.style.top = (fraction * totalHeight) + 'px';
        nowLine.setAttribute('data-time',
            String(now.getHours()).padStart(2, '0') + ':' +
            String(now.getMinutes()).padStart(2, '0')
        );
    }

    updateNowLine();
    setInterval(updateNowLine, 60000);
})();
</script>

<?php
$content = ob_get_clean();
include dirname(__DIR__) . '/admin/layout.php';
