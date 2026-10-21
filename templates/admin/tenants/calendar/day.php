<?php
/**
 * Calendar day view — vertical timeline with booking blocks.
 *
 * The timeline always renders, even with zero bookings, so operators can
 * scan every hour and click straight into a time-prefilled create form.
 *
 * Variables: $tenant, $date, $dateStr, $prevDate, $nextDate, $isToday, $today,
 *            $bookings, $hours, $hourStart, $hourEnd, $tenantId, $csrfToken,
 *            $isBlocked, $hasAvailability
 */
$tenant    = $tenant ?? [];
/** @var \DateTimeImmutable $date */
$date      = $date ?? new \DateTimeImmutable();
$bookings  = $bookings ?? [];
$hours     = $hours ?? [];
$tenantId  = $tenantId ?? '';
$dateStr   = $dateStr ?? date('Y-m-d');
$prevDate  = $prevDate ?? '';
$nextDate  = $nextDate ?? '';
$isToday   = $isToday ?? false;
$hourStart = $hourStart ?? 7;
$hourEnd   = $hourEnd ?? 21;
$brandColor = $tenant['brand_color'] ?? '#6366f1';
$isBlocked  = $isBlocked ?? false;
$hasAvailability = $hasAvailability ?? true;

$baseUrl = "/admin/tenants/" . htmlspecialchars($tenantId, ENT_QUOTES, 'UTF-8');
$bookingCount = count($bookings);

ob_start();
?>

<!-- Calendar header -->
<div class="vb-calendar-header">
    <div class="vb-calendar-header-left">
        <a href="<?= $baseUrl ?>/calendar/day?date=<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>"
           class="vb-btn vb-btn-ghost vb-btn-sm <?= $isToday ? 'is-active' : '' ?>">
            <?= __('admin.calendar.today') ?>
        </a>
        <div class="vb-calendar-nav-arrows">
            <a href="<?= $baseUrl ?>/calendar/day?date=<?= htmlspecialchars($prevDate, ENT_QUOTES, 'UTF-8') ?>"
               class="vb-btn vb-btn-ghost vb-btn-icon" aria-label="<?= __('admin.calendar.prev_day') ?>">
                <i data-lucide="chevron-left"></i>
            </a>
            <a href="<?= $baseUrl ?>/calendar/day?date=<?= htmlspecialchars($nextDate, ENT_QUOTES, 'UTF-8') ?>"
               class="vb-btn vb-btn-ghost vb-btn-icon" aria-label="<?= __('admin.calendar.next_day') ?>">
                <i data-lucide="chevron-right"></i>
            </a>
        </div>
        <h2 class="vb-calendar-date-title">
            <?= htmlspecialchars(\App\Engine\Locale::dateLong($date), ENT_QUOTES, 'UTF-8') ?>
            <?php if ($isToday): ?>
                <span class="vb-badge vb-badge-primary"><?= __('admin.calendar.today') ?></span>
            <?php endif; ?>
        </h2>
    </div>
    <div class="vb-calendar-header-right">
        <a href="<?= $baseUrl ?>/bookings/create?date=<?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?>"
           class="vb-btn vb-btn-primary vb-btn-sm" id="btn-calendar-new-booking">
            <i data-lucide="plus"></i>
            <?= __('admin.calendar.new_booking') ?>
        </a>
        <nav class="vb-segmented" aria-label="<?= __('admin.calendar.page_title') ?>">
            <a href="<?= $baseUrl ?>/calendar?date=<?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?>"
               class="vb-segmented-item"><?= __('admin.calendar.view_month') ?></a>
            <a href="<?= $baseUrl ?>/calendar/week?date=<?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?>"
               class="vb-segmented-item"><?= __('admin.calendar.view_week') ?></a>
            <a href="<?= $baseUrl ?>/calendar/day?date=<?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?>"
               class="vb-segmented-item active"><?= __('admin.calendar.view_day') ?></a>
        </nav>
    </div>
</div>

