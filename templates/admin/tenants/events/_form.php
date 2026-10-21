<?php
/**
 * Shared event form fields — used by both create and edit templates.
 *
 * Variables: $csrfToken, $tenantId, $event (array|null — populated on edit)
 *
 * Old input and field errors are read from session via helpers:
 *   old(), has_error(), field_error(), error_class()
 */
$isEdit = isset($event) && $event !== null;

// Parse event datetime fields: prefer old input, fall back to entity
$startDate = '';
$startTime = '';
$endDate = '';
$endTime = '';
if ($isEdit) {
    $startDate = old('start_date', date('Y-m-d', strtotime($event['start_datetime'])));
    $startTime = old('start_time', date('H:i', strtotime($event['start_datetime'])));
    $endDate   = old('end_date', date('Y-m-d', strtotime($event['end_datetime'])));
    $endTime   = old('end_time', date('H:i', strtotime($event['end_datetime'])));
} else {
    $startDate = old('start_date');
    $startTime = old('start_time');
    $endDate   = old('end_date');
    $endTime   = old('end_time');
}

$isRecurring = (int) old('is_recurring', $isEdit ? $event['is_recurring'] : 0);
$frequency = old('frequency', '');
$count = old('count', '');
if ($isEdit && (int) $event['is_recurring'] && $event['rrule'] && !$frequency) {
    // Parse RRULE for form fields
    if (preg_match('/FREQ=(\w+)/', $event['rrule'], $m)) {
        $frequency = $m[1];
    }
    if (preg_match('/COUNT=(\d+)/', $event['rrule'], $m)) {
        $count = $m[1];
    }
}

$exceptionDates = old('exception_dates', '');
if ($isEdit && !$exceptionDates && $event['exception_dates']) {
    $decoded = json_decode($event['exception_dates'], true);
    if (is_array($decoded)) {
        $exceptionDates = implode("\n", $decoded);
    }
}
?>

<input type="hidden" name="_csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" name="_record_version" value="<?= e($recordVersion ?? '') ?>">

<!-- Name -->
<div class="vb-form-group">
    <label for="event_name" class="vb-label"><?= __('admin.events.name_label') ?></label>
    <input type="text" id="event_name" name="name" class="vb-input" required
           value="<?= e(old('name', $isEdit ? $event['name'] : '')) ?>"
           placeholder="<?= __('admin.events.name_placeholder') ?>">
</div>

<!-- Description -->
<div class="vb-form-group">
    <label for="event_description" class="vb-label"><?= __('admin.events.description_label') ?></label>
    <textarea id="event_description" name="description" class="vb-input" rows="3"
              placeholder="<?= __('admin.events.description_placeholder') ?>"><?= e(old('description', $isEdit ? ($event['description'] ?? '') : '')) ?></textarea>
</div>

    <?php
        // Cover image upload
        $uploadFieldName   = 'cover_image';
        $uploadFieldId     = 'event_cover_image';
        $uploadLabel       = __('admin.events.label_cover_image');
        $uploadHint        = __('admin.events.cover_image_hint');
        $uploadCurrentPath = $event['cover_image_path'] ?? null;
        $uploadShape       = 'rect';
        include __DIR__ . '/../../../partials/upload-field.php';
    ?>

<!-- Location -->
<div class="vb-form-group">
    <label for="event_location" class="vb-label"><?= __('admin.events.location_label') ?></label>
    <input type="text" id="event_location" name="location" class="vb-input"
           value="<?= e(old('location', $isEdit ? ($event['location'] ?? '') : '')) ?>"
           placeholder="<?= __('admin.events.location_placeholder') ?>">
</div>

<div class="vb-form-row">
    <!-- Price -->
    <div class="vb-form-group">
        <label for="event_price" class="vb-label"><?= __('admin.events.price_label') ?></label>
        <input type="number" id="event_price" name="price" class="vb-input" step="0.01" min="0"
               value="<?= e(old('price', $isEdit ? ($event['price'] ?? '') : '')) ?>"
               placeholder="<?= __('admin.events.price_placeholder') ?>">
    </div>

    <!-- Max Participants -->
    <div class="vb-form-group">
        <label for="event_max" class="vb-label"><?= __('admin.events.max_participants_label') ?></label>
        <input type="number" id="event_max" name="max_participants" class="vb-input" min="1" required
               value="<?= e(old('max_participants', $isEdit ? $event['max_participants'] : '20')) ?>">
    </div>
</div>

<div class="vb-form-row">
    <!-- Min Spots Per Booking -->
    <div class="vb-form-group">
        <label for="event_min_spots" class="vb-label"><?= __('admin.events.min_spot_count_label') ?></label>
        <input type="number" id="event_min_spots" name="min_spot_count" class="vb-input" min="1"
               value="<?= e(old('min_spot_count', $isEdit ? ($event['min_spot_count'] ?? '1') : '1')) ?>">
    </div>

    <!-- Max Spots Per Booking -->
    <div class="vb-form-group">
        <label for="event_max_spots" class="vb-label"><?= __('admin.events.max_spot_count_label') ?></label>
        <input type="number" id="event_max_spots" name="max_spot_count" class="vb-input" min="1"
               value="<?= e(old('max_spot_count', $isEdit ? ($event['max_spot_count'] ?? '') : '')) ?>"
               placeholder="<?= __('admin.events.max_spot_count_hint') ?>">
    </div>
