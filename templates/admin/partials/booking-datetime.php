<?php
/** Venue-local booking facts. Stays use dates; timed bookings use their time range. */
$dateTimeStart = new \DateTimeImmutable($bookingDateTime['start_datetime']);
$dateTimeEnd = new \DateTimeImmutable($bookingDateTime['end_datetime']);
$dateTimeMode = $bookingDateTimeContext ?? 'cell';
$dateTimeStay = ($bookingDateTime['booking_pattern'] ?? '') === 'resource';
$dateTimeDate = $dateTimeMode === 'detail'
    ? \App\Engine\Locale::dateLong($dateTimeStart) : \App\Engine\Locale::date($dateTimeStart);
$dateTimeRange = \App\Engine\Locale::time($dateTimeStart) . ' – ' . \App\Engine\Locale::time($dateTimeEnd);
if ($dateTimeStay) {
    $dateTimePrimary = $dateTimeDate;
    $dateTimeSecondary = '– ' . ($dateTimeMode === 'detail' ? \App\Engine\Locale::dateLong($dateTimeEnd) : \App\Engine\Locale::date($dateTimeEnd));
} elseif ($dateTimeMode === 'schedule') {
    $dateTimePrimary = \App\Engine\Locale::time($dateTimeStart);
    $dateTimeSecondary = \App\Engine\Locale::time($dateTimeEnd);
} elseif ($dateTimeMode === 'next') {
    $dateTimePrimary = $dateTimeRange;
    $dateTimeSecondary = $dateTimeDate;
} else {
    $dateTimePrimary = $dateTimeDate;
    $dateTimeSecondary = $dateTimeRange;
}
?>
<span class="vb-booking-datetime">
    <span class="vb-cell-primary"><?php if ($dateTimeStay): ?><span class="sr-only"><?= __('admin.bookings.label_check_in') ?>: </span><?php endif; ?><?= htmlspecialchars($dateTimePrimary, ENT_QUOTES, 'UTF-8') ?></span>
    <span class="vb-cell-secondary"><?php if ($dateTimeStay): ?><span class="sr-only"><?= __('admin.bookings.label_check_out') ?>: </span><?php endif; ?><?= htmlspecialchars($dateTimeSecondary, ENT_QUOTES, 'UTF-8') ?></span>
</span>
<?php unset($bookingDateTime, $bookingDateTimeContext, $dateTimeStart, $dateTimeEnd, $dateTimeMode, $dateTimeStay, $dateTimeDate, $dateTimeRange, $dateTimePrimary, $dateTimeSecondary); ?>
