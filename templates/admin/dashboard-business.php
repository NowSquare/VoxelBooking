<?php
/** Tenant dashboard: today's work, exceptions, then aggregate context. */
use App\Engine\Locale;

$activePage = 'dashboard';
$tenantPath = '/admin/tenants/' . htmlspecialchars($tenant['id'], ENT_QUOTES, 'UTF-8');
$bookingPath = '/book/' . htmlspecialchars($tenant['slug'] ?? '', ENT_QUOTES, 'UTF-8');
$canConfigure = ($user['type'] ?? '') === 'operator' || ($user['role'] ?? '') === 'owner';
$nextBooking = $upcoming[0] ?? null;
$today = $dashboardDate ?? new \DateTimeImmutable('today', new \DateTimeZone($tenant['timezone'] ?? 'UTC'));
$setupActions = $setupActions ?? [];
$pendingApprovals = $pendingApprovals ?? 0;
ob_start();
?>
<div class="vb-dashboard-header">
    <div>
        <p class="vb-dashboard-date" id="dashboard-date"><?= htmlspecialchars(Locale::date($today), ENT_QUOTES, 'UTF-8') ?></p>
        <h2 class="vb-dashboard-title"><?= htmlspecialchars($tenant['name'] ?? '', ENT_QUOTES, 'UTF-8') ?></h2>
    </div>
    <div class="vb-dashboard-actions">
        <a href="<?= $tenantPath ?>/bookings/create" class="vb-btn vb-btn-primary"><i data-lucide="plus"></i><?= __('admin.bookings.new_booking') ?></a>
        <button type="button" class="vb-btn vb-btn-secondary vb-copy-btn" @click="copyBookingUrl"
                data-copy-url="<?= htmlspecialchars(($_SERVER['REQUEST_SCHEME'] ?? 'https') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/book/' . ($tenant['slug'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            <span class="vb-copy-icon"><i data-lucide="copy"></i></span><span class="vb-copy-check"><i data-lucide="check"></i></span>
            <?= __('admin.common.copy_booking_url') ?>
        </button>
    </div>
</div>

<?php if ($pendingApprovals > 0): ?>
<a href="<?= $tenantPath ?>/bookings?status=pending" class="vb-dashboard-notice">
    <i data-lucide="clock" aria-hidden="true"></i>
    <span><strong><?= __('admin.dashboard.awaiting_approval') ?></strong><span><?= __('admin.dashboard.review_pending', ['count' => Locale::number($pendingApprovals)]) ?></span></span>
    <i data-lucide="arrow-right" aria-hidden="true"></i>
</a>
<?php endif; ?>

