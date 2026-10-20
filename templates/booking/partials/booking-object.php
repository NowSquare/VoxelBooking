<?php
/** Same commitment, with greater detail at review, confirmation and management. */
$objectMode = $objectMode ?? 'context';
$objectRows = match ($objectMode) {
    'review' => 'bookingObjectReviewRows',
    'confirmed' => 'bookingObjectConfirmRows',
    'managed' => 'bookingObjectManagedRows',
    'rescheduled' => 'rescheduleConfirmedObjectRows',
    default => null,
};
?>
<section class="vb-book-object vb-book-object--<?= $objectMode ?>" aria-label="<?= __('booking.object.label') ?>">
    <div class="vb-book-object-heading">
        <div>
            <p class="vb-book-object-eyebrow" x-text="t('object.<?= in_array($objectMode, ['confirmed', 'rescheduled'], true) ? 'saved' : 'label' ?>')"></p>
            <h2 class="vb-book-object-title" x-text="bookingObjectTitle"></h2>
            <?php if ($objectMode === 'context'): ?>
            <p class="vb-book-object-meta" x-show="bookingObjectMeta" x-text="bookingObjectMeta"></p>
            <p class="vb-book-object-when" x-show="bookingObjectWhen" x-text="bookingObjectWhen"></p>
            <?php endif; ?>
        </div>
        <?php if (in_array($objectMode, ['context', 'review'], true)): ?>
        <div class="vb-book-object-edits">
            <button type="button" x-show="canEditChoice" @click="editChoice" x-text="t('object.edit_choice')"></button>
            <button type="button" x-show="canEditWhen" @click="editWhen" x-text="t('object.edit_when')"></button>
            <?php if ($objectMode === 'review'): ?>
            <button type="button" @click="editDetails" x-bind:disabled="submitting" x-text="t('object.edit_details')"></button>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php if ($objectRows): ?>
    <dl class="vb-book-object-facts">
        <template x-for="(row, ri) in <?= $objectRows ?>" x-bind:key="ri">
            <div><dt x-text="row.label"></dt><dd x-text="row.value"></dd></div>
        </template>
    </dl>
    <?php if (in_array($objectMode, ['review', 'managed'], true)): ?>
    <dl class="vb-book-object-facts vb-book-object-contact">
        <template x-for="row in bookingObjectContactRows" x-bind:key="row.label">
            <div><dt x-text="row.label"></dt><dd x-text="row.value"></dd></div>
        </template>
    </dl>
    <?php endif; ?>
    <?php endif; ?>
</section>
