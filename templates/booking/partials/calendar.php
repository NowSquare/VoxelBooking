<?php
/** One keyboard and semantic contract for initial and reschedule calendars.
 * @var array{scope:string,cells:string,month:string,prev:string,next:string,select:string,canPrev?:string} $calendar
 */
?>
<div class="vb-book-calendar" data-calendar="<?= $calendar['scope'] ?>" data-week-start="<?= (int) \App\Engine\Locale::weekStart() ?>" @keydown="calendarGridKeydown">
    <div class="vb-book-calendar-nav">
        <button type="button" class="vb-book-calendar-btn" @click="<?= $calendar['prev'] ?>" <?= isset($calendar['canPrev']) ? 'x-bind:disabled="!' . $calendar['canPrev'] . '"' : '' ?> aria-label="<?= __('booking.calendar.prev_month') ?>">
            <i data-lucide="chevron-left" aria-hidden="true"></i>
        </button>
        <span class="vb-book-calendar-month" x-text="<?= $calendar['month'] ?>" aria-live="polite"></span>
        <button type="button" class="vb-book-calendar-btn" @click="<?= $calendar['next'] ?>" aria-label="<?= __('booking.calendar.next_month') ?>">
            <i data-lucide="chevron-right" aria-hidden="true"></i>
        </button>
    </div>
    <p class="vb-book-calendar-feedback vb-book-calendar-zone" x-show="isDisplayTimezoneDifferent" x-text="calendarTimezoneLabel"></p>
    <div class="vb-book-calendar-grid" role="grid" x-bind:aria-label="<?= $calendar['month'] ?>" x-bind:aria-busy="calendarLoading">
        <div class="vb-book-calendar-week" role="row">
            <template x-for="(d, di) in dayNames" x-bind:key="di">
                <div class="vb-book-calendar-dayname" role="columnheader" x-bind:aria-label="calendarWeekdayLabel(di)" x-text="d"></div>
            </template>
        </div>
        <template x-for="(week, wi) in calendarWeeks(<?= $calendar['cells'] ?>)" x-bind:key="wi">
            <div class="vb-book-calendar-week" role="row">
                <template x-for="(cell, ci) in week" x-bind:key="ci">
                    <div role="gridcell" x-bind:aria-selected="cell.selected || cell.isSelected || false">
                        <button type="button" class="vb-book-calendar-cell"
                            x-bind:class="calendarCellClasses(cell)"
                            x-bind:data-date="cell.dateStr"
                            x-bind:tabindex="calendarCellTabindex(cell, <?= $calendar['cells'] ?>)"
                            x-bind:aria-label="calendarDateLabel(cell)"
                            x-bind:aria-disabled="calendarLoading || cell.disabled || cell.dayRestricted || false"
                            x-bind:aria-current="calendarToday(cell)"
                            x-bind:disabled="!cell.day"
                            @click="<?= $calendar['select'] ?>(cell)"
                            x-text="cell.day"></button>
                    </div>
                </template>
            </div>
        </template>
    </div>
    <p class="vb-book-calendar-feedback" x-show="calendarLoading" role="status" x-text="t('calendar.loading')"></p>
    <p class="vb-book-calendar-feedback" x-show="!calendarLoading && !hasLoadError && !calendarHasAvailability(<?= $calendar['cells'] ?>)" x-text="t('calendar.no_dates')"></p>
</div>
