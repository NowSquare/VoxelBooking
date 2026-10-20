<?php
/** A shared, centred outcome header. Booking facts remain left aligned below. */
$outcome = $outcome ?? 'booking';
$outcomeHeading = match ($outcome) {
    'cancelled' => "t('manage.cancelled_heading')",
    'rescheduled' => "t('manage.reschedule_success_heading')",
    default => "eventIsWaitlisted ? t('event.waitlisted_title') : (bookingIsPending ? t('pending.heading') : t('confirmed.heading'))",
};
?>
<div class="vb-book-outcome" data-outcome="<?= $outcome ?>">
    <div class="vb-book-outcome-symbol" aria-hidden="true" <?= $outcome === 'booking' ? 'x-bind:class="{ \'is-waiting\': bookingIsPending || eventIsWaitlisted }"' : ($outcome === 'cancelled' ? 'data-status="cancelled"' : '') ?>>
        <?php if ($outcome === 'cancelled'): ?>
        <svg viewBox="0 0 24 24" fill="none"><path d="m6 6 12 12M18 6 6 18"/></svg>
        <?php else: ?>
        <svg viewBox="0 0 24 24" fill="none" <?= $outcome === 'booking' ? 'x-show="!bookingIsPending && !eventIsWaitlisted"' : '' ?>><path d="m5 12 4 4L19 6"/></svg>
        <?php if ($outcome === 'booking'): ?>
        <svg viewBox="0 0 24 24" fill="none" x-show="bookingIsPending || eventIsWaitlisted"><circle cx="12" cy="12" r="8"/><path d="M12 7v5l3 2"/></svg>
        <?php endif; ?>
        <?php endif; ?>
    </div>
    <h2 class="vb-book-confirm-heading" x-text="<?= $outcomeHeading ?>"></h2>
    <?php if ($outcome === 'booking'): ?>
    <p class="vb-book-confirm-message" x-show="eventIsWaitlisted" x-text="t('event.waitlisted_message')"></p>
    <p class="vb-book-confirm-message" x-show="bookingIsPending && !eventIsWaitlisted" x-text="t('pending.message')"></p>
    <p class="vb-book-confirm-message" x-show="!eventIsWaitlisted && !bookingIsPending && confirmEmailSent" x-text="confirmEmailSent"></p>
    <p class="vb-book-confirm-message-custom" x-show="confirmCustomMessage" x-text="confirmCustomMessage"></p>
    <p class="vb-book-confirm-ref" x-show="booking" x-text="bookingReference"></p>
    <?php else: ?>
    <p class="vb-book-confirm-message" x-text="t('manage.<?= $outcome === 'cancelled' ? 'cancelled_message' : 'reschedule_success_message' ?>')"></p>
    <?php endif; ?>
</div>