<?php if (!empty($setupActions)): ?>
<section class="vb-dashboard-setup" aria-labelledby="dashboard-setup">
    <h3 id="dashboard-setup"><?= __('admin.dashboard.setup_required') ?></h3>
    <?php if ($canConfigure): ?>
    <div class="vb-dashboard-setup-actions">
        <?php foreach ($setupActions as $action): ?>
        <a class="vb-link-cta" href="<?= $tenantPath ?>/<?= htmlspecialchars($action['path'], ENT_QUOTES, 'UTF-8') ?>"><?= __('admin.dashboard.' . $action['key']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p><?= __('admin.dashboard.setup_contact_owner') ?></p>
    <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($nextBooking !== null): ?>
<section class="vb-dashboard-next" aria-labelledby="next-booking">
    <div>
        <h3 class="vb-dashboard-kicker" id="next-booking"><?= __('admin.dashboard.next_booking') ?></h3>
        <a class="vb-dashboard-next-name" href="<?= $tenantPath ?>/bookings/<?= htmlspecialchars($nextBooking['id'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($nextBooking['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></a>
        <p><?= htmlspecialchars(booking_display_label($nextBooking), ENT_QUOTES, 'UTF-8') ?></p>
        <span class="vb-status vb-status-<?= htmlspecialchars($nextBooking['status'], ENT_QUOTES, 'UTF-8') ?>"><?= __('admin.bookings.status_' . $nextBooking['status']) ?></span>
    </div>
    <div class="vb-dashboard-next-time">
        <?php $bookingDateTime = $nextBooking; $bookingDateTimeContext = 'next'; include __DIR__ . '/partials/booking-datetime.php'; ?>
    </div>
</section>
<?php endif; ?>

<section class="vb-card vb-card-flush vb-dashboard-schedule" aria-labelledby="schedule-today">
    <div class="vb-dashboard-section-header">
        <h3 id="schedule-today"><?= __('admin.dashboard.schedule_today') ?></h3>
        <a href="<?= $tenantPath ?>/calendar" class="vb-btn vb-btn-ghost vb-btn-sm"><?= __('admin.dashboard.open_calendar') ?><i data-lucide="arrow-right"></i></a>
    </div>
    <?php if (empty($todaySchedule)): ?>
    <div class="vb-dashboard-quiet">
        <i data-lucide="calendar-off" aria-hidden="true"></i>
        <div><h4><?= __('admin.dashboard.no_schedule') ?></h4><p><?= __('admin.dashboard.quiet_day_hint') ?></p></div>
    </div>
    <?php else: ?>
    <div class="vb-schedule-grid vb-dashboard-schedule-list">
        <?php foreach ($todaySchedule as $booking):
            $serviceColor = $booking['service_color'] ?? null;
            $serviceColor = is_string($serviceColor) && preg_match('/^#[0-9A-Fa-f]{6}$/', $serviceColor) ? $serviceColor : 'var(--vb-accent)';
        ?>
        <a href="<?= $tenantPath ?>/bookings/<?= htmlspecialchars($booking['id'], ENT_QUOTES, 'UTF-8') ?>" class="vb-dashboard-schedule-row vb-schedule-pill <?= ($booking['booking_pattern'] ?? '') === 'resource' ? 'vb-dashboard-schedule-row--stay' : '' ?>">
            <div class="vb-dashboard-schedule-time">
                <?php $bookingDateTime = $booking; $bookingDateTimeContext = 'schedule'; include __DIR__ . '/partials/booking-datetime.php'; ?>
            </div>
            <span class="vb-schedule-service-dot" style="background: <?= htmlspecialchars($serviceColor, ENT_QUOTES, 'UTF-8') ?>" aria-hidden="true"></span>
            <div class="vb-dashboard-schedule-person">
                <strong><?= htmlspecialchars($booking['customer_name'] ?? '—', ENT_QUOTES, 'UTF-8') ?></strong>
                <span><?= htmlspecialchars(booking_display_label($booking), ENT_QUOTES, 'UTF-8') ?><?php if (!empty($booking['staff_name'])): ?> · <span class="vb-schedule-pill-staff"><?= htmlspecialchars($booking['staff_name'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></span>
            </div>
            <span class="vb-status vb-status-<?= htmlspecialchars($booking['status'], ENT_QUOTES, 'UTF-8') ?>"><?= __('admin.bookings.status_' . $booking['status']) ?></span>
            <i data-lucide="chevron-right" class="vb-icon-sm" aria-hidden="true"></i>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php if (($tenant['booking_pattern'] ?? '') === 'timeslot' && $staffWorkingToday !== null): ?>
<section class="vb-dash-staff-today-wrap vb-dashboard-staff" aria-labelledby="staff-today">
    <h3 class="vb-dashboard-kicker" id="staff-today"><?= __('admin.dashboard.staff_working_today') ?></h3>
    <?php if (empty($staffWorkingToday)): ?>
    <p class="vb-dashboard-support"><?= __('admin.dashboard.staff_none_today') ?></p>
    <?php else: ?>
    <div class="vb-staff-today-list">
        <?php foreach ($staffWorkingToday as $staff): ?>
        <div class="vb-staff-today-row">
            <span class="vb-staff-today-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr($staff['name'], 0, 1)), ENT_QUOTES, 'UTF-8') ?></span>
            <div class="vb-staff-today-info"><strong class="vb-staff-today-name"><?= htmlspecialchars($staff['name'], ENT_QUOTES, 'UTF-8') ?></strong><?php if (!empty($staff['title'])): ?><span class="vb-staff-today-title"><?= htmlspecialchars($staff['title'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?></div>
            <div class="vb-staff-today-windows"><?php foreach ($staff['windows'] as $window): ?><span class="vb-staff-today-window"><?= Locale::time(new \DateTimeImmutable($window['start'])) ?> – <?= Locale::time(new \DateTimeImmutable($window['end'])) ?></span><?php endforeach; ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>

<div class="vb-dashboard-metrics" aria-label="<?= __('admin.dashboard.booking_overview') ?>">
    <?php foreach ([['bookings_today', $todayBookings ?? 0], ['this_week', $weekBookings ?? 0], ['this_month', $monthBookings ?? 0], ['total_customers', $totalCustomers ?? 0]] as [$key, $value]): ?>
    <div><span><?= __('admin.dashboard.' . $key) ?></span><strong><?= Locale::number((int) $value) ?></strong></div>
    <?php endforeach; ?>
</div>

<nav class="vb-dashboard-footer-actions" aria-label="<?= __('admin.common.actions') ?>">
    <a href="<?= $tenantPath ?>/bookings" class="vb-btn vb-btn-ghost vb-btn-sm"><?= __('admin.dashboard.view_all_bookings') ?><i data-lucide="arrow-right"></i></a>
    <a href="<?= $bookingPath ?>" target="_blank" rel="noopener" class="vb-btn vb-btn-ghost vb-btn-sm"><?= __('admin.dashboard.view_booking_page') ?><i data-lucide="external-link"></i></a>
</nav>
<?php
$content = ob_get_clean();
include __DIR__ . '/layout.php';