<?php if ($isBlocked || !$hasAvailability): ?>
<div class="vb-calendar-state-bar <?= $isBlocked ? 'is-blocked' : 'is-unavailable' ?>">
    <?php if ($isBlocked): ?>
        <i data-lucide="ban"></i>
        <span><?= __('admin.calendar.day_blocked') ?></span>
    <?php else: ?>
        <i data-lucide="moon"></i>
        <span><?= __('admin.calendar.no_availability') ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- Day timeline — always rendered -->
<div class="vb-calendar-day-container">
    <div class="vb-calendar-timeline">
        <?php
        $totalMinutes = ($hourEnd - $hourStart + 1) * 60;
        foreach ($hours as $h):
            $timeObj = new DateTimeImmutable(sprintf('%02d:00', $h));
            $hourTime = sprintf('%02d:00', $h);
        ?>
        <div class="vb-calendar-hour-row vb-fade-in-up stagger-<?= min(((int)$h - $hourStart) + 1, 6) ?>">
            <div class="vb-calendar-hour-label">
                <?= htmlspecialchars(\App\Engine\Locale::time($timeObj), ENT_QUOTES, 'UTF-8') ?>
            </div>
            <a href="<?= $baseUrl ?>/bookings/create?date=<?= htmlspecialchars($dateStr, ENT_QUOTES, 'UTF-8') ?>&time=<?= $hourTime ?>"
               class="vb-calendar-hour-line vb-calendar-hour-link"
               title="<?= __('admin.calendar.new_booking') ?>"></a>
        </div>
        <?php endforeach; ?>

        <!-- Booking blocks -->
        <?php foreach ($bookings as $i => $b):
            $start     = new DateTimeImmutable($b['start_datetime']);
            $end       = new DateTimeImmutable($b['end_datetime']);
            $timelineStart = new DateTimeImmutable($dateStr . sprintf(' %02d:00:00', $hourStart));
            $startMins = max(0, min($totalMinutes - 15, ($start->getTimestamp() - $timelineStart->getTimestamp()) / 60));
            $endMins = max(0, min($totalMinutes, ($end->getTimestamp() - $timelineStart->getTimestamp()) / 60));
            $duration  = min(max($endMins - $startMins, 15), $totalMinutes - $startMins);
            $topPct    = ($startMins / $totalMinutes) * 100;
            $heightPct = ($duration / $totalMinutes) * 100;
            $color = $b['service_color'] ?: $brandColor;
            $statusClass = match ($b['status']) {
                'confirmed' => 'is-confirmed',
                'completed' => 'is-completed',
                'pending'   => 'is-pending',
                'no_show'   => 'is-noshow',
                default     => '',
            };
            $blockLabel = booking_display_label($b);
            $customerName = $b['customer_name'] ?? __('admin.calendar.no_customer');
            $tooltipText = htmlspecialchars($customerName . ' · ' . \App\Engine\Locale::time($start) . '–' . \App\Engine\Locale::time($end) . ($blockLabel !== '—' ? ' · ' . $blockLabel : ''), ENT_QUOTES, 'UTF-8');
        ?>
        <div class="vb-booking-block <?= $statusClass ?> vb-fade-in-up stagger-<?= min($i + 1, 6) ?>"
             style="top: <?= round($topPct, 2) ?>%; height: <?= round($heightPct, 2) ?>%; --block-color: <?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>; --col-index: <?= (int) ($b['colIndex'] ?? 0) ?>; --col-total: <?= (int) ($b['colTotal'] ?? 1) ?>;"
             title="<?= $tooltipText ?>">
            <div class="vb-booking-block-content">
                <span class="vb-booking-block-name">
                    <?= htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <span class="vb-booking-block-time">
                    <?= htmlspecialchars(\App\Engine\Locale::time($start), ENT_QUOTES, 'UTF-8') ?>
                    – <?= htmlspecialchars(\App\Engine\Locale::time($end), ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php if ($blockLabel !== '—'): ?>
                <span class="vb-booking-block-service">
                    <?= htmlspecialchars($blockLabel, ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>

        <?php if ($bookingCount === 0): ?>
        <div class="vb-calendar-zero-guidance vb-fade-in-up stagger-1">
            <i data-lucide="mouse-pointer-click"></i>
            <span><?= __('admin.calendar.click_to_book') ?></span>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include dirname(__DIR__, 3) . '/admin/layout.php';
