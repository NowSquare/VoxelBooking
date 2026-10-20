<!DOCTYPE html>
<html lang="<?= \App\Engine\Locale::getLocale() ?>" dir="<?= \App\Engine\Locale::direction() ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php if (!empty($tenantConfig['manage_mode'])): ?>
    <meta name="robots" content="noindex, nofollow">
    <?php endif; ?>
    <meta name="description" content="<?= htmlspecialchars(__('booking.meta.description', ['name' => $tenant['name']])) ?>">
    <title><?= htmlspecialchars(__('booking.meta.title', ['name' => $tenant['name']])) ?></title>
    <link rel="icon" href="/favicon.ico" type="image/png">



    <!-- Self-hosted Inter (split WOFF2, same as admin shell) -->
    <style>
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url('/fonts/inter-latin-ext.woff2') format('woff2');
            unicode-range: U+0100-02BA, U+02BD-02C5, U+02C7-02CC, U+02CE-02D7, U+02DD-02FF, U+0304, U+0308, U+0329, U+1D00-1DBF, U+1E00-1E9F, U+1EF2-1EFF, U+2020, U+20A0-20AB, U+20AD-20C0, U+2113, U+2C60-2C7F, U+A720-A7FF;
        }
        @font-face {
            font-family: 'Inter';
            font-style: normal;
            font-weight: 100 900;
            font-display: swap;
            src: url('/fonts/inter-latin.woff2') format('woff2');
            unicode-range: U+0000-00FF, U+0131, U+0152-0153, U+02BB-02BC, U+02C6, U+02DA, U+02DC, U+0304, U+0308, U+0329, U+2000-206F, U+20AC, U+2122, U+2191, U+2193, U+2212, U+2215, U+FEFF, U+FFFD;
        }
    </style>

    <link rel="stylesheet" href="/assets/css/booking-css.css?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/css/booking-css.css') ?>">

    <!-- Brand tokens (per-tenant) — must follow compiled CSS to override defaults -->
    <style><?= $brandStyle ?></style>

    <!-- Anti-FOUC: hide until Alpine is ready -->
    <style>
        [x-cloak] { display: none !important; }
    </style>

    <!-- Theme bootstrap: runs before paint to prevent flash of wrong theme -->
    <script>
        (function() {
            var s = localStorage.getItem('vb-theme');
            var t = s || (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>
<body<?= ($isEmbed ?? false) ? ' class="vb-embed-mode"' : '' ?>>
    <div class="vb-book-app" x-data="bookingWizard" x-cloak
         x-init="$el.removeAttribute('x-cloak')"
         id="vb-book-app">

        <!-- ── Demo Banner (above header, hidden in embed mode) ── -->
        <?php if (!($isEmbed ?? false) && \App\Engine\DemoMode::isActive()): ?>
        <div class="vb-book-demo-banner" role="status" x-show="demoNoticeVisible">
            <span><?= htmlspecialchars(__('admin.demo.booking_notice'), ENT_QUOTES, 'UTF-8') ?></span>
            <button type="button" @click="dismissDemoNotice" aria-label="<?= __('booking.common.dismiss_demo') ?>"><i data-lucide="x" aria-hidden="true"></i></button>
        </div>
        <?php endif; ?>

        <!-- ── Header (hidden in embed mode) ── -->
        <?php if (!($isEmbed ?? false)): ?>
        <header class="vb-book-header">
            <?php if (!empty($tenant['cover_image_path'])): ?>
            <div class="vb-book-business-cover"><img src="/<?= htmlspecialchars(ltrim($tenant['cover_image_path'], '/'), ENT_QUOTES, 'UTF-8') ?>" alt="" fetchpriority="high"></div>
            <?php endif; ?>
            <div class="vb-book-header-inner">
                <?php if (!empty($tenant['logo_path'])): ?>
                    <img
                        src="/<?= htmlspecialchars($tenant['logo_path']) ?>"
                        alt="<?= htmlspecialchars($tenant['name']) ?>"
                        class="vb-book-logo"
                    >
                <?php endif; ?>
                <h1 class="vb-book-business-name"><?= htmlspecialchars($tenant['booking_page_heading'] ?: $tenant['name']) ?></h1>
                <p class="vb-book-timezone-context" x-show="showVenueTimezone" x-text="venueTimeLabel"></p>
                <?php if (!empty($tenant['booking_page_description'])): ?>
                    <p class="vb-book-business-desc"><?= htmlspecialchars($tenant['booking_page_description']) ?></p>
                <?php endif; ?>
            </div>

            <nav class="vb-book-phases" x-show="showProgress" aria-label="<?= __('booking.progress.label') ?>">
                <ol>
                    <template x-for="(phase, pi) in phaseSteps" x-bind:key="phase.id">
                        <li x-bind:aria-current="phaseCurrent(phase)" x-bind:class="{ 'is-complete': phaseComplete(phase) }">
                            <span class="vb-book-phase-number" aria-hidden="true" x-text="pi + 1"></span>
                            <span x-text="phase.label"></span>
                        </li>
                    </template>
                </ol>
            </nav>
        </header>
        <?php endif; ?>

        <!-- ── Timezone Selector ── -->
        <div class="vb-book-tz-bar" x-show="showTimezoneSelector">
            <div class="vb-book-tz-inner">
                <button class="vb-book-tz-trigger" type="button" x-bind:aria-expanded="tzDropdownOpen" @click="toggleTzDropdown">
                    <i data-lucide="globe" class="vb-book-tz-icon"></i>
                    <span class="vb-book-tz-label" x-text="tzDisplayLabel(customerTz)"></span>
                    <span class="vb-book-tz-badge" x-show="tzMatch" x-text="t('timezone.same_as_business')"></span>
                    <i data-lucide="chevron-down" class="vb-book-tz-chevron"></i>
                </button>

                <div class="vb-book-tz-dropdown" x-show="tzDropdownOpen" @click.outside="closeTzDropdown" @keydown.escape.window="closeTzDropdown">
                    <div class="vb-book-tz-search-wrap">
                        <input type="text" class="vb-book-tz-search" x-ref="tzSearch"
                               x-bind:value="tzSearchQuery"
                               @input="setTzSearchQuery($el.value)"
                               placeholder="<?= __('booking.timezone.search') ?>"
                               autocomplete="off">
                    </div>
                    <div class="vb-book-tz-list">
                        <template x-for="group in tzGroups" x-bind:key="group.label">
                            <div class="vb-book-tz-group">
                                <div class="vb-book-tz-group-label" x-text="group.label"></div>
                                <template x-for="zone in group.zones" x-bind:key="zone">
                                    <button type="button" class="vb-book-tz-option"
                                            x-bind:class="{ 'is-selected': isZoneSelected(zone) }"
                                            @click="selectTimezone(zone)"
                                            x-text="tzDisplayLabel(zone)">
                                    </button>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── Main Flow ── -->
        <main class="vb-book-flow" id="vb-book-flow">
            <p class="vb-sr-only" role="status" aria-live="polite" aria-atomic="true" x-text="announcement"></p>
            <div x-show="showBookingContext" x-cloak>
                <?php $objectMode = 'context'; include __DIR__ . '/partials/booking-object.php'; ?>
            </div>
            <div class="vb-book-load-error" x-show="hasLoadError && !isLoadError" role="alert" x-cloak>
                <p x-text="loadError"></p>
                <button type="button" class="vb-book-btn vb-book-btn-secondary" @click="retryLoad" x-text="t('buttons.retry')"></button>
            </div>
            <div class="vb-book-step" data-book-step="load-error" x-show="isLoadError" x-cloak>
                <h2 class="vb-book-step-title" x-text="t('errors.load_title')"></h2>
                <p class="vb-book-step-subtitle" x-text="loadError"></p>
                <button type="button" class="vb-book-btn vb-book-btn-primary" @click="retryLoad" x-text="t('buttons.retry')"></button>
            </div>

            <!-- Loading shimmer skeleton -->
            <div data-book-step="loading" x-show="isLoading" x-cloak class="vb-book-step">
                <div class="vb-book-shimmer-block">
                    <div class="vb-book-shimmer-title"></div>
                    <div class="vb-book-shimmer-card"></div>
                    <div class="vb-book-shimmer-card"></div>
                    <div class="vb-book-shimmer-card vb-book-shimmer-card-short"></div>
                </div>
            </div>

            <!-- Empty state -->
            <div data-book-step="empty" x-show="isEmpty" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('empty.no_services')"></h2>
                    <div class="vb-book-step-subtitle" x-text="t('empty.no_services_desc')"></div>
                </div>
            </div>

            <!-- Unsupported pattern -->
            <div data-book-step="unsupported" x-show="isUnsupported" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('empty.coming_soon')"></h2>
                    <div class="vb-book-step-subtitle" x-text="t('empty.coming_soon_desc')"></div>
                </div>
            </div>

            <!-- ═══ Resource Step 1: Room Selection ═══ -->
            <div data-book-step="resource" x-show="isResourceStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('steps.resource_title')"></h2>
                </div>
                <div class="vb-book-service-list" role="radiogroup" @keydown="radiogroupKeydownFocusOnly">
                    <template x-for="(resource, ri) in resources" x-bind:key="resource.id">
                        <button type="button" class="vb-book-service-card"
                             x-bind:class="{ 'is-selected': isResourceSelected(resource) }"
                             x-bind:style="serviceAnimDelay(ri)"
                             @click="selectResource(resource)"
                             role="radio" tabindex="0"
                             x-bind:aria-checked="isResourceSelected(resource)">
                            <template x-if="resource.cover_image_path">
                                <div class="vb-book-resource-cover">
                                    <img x-bind:src="resource.cover_image_path"
                                         x-bind:alt="resource.name"
                                         loading="lazy">
                                </div>
                            </template>
                            <div class="vb-book-service-info">
                                <div class="vb-book-service-name" x-text="resource.name"></div>
                                <div class="vb-book-service-meta">
                                    <span x-text="t('resource.capacity_label', { count: resource.capacity })"></span>
                                    <template x-if="resource.min_stay_nights || resource.max_stay_nights">
                                        <span>· <span x-text="t('resource.stay_range', { min: resource.min_stay_nights, max: resource.max_stay_nights })"></span></span>
                                    </template>
                                </div>
                                <template x-if="resource.description">
                                    <div class="vb-book-service-desc" x-text="resource.description"></div>
                                </template>
                            </div>
                            <template x-if="resource.price_per_night">
                                <div class="vb-book-service-price" x-text="formatPrice(resource.price_per_night) + t('resource.per_night')"></div>
                            </template>
                        </button>
                    </template>
                </div>
            </div>

            <!-- ═══ Resource Step 2: Date Range ═══ -->
            <div data-book-step="resource-date" x-show="isResourceDateStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('steps.dates_title')"></h2>
                    <div class="vb-book-step-subtitle" x-text="checkInDate ? t('resource.select_check_out') : t('resource.select_check_in')"></div>
                    <p class="vb-book-step-subtitle" x-text="resourceStayHint"></p>
                </div>

                <!-- Calendar (reused structure) -->
                <?php $calendar = ['scope' => 'resource', 'cells' => 'resourceCalendarCells', 'month' => 'resourceMonthLabel', 'prev' => 'prevResourceMonth', 'next' => 'nextResourceMonth', 'select' => 'clickResourceDate', 'canPrev' => 'canPrevResourceMonth']; include __DIR__ . '/partials/calendar.php'; ?>

                <!-- Back link -->
                <template x-if="resourceDateBackTarget">
                    <div class="vb-book-back-link">
                        <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="goBack(resourceDateBackTarget)" x-text="t('back.generic')"></button>
                    </div>
                </template>
            </div>

            <!-- ═══ Resource Step 3: Guest Count ═══ -->
            <div data-book-step="guests" x-show="isGuestStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('steps.guests_title')"></h2>
                </div>



                <div class="vb-book-party-size">
                    <div class="vb-book-counter">
                        <button type="button" class="vb-book-counter-btn" @click="decrementGuests" x-bind:aria-label="t('counter.fewer_guests')"
                                x-bind:disabled="guestCount <= 1">
                            <i data-lucide="minus"></i>
                        </button>
                        <span class="vb-book-counter-value" aria-live="polite" x-text="guestCount"></span>
                        <button type="button" class="vb-book-counter-btn" @click="incrementGuests" x-bind:aria-label="t('counter.more_guests')"
                                x-bind:disabled="guestCount >= guestMax">
                            <i data-lucide="plus"></i>
                        </button>
                    </div>
                    <div class="vb-book-counter-label" x-text="guestCount === 1 ? t('capacity.guest') : t('capacity.guests')"></div>
                    <template x-if="guestCount >= guestMax && guestMax > 0">
                        <div class="vb-book-counter-hint"
                             x-text="t('resource.max_guests_reached').replace(':count', guestMax)"></div>
                    </template>
                </div>

                <div class="vb-book-form-actions">
                    <button type="button" class="vb-book-btn vb-book-btn-primary" @click="submitGuests"
                            x-text="t('buttons.continue')"></button>
                    <div class="vb-book-back-link">
                        <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="goBack('resource-date')" x-text="t('back.change_date')"></button>
                    </div>
                </div>
            </div>

            <!-- ═══ Capacity: Step 1 — Party Size ═══ -->
            <div data-book-step="party-size" x-show="isPartySizeStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('capacity.party_size_title')"></h2>
                    <div class="vb-book-step-subtitle" x-text="t('capacity.party_size_hint')"></div>
                </div>
                <div class="vb-book-party-size">
                    <div class="vb-book-counter">
                        <button type="button" class="vb-book-counter-btn" @click="decrementPartySize" x-bind:aria-label="t('counter.fewer_guests')"
                                x-bind:disabled="partySize <= minPartySize">
                            <i data-lucide="minus"></i>
                        </button>
                        <span class="vb-book-counter-value" aria-live="polite" x-text="partySize"></span>
                        <button type="button" class="vb-book-counter-btn" @click="incrementPartySize" x-bind:aria-label="t('counter.more_guests')"
                                x-bind:disabled="partySize >= maxPartySize">
                            <i data-lucide="plus"></i>
                        </button>
                    </div>
                    <div class="vb-book-counter-label" x-text="partySize === 1 ? t('capacity.guest') : t('capacity.guests')"></div>
                    <template x-if="minPartySize > 1">
                        <div class="vb-book-counter-hint" x-text="t('capacity.min_guests_hint').replace(':count', minPartySize)"></div>
                    </template>
                    <template x-if="partySize >= maxPartySize && maxPartySize > 0">
                        <div class="vb-book-counter-hint" x-text="t('capacity.max_party_size_reached').replace(':count', maxPartySize)"></div>
                    </template>
                </div>
                <div class="vb-book-form-actions">
                    <button type="button" class="vb-book-btn vb-book-btn-primary" @click="confirmPartySize"
                            x-text="t('buttons.continue')"></button>
                </div>
            </div>

            <!-- ═══ Capacity: Step 2 — Date Selection ═══ -->
            <div data-book-step="capacity-date" x-show="isCapacityDateStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('capacity.date_title')"></h2>
                </div>
                <?php $calendar = ['scope' => 'capacity', 'cells' => 'capacityCalendarGrid', 'month' => 'capacityMonthLabel', 'prev' => 'prevCapacityMonth', 'next' => 'nextCapacityMonth', 'select' => 'selectCapacityDate']; include __DIR__ . '/partials/calendar.php'; ?>
                <div class="vb-book-form-actions">
                    <div class="vb-book-back-link">
                        <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="goBack('party-size')"
                                x-text="t('back.change_party_size')"></button>
                    </div>
                </div>
            </div>

            <!-- ═══ Capacity: Step 3 — Time Slot Selection ═══ -->
            <div data-book-step="capacity-time" x-show="isCapacityTimeStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('capacity.time_title')"></h2>
                    <div class="vb-book-step-subtitle" x-text="formatDateDisplay(selectedDate)"></div>
                </div>

                <!-- Selection confirmation (sticky, above slot list) -->
                <div class="vb-book-selection-confirm" x-show="selectedCapacitySlot" x-cloak>
                    <div class="vb-book-selection-badge">
                        <i data-lucide="calendar-check"></i>
                        <div class="vb-book-selection-detail">
                            <span class="vb-book-selection-label" x-text="t('buttons.selected_time')"></span>
                            <span class="vb-book-selection-value" x-text="capacitySlotLabel"></span>
                        </div>
                    </div>
                    <button type="button" class="vb-book-btn vb-book-btn-primary vb-book-btn-continue"
                            @click="confirmCapacitySlot"
                            x-text="t('buttons.continue')">
                    </button>
                </div>

<p x-show="slotsLoading" class="vb-book-empty" role="status" x-text="t('availability.loading_times')"></p>
                <div class="vb-book-slot-list" role="radiogroup" @keydown="radiogroupKeydown">
                    <template x-for="slot in capacitySlots" x-bind:key="slot.id">
                        <button type="button"
                                class="vb-book-slot-card"
                                role="radio"
                                x-bind:class="{ 'is-dimmed': selectedCapacitySlot && selectedCapacitySlot.id !== slot.id }"
                                x-bind:aria-checked="selectedCapacitySlot && selectedCapacitySlot.id === slot.id"
                                @click="selectCapacitySlot(slot)">
                            <div class="vb-book-slot-time">
                                <span x-text="formatCapacitySlotTime(slot.time, slot.start_utc) + ' – ' + formatCapacitySlotTime(slot.end_time, slot.end_utc)"></span>
                                <span class="vb-book-slot-label" x-show="slot.label" x-text="slot.label"></span>
                            </div>
                            <div class="vb-book-slot-meta">
                                <span class="vb-book-slot-remaining" x-text="t('capacity.spots_remaining').replace(':count', slot.remaining)"></span>
                            </div>
                        </button>
                    </template>
                </div>
                <div x-show="capacitySlots.length === 0 && !slotsLoading && !hasLoadError" class="vb-book-empty">
                    <span x-text="t('empty.no_slots_date')"></span>
                </div>

                <div class="vb-book-form-actions">
                    <div class="vb-book-back-link">
                        <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="goBack('capacity-date')"
                                x-text="t('back.change_date_cap')"></button>
                    </div>
                </div>
            </div>

            <!-- ═══ Event Step 1: Event List ═══ -->
            <div data-book-step="event-list" x-show="isEventListStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('event.events_title')"></h2>
                </div>
                <div class="vb-book-event-list">
                    <template x-for="(event, i) in eventList" :key="event.id + '-' + (event.date || '')">
                        <button type="button"
                                class="vb-book-event-card"
                                @click="selectEvent(event)"
                                x-bind:disabled="!eventCanBook(event)"
                                :class="{ 'is-full': !eventCanBook(event) }">
                            <div class="vb-book-event-card-header">
                                <span class="vb-book-event-name" x-text="event.name"></span>
                                <span class="vb-book-event-price" x-text="formatEventPrice(event.price)"></span>
                            </div>
                            <div class="vb-book-event-card-meta">
                                <span class="vb-book-event-date">
                                    <i data-lucide="calendar"></i>
                                    <span x-text="formatEventDate(event.start_datetime, event.start_utc)"></span>
                                </span>
                                <span class="vb-book-event-time">
                                    <i data-lucide="clock"></i>
                                    <span x-text="formatEventTime(event.start_datetime, event.start_utc) + ' – ' + formatEventTime(event.end_datetime, event.end_utc)"></span>
                                </span>
                                <span x-show="event.location" class="vb-book-event-location">
                                    <i data-lucide="map-pin"></i>
                                    <span x-text="event.location"></span>
                                </span>
                            </div>
                            <div class="vb-book-event-card-footer">
                                <span x-show="event.remaining > 0 && eventCanBook(event)"
                                      class="vb-book-event-spots"
                                      x-text="t('event.spots_remaining').replace(':count', event.remaining)"></span>
                                <span x-show="eventWaitlistOnly(event)"
                                      class="vb-book-event-badge vb-book-event-badge-waitlist"
                                      x-text="t('event.waitlist_badge')"></span>
                                <span x-show="!eventCanBook(event)"
                                      class="vb-book-event-badge vb-book-event-badge-full"
                                      x-text="eventUnavailableLabel(event)"></span>
                            </div>
                        </button>
                    </template>
                </div>
                <div x-show="eventList.length === 0" class="vb-book-empty">
                    <span x-text="t('empty.no_events')"></span>
                </div>
            </div>

            <!-- ═══ Event Step 2: Event Detail ═══ -->
            <div data-book-step="event-detail" x-show="isEventDetailStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('event.event_detail_title')"></h2>
                </div>
                <template x-if="selectedEvent">
                    <div class="vb-book-event-detail">
                        <h3 class="vb-book-event-detail-name" x-text="eventName"></h3>
                        <p class="vb-book-event-detail-desc" x-show="hasEventDescription" x-text="eventDescription"></p>

                        <div class="vb-book-event-detail-grid">
                            <div class="vb-book-event-detail-row">
                                <span class="vb-book-event-detail-label" x-text="t('event.date_label')"></span>
                                <span x-text="formatEventDate(eventStartDatetime, selectedEvent.start_utc)"></span>
                            </div>
                            <div class="vb-book-event-detail-row">
                                <span class="vb-book-event-detail-label" x-text="t('event.time_label')"></span>
                                <span x-text="eventTimeSummary"></span>
                            </div>
                            <div class="vb-book-event-detail-row" x-show="hasEventLocation">
                                <span class="vb-book-event-detail-label" x-text="t('event.location_label')"></span>
                                <span x-text="eventLocation"></span>
                            </div>
                            <div class="vb-book-event-detail-row">
                                <span class="vb-book-event-detail-label" x-text="t('event.price_label')"></span>
                                <span x-text="formatEventPrice(eventPrice)"></span>
                            </div>
                            <div class="vb-book-event-detail-row">
                                <span class="vb-book-event-detail-label" x-text="t('event.spots_remaining').replace(':count', '')"></span>
                                <span x-text="eventCapacityLabel"></span>
                            </div>
                        </div>

                        <div class="vb-book-form-actions">
                            <p class="vb-book-step-subtitle" x-show="isEventWaitlistSelection" x-text="t('event.waitlist_notice')"></p>
                            <button type="button" class="vb-book-btn vb-book-btn-primary" @click="confirmEventDetail"
                                    x-text="t('buttons.continue')"></button>
                            <div class="vb-book-back-link">
                                <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="goBack('event-list')"
                                        x-text="t('back.change_event')"></button>
                            </div>
                        </div>
                    </div>
                </template>
            </div>

            <!-- ═══ Event Step 3: Spot Count ═══ -->
            <div data-book-step="event-spots" x-show="isEventSpotsStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('event.spots_title')"></h2>
                </div>
                <div class="vb-book-party-size">
                    <div class="vb-book-counter">
                        <button type="button" class="vb-book-counter-btn" @click="decrementEventSpots" x-bind:aria-label="t('counter.fewer_spots')"
                                x-bind:disabled="eventSpotCount <= eventMinSpots">
                            <i data-lucide="minus"></i>
                        </button>
                        <span class="vb-book-counter-value" aria-live="polite" x-text="eventSpotCount"></span>
                        <button type="button" class="vb-book-counter-btn" @click="incrementEventSpots" x-bind:aria-label="t('counter.more_spots')"
                                x-bind:disabled="eventSpotCount >= eventMaxSpots">
                            <i data-lucide="plus"></i>
                        </button>
                    </div>
                    <div class="vb-book-counter-label" x-text="eventSpotCount === 1 ? t('event.spot') : t('event.spots')"></div>
                    <template x-if="showEventMaxHint">
                        <div class="vb-book-counter-hint" x-text="eventMaxHintText">
                        </div>
                    </template>
                </div>
                <div class="vb-book-form-actions">
                    <button type="button" class="vb-book-btn vb-book-btn-primary" @click="confirmEventSpots"
                            x-text="t('buttons.continue')"></button>
                    <div class="vb-book-back-link">
                        <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="goBack('event-detail')"
                                x-text="t('back.change_event')"></button>
                    </div>
                </div>
            </div>

            <!-- ═══ Step 1: Service Selection ═══ -->
            <div data-book-step="service" x-show="isServiceStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('steps.service_title')"></h2>
                </div>
                <div class="vb-book-service-list" role="radiogroup" @keydown="radiogroupKeydownFocusOnly">
                    <template x-for="(service, si) in services" x-bind:key="service.id">
                        <button type="button" class="vb-book-service-card"
                             x-bind:class="{ 'is-selected': isServiceSelected(service) }"
                             x-bind:style="serviceAnimDelay(si) + (service.color ? '; --svc-color: ' + service.color : '')"
                             @click="selectService(service)"
                             role="radio" tabindex="0"
                             x-bind:aria-checked="isServiceSelected(service)">
                            <template x-if="service.cover_image_path">
                                <div class="vb-book-service-cover">
                                    <img x-bind:src="service.cover_image_path"
                                         x-bind:alt="service.name"
                                         loading="lazy">
                                </div>
                            </template>
                            <div class="vb-book-service-info">
                                <div class="vb-book-service-name" x-text="service.name"></div>
                                <div class="vb-book-service-meta">
                                    <span class="vb-book-service-meta-item">
                                        <svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.2"/><path d="M8 5v3.5l2.5 1.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        <span x-text="formatDuration(service.duration_minutes)"></span>
                                    </span>
                                </div>
                                <template x-if="service.description">
                                    <div class="vb-book-service-desc" x-text="service.description"></div>
                                </template>
                            </div>
                            <template x-if="hasPrice(service)">
                                <div class="vb-book-service-price" x-text="servicePriceLabel(service)"></div>
                            </template>
                        </button>
                    </template>
                </div>
            </div>

            <!-- ═══ Step 2: Staff Selection ═══ -->
            <div data-book-step="staff" x-show="isStaffStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('steps.staff_title')"></h2>
                    <div class="vb-book-step-subtitle" x-text="t('steps.staff_subtitle')"></div>
                </div>
                <div class="vb-book-staff-grid" role="radiogroup" @keydown="radiogroupKeydownFocusOnly">
                    <!-- Any available -->
                    <button type="button" class="vb-book-staff-card"
                         x-bind:class="{ 'is-selected': isAnyStaffSelected() }"
                         @click="selectAnyStaff"
                         role="radio" tabindex="0"
                         x-bind:aria-checked="isAnyStaffSelected()">
                        <div class="vb-book-staff-avatar">
                            <i data-lucide="users"></i>
                        </div>
                        <div class="vb-book-staff-name" x-text="t('staff.any_available')"></div>
                    </button>
                    <!-- Staff members -->
                    <template x-for="member in staff" x-bind:key="member.id">
                        <button type="button" class="vb-book-staff-card"
                             x-bind:class="{ 'is-selected': isStaffSelected(member) }"
                             @click="selectStaff(member)"
                             role="radio" tabindex="0"
                             x-bind:aria-checked="isStaffSelected(member)">
                            <div class="vb-book-staff-avatar">
                                <template x-if="hasAvatar(member)">
                                    <img x-bind:src="avatarUrl(member)" x-bind:alt="member.name">
                                </template>
                                <template x-if="noAvatar(member)">
                                    <span x-text="staffInitials(member.name)"></span>
                                </template>
                            </div>
                            <div class="vb-book-staff-name" x-text="member.name"></div>
                            <template x-if="member.title">
                                <div class="vb-book-staff-title" x-text="member.title"></div>
                            </template>
                        </button>
                    </template>
                </div>
                <!-- Back link -->
                <template x-if="showStaffBackLink">
                    <div class="vb-book-back-link">
                        <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="goBack('service')" x-text="t('back.change_service')"></button>
                    </div>
                </template>
            </div>

            <!-- ═══ Step 3: Date & Time ═══ -->
            <div data-book-step="date" x-show="isDateStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('steps.date_title')"></h2>
                </div>



                <div class="vb-book-finder">
                    <button type="button" class="vb-book-finder-toggle" @click="toggleFinder" x-bind:aria-expanded="finderOpen" aria-controls="vb-find-time">
                        <i data-lucide="clock" aria-hidden="true"></i><span x-text="t('finder.open')"></span>
                    </button>
                    <div id="vb-find-time" class="vb-book-finder-body" x-show="finderOpen" x-cloak>
                        <h3 x-text="t('finder.title')"></h3>
                        <p x-text="t('finder.description')"></p>
                        <div class="vb-book-finder-controls">
                            <label for="vb-finder-after" x-text="t('finder.time_label')"></label>
                            <select id="vb-finder-after" class="vb-book-input" @change="setFinderAfter($el.value)">
                                <template x-for="option in finderTimeOptions" x-bind:key="option.value"><option x-bind:value="option.value" x-text="option.label"></option></template>
                            </select>
                            <button type="button" class="vb-book-btn vb-book-btn-secondary" @click="findTimes" x-bind:disabled="finderLoading" x-text="finderLoading ? t('finder.searching') : t('finder.search')"></button>
                        </div>
                        <p class="vb-book-field-error" x-show="finderError" x-text="finderError" role="alert"></p>
                        <p x-show="finderSearched && finderResults.length === 0" x-text="t('finder.no_results')"></p>
                        <div class="vb-book-finder-results">
                            <template x-for="result in finderResults" x-bind:key="result.date">
                                <button type="button" @click="chooseFinderResult(result)"><span x-text="finderResultLabel(result)"></span><span x-text="t('finder.choose')"></span></button>
                            </template>
                        </div>
                    </div>
                </div>
                <!-- Timezone mismatch notice -->
                <div class="vb-book-tz-notice" x-show="isTzMismatch">
                    <i data-lucide="globe" class="vb-book-tz-notice-icon"></i>
                    <span x-text="t('timezone.notice', { tz: tzDisplayLabel(customerTz) })"></span>
                </div>

                <div class="vb-book-availability-layout">
                <!-- Calendar -->
                <?php $calendar = ['scope' => 'timeslot', 'cells' => 'calendarCells', 'month' => 'monthLabel', 'prev' => 'prevMonth', 'next' => 'nextMonth', 'select' => 'clickDate', 'canPrev' => 'canPrevMonth']; include __DIR__ . '/partials/calendar.php'; ?>

                <!-- Time Slots -->
                <div id="vb-time-container" class="vb-book-times-panel" x-bind:aria-busy="slotsLoading">
                    <h3 class="vb-book-times-heading" x-text="selectedTimesHeading"></h3>
                    <p class="vb-book-times-zone" x-show="hasTimezoneDifference" x-text="venueTimeOrCustomerLabel"></p>
                    <p class="vb-book-empty" x-show="!hasSelectedDate" x-text="t('availability.choose_date_hint')"></p>
                    <p class="vb-book-empty" x-show="slotsLoading" role="status" x-text="t('availability.loading_times')"></p>
                    <template x-if="hasNoSlots">
                        <div class="vb-book-empty" x-text="t('empty.no_times')"></div>
                    </template>
                    <template x-if="hasSlots">
                        <div class="vb-book-time-sections" role="radiogroup" @keydown="radiogroupKeydown">
                            <template x-for="(group, gi) in groupedSlots" x-bind:key="group.label">
                                <div class="vb-book-time-section">
                                    <template x-if="groupedSlots.length > 1">
                                        <div class="vb-book-time-section-label" x-text="group.label"></div>
                                    </template>
                                    <div class="vb-book-time-grid">
                                        <template x-for="(slot, i) in group.slots" x-bind:key="slot.time">
                                            <button type="button" class="vb-book-time-pill"
                                                 x-bind:class="{
                                                     'is-selected': isSlotSelected(slot),
                                                     'is-dimmed': isSlotDimmed(slot)
                                                 }"
                                                 @click="selectSlot(slot)"
                                                 role="radio" x-bind:tabindex="slotTabindex(slot)"
                                                 x-bind:aria-checked="isSlotSelected(slot)"
                                                 x-bind:style="slotAnimDelay(i)">
                                                <i data-lucide="check" class="vb-pill-check" x-show="isSlotSelected(slot)"></i>
                                                <span x-text="displaySlotTime(slot)"></span><small class="vb-book-slot-date" x-show="slotDateNote(slot)" x-text="slotDateNote(slot)"></small>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                </div>
                <!-- Selection confirmation (sticky, above calendar/grid) -->
                <div class="vb-book-selection-confirm" x-show="selectedSlot" x-cloak>
                    <div class="vb-book-selection-badge">
                        <i data-lucide="calendar-check"></i>
                        <div class="vb-book-selection-detail">
                            <span class="vb-book-selection-label" x-text="t('buttons.selected_time')"></span>
                            <span class="vb-book-selection-value" x-text="selectedSlotLabel"></span>
                        </div>
                    </div>
                    <button type="button" class="vb-book-btn vb-book-btn-primary vb-book-btn-continue"
                            @click="confirmSlot"
                            x-text="t('buttons.continue')">
                    </button>
                </div>
                <!-- Back link -->
                <template x-if="dateBackTarget">
                    <div class="vb-book-back-link">
                        <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="goBack(dateBackTarget)"
                                x-text="dateBackLabel"></button>
                    </div>
                </template>
            </div>

            <!-- ═══ Step 4: Customer Details ═══ -->
            <div data-book-step="details" x-show="isDetailsStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('steps.details_title')"></h2>
                    <div class="vb-book-step-subtitle" x-text="t('steps.details_subtitle')"></div>
                </div>

                <form @submit.prevent="submitDetails" novalidate>
                    <div class="vb-book-form-group">
                        <label class="vb-book-label" for="vb-name"><?= __('booking.form.name_label') ?> <span class="vb-book-required" aria-hidden="true">*</span></label>
                        <input class="vb-book-input" id="vb-name" type="text" required
                               x-bind:value="customerName"
                               @input="setCustomerName($el.value)"
                               x-bind:class="{ 'has-error': hasError('name') }"
                               x-bind:aria-invalid="hasError('name')" aria-describedby="vb-name-error"
                               placeholder="<?= __('booking.form.name_placeholder') ?>"
                               autocomplete="name">
                        <p class="vb-book-field-error" id="vb-name-error" x-show="hasError('name')" x-text="errorMessage('name')"></p>
                    </div>

                    <div class="vb-book-form-group">
                        <label class="vb-book-label" for="vb-email"><?= __('booking.form.email_label') ?> <span class="vb-book-required" aria-hidden="true">*</span></label>
                        <input class="vb-book-input" id="vb-email" type="email" required
                               x-bind:value="customerEmail"
                               @input="setCustomerEmail($el.value)"
                               x-bind:class="{ 'has-error': hasError('email') }"
                               x-bind:aria-invalid="hasError('email')" aria-describedby="vb-email-error"
                               placeholder="<?= __('booking.form.email_placeholder') ?>"
                               autocomplete="email">
                        <p class="vb-book-field-error" id="vb-email-error" x-show="hasError('email')" x-text="errorMessage('email')"></p>
                    </div>

                    <?php /* Phone field — only rendered when require_phone is true */ ?>
                    <template x-if="config.require_phone">
                        <div class="vb-book-form-group">
                            <label class="vb-book-label" for="vb-phone"><?= __('booking.form.phone_label') ?> <span class="vb-book-required" aria-hidden="true">*</span></label>
                            <input class="vb-book-input" id="vb-phone" type="tel" required
                                   x-bind:value="customerPhone"
                                   @input="setCustomerPhone($el.value)"
                                   x-bind:class="{ 'has-error': hasError('phone') }"
                               x-bind:aria-invalid="hasError('phone')" aria-describedby="vb-phone-error"
                                   placeholder="<?= __('booking.form.phone_placeholder') ?>"
                                   autocomplete="tel">
                        <p class="vb-book-field-error" id="vb-phone-error" x-show="hasError('phone')" x-text="errorMessage('phone')"></p>
                        </div>
                    </template>

                    <div class="vb-book-form-group">
                        <label class="vb-book-label" for="vb-notes"><?= __('booking.form.notes_label') ?></label>
                        <textarea class="vb-book-textarea" id="vb-notes" rows="3"
                                  x-bind:value="customerNotes"
                                  @input="setCustomerNotes($el.value)"
                                  placeholder="<?= __('booking.form.notes_placeholder') ?>"></textarea>
                    </div>

                    <template x-for="field in config.custom_fields" x-bind:key="fieldName(field)">
                        <div class="vb-book-form-group">
                            <label class="vb-book-label" x-bind:for="customFieldId(field)" x-text="fieldLabel(field)"></label>
                            <template x-if="isTextarea(field)">
                                <textarea class="vb-book-textarea" rows="3" x-bind:id="customFieldId(field)" x-bind:data-book-custom="fieldName(field)"
                                    x-bind:value="customValue(field)" @input="updateCustomField" x-bind:placeholder="fieldPlaceholder(field)"
                                    x-bind:required="field.required" x-bind:aria-invalid="customHasError(field)" x-bind:aria-describedby="customErrorId(field)"></textarea>
                            </template>
                            <template x-if="isPlainInput(field)">
                                <input class="vb-book-input" x-bind:id="customFieldId(field)" x-bind:type="customInputType(field)" x-bind:data-book-custom="fieldName(field)"
                                    x-bind:value="customValue(field)" @input="updateCustomField" x-bind:placeholder="fieldPlaceholder(field)"
                                    x-bind:min="field.min" x-bind:max="field.max" x-bind:step="field.step" x-bind:required="field.required"
                                    x-bind:aria-invalid="customHasError(field)" x-bind:aria-describedby="customErrorId(field)">
                            </template>
                            <template x-if="isSelect(field)">
                                <select class="vb-book-input" x-bind:id="customFieldId(field)" x-bind:data-book-custom="fieldName(field)" @change="updateCustomField"
                                    x-bind:required="field.required" x-bind:aria-invalid="customHasError(field)" x-bind:aria-describedby="customErrorId(field)">
                                    <option value="" x-text="t('form.select_option')"></option>
                                    <template x-for="option in field.options" x-bind:key="customOptionValue(option)">
                                        <option x-bind:value="customOptionValue(option)" x-bind:selected="customOptionSelected(field, option)" x-text="customOptionLabel(option)"></option>
                                    </template>
                                </select>
                            </template>
                            <template x-if="isCheckbox(field)">
                                <input type="checkbox" class="vb-book-consent-checkbox" x-bind:id="customFieldId(field)" x-bind:data-book-custom="fieldName(field)"
                                    x-bind:checked="customValue(field)" @change="updateCustomField" x-bind:required="field.required"
                                    x-bind:aria-invalid="customHasError(field)" x-bind:aria-describedby="customErrorId(field)">
                            </template>
                            <p class="vb-book-field-error" x-bind:id="customErrorId(field)" x-show="customHasError(field)" x-text="customErrorMessage(field)"></p>
                        </div>
                    </template>

                    <!-- Consent -->
                    <template x-if="config.requires_consent">
                        <div>
                            <div class="vb-book-consent" x-bind:class="{ 'has-error': hasError('consent') }">
                                <input type="checkbox" class="vb-book-consent-checkbox" id="vb-consent"
                                       x-bind:checked="consentGiven"
                                       @change="setConsentGiven($el.checked)"
                                       aria-required="true" x-bind:aria-invalid="hasError('consent')" aria-describedby="vb-consent-error">
                                <label class="vb-book-consent-label" for="vb-consent" x-text="consentLabel()"></label>
                            </div>
                            <div id="vb-consent-error" class="vb-book-consent-error" x-show="hasError('consent')" x-cloak
                                 x-text="t('errors.required_consent')"></div>
                        </div>
                    </template>

                    <!-- Honeypot: invisible to humans, caught by bots -->
                    <div class="vb-book-hp" aria-hidden="true" tabindex="-1">
                        <input type="text" name="__hp" autocomplete="off" tabindex="-1">
                    </div>

                    <div class="vb-book-form-actions">
                        <button type="submit" class="vb-book-btn vb-book-btn-primary">
                            <span class="vb-book-btn-text" x-text="t('buttons.review')"></span>
                        </button>
                        <div class="vb-book-back-link">
                            <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="goBack(activeDetailsBackTarget)" x-text="activeDetailsBackLabel"></button>
                        </div>
                    </div>
                </form>
            </div>

            <!-- ═══ Step 5: Review / Summary ═══ -->
            <div data-book-step="review" x-show="isReviewStep" x-cloak class="vb-book-step">
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('steps.confirm_title')"></h2>
                    <div class="vb-book-step-subtitle" x-text="t('steps.confirm_subtitle')"></div>
                    <p class="vb-book-step-subtitle" x-show="isEventWaitlistSelection" x-text="t('event.waitlist_notice')"></p>
                </div>

                <?php $objectMode = 'review'; include __DIR__ . '/partials/booking-object.php'; ?>

                <!-- Preparation callout (service-level, e.g. "Please arrive 10 minutes early") -->
                <template x-if="preparationText">
                    <div class="vb-book-preparation-callout" x-text="preparationText"></div>
                </template>

                <!-- Slot-taken recovery panel -->
                <template x-if="slotAlternatives.length > 0">
                    <div class="vb-book-slot-recovery">
                        <p class="vb-book-slot-recovery-msg" x-text="t('recovery.slot_taken')"></p>
                        <div class="vb-book-slot-recovery-pills">
                            <template x-for="alt in slotAlternatives" x-bind:key="alt.time">
                                <button type="button" class="vb-book-slot-pill"
                                        @click="selectAlternative(alt)"
                                        x-text="formatSlotTime(alt.time, alt.start_utc)"></button>
                            </template>
                        </div>
                    </div>
                </template>

                <p class="vb-book-review-notice" x-show="config.requires_approval" x-text="t('review.approval_hint')"></p>
                <div class="vb-book-form-actions">
                    <button class="vb-book-btn vb-book-btn-primary" @click="activeSubmitHandler()"
                            x-bind:disabled="submitting"
                            x-bind:class="{ 'is-loading': submitting }">
                        <span class="vb-book-btn-text" x-text="reviewSubmitLabel"></span>
                    </button>
                    <div class="vb-book-back-link">
                        <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="goBack('details')" x-bind:disabled="submitting" x-text="t('back.edit_details')"></button>
                    </div>

                    <!-- Cancellation policy disclosure -->
                    <template x-if="hasCancellationPolicy">
                        <div class="vb-book-policy-wrap">
                            <button type="button" class="vb-book-policy-toggle"
                                    x-bind:class="{ 'is-open': policyOpen }"
                                    @click="togglePolicy"
                                    x-bind:aria-expanded="policyOpen">
                                <svg class="vb-book-policy-chevron" viewBox="0 0 16 16" fill="none">
                                    <path d="M6 4l4 4-4 4" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <span x-text="t('review.cancellation_policy_label')"></span>
                            </button>
                            <div class="vb-book-policy-text" x-show="policyOpen" x-transition
                                 x-text="cancellationPolicyText"></div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- ═══ Step 6: Confirmation ═══ -->
            <div data-book-step="confirmed" x-show="isConfirmedStep" x-cloak class="vb-book-step">
                <div class="vb-book-confirmation">
                    <?php $outcome = 'booking'; include __DIR__ . '/partials/outcome.php'; ?>

                    <?php $objectMode = 'confirmed'; include __DIR__ . '/partials/booking-object.php'; ?>

                    <!-- Primary calendar actions (hidden when no calendar data) -->
                    <template x-if="hasCalendarActions">
                        <div class="vb-book-confirm-actions">
                            <a x-bind:href="gcalUrl" target="_blank" rel="noopener" class="vb-book-btn vb-book-btn-secondary">
                                <i data-lucide="calendar" class="vb-book-btn-icon"></i>
                                <span class="vb-book-btn-text" x-text="t('buttons.add_to_calendar')"></span>
                            </a>
                            <button type="button" class="vb-book-btn vb-book-btn-secondary" @click="downloadIcs">
                                <i data-lucide="download" class="vb-book-btn-icon"></i>
                                <span class="vb-book-btn-text" x-text="t('buttons.download_ics')"></span>
                            </button>
                        </div>
                    </template>

                    <!-- Secondary actions: book another, reschedule, cancel -->
                    <div class="vb-book-confirm-actions-secondary">
                        <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="bookAnother"
                                x-text="t('buttons.book_another')"></button>
                        <template x-if="booking">
                            <a x-bind:href="manageUrl(booking.id)" class="vb-book-btn vb-book-btn-ghost" x-text="t('buttons.view_booking')"></a>
                        </template>
                    </div>
                </div>
            </div>

            <!-- ── Step: Manage Booking ── -->
            <div class="vb-book-step" data-book-step="manage" x-show="isManageStep">
                <div class="vb-book-manage-container">
                    <!-- Loading state -->
                    <template x-if="manageLoading">
                        <div class="vb-book-manage-loading">
                            <div class="vb-book-spinner"></div>
                            <p x-text="t('manage.loading')"></p>
                        </div>
                    </template>

                    <!-- Cancelled state -->
                    <template x-if="!manageLoading && manageCancelled">
                        <div class="vb-book-manage-cancelled">
                            <?php $outcome = 'cancelled'; include __DIR__ . '/partials/outcome.php'; ?>
                            <div class="vb-book-confirm-actions-secondary">
                                <a x-bind:href="bookingPageUrl" class="vb-book-btn vb-book-btn-primary"
                                   x-text="t('manage.book_again')"></a>
                            </div>
                        </div>
                    </template>

                    <!-- Active booking management -->
                    <template x-if="!manageLoading && managedBooking && !manageCancelled">
                        <div class="vb-book-manage-active">
                            <!-- Status hero: icon + badge -->
                            <div class="vb-book-manage-status">
                                <div class="vb-book-manage-status-icon"
                                     x-bind:class="'is-' + managedBooking.status">
                                    <template x-if="managedBooking.status === 'confirmed'">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6 9 17l-5-5"/></svg>
                                    </template>
                                    <template x-if="managedBooking.status === 'cancelled'">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M18 6 6 18"/><path d="m6 6 12 12"/></svg>
                                    </template>
                                    <template x-if="managedBooking.status === 'completed'">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="m9 11 3 3L22 4"/></svg>
                                    </template>
                                    <template x-if="managedBooking.status === 'rescheduled'">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M21 7.5V6a2 2 0 0 0-2-2H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h3.5"/><path d="M16 2v4"/><path d="M8 2v4"/><path d="M3 10h5"/><path d="M17.5 17.5 16 16.3V14"/><circle cx="16" cy="16" r="6"/></svg>
                                    </template>
                                    <template x-if="managedBooking.status === 'pending' || managedBooking.status === 'waitlisted'">
                                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    </template>
                                </div>
                                <h2 class="vb-book-step-title" x-text="t('manage.heading')"></h2>
                                <span class="vb-book-manage-status-badge"
                                      x-bind:class="'is-' + managedBooking.status"
                                      x-text="manageStatusLabel"></span>
                            </div>

                            <!-- Booking details card -->
                            <?php $objectMode = 'managed'; include __DIR__ . '/partials/booking-object.php'; ?>

                            <!-- Action buttons -->
                            <div class="vb-book-manage-actions">
                                <template x-if="replacementBookingUrl">
                                    <a x-bind:href="replacementBookingUrl" class="vb-book-btn vb-book-btn-primary"
                                       x-text="t('manage.view_current_booking')"></a>
                                </template>
                                <!-- Reschedule button -->
                                <template x-if="manageCanReschedule">
                                    <button type="button"
                                            class="vb-book-btn vb-book-btn-primary"
                                            @click="startReschedule"
                                            x-text="t('buttons.reschedule')">
                                    </button>
                                </template>

                                <!-- Reschedule disabled explanation -->
                                <template x-if="!manageCanReschedule && managedBooking.status === 'confirmed' && manageRescheduleReason">
                                    <p class="vb-book-manage-gate-msg" x-text="rescheduleGateMessage"></p>
                                </template>

                                <!-- Cancel button -->
                                <template x-if="manageCanCancel">
                                    <button type="button"
                                            class="vb-book-btn vb-book-btn-danger"
                                            @click="openCancellation"
                                            x-text="t('buttons.cancel_booking')">
                                    </button>
                                </template>

                                <!-- Time gate message for cancel -->
                                <template x-if="!manageCanCancel && managedBooking.status === 'confirmed' && config.allow_cancellation">
                                    <p class="vb-book-manage-gate-msg" x-text="t('manage.time_gate_cancel')"></p>
                                </template>

                                <!-- Book another -->
                                <a x-bind:href="bookingPageUrl" class="vb-book-btn vb-book-btn-ghost"
                                   x-text="t('buttons.book_another')"></a>

                                <!-- Privacy / data rights -->
                                <template x-if="privacyUrl">
                                    <a x-bind:href="privacyUrl" class="vb-book-manage-privacy-link">
                                        <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/></svg>
                                        <span x-text="t('manage.privacy_link')"></span>
                                    </a>
                                </template>
                            </div>
                        </div>
                    </template>

                </div>
            </div>

            <!-- Cancel confirmation modal — placed outside .vb-book-step to avoid
                 CSS transform containment (transform creates a new containing block
                 for position:fixed, breaking full-viewport backdrop coverage). -->
            <div class="vb-book-modal-overlay" x-show="manageCancelModalOpen" x-transition.opacity x-cloak>
                <div class="vb-book-modal" role="dialog" aria-modal="true" aria-labelledby="vb-cancel-title" aria-describedby="vb-cancel-description" @keydown="cancelDialogKeydown" @click.outside="closeCancellation">
                    <h3 id="vb-cancel-title" class="vb-book-modal-title" x-text="t('manage.cancel_heading')"></h3>
                    <p id="vb-cancel-description" class="vb-book-modal-body" x-text="t('manage.cancel_confirm')"></p>

                    <div class="vb-book-modal-field">
                        <label class="vb-book-label" for="vb-cancel-reason" x-text="t('manage.cancel_reason_label')"></label>
                        <textarea id="vb-cancel-reason" class="vb-book-input vb-book-textarea"
                                  rows="3"
                                  x-bind:placeholder="t('manage.cancel_reason_placeholder')"
                                  @input="setManageCancelReason($event.target.value)"></textarea>
                    </div>

                    <div class="vb-book-modal-actions">
                        <button type="button"
                                class="vb-book-btn vb-book-btn-ghost"
                                @click="closeCancellation" data-cancel-keep
                                x-text="t('manage.cancel_nevermind')">
                        </button>
                        <button type="button"
                                class="vb-book-btn vb-book-btn-danger"
                                @click="cancelManagedBooking"
                                x-bind:disabled="manageCancelling">
                            <span x-show="!manageCancelling" x-text="t('manage.cancel_button')"></span>
                            <span x-show="manageCancelling" class="vb-book-spinner-inline"></span>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ═══ Reschedule Step 1: Date & Time Selection ═══ -->
            <div class="vb-book-step" data-book-step="reschedule-date" x-show="isRescheduleDateStep" x-cloak>
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('manage.reschedule_heading')"></h2>
                    <div class="vb-book-step-subtitle" x-text="t('manage.reschedule_pick_date')"></div>
                </div>

                <!-- Selection confirmation (sticky, above calendar/grid) -->
                <div class="vb-book-selection-confirm" x-show="rescheduleSlot" x-cloak>
                    <div class="vb-book-selection-badge">
                        <i data-lucide="calendar-check"></i>
                        <div class="vb-book-selection-detail">
                            <span class="vb-book-selection-label" x-text="t('buttons.selected_time')"></span>
                            <span class="vb-book-selection-value" x-text="rescheduleSlotLabel"></span>
                        </div>
                    </div>
                    <button type="button" class="vb-book-btn vb-book-btn-primary vb-book-btn-continue"
                            @click="confirmRescheduleSlot"
                            x-text="t('buttons.continue')">
                    </button>
                </div>

                <!-- Calendar -->
                <?php $calendar = ['scope' => 'reschedule-timeslot', 'cells' => 'rescheduleCalendarCells', 'month' => 'rescheduleMonthLabel', 'prev' => 'reschedulePrevMonth', 'next' => 'rescheduleNextMonth', 'select' => 'selectRescheduleDate']; include __DIR__ . '/partials/calendar.php'; ?>

                <!-- Time Slots -->
                <div x-show="rescheduleDate" id="vb-reschedule-time-container">
                    <template x-if="rescheduleSlots.length === 0 && rescheduleDate && !slotsLoading && !hasLoadError">
                        <div class="vb-book-empty" x-text="t('empty.no_times')"></div>
                    </template>
                    <template x-if="rescheduleSlots.length > 0">
                        <div class="vb-book-time-grid" role="radiogroup" @keydown="radiogroupKeydown">
                            <template x-for="(slot, i) in rescheduleSlots" x-bind:key="'rs-' + slot.time">
                                <button type="button" class="vb-book-time-pill"
                                     x-bind:class="{
                                         'is-selected': rescheduleSlot && rescheduleSlot.time === slot.time,
                                         'is-dimmed': rescheduleSlot && rescheduleSlot.time !== slot.time
                                     }"
                                     @click="selectRescheduleSlot(slot)"
                                     role="radio" tabindex="0"
                                     x-bind:aria-checked="rescheduleSlot && rescheduleSlot.time === slot.time"
                                     x-bind:style="slotAnimDelay(i)">
                                    <i data-lucide="check" class="vb-pill-check" x-show="rescheduleSlot && rescheduleSlot.time === slot.time"></i>
                                    <span x-text="formatDisplayTime(slot.time, rescheduleDate, slot.start_utc)"></span><small class="vb-book-slot-date" x-show="rescheduleSlotDateNote(slot)" x-text="rescheduleSlotDateNote(slot)"></small>
                                </button>
                            </template>
                        </div>
                    </template>
                </div>

                <!-- Back link -->
                <div class="vb-book-back-link">
                    <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="cancelReschedule"
                            x-text="t('manage.reschedule_cancel')"></button>
                </div>
            </div>

            <!-- ═══ Reschedule: Resource Date Range ═══ -->
            <div class="vb-book-step" data-book-step="reschedule-resource" x-show="isRescheduleResourceStep" x-cloak>
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('manage.reschedule_heading')"></h2>
                    <div class="vb-book-step-subtitle" x-text="t('resource.select_dates')"></div>
                </div>

                <!-- Selection confirmation -->
                <div class="vb-book-selection-confirm" x-show="rescheduleCheckIn && rescheduleCheckOut" x-cloak>
                    <div class="vb-book-selection-badge">
                        <i data-lucide="calendar-check"></i>
                        <div class="vb-book-selection-detail">
                            <span class="vb-book-selection-label" x-text="t('resource.check_in_label') + ' → ' + t('resource.check_out_label')"></span>
                            <span class="vb-book-selection-value" x-text="rescheduleResourceLabel"></span>
                        </div>
                    </div>
                    <button type="button" class="vb-book-btn vb-book-btn-primary vb-book-btn-continue"
                            @click="confirmRescheduleResource"
                            x-text="t('buttons.continue')">
                    </button>
                </div>

                <!-- Calendar -->
                <?php $calendar = ['scope' => 'reschedule-resource', 'cells' => 'rescheduleResourceCalendarCells', 'month' => 'rescheduleMonthLabel', 'prev' => 'rescheduleResourcePrevMonth', 'next' => 'rescheduleResourceNextMonth', 'select' => 'selectRescheduleResourceDate']; include __DIR__ . '/partials/calendar.php'; ?>

                <div class="vb-book-back-link">
                    <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="cancelReschedule"
                            x-text="t('manage.reschedule_cancel')"></button>
                </div>
            </div>

            <!-- ═══ Reschedule: Capacity Date + Slot ═══ -->
            <div class="vb-book-step" data-book-step="reschedule-capacity" x-show="isRescheduleCapacityStep" x-cloak>
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('manage.reschedule_heading')"></h2>
                    <div class="vb-book-step-subtitle" x-text="t('manage.reschedule_pick_date')"></div>
                </div>

                <!-- Selection confirmation (slot selected) -->
                <div class="vb-book-selection-confirm" x-show="rescheduleCapacitySlot" x-cloak>
                    <div class="vb-book-selection-badge">
                        <i data-lucide="calendar-check"></i>
                        <div class="vb-book-selection-detail">
                            <span class="vb-book-selection-label" x-text="t('buttons.selected_time')"></span>
                            <span class="vb-book-selection-value" x-text="rescheduleCapacitySlotLabel"></span>
                        </div>
                    </div>
                    <button type="button" class="vb-book-btn vb-book-btn-primary vb-book-btn-continue"
                            @click="confirmRescheduleCapacity"
                            x-text="t('buttons.continue')">
                    </button>
                </div>

                <!-- Calendar -->
                <?php $calendar = ['scope' => 'reschedule-capacity', 'cells' => 'rescheduleCalendarCells', 'month' => 'rescheduleMonthLabel', 'prev' => 'rescheduleCapacityPrevMonth', 'next' => 'rescheduleCapacityNextMonth', 'select' => 'selectRescheduleCapacityDate']; include __DIR__ . '/partials/calendar.php'; ?>

                <!-- Capacity slots (shown after date selection) -->
                <div x-show="rescheduleDate" id="vb-reschedule-capacity-slots">
                    <template x-if="rescheduleCapacitySlots.length === 0 && rescheduleDate && !slotsLoading && !hasLoadError">
                        <div class="vb-book-empty" x-text="t('empty.no_times')"></div>
                    </template>
                    <template x-if="rescheduleCapacitySlots.length > 0">
                        <div class="vb-book-time-grid" role="radiogroup" @keydown="radiogroupKeydown">
                            <template x-for="(slot, i) in rescheduleCapacitySlots" x-bind:key="'rcs-' + slot.id">
                                <button type="button" class="vb-book-time-pill"
                                     x-bind:class="{
                                         'is-selected': rescheduleCapacitySlot && rescheduleCapacitySlot.id === slot.id,
                                         'is-dimmed': rescheduleCapacitySlot && rescheduleCapacitySlot.id !== slot.id
                                     }"
                                     @click="selectRescheduleCapacitySlot(slot)"
                                     role="radio" tabindex="0"
                                     x-bind:aria-checked="rescheduleCapacitySlot && rescheduleCapacitySlot.id === slot.id"
                                     x-bind:style="slotAnimDelay(i)">
                                    <i data-lucide="check" class="vb-pill-check" x-show="rescheduleCapacitySlot && rescheduleCapacitySlot.id === slot.id"></i>
                                    <span x-text="rescheduleCapacityTimeLabel(slot)"></span>
                                </button>
                            </template>
                        </div>
                    </template>
                </div>

                <div class="vb-book-back-link">
                    <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="cancelReschedule"
                            x-text="t('manage.reschedule_cancel')"></button>
                </div>
            </div>

            <!-- ═══ Reschedule: Event Selection ═══ -->
            <div class="vb-book-step" data-book-step="reschedule-event" x-show="isRescheduleEventStep" x-cloak>
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('manage.reschedule_heading')"></h2>
                    <div class="vb-book-step-subtitle" x-text="t('event.select_event')"></div>
                </div>

                <p class="vb-book-calendar-feedback" role="status" x-show="calendarLoading" x-text="t('event.loading')"></p>
                <template x-if="hasNoRescheduleEvents">
                    <div class="vb-book-empty" x-text="t('event.no_upcoming')"></div>
                </template>

                <template x-if="rescheduleEvents.length > 0">
                    <div class="vb-book-service-list" role="radiogroup" x-bind:aria-label="t('event.events_title')" @keydown="radiogroupKeydown">
                        <template x-for="ev in rescheduleEvents" x-bind:key="ev.id">
                            <button type="button" class="vb-book-service-card"
                                 x-bind:class="{ 'is-selected': rescheduleSelectedEvent && rescheduleSelectedEvent.id === ev.id }"
                                 @click="selectRescheduleEvent(ev)"
                                 role="radio" x-bind:tabindex="rescheduleEventTabindex(ev)"
                                 x-bind:aria-checked="rescheduleSelectedEvent && rescheduleSelectedEvent.id === ev.id">
                                <div class="vb-book-service-info">
                                    <div class="vb-book-service-name" x-text="ev.name"></div>
                                    <div class="vb-book-service-meta">
                                        <span class="vb-book-service-meta-item">
                                            <svg viewBox="0 0 16 16" fill="none"><circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-width="1.2"/><path d="M8 5v3.5l2.5 1.5" stroke="currentColor" stroke-width="1.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            <span x-text="formatEventDate(ev.start_datetime, ev.start_utc) + ' · ' + formatEventTime(ev.start_datetime, ev.start_utc) + '–' + formatEventTime(ev.end_datetime, ev.end_utc)"></span>
                                        </span>
                                    </div>
                                    <template x-if="ev.location">
                                        <div class="vb-book-service-desc" x-text="ev.location"></div>
                                    </template>
                                </div>
                                <template x-if="ev.spots_remaining !== undefined && ev.spots_remaining !== null">
                                    <div class="vb-book-service-price" x-text="ev.spots_remaining + ' ' + t('event.spots_left')"></div>
                                </template>
                            </button>
                        </template>
                    </div>
                </template>

                <template x-if="rescheduleSelectedEvent">
                    <div class="vb-book-form-actions" style="margin-top: var(--vb-space-4)">
                        <button type="button" class="vb-book-btn vb-book-btn-primary"
                                @click="confirmRescheduleEvent"
                                x-text="t('buttons.continue')">
                        </button>
                    </div>
                </template>

                <div class="vb-book-back-link">
                    <button type="button" class="vb-book-btn vb-book-btn-ghost" @click="cancelReschedule"
                            x-text="t('manage.reschedule_cancel')"></button>
                </div>
            </div>

            <!-- ═══ Reschedule Step 2: Review ═══ -->
            <div class="vb-book-step" data-book-step="reschedule-review" x-show="isRescheduleReviewStep" x-cloak>
                <div class="vb-book-step-header">
                    <h2 class="vb-book-step-title" x-text="t('manage.reschedule_review_heading')"></h2>
                    <div class="vb-book-step-subtitle" x-text="t('manage.reschedule_review_subtitle')"></div>
                </div>

                <!-- Before/After comparison -->
                <div class="vb-book-reschedule-compare">
                    <div class="vb-book-reschedule-from">
                        <span class="vb-book-reschedule-label" x-text="t('manage.reschedule_original_label')"></span>
                        <span class="vb-book-reschedule-datetime" x-text="rescheduleOriginalDisplay"></span>
                    </div>
                    <div class="vb-book-reschedule-arrow">
                        <i data-lucide="arrow-down"></i>
                    </div>
                    <div class="vb-book-reschedule-to">
                        <span class="vb-book-reschedule-label" x-text="t('manage.reschedule_new_label')"></span>
                        <span class="vb-book-reschedule-datetime" x-text="rescheduleNewDisplay"></span>
                    </div>
                </div>

                <p class="vb-book-step-subtitle" x-show="rescheduleStayTotal" x-text="rescheduleStayTotal"></p>

                <div class="vb-book-form-actions">
                    <button class="vb-book-btn vb-book-btn-primary" @click="confirmReschedule"
                            x-bind:disabled="rescheduleSubmitting"
                            x-bind:class="{ 'is-loading': rescheduleSubmitting }">
                        <span class="vb-book-btn-text" x-text="t('manage.reschedule_confirm_button')"></span>
                    </button>
                    <div class="vb-book-back-link">
                        <button type="button" class="vb-book-btn vb-book-btn-ghost"
                                @click="goBackToRescheduleDate" x-bind:disabled="rescheduleSubmitting"
                                x-text="t('manage.reschedule_back_to_date')"></button>
                    </div>
                </div>
            </div>

            <!-- ═══ Reschedule Step 3: Confirmed ═══ -->
            <div class="vb-book-step" data-book-step="reschedule-confirmed" x-show="isRescheduleConfirmedStep" x-cloak>
                <div class="vb-book-confirmation">
                    <?php $outcome = 'rescheduled'; include __DIR__ . '/partials/outcome.php'; ?>

                    <?php $objectMode = 'rescheduled'; include __DIR__ . '/partials/booking-object.php'; ?>

                    <div class="vb-book-confirm-actions-secondary">
                        <template x-if="rescheduleNewBooking">
                            <a x-bind:href="manageUrl(rescheduleNewBooking.id)" class="vb-book-btn vb-book-btn-primary"
                               x-text="t('manage.heading')"></a>
                        </template>
                        <a x-bind:href="bookingPageUrl" class="vb-book-btn vb-book-btn-ghost"
                           x-text="t('buttons.book_another')"></a>
                    </div>
                </div>
            </div>

        </main>

        <!-- ── Toast ── -->
        <template x-if="hasToast">
            <div class="vb-book-toast"
                 x-bind:class="toastClass"
                 x-transition:enter="vb-book-toast-enter"
                 x-transition:enter-start="vb-book-toast-enter-start"
                 x-transition:enter-end="vb-book-toast-enter-end"
                 x-transition:leave="vb-book-toast-leave"
                 x-transition:leave-start="vb-book-toast-leave-start"
                 x-transition:leave-end="vb-book-toast-leave-end"
                 role="alert">
                <span class="vb-book-toast-icon">
                    <i x-show="isToastError" data-lucide="alert-circle"></i>
                    <i x-show="isToastWarn" data-lucide="alert-triangle"></i>
                    <i x-show="isToastInfo" data-lucide="info"></i>
                </span>
                <span class="vb-book-toast-message" x-text="toastMessage"></span>
                <button class="vb-book-toast-close" type="button" @click="dismissToast" aria-label="<?= __('booking.common.dismiss') ?>">
                    <i data-lucide="x"></i>
                </button>
            </div>
        </template>

        <!-- ── Theme Toggle ── -->
        <button type="button"
                class="vb-book-theme-toggle"
                @click="toggleTheme"
                x-bind:aria-label="isDark ? t('theme.switch_to_light') : t('theme.switch_to_dark')"
                x-bind:title="isDark ? t('theme.switch_to_light') : t('theme.switch_to_dark')">
            <svg x-show="isDark" x-cloak class="vb-book-theme-icon" x-bind:class="isDark ? 'is-visible' : 'is-hidden'"
                 data-lucide="sun"></svg>
            <svg x-show="!isDark" class="vb-book-theme-icon" x-bind:class="!isDark ? 'is-visible' : 'is-hidden'"
                 data-lucide="moon"></svg>
        </button>

        <!-- ── Footer (hidden in embed mode) ── -->
        <?php if (!($isEmbed ?? false)): ?>
        <?php if (!empty($tenant['show_powered_by'])): ?>
        <footer class="vb-book-footer" x-show="!isLoading">
            <span><?= __('booking.footer.powered_by') ?></span>
            <a href="<?= htmlspecialchars(brand_url(), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars(app_name(), ENT_QUOTES, 'UTF-8') ?></a>
        </footer>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Tenant config for JS -->
    <script>
        window.__VB_CONFIG__ = <?= json_encode($tenantConfig, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.__VB_CSRF__ = <?= json_encode($csrfToken) ?>;
        window.__VB_TS__ = Date.now();
        window.__VB_EMBED__ = <?= json_encode((bool) ($isEmbed ?? false)) ?>;
        window.__VB_I18N__ = <?= json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.__VB_FMT__ = <?= json_encode($formatting, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        window.__VB_TZ_GROUPS__ = <?= json_encode($timezoneGroups ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        <?php if (\App\Engine\DemoMode::isActive()): ?>
        window.VB_DEMO = true;
        window.__VB_DEMO_NOTICE__ = <?= json_encode(__('admin.demo.booking_notice')) ?>;
        <?php endif; ?>
    </script>
    <script type="module" src="/assets/js/booking.js?v=<?= filemtime(dirname(__DIR__, 2) . '/public/assets/js/booking.js') ?>"></script>
</body>
</html>