</div>

<!-- Start Date/Time -->
<div class="vb-form-row">
    <div class="vb-form-group">
        <label for="event_start_date" class="vb-label"><?= __('admin.events.start_datetime_label') ?></label>
        <input type="date" id="event_start_date" name="start_date" class="vb-input" required
               value="<?= htmlspecialchars($startDate, ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="vb-form-group">
        <label for="event_start_time" class="vb-label">&nbsp;</label>
        <input type="time" id="event_start_time" name="start_time" class="vb-input" required
               value="<?= htmlspecialchars($startTime, ENT_QUOTES, 'UTF-8') ?>">
    </div>
</div>

<!-- End Date/Time -->
<div class="vb-form-row">
    <div class="vb-form-group">
        <label for="event_end_date" class="vb-label"><?= __('admin.events.end_datetime_label') ?></label>
        <input type="date" id="event_end_date" name="end_date" class="vb-input" required
               value="<?= htmlspecialchars($endDate, ENT_QUOTES, 'UTF-8') ?>">
    </div>
    <div class="vb-form-group">
        <label for="event_end_time" class="vb-label">&nbsp;</label>
        <input type="time" id="event_end_time" name="end_time" class="vb-input" required
               value="<?= htmlspecialchars($endTime, ENT_QUOTES, 'UTF-8') ?>">
    </div>
</div>

<div class="vb-section-divider"></div>

<!-- Recurring Toggle -->
<div class="vb-form-group">
    <label class="vb-checkbox-label">
        <input type="checkbox" name="is_recurring" value="1" id="event_recurring"
               <?= $isRecurring ? 'checked' : '' ?>
               onchange="document.getElementById('recurring-config').style.display = this.checked ? 'block' : 'none';">
        <?= __('admin.events.is_recurring_label') ?>
    </label>
</div>

<!-- Recurring Config -->
<div id="recurring-config" style="display: <?= $isRecurring ? 'block' : 'none' ?>;">
    <div class="vb-form-row">
        <div class="vb-form-group">
            <label for="event_frequency" class="vb-label"><?= __('admin.events.frequency_label') ?></label>
            <select id="event_frequency" name="frequency" class="vb-input">
                <option value="WEEKLY" <?= $frequency === 'WEEKLY' ? 'selected' : '' ?>><?= __('admin.events.freq_weekly') ?></option>
                <option value="DAILY" <?= $frequency === 'DAILY' ? 'selected' : '' ?>><?= __('admin.events.freq_daily') ?></option>
                <option value="MONTHLY" <?= $frequency === 'MONTHLY' ? 'selected' : '' ?>><?= __('admin.events.freq_monthly') ?></option>
            </select>
        </div>
        <div class="vb-form-group">
            <label for="event_count" class="vb-label"><?= __('admin.events.count_label') ?></label>
            <input type="number" id="event_count" name="count" class="vb-input" min="1"
                   value="<?= htmlspecialchars($count ?: '12', ENT_QUOTES, 'UTF-8') ?>">
        </div>
    </div>

    <div class="vb-form-group">
        <label for="event_exceptions" class="vb-label"><?= __('admin.events.exception_dates_label') ?></label>
        <textarea id="event_exceptions" name="exception_dates" class="vb-input" rows="3"
                  placeholder="<?= __('admin.events.exception_dates_placeholder') ?>"><?= htmlspecialchars($exceptionDates, ENT_QUOTES, 'UTF-8') ?></textarea>
        <div class="vb-form-help"><?= __('admin.events.exception_dates_help') ?></div>
    </div>
</div>

<div class="vb-section-divider"></div>

<!-- Waitlist -->
<div class="vb-form-group">
    <label class="vb-checkbox-label">
        <input type="checkbox" name="allow_waitlist" value="1" id="event_waitlist"
               <?= (int) old('allow_waitlist', $isEdit ? $event['allow_waitlist'] : 0) ? 'checked' : '' ?>
               onchange="document.getElementById('waitlist-config').style.display = this.checked ? 'block' : 'none';">
        <?= __('admin.events.allow_waitlist_label') ?>
    </label>
</div>

<div id="waitlist-config" style="display: <?= (int) old('allow_waitlist', $isEdit ? $event['allow_waitlist'] : 0) ? 'block' : 'none' ?>;">
    <div class="vb-form-group">
        <label for="event_waitlist_max" class="vb-label"><?= __('admin.events.waitlist_max_label') ?></label>
        <input type="number" id="event_waitlist_max" name="waitlist_max" class="vb-input" min="1"
               value="<?= e(old('waitlist_max', $isEdit ? $event['waitlist_max'] : '5')) ?>">
    </div>
</div>
