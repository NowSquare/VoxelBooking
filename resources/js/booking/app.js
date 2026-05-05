/**
 * VoxelBooking — Booking Flow (Alpine.js CSP-safe)
 *
 * Architecture:
 *   Alpine.data('bookingWizard')  → single component for the entire flow
 *   Lucide via data-lucide        → icons rendered after Alpine commits DOM
 *   Timezone conversion           → all display times in customer TZ, storage in tenant TZ
 *
 * CSP-safe rules:
 *   - No inline JS in x-on / x-bind / x-show
 *   - All component logic registered via Alpine.data()
 *   - HTML references methods by name: @click="selectService"
 *   - x-show references computed booleans by name
 */

import Alpine from '@alpinejs/csp';
import { createIcons } from 'lucide';
import {
    ChevronLeft, ChevronRight, ChevronDown, Clock, Globe, Check, X,
    AlertCircle, Info, AlertTriangle, Calendar as CalendarIcon, CalendarCheck,
    User, Users, ExternalLink, Download, Plus, Minus, MapPin, Ticket,
    Sun, Moon, ArrowDown,
} from 'lucide';
import { resolveInitialPartySize } from './party-size.js';

const ICON_SET = {
    ChevronLeft, ChevronRight, ChevronDown, Clock, Globe, Check, X,
    AlertCircle, Info, AlertTriangle, Calendar: CalendarIcon, CalendarCheck,
    User, Users, ExternalLink, Download, Plus, Minus, MapPin, Ticket,
    Sun, Moon, ArrowDown,
};

// ── Theme bootstrap (runs before Alpine to prevent FOUC) ──
(function() {
    const stored = localStorage.getItem('vb-theme');
    const system = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
    const theme = stored || system;
    document.documentElement.setAttribute('data-theme', theme);
})();

// ── Globals injected by PHP ──
const config = window.__VB_CONFIG__;
const apiBase = `/api/${config.slug}`;
const csrfToken = window.__VB_CSRF__ || '';
const i18n = window.__VB_I18N__ || {};
const fmt   = window.__VB_FMT__  || {};

// ── Translation helper ──
function t(key, replace = {}) {
    let value = i18n[key] ?? key;
    for (const [k, v] of Object.entries(replace)) {
        value = value.replace(`:${k}`, v);
    }
    return value;
}

// ── HTML escape ──
function esc(str) {
    if (!str) return '';
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}

// ── Motion preference helper ──
function prefersReducedMotion() {
    return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}
function scrollBehavior() {
    return prefersReducedMotion() ? 'auto' : 'smooth';
}

// ── Timezone conversion engine (extracted to timezone.js for testability) ──
import { convertTime, getTimezoneOffsetMinutes, formatSlotDisplay } from './timezone.js';

// ── Timezone list (injected by server from PHP's canonical timezone_identifiers_list()) ──
const TIMEZONE_GROUPS = window.__VB_TZ_GROUPS__ || [];

// Human-readable timezone label
function tzLabel(tz) {
    const city = tz.split('/').pop().replace(/_/g, ' ');
    try {
        const now = new Date();
        const offset = new Intl.DateTimeFormat('en-US', {
            timeZone: tz, timeZoneName: 'shortOffset',
        }).formatToParts(now).find(p => p.type === 'timeZoneName')?.value || '';
        return `${city} (${offset})`;
    } catch {
        return city;
    }
}


// ── Radiogroup keyboard navigation (WAI-ARIA) ──
// Two variants for radiogroup keyboard navigation:
//
//  radiogroupKeydown        → focus + click (for time pills, capacity slots
//                              that do NOT auto-advance on selection)
//  radiogroupKeydownFocusOnly → focus only (for service/resource/staff groups
//                               that auto-advance on click — selection stays
//                               on Enter/Space via the per-card handlers)

function radiogroupNav(event) {
    const keys = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
    if (!keys.includes(event.key)) return null;

    const group = event.currentTarget;
    const radios = Array.from(group.querySelectorAll('[role="radio"]:not([aria-disabled="true"])'));
    if (radios.length === 0) return null;

    const current = document.activeElement;
    const idx = radios.indexOf(current);
    if (idx === -1) return null;

    event.preventDefault();
    let next;

    switch (event.key) {
        case 'ArrowRight':
        case 'ArrowDown':
            next = radios[(idx + 1) % radios.length];
            break;
        case 'ArrowLeft':
        case 'ArrowUp':
            next = radios[(idx - 1 + radios.length) % radios.length];
            break;
        case 'Home':
            next = radios[0];
            break;
        case 'End':
            next = radios[radios.length - 1];
            break;
    }

    return next || null;
}

function radiogroupKeydown(event) {
    const next = radiogroupNav(event);
    if (next) {
        next.focus();
        next.click();
    }
}

function radiogroupKeydownFocusOnly(event) {
    const next = radiogroupNav(event);
    if (next) {
        next.focus();
    }
}

// Calendar grid keyboard navigation (WAI-ARIA grid pattern).
// Arrow left/right = prev/next day, up/down = prev/next week, Home/End = first/last of month.
function calendarGridKeydown(event) {
    const keys = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
    if (!keys.includes(event.key)) return;

    const grid = event.currentTarget;
    const cells = Array.from(grid.querySelectorAll('.vb-book-calendar-cell:not(.is-disabled)'));
    if (cells.length === 0) return;

    const current = document.activeElement;
    const idx = cells.indexOf(current);
    if (idx === -1) return;

    event.preventDefault();
    let next;

    switch (event.key) {
        case 'ArrowRight':
            next = cells[Math.min(idx + 1, cells.length - 1)];
            break;
        case 'ArrowLeft':
            next = cells[Math.max(idx - 1, 0)];
            break;
        case 'ArrowDown': {
            // Find next cell ~7 positions ahead (same day next week)
            const target = idx + 7;
            next = target < cells.length ? cells[target] : cells[cells.length - 1];
            break;
        }
        case 'ArrowUp': {
            const target = idx - 7;
            next = target >= 0 ? cells[target] : cells[0];
            break;
        }
        case 'Home':
            next = cells[0];
            break;
        case 'End':
            next = cells[cells.length - 1];
            break;
    }

    if (next && next !== current) {
        next.focus();
    }
}

// ── Alpine: Booking Wizard Component ──
Alpine.data('bookingWizard', () => ({
    // Step management
    step: 'loading',
    calendarFading: false,

    // Data
    services: [],
    staff: [],
    selectedService: null,
    selectedStaff: null,
    selectedDate: null,
    selectedSlot: null,
    availableDates: [],
    availableSlots: [],
    currentMonth: new Date().getMonth(),
    currentYear: new Date().getFullYear(),
    customerName: '',
    customerEmail: '',
    customerPhone: '',
    customerNotes: '',
    customFields: {},
    consentGiven: false,
    booking: null,
    submitting: false,
    formErrors: {},
    policyOpen: false,
    slotAlternatives: [],

    // Resource pattern state
    resources: [],
    selectedResource: null,
    checkInDate: null,
    checkOutDate: null,
    guestCount: 1,
    resourceAvailability: null,
    resourceDates: [],
    checkInMonth: new Date().getMonth(),
    checkInYear: new Date().getFullYear(),

    // Capacity pattern state
    partySize: resolveInitialPartySize(config.min_party_size || 1, config.max_party_size || 8),
    minPartySize: 1,
    maxPartySize: 8,
    capacitySlots: [],
    selectedCapacitySlot: null,
    capacityDates: [],
    capacityMonth: new Date().getMonth(),
    capacityYear: new Date().getFullYear(),

    // Event pattern state
    eventList: [],
    selectedEvent: null,
    eventSpotCount: 1,
    eventIsWaitlisted: false,
    bookingIsPending: false,

    // Manage mode state
    manageMode: config.manage_mode || false,
    manageBookingId: config.manage_booking_id || null,
    managedBooking: null,
    manageCanCancel: false,
    manageCanReschedule: false,
    manageRescheduleReason: null,
    manageCancelReason: '',
    manageCancelModalOpen: false,
    manageCancelling: false,
    manageCancelled: false,
    manageLoading: false,

    // Reschedule flow state
    rescheduleDate: null,
    rescheduleSlot: null,
    rescheduleSlots: [],
    rescheduleDates: [],
    rescheduleMonth: new Date().getMonth(),
    rescheduleYear: new Date().getFullYear(),
    rescheduleSubmitting: false,
    rescheduleNewBooking: null,
    rescheduleCalendarFading: false,
    // Resource reschedule
    rescheduleCheckIn: null,
    rescheduleCheckOut: null,
    rescheduleResourceDates: [],
    // Capacity reschedule
    rescheduleCapacitySlots: [],
    rescheduleCapacitySlot: null,
    // Event reschedule
    rescheduleEvents: [],
    rescheduleSelectedEvent: null,

    // CSP-safe setters for x-model (nested property assignment is prohibited)
    setCustomerName(val) { this.customerName = val; },
    setCustomerEmail(val) { this.customerEmail = val; },
    setCustomerPhone(val) { this.customerPhone = val; },
    setCustomerNotes(val) { this.customerNotes = val; },
    setConsentGiven(val) { this.consentGiven = val; },
    setTzSearchQuery(val) { this.tzSearchQuery = val; },

    // Timezone
    customerTz: '',
    tenantTz: config.timezone || 'UTC',
    tzDropdownOpen: false,
    tzSearchQuery: '',

    // Toast
    toast: null,
    toastTimer: null,

    // Theme
    isDark: document.documentElement.getAttribute('data-theme') === 'dark',

    // Config passthrough
    config,

    // ── CSP-safe step visibility (x-show only accepts property/method refs) ──
    isStep(name) { return this.step === name; },
    get isLoading() { return this.step === 'loading'; },
    get isEmpty() { return this.step === 'empty'; },
    get isUnsupported() { return this.step === 'unsupported'; },
    get isServiceStep() { return this.step === 'service'; },
    get isStaffStep() { return this.step === 'staff'; },
    get isDateStep() { return this.step === 'date'; },
    get isDetailsStep() { return this.step === 'details'; },
    get isReviewStep() { return this.step === 'review'; },
    get isConfirmedStep() { return this.step === 'confirmed'; },
    get isResourceStep() { return this.step === 'resource'; },
    get isResourceDateStep() { return this.step === 'resource-date'; },
    get isGuestStep() { return this.step === 'guests'; },
    get isPartySizeStep() { return this.step === 'party-size'; },
    get isCapacityDateStep() { return this.step === 'capacity-date'; },
    get isCapacityTimeStep() { return this.step === 'capacity-time'; },
    get isEventListStep() { return this.step === 'event-list'; },
    get isEventDetailStep() { return this.step === 'event-detail'; },
    get isEventSpotsStep() { return this.step === 'event-spots'; },
    get isManageStep() { return this.step === 'manage'; },
    get isRescheduleDateStep() { return this.step === 'reschedule-date'; },
    get isRescheduleReviewStep() { return this.step === 'reschedule-review'; },
    get isRescheduleConfirmedStep() { return this.step === 'reschedule-confirmed'; },
    get isRescheduleResourceStep() { return this.step === 'reschedule-resource'; },
    get isRescheduleCapacityStep() { return this.step === 'reschedule-capacity'; },
    get isRescheduleEventStep() { return this.step === 'reschedule-event'; },
    get hasToast() { return !!this.toast; },
    get toastType() { return this.toast ? this.toast.type : ''; },
    get toastMessage() { return this.toast ? this.toast.message : ''; },
    get isToastError() { return this.toast && this.toast.type === 'error'; },
    get isToastWarn() { return this.toast && this.toast.type === 'warn'; },
    get isToastInfo() { return this.toast && this.toast.type === 'info'; },

    // Event detail getters (null-safe for CSP pre-compilation)
    get eventName() { return this.selectedEvent ? this.selectedEvent.name : ''; },
    get eventDescription() { return this.selectedEvent ? this.selectedEvent.description : ''; },
    get hasEventDescription() { return !!(this.selectedEvent && this.selectedEvent.description); },
    get eventStartDatetime() { return this.selectedEvent ? this.selectedEvent.start_datetime : ''; },
    get eventEndDatetime() { return this.selectedEvent ? this.selectedEvent.end_datetime : ''; },
    get eventLocation() { return this.selectedEvent ? this.selectedEvent.location : ''; },
    get hasEventLocation() { return !!(this.selectedEvent && this.selectedEvent.location); },
    get eventPrice() { return this.selectedEvent ? this.selectedEvent.price : 0; },
    get eventCapacityLabel() { return this.selectedEvent ? this.selectedEvent.remaining + ' / ' + this.selectedEvent.max_participants : ''; },
    get eventTimeSummary() { return this.selectedEvent ? this.formatEventTime(this.selectedEvent.start_datetime) + ' – ' + this.formatEventTime(this.selectedEvent.end_datetime) : ''; },
    get showEventMaxHint() { return this.eventSpotCount >= this.eventMaxSpots && this.selectedEvent && this.selectedEvent.remaining > 0; },
    get eventMaxHintText() {
        if (!this.selectedEvent) return '';
        if (this.selectedEvent.max_spot_count && this.eventSpotCount >= this.selectedEvent.max_spot_count) {
            return this.t('event.max_spots_reached').replace(':count', this.selectedEvent.max_spot_count);
        }
        return this.t('event.max_reached');
    },

    get hasSelectedDate() { return !!this.selectedDate; },
    get hasNoSlots() { return this.availableSlots.length === 0 && !!this.selectedDate; },
    get hasSlots() { return this.availableSlots.length > 0; },
    get isTzMismatch() { return !this.tzMatch; },
    get isCalendarFading() { return this.calendarFading; },

    // ── Init ──
    init() {
        // Detect browser timezone
        try {
            this.customerTz = Intl.DateTimeFormat().resolvedOptions().timeZone;
        } catch {
            this.customerTz = this.tenantTz;
        }

        // Listen for system theme changes (auto-sync when no explicit override)
        window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', (e) => {
            if (!localStorage.getItem('vb-theme')) {
                const theme = e.matches ? 'dark' : 'light';
                document.documentElement.setAttribute('data-theme', theme);
                this.isDark = e.matches;
            }
        });

        // Check if in manage mode (URL: /book/{slug}/manage/{booking_id})
        if (this.manageMode && this.manageBookingId) {
            this.loadManagedBooking();
            return;
        }

        if (config.booking_pattern === 'timeslot') {
            this.loadServices();
        } else if (config.booking_pattern === 'resource') {
            this.loadResources();
        } else if (config.booking_pattern === 'capacity') {
            this.initCapacity();
        } else if (config.booking_pattern === 'event') {
            this.loadEvents();
        } else {
            this.step = 'unsupported';
        }
    },

    // ── Theme ──
    toggleTheme() {
        this.isDark = !this.isDark;
        const theme = this.isDark ? 'dark' : 'light';
        document.documentElement.setAttribute('data-theme', theme);
        localStorage.setItem('vb-theme', theme);
        this.$nextTick(() => createIcons({ icons: ICON_SET }));
    },

    // ── Step transitions (spec: §6.4 Flow Orchestrator) ──
    // Exit:  .is-exiting → opacity 0, translateY(-10px), 180ms ease-in
    // Swap:  after 200ms total (180ms exit + 20ms gap), step property changes
    // Enter: @keyframes vb-step-enter → opacity 1, translateY(0), 250ms ease-out
    // Focus: move to first interactive element in new step
    goToStep(name) {
        // Flush any stale exit marks from prior transitions
        this.clearExitStates();

        // Mark current visible step as exiting
        const currentStepEl = this.$el.querySelector('.vb-book-step:not([style*="display: none"])');
        if (currentStepEl) {
            currentStepEl.classList.add('is-exiting');
        }

        setTimeout(() => {
            // Remove exit class before step change — the element is about
            // to be hidden by Alpine's x-show, so the class must not persist
            // for when this step is revisited via back navigation.
            if (currentStepEl) {
                currentStepEl.classList.remove('is-exiting');
            }

            this.step = name;
            this.$nextTick(() => {
                createIcons({ icons: ICON_SET });
                // Focus management: move focus to first interactive element
                const newStep = this.$el.querySelector('.vb-book-step:not([style*="display: none"])');
                if (newStep) {
                    const focusTarget = newStep.querySelector(
                        'button:not([disabled]), [role="radio"], input:not([type="hidden"]), a[href], [tabindex="0"]'
                    );
                    if (focusTarget) {
                        focusTarget.focus({ preventScroll: true });
                    }
                }

                // Embed mode: notify parent window on booking confirmation
                if (name === 'confirmed' && window.__VB_EMBED__ && window.parent !== window) {
                    try {
                        window.parent.postMessage({ type: 'booking:confirmed' }, '*');
                    } catch (e) { /* cross-origin safety */ }
                }
            });
        }, 200); // 180ms exit animation + 20ms settle gap
    },

    // Remove .is-exiting from all step elements. Called before any
    // step assignment to prevent stale exit animation state.
    clearExitStates() {
        this.$el.querySelectorAll('.vb-book-step.is-exiting').forEach(
            el => el.classList.remove('is-exiting')
        );
    },

    // ── Progress indicator ──
    get progressSteps() {
        if (config.booking_pattern === 'resource') {
            return ['resource', 'resource-date', 'guests', 'details', 'review'];
        }
        if (config.booking_pattern === 'capacity') {
            return ['party-size', 'capacity-date', 'capacity-time', 'details', 'review'];
        }
        if (config.booking_pattern === 'event') {
            return ['event-list', 'event-detail', 'event-spots', 'details', 'review'];
        }
        const steps = ['service'];
        if (this.staff.length > 1) steps.push('staff');
        steps.push('date', 'details', 'review');
        return steps;
    },

    get currentStepIndex() {
        return this.progressSteps.indexOf(this.step);
    },

    isProgressDotActive(i) {
        return i === this.currentStepIndex;
    },

    isProgressDotCompleted(i) {
        return i < this.currentStepIndex;
    },

    get showProgress() {
        return this.step !== 'loading' && this.step !== 'confirmed' && this.step !== 'unsupported' && this.step !== 'empty';
    },

    // ── Timezone ──
    get tzMatch() {
        return this.customerTz === this.tenantTz;
    },

    get tzGroups() {
        const q = this.tzSearchQuery.toLowerCase();
        const resolved = TIMEZONE_GROUPS.map(g => ({
            label: t(g.labelKey),
            zones: q
                ? g.zones.filter(z => z.toLowerCase().includes(q) || tzLabel(z).toLowerCase().includes(q))
                : g.zones,
        }));
        return q ? resolved.filter(g => g.zones.length > 0) : resolved;
    },

    tzGroupLabel(group) {
        return group.label;
    },

    tzDisplayLabel(tz) {
        return tzLabel(tz);
    },

    selectTimezone(tz) {
        this.customerTz = tz;
        this.tzDropdownOpen = false;
        this.tzSearchQuery = '';
        // Re-render time slots if we're on the date step with slots loaded
        if (this.step === 'date' && this.selectedDate) {
            // Slots stay the same, just display changes via reactive binding
        }
    },

    toggleTzDropdown() {
        this.tzDropdownOpen = !this.tzDropdownOpen;
        if (this.tzDropdownOpen) {
            this.$nextTick(() => {
                const input = this.$refs.tzSearch;
                if (input) input.focus();
            });
        }
    },

    closeTzDropdown() {
        this.tzDropdownOpen = false;
        this.tzSearchQuery = '';
    },

    // ── API ──
    async api(path, options = {}) {
        const url = `${apiBase}${path}`;
        const res = await fetch(url, {
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            ...options,
        });
        return res.json();
    },

    // ── Step 1: Services ──
    async loadServices() {
        const data = await this.api('/services');
        this.services = data.services || [];

        if (this.services.length === 0) {
            this.clearExitStates();
            this.step = 'empty';
            return;
        }

        if (this.services.length === 1) {
            this.selectedService = this.services[0];
            this.loadStaff();
            return;
        }

        // Use goToStep for proper exit animation when called from goBack().
        // On init (step === 'loading'), goToStep still works correctly.
        this.goToStep('service');
    },

    // Service card stagger index for animation-delay
    serviceAnimDelay(i) {
        return 'animation-delay:' + (i * 60) + 'ms';
    },

    selectService(service) {
        this.selectedService = service;
        setTimeout(() => this.loadStaff(), 200);
    },

    isServiceSelected(service) {
        return this.selectedService?.id === service.id;
    },

    // ── Step 2: Staff ──
    async loadStaff() {
        const params = this.selectedService ? `?service_id=${this.selectedService.id}` : '';
        const data = await this.api(`/staff${params}`);
        this.staff = data.staff || [];

        if (this.staff.length <= 1) {
            this.selectedStaff = this.staff[0] || null;
            this.loadDates();
            return;
        }

        this.goToStep('staff');
    },

    selectStaff(staff) {
        this.selectedStaff = staff;
        setTimeout(() => this.loadDates(), 200);
    },

    selectAnyStaff() {
        this.selectedStaff = null;
        setTimeout(() => this.loadDates(), 200);
    },

    isStaffSelected(staff) {
        return this.selectedStaff?.id === staff.id;
    },

    isAnyStaffSelected() {
        return !this.selectedStaff;
    },

    staffInitials(name) {
        return name.split(' ').map(w => w[0]).slice(0, 2).join('').toUpperCase();
    },

    // ── Step 3: Date & Time ──
    async loadDates() {
        const params = new URLSearchParams({
            year: this.currentYear,
            month: this.currentMonth + 1,
        });
        if (this.selectedService) params.set('service_id', this.selectedService.id);
        if (this.selectedStaff) params.set('staff_id', this.selectedStaff.id);

        const data = await this.api(`/available-dates?${params}`);
        this.availableDates = data.dates || [];

        if (this.step !== 'date') {
            this.goToStep('date');
        }
    },

    async prevMonth() {
        this.calendarFading = true;
        this.currentMonth--;
        if (this.currentMonth < 0) { this.currentMonth = 11; this.currentYear--; }
        await this.loadDates();
        setTimeout(() => { this.calendarFading = false; }, 180);
    },

    async nextMonth() {
        this.calendarFading = true;
        this.currentMonth++;
        if (this.currentMonth > 11) { this.currentMonth = 0; this.currentYear++; }
        await this.loadDates();
        setTimeout(() => { this.calendarFading = false; }, 180);
    },

    get canPrevMonth() {
        const now = new Date();
        return !(this.currentYear === now.getFullYear() && this.currentMonth === now.getMonth());
    },

    get monthLabel() {
        const label = new Date(this.currentYear, this.currentMonth, 1)
            .toLocaleDateString(fmt.intl_locale || config.locale || 'en', { month: 'long', year: 'numeric' });
        return label.charAt(0).toUpperCase() + label.slice(1);
    },

    get dayNames() {
        const all = [t('days_short.0'), t('days_short.1'), t('days_short.2'), t('days_short.3'), t('days_short.4'), t('days_short.5'), t('days_short.6')];
        const ws = fmt.week_start ?? 0;
        return [...all.slice(ws), ...all.slice(0, ws)];
    },

    get calendarCells() {
        const year = this.currentYear;
        const month = this.currentMonth;
        const today = new Date();
        const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const weekStart = fmt.week_start ?? 0;
        const rawDay = new Date(year, month, 1).getDay();
        const firstDay = (rawDay - weekStart + 7) % 7;

        const cells = [];

        // Empty cells
        for (let i = 0; i < firstDay; i++) {
            cells.push({ day: '', dateStr: '', disabled: true, today: false, hasSlots: false, selected: false, _key: 'empty-' + i });
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const isPast = new Date(dateStr) < new Date(today.toDateString());
            const hasSlots = this.availableDates.includes(dateStr);
            cells.push({
                day,
                dateStr,
                disabled: !hasSlots || isPast,
                today: dateStr === todayStr,
                hasSlots,
                selected: dateStr === this.selectedDate,
            });
        }

        return cells;
    },

    // CSP-safe helpers for template bindings
    cellKey(cell) {
        return cell._key || cell.dateStr;
    },

    clickDate(cell) {
        if (!cell.disabled && cell.day) {
            this.selectDate(cell.dateStr);
        }
    },

    get dateBackLabel() {
        if (this.dateBackTarget === 'staff') return t('back.change_staff');
        return t('back.change_service');
    },

    fieldLabel(field) {
        return field.label + (field.required ? ' *' : '');
    },

    fieldPlaceholder(field) {
        return field.placeholder || '';
    },

    servicePriceLabel(service) {
        return service.price_label || this.formatPrice(service.price);
    },

    cellTabindex(cell) {
        return cell.disabled ? -1 : 0;
    },

    cellRole(cell) {
        return cell.day ? 'gridcell' : '';
    },

    consentLabel() {
        return config.consent_text || t('form.consent_default');
    },

    slotAnimDelay(i) {
        return 'animation-delay:' + (i * 40) + 'ms';
    },

    hasPrice(service) {
        return service.price !== null;
    },

    isTextarea(field) {
        return field.type === 'textarea';
    },

    isNotTextarea(field) {
        return field.type !== 'textarea';
    },

    hasAvatar(member) {
        return !!member.avatar_path;
    },

    avatarUrl(member) {
        return member.avatar_path;
    },

    noAvatar(member) {
        return !member.avatar_path;
    },

    customFieldId(field) {
        return 'vb-cf-' + field.name;
    },

    async selectDate(dateStr) {
        this.selectedDate = dateStr;
        this.selectedSlot = null;
        this.availableSlots = [];

        const params = new URLSearchParams({ date: dateStr });
        if (this.selectedService) params.set('service_id', this.selectedService.id);
        if (this.selectedStaff) params.set('staff_id', this.selectedStaff.id);

        const data = await this.api(`/availability?${params}`);
        this.availableSlots = data.slots || [];

        // Scroll time slots into view after render (respects reduced-motion)
        if (this.availableSlots.length > 0 && !prefersReducedMotion()) {
            this.$nextTick(() => {
                const el = document.getElementById('vb-time-container');
                if (el) el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            });
        }
    },

    // Display a slot time in the customer's timezone
    displaySlotTime(slot) {
        return formatSlotDisplay(slot.time, this.selectedDate, this.tenantTz, this.customerTz);
    },

    selectSlot(slot) {
        this.selectedSlot = slot;
    },

    confirmSlot() {
        if (!this.selectedSlot) return;
        this.goToStep('details');
    },

    isSlotSelected(slot) {
        return this.selectedSlot?.time === slot.time;
    },

    isSlotDimmed(slot) {
        return this.selectedSlot && this.selectedSlot.time !== slot.time;
    },

    get selectedSlotLabel() {
        if (!this.selectedSlot || !this.selectedDate) return '';
        const start = this.displaySlotTime(this.selectedSlot);
        const end = this.selectedSlot.end_time
            ? formatSlotDisplay(this.selectedSlot.end_time, this.selectedDate, this.tenantTz, this.customerTz)
            : null;
        const timeStr = end ? start + ' – ' + end : start;
        return this.formatDateDisplay(this.selectedDate) + '  ·  ' + timeStr;
    },

    // ── Step 4: Details ──
    submitDetails() {
        this.formErrors = {};

        if (!this.customerName.trim()) {
            this.formErrors.name = true;
        }
        if (!this.customerEmail.trim()) {
            this.formErrors.email = true;
        } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.customerEmail.trim())) {
            this.formErrors.email = true;
        }
        if (config.require_phone && !this.customerPhone.trim()) {
            this.formErrors.phone = true;
        }
        if (config.requires_consent && !this.consentGiven) {
            this.formErrors.consent = true;
        }

        if (Object.keys(this.formErrors).length > 0) return;

        // Collect custom field values from refs
        const customEls = this.$el.querySelectorAll('[data-book-custom]');
        customEls.forEach(el => {
            this.customFields[el.dataset.bookCustom] = el.value.trim();
        });

        this.goToStep('review');
    },

    hasError(field) {
        return !!this.formErrors[field];
    },

    // ── Step 5: Review / Summary ──
    get summaryRows() {
        const rows = [];
        if (this.selectedService) {
            rows.push({ label: t('summary.service_label'), value: this.selectedService.name });
            if (this.selectedService.price !== null) {
                rows.push({ label: t('summary.price_label'), value: this.selectedService.price_label || this.formatPrice(this.selectedService.price) });
            }
        }
        if (this.selectedStaff) {
            rows.push({ label: t('summary.with_label'), value: this.selectedStaff.name });
        }
        rows.push({ label: t('summary.date_label'), value: this.formatDateDisplay(this.selectedDate) });
        if (this.selectedSlot) {
            const displayStart = formatSlotDisplay(this.selectedSlot.time, this.selectedDate, this.tenantTz, this.customerTz);
            const displayEnd   = formatSlotDisplay(this.selectedSlot.end_time, this.selectedDate, this.tenantTz, this.customerTz);
            rows.push({ label: t('summary.time_label'), value: `${displayStart} – ${displayEnd}` });
        }
        if (this.selectedService) {
            rows.push({ label: t('summary.duration_label'), value: this.formatDuration(this.selectedService.duration_minutes) });
        }
        if (!this.tzMatch) {
            rows.push({ label: t('timezone.label'), value: this.tzDisplayLabel(this.customerTz) });
        }

        // Custom field values (only filled fields appear in the summary)
        if (config.custom_fields && config.custom_fields.length) {
            for (const field of config.custom_fields) {
                const value = (this.customFields[field.name] || '').trim();
                if (value) {
                    rows.push({ label: field.label, value });
                }
            }
        }

        return rows;
    },

    // Resource pattern summary
    get resourceSummaryRows() {
        const rows = [];
        if (this.selectedResource) {
            rows.push({ label: t('resource.summary_resource'), value: this.selectedResource.name });
        }
        if (this.checkInDate) {
            rows.push({ label: t('resource.check_in_label'), value: this.formatDateDisplay(this.checkInDate) });
        }
        if (this.checkOutDate) {
            rows.push({ label: t('resource.check_out_label'), value: this.formatDateDisplay(this.checkOutDate) });
        }
        if (this.resourceAvailability) {
            rows.push({ label: t('resource.nights_label'), value: String(this.resourceAvailability.nights) });
            rows.push({ label: t('resource.total_label'), value: this.formatPrice(this.resourceAvailability.total) });
        }
        if (this.guestCount > 1) {
            rows.push({ label: t('resource.guests_label'), value: String(this.guestCount) });
        }

        // Custom field values
        if (config.custom_fields && config.custom_fields.length) {
            for (const field of config.custom_fields) {
                const value = (this.customFields[field.name] || '').trim();
                if (value) {
                    rows.push({ label: field.label, value });
                }
            }
        }
        return rows;
    },

    // Preparation text (service-level, shown as callout on review step)
    get preparationText() {
        return this.selectedService?.preparation_text || '';
    },

    // ── Step 6: Submit ──
    async submitBooking() {


        this.submitting = true;

        const slot = this.selectedSlot;
        const startDt = `${this.selectedDate}T${slot.time}:00`;

        const payload = {
            service_id: this.selectedService?.id || null,
            staff_id: this.selectedStaff?.id || slot.staff_id || null,
            start_datetime: startDt,
            customer: {
                name: this.customerName.trim(),
                email: this.customerEmail.trim(),
                phone: this.customerPhone.trim() || '',
            },
            notes: this.customerNotes.trim() || '',
            custom_fields: Object.keys(this.customFields).length > 0 ? this.customFields : null,
            consent_given: this.consentGiven,
            customer_timezone: this.customerTz,
            __ts: window.__VB_TS__,
            __hp: this.$el.querySelector('[name="__hp"]')?.value || '',
        };

        try {
            const data = await this.api('/bookings', {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            if (data.error) {
                this.submitting = false;
                if (data.error === 'slot_unavailable') {
                    const alts = data.alternatives || [];
                    if (alts.length > 0) {
                        this.slotAlternatives = alts;
                    } else {
                        this.showToast(data.message || t('errors.slot_taken'), 'warn');
                        this.goToStep('date');
                    }
                } else if (data.error === 'max_bookings_exceeded') {
                    this.showToast(data.message || t('errors.generic'), 'error');
                } else {
                    this.showToast(data.message || t('errors.generic'), 'error');
                }
                return;
            }

            this.booking = data.booking;
            this.bookingIsPending = (data.booking.status === 'pending');
            this.goToStep('confirmed');
            this.$nextTick(() => window.scrollTo({ top: 0, behavior: scrollBehavior() }));
        } catch {
            this.submitting = false;
            this.showToast(t('errors.connection'), 'error');
        }
    },

    // Slot-taken recovery: user picks an alternative time
    selectAlternative(alt) {
        this.selectedSlot = { time: alt.time, end_time: alt.end_time, staff_id: alt.staff_id };
        this.slotAlternatives = [];
        // No auto-submit — user must explicitly click "Confirm booking" again
    },

    // Format a slot time for display (used by recovery pills)
    formatSlotTime(time) {
        return formatSlotDisplay(time, this.selectedDate, this.tenantTz, this.customerTz);
    },

    // ── Confirmation ──
    get confirmSummaryRows() {
        if (!this.booking) return [];
        const b = this.booking;
        const rows = [];
        if (b.service) rows.push({ label: t('summary.service_label'), value: b.service });
        if (b.staff) rows.push({ label: t('summary.with_label'), value: b.staff });
        rows.push({ label: t('summary.date_label'), value: this.formatDateDisplay(b.date) });

        const displayStart = formatSlotDisplay(b.time, b.date, this.tenantTz, this.customerTz);
        const displayEnd   = formatSlotDisplay(b.end_time, b.date, this.tenantTz, this.customerTz);
        rows.push({ label: t('summary.time_label'), value: `${displayStart} – ${displayEnd}` });
        rows.push({ label: t('summary.duration_label'), value: this.formatDuration(b.duration) });
        return rows;
    },

    get hasCalendarActions() {
        if (!this.booking) return false;
        // No calendar links for pending/waitlisted bookings (PRD: links appear after approval)
        if (this.bookingIsPending || this.eventIsWaitlisted) return false;
        const b = this.booking;
        // Timeslot: needs date + time; Resource: needs check_in + check_out; Capacity: needs date + time
        if (config.booking_pattern === 'resource') return !!(b.check_in && b.check_out);
        if (config.booking_pattern === 'capacity') return !!(b.date && b.time && b.end_time);
        if (config.booking_pattern === 'event') return !!(b.date && b.time && b.end_time);
        return !!(b.date && b.time && b.end_time);
    },

    get gcalUrl() {
        if (!this.booking) return '#';
        const b = this.booking;

        if (config.booking_pattern === 'resource') {
            // All-day event: check_in → check_out
            const start = b.check_in.replace(/-/g, '');
            const end = b.check_out.replace(/-/g, '');
            const title = encodeURIComponent(b.resource || config.name);
            return `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${title}&dates=${start}/${end}`;
        }

        if (config.booking_pattern === 'capacity') {
            const start = `${b.date.replace(/-/g, '')}T${b.time.replace(':', '')}00`;
            const end = `${b.date.replace(/-/g, '')}T${b.end_time.replace(':', '')}00`;
            const title = encodeURIComponent(b.label || config.name);
            return `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${title}&dates=${start}/${end}`;
        }

        if (config.booking_pattern === 'event') {
            const start = `${b.date.replace(/-/g, '')}T${b.time.replace(':', '')}00`;
            const end = `${b.date.replace(/-/g, '')}T${b.end_time.replace(':', '')}00`;
            const title = encodeURIComponent(b.event_name || config.name);
            return `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${title}&dates=${start}/${end}`;
        }

        const start = `${b.date.replace(/-/g, '')}T${b.time.replace(':', '')}00`;
        const end = `${b.date.replace(/-/g, '')}T${b.end_time.replace(':', '')}00`;
        const title = encodeURIComponent(b.service || config.name);
        return `https://calendar.google.com/calendar/render?action=TEMPLATE&text=${title}&dates=${start}/${end}`;
    },

    // Basic client-side .ics export (no VTIMEZONE definition block).
    // Values are escaped per iCalendar text rules but this is not a
    // full RFC 5545 implementation.
    downloadIcs() {
        if (!this.booking) return;
        const b = this.booking;
        const esc = (s) => s.replace(/\\/g, '\\\\').replace(/;/g, '\\;').replace(/,/g, '\\,').replace(/\n/g, '\\n');
        const uid = `${b.id}@${config.slug}.voxelbooking`;

        let lines;

        if (config.booking_pattern === 'resource') {
            // All-day event for resource bookings
            const dtStart = b.check_in.replace(/-/g, '');
            const dtEnd = b.check_out.replace(/-/g, '');
            const summary = esc(b.resource || config.name);
            const nights = b.nights || '';
            const description = nights ? esc(`${nights} night stay`) : '';

            lines = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//VoxelBooking//EN',
                'CALSCALE:GREGORIAN',
                'METHOD:PUBLISH',
                'BEGIN:VEVENT',
                `UID:${uid}`,
                `DTSTART;VALUE=DATE:${dtStart}`,
                `DTEND;VALUE=DATE:${dtEnd}`,
                `SUMMARY:${summary}`,
                description ? `DESCRIPTION:${description}` : '',
                `DTSTAMP:${new Date().toISOString().replace(/[-:.]/g, '').slice(0, 15)}Z`,
                'END:VEVENT',
                'END:VCALENDAR',
            ];
        } else {
            const pad = (s) => s.replace(/-/g, '').replace(/:/g, '');
            const dtStart = `${pad(b.date)}T${pad(b.time)}00`;
            const dtEnd = `${pad(b.date)}T${pad(b.end_time)}00`;
            const summary = esc(b.service || config.name);
            const description = b.staff ? esc(`With ${b.staff}`) : '';

            lines = [
                'BEGIN:VCALENDAR',
                'VERSION:2.0',
                'PRODID:-//VoxelBooking//EN',
                'CALSCALE:GREGORIAN',
                'METHOD:PUBLISH',
                'BEGIN:VEVENT',
                `UID:${uid}`,
                `DTSTART;TZID=${this.tenantTz}:${dtStart}`,
                `DTEND;TZID=${this.tenantTz}:${dtEnd}`,
                `SUMMARY:${summary}`,
                description ? `DESCRIPTION:${description}` : '',
                `DTSTAMP:${new Date().toISOString().replace(/[-:.]/g, '').slice(0, 15)}Z`,
                'END:VEVENT',
                'END:VCALENDAR',
            ];
        }

        const ics = lines.filter(Boolean).join('\r\n');
        const blob = new Blob([ics], { type: 'text/calendar;charset=utf-8' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `booking-${b.id}.ics`;
        a.click();
        URL.revokeObjectURL(url);
    },

    // Confirmation email-sent message with :email replaced
    // Only shown when the API confirms an email was actually dispatched
    get confirmEmailSent() {
        if (!this.booking?.email_sent) return '';
        return t('confirmed.email_sent').replace(':email', this.customerEmail);
    },

    // Custom confirmation message from tenant config
    get confirmCustomMessage() {
        return config.confirmation_message || '';
    },

    // Affordance flags
    get showReschedule() { return config.allow_rescheduling; },
    get showCancel() { return config.allow_cancellation; },
    get bookingPageUrl() { return `/book/${config.slug}`; },
    manageUrl(bookingId) {
        return `/book/${config.slug}/manage/${bookingId}`;
    },

    // Book another: reload page
    bookAnother() {
        window.location.href = `/book/${config.slug}`;
    },

    // Cancellation policy
    get hasCancellationPolicy() { return !!config.cancellation_policy; },
    get cancellationPolicyText() { return config.cancellation_policy || ''; },
    togglePolicy() { this.policyOpen = !this.policyOpen; },

    // ── Toast ──
    showToast(message, type = 'error') {
        if (this.toastTimer) clearTimeout(this.toastTimer);
        this.toast = { message, type };
        this.toastTimer = setTimeout(() => { this.toast = null; }, 5000);
    },

    dismissToast() {
        this.toast = null;
        if (this.toastTimer) clearTimeout(this.toastTimer);
    },

    get toastClass() {
        return this.toast ? 'vb-book-toast-' + this.toast.type : '';
    },

    isZoneSelected(zone) {
        return zone === this.customerTz;
    },

    // ── Self-service booking management ──

    /**
     * Load booking details for the manage page.
     * Called on init when manage_mode is true.
     */
    async loadManagedBooking() {
        this.manageLoading = true;
        this.step = 'manage';
        try {
            const resp = await fetch(`/api/${config.slug}/bookings/${this.manageBookingId}`);
            const data = await resp.json();
            if (!resp.ok || data.error) {
                this.showToast(data.message || t('manage.not_found'));
                return;
            }
            this.managedBooking = data.booking;
            this.manageCanCancel = data.can_cancel;
            this.manageCanReschedule = data.can_reschedule;
            this.manageRescheduleReason = data.reschedule_reason || null;

            // If already cancelled, show the cancelled state
            if (data.booking.status === 'cancelled') {
                this.manageCancelled = true;
            }
        } catch {
            this.showToast(t('errors.connection'));
        } finally {
            this.manageLoading = false;
        }
    },

    /**
     * Cancel the managed booking via API.
     */
    async cancelManagedBooking() {
        if (!this.manageBookingId) return;
        this.manageCancelling = true;
        try {
            const resp = await fetch(`/api/${config.slug}/bookings/${this.manageBookingId}/cancel`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify({ reason: this.manageCancelReason }),
            });
            const data = await resp.json();
            if (!resp.ok || data.error) {
                this.showToast(data.message || t('errors.generic'));
                this.manageCancelModalOpen = false;
                return;
            }
            // Success — show cancelled state
            this.manageCancelled = true;
            this.manageCancelModalOpen = false;
            this.manageCanCancel = false;
            this.manageCanReschedule = false;
            this.manageRescheduleReason = null;
            if (this.managedBooking) {
                this.managedBooking.status = 'cancelled';
            }
        } catch {
            this.showToast(t('errors.connection'));
        } finally {
            this.manageCancelling = false;
        }
    },

    // CSP-safe setter for cancel reason textarea
    setManageCancelReason(val) { this.manageCancelReason = val; },

    /**
     * Returns the correct i18n key for why rescheduling is disabled.
     */
    get rescheduleGateMessage() {
        switch (this.manageRescheduleReason) {
            case 'too_late':               return t('manage.reschedule_reason_too_late');
            case 'pattern_not_supported':  return t('manage.reschedule_reason_disabled');
            case 'rescheduling_disabled':   return t('manage.reschedule_reason_disabled');
            case 'not_confirmed':          return t('manage.reschedule_reason_not_confirmed');
            default:                       return t('manage.reschedule_reason_disabled');
        }
    },

    // ── Self-service reschedule flow ──

    /**
     * Start the reschedule wizard from the manage page.
     * Branches by booking pattern to the appropriate flow.
     */
    async startReschedule() {

        if (!this.managedBooking || !this.manageCanReschedule) return;

        const pattern = this.managedBooking.booking_pattern || 'timeslot';

        // Reset shared state
        this.rescheduleDate = null;
        this.rescheduleSlot = null;
        this.rescheduleSlots = [];
        this.rescheduleDates = [];
        this.rescheduleNewBooking = null;
        this.rescheduleCheckIn = null;
        this.rescheduleCheckOut = null;
        this.rescheduleResourceDates = [];
        this.rescheduleCapacitySlots = [];
        this.rescheduleCapacitySlot = null;
        this.rescheduleEvents = [];
        this.rescheduleSelectedEvent = null;

        // Pre-position calendar to booking month
        const bookingDate = this.managedBooking.check_in || this.managedBooking.date;
        if (bookingDate) {
            const [y, m] = bookingDate.split('-').map(Number);
            this.rescheduleYear = y;
            this.rescheduleMonth = m - 1;
        } else {
            const now = new Date();
            this.rescheduleYear = now.getFullYear();
            this.rescheduleMonth = now.getMonth();
        }

        if (pattern === 'resource') {
            this.goToStep('reschedule-resource');
            await this.loadRescheduleResourceDates();
        } else if (pattern === 'capacity') {
            this.goToStep('reschedule-capacity');
            await this.loadRescheduleCapacityDates();
        } else if (pattern === 'event') {
            this.goToStep('reschedule-event');
            await this.loadRescheduleEvents();
        } else {
            this.goToStep('reschedule-date');
            await this.loadRescheduleDates();
        }
    },

    /**
     * Cancel the reschedule flow and return to the manage page.
     */
    cancelReschedule() {
        this.rescheduleDate = null;
        this.rescheduleSlot = null;
        this.rescheduleSlots = [];
        this.rescheduleDates = [];
        this.rescheduleNewBooking = null;
        this.rescheduleCheckIn = null;
        this.rescheduleCheckOut = null;
        this.rescheduleResourceDates = [];
        this.rescheduleCapacitySlots = [];
        this.rescheduleCapacitySlot = null;
        this.rescheduleEvents = [];
        this.rescheduleSelectedEvent = null;
        this.goToStep('manage');
    },

    /**
     * Go back from review to the pattern-appropriate date step.
     */
    goBackToRescheduleDate() {
        this.rescheduleSlot = null;
        this.rescheduleCapacitySlot = null;
        this.rescheduleSelectedEvent = null;
        const pattern = this.managedBooking?.booking_pattern || 'timeslot';
        if (pattern === 'resource') {
            this.rescheduleCheckOut = null;
            this.goToStep('reschedule-resource');
        } else if (pattern === 'capacity') {
            this.goToStep('reschedule-capacity');
        } else if (pattern === 'event') {
            this.goToStep('reschedule-event');
        } else {
            this.goToStep('reschedule-date');
        }
    },

    /**
     * Load available dates for the reschedule calendar.
     */
    async loadRescheduleDates() {
        if (!this.managedBooking) return;
        const b = this.managedBooking;
        const params = new URLSearchParams({
            year: this.rescheduleYear,
            month: this.rescheduleMonth + 1,
        });
        if (b.service_id) params.set('service_id', b.service_id);
        if (b.staff_id) params.set('staff_id', b.staff_id);

        const data = await this.api(`/available-dates?${params}`);
        this.rescheduleDates = data.dates || [];
    },

    /**
     * Navigate to the previous month in the reschedule calendar.
     */
    async reschedulePrevMonth() {
        this.rescheduleCalendarFading = true;
        this.rescheduleMonth--;
        if (this.rescheduleMonth < 0) { this.rescheduleMonth = 11; this.rescheduleYear--; }
        this.rescheduleDate = null;
        this.rescheduleSlot = null;
        this.rescheduleSlots = [];
        await this.loadRescheduleDates();
        setTimeout(() => { this.rescheduleCalendarFading = false; }, 180);
    },

    /**
     * Navigate to the next month in the reschedule calendar.
     */
    async rescheduleNextMonth() {
        this.rescheduleCalendarFading = true;
        this.rescheduleMonth++;
        if (this.rescheduleMonth > 11) { this.rescheduleMonth = 0; this.rescheduleYear++; }
        this.rescheduleDate = null;
        this.rescheduleSlot = null;
        this.rescheduleSlots = [];
        await this.loadRescheduleDates();
        setTimeout(() => { this.rescheduleCalendarFading = false; }, 180);
    },

    /**
     * Calendar month label for the reschedule calendar.
     */
    get rescheduleMonthLabel() {
        const label = new Date(this.rescheduleYear, this.rescheduleMonth, 1)
            .toLocaleDateString(fmt.intl_locale || config.locale || 'en', { month: 'long', year: 'numeric' });
        return label.charAt(0).toUpperCase() + label.slice(1);
    },

    /**
     * Calendar grid cells for the reschedule calendar.
     */
    get rescheduleCalendarCells() {
        const year = this.rescheduleYear;
        const month = this.rescheduleMonth;
        const today = new Date();
        const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const weekStart = fmt.week_start ?? 0;
        const rawDay = new Date(year, month, 1).getDay();
        const firstDay = (rawDay - weekStart + 7) % 7;

        const cells = [];

        for (let i = 0; i < firstDay; i++) {
            cells.push({ day: '', dateStr: '', disabled: true, today: false, hasSlots: false, selected: false });
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const isPast = new Date(dateStr) < new Date(today.toDateString());
            const hasSlots = this.rescheduleDates.includes(dateStr);
            cells.push({
                day,
                dateStr,
                disabled: !hasSlots || isPast,
                today: dateStr === todayStr,
                hasSlots,
                selected: dateStr === this.rescheduleDate,
            });
        }

        return cells;
    },

    /**
     * Select a date in the reschedule calendar and load time slots.
     */
    async selectRescheduleDate(cell) {
        if (cell.disabled || !cell.day) return;
        this.rescheduleDate = cell.dateStr;
        this.rescheduleSlot = null;
        this.rescheduleSlots = [];

        const b = this.managedBooking;
        const params = new URLSearchParams({ date: cell.dateStr });
        if (b.service_id) params.set('service_id', b.service_id);
        if (b.staff_id) params.set('staff_id', b.staff_id);

        const data = await this.api(`/availability?${params}`);
        this.rescheduleSlots = data.slots || [];
    },

    /**
     * Select a time slot and transition to the review step.
     */
    selectRescheduleSlot(slot) {
        this.rescheduleSlot = slot;
    },

    confirmRescheduleSlot() {
        if (!this.rescheduleSlot) return;
        this.goToStep('reschedule-review');
    },

    get rescheduleSlotLabel() {
        if (!this.rescheduleSlot || !this.rescheduleDate) return '';
        const start = formatSlotDisplay(this.rescheduleSlot.time, this.rescheduleDate, this.tenantTz, this.customerTz);
        const end = this.rescheduleSlot.end_time
            ? formatSlotDisplay(this.rescheduleSlot.end_time, this.rescheduleDate, this.tenantTz, this.customerTz)
            : null;
        const timeStr = end ? start + ' – ' + end : start;
        return this.formatDateDisplay(this.rescheduleDate) + '  ·  ' + timeStr;
    },

    /**
     * Display string for the original booking date/time (pattern-aware).
     */
    get rescheduleOriginalDisplay() {
        if (!this.managedBooking) return '';
        const b = this.managedBooking;
        const pattern = b.booking_pattern || 'timeslot';
        if (pattern === 'resource') {
            const cin = b.check_in ? this.formatDateDisplay(b.check_in) : '';
            const cout = b.check_out ? this.formatDateDisplay(b.check_out) : '';
            return cin && cout ? `${cin} → ${cout}` : cin;
        }
        if (pattern === 'event') {
            const parts = [b.event_name || ''];
            if (b.date) parts.push(this.formatDateDisplay(b.date));
            if (b.time && b.end_time) parts.push(`${b.time} – ${b.end_time}`);
            return parts.filter(Boolean).join(', ');
        }
        const dateLabel = this.formatDateDisplay(b.date);
        const timeLabel = b.time && b.end_time ? `${b.time} – ${b.end_time}` : '';
        return timeLabel ? `${dateLabel}, ${timeLabel}` : dateLabel;
    },

    /**
     * Display string for the new reschedule date/time (pattern-aware).
     */
    get rescheduleNewDisplay() {
        const pattern = this.managedBooking?.booking_pattern || 'timeslot';
        if (pattern === 'resource') {
            if (!this.rescheduleCheckIn || !this.rescheduleCheckOut) return '';
            return `${this.formatDateDisplay(this.rescheduleCheckIn)} → ${this.formatDateDisplay(this.rescheduleCheckOut)}`;
        }
        if (pattern === 'capacity') {
            if (!this.rescheduleDate || !this.rescheduleCapacitySlot) return '';
            const dateLabel = this.formatDateDisplay(this.rescheduleDate);
            const label = this.rescheduleCapacitySlot.label || '';
            const time = this.rescheduleCapacitySlot.time && this.rescheduleCapacitySlot.end_time
                ? `${this.rescheduleCapacitySlot.time} – ${this.rescheduleCapacitySlot.end_time}` : '';
            return [dateLabel, label, time].filter(Boolean).join(', ');
        }
        if (pattern === 'event') {
            if (!this.rescheduleSelectedEvent) return '';
            const ev = this.rescheduleSelectedEvent;
            const parts = [ev.name || ''];
            if (ev.start_datetime) {
                parts.push(this.formatEventDate(ev.start_datetime));
                parts.push(this.formatEventTime(ev.start_datetime) + '–' + this.formatEventTime(ev.end_datetime));
            }
            return parts.filter(Boolean).join(', ');
        }
        if (!this.rescheduleDate || !this.rescheduleSlot) return '';
        const dateLabel = this.formatDateDisplay(this.rescheduleDate);
        const endTime = this.rescheduleSlot.end_time || '';
        const timeLabel = endTime ? `${this.rescheduleSlot.time} – ${endTime}` : this.rescheduleSlot.time;
        return `${dateLabel}, ${timeLabel}`;
    },

    /**
     * Build pattern-specific payload for the reschedule API.
     */
    _buildReschedulePayload() {
        const pattern = this.managedBooking?.booking_pattern || 'timeslot';
        if (pattern === 'resource') {
            return { check_in: this.rescheduleCheckIn, check_out: this.rescheduleCheckOut };
        }
        if (pattern === 'capacity') {
            return { date: this.rescheduleDate, slot_id: this.rescheduleCapacitySlot?.id };
        }
        if (pattern === 'event') {
            return { date: this.rescheduleSelectedEvent?.date, event_id: this.rescheduleSelectedEvent?.id };
        }
        return { new_date: this.rescheduleDate, new_time: this.rescheduleSlot?.time };
    },

    /**
     * Check if the reschedule review can proceed (pattern-aware guard).
     */
    get canConfirmReschedule() {
        const pattern = this.managedBooking?.booking_pattern || 'timeslot';
        if (pattern === 'resource') return !!(this.rescheduleCheckIn && this.rescheduleCheckOut);
        if (pattern === 'capacity') return !!(this.rescheduleDate && this.rescheduleCapacitySlot);
        if (pattern === 'event') return !!this.rescheduleSelectedEvent;
        return !!(this.rescheduleDate && this.rescheduleSlot);
    },

    /**
     * Confirm the reschedule via the public API (pattern-aware).
     */
    async confirmReschedule() {
        if (this.rescheduleSubmitting || !this.canConfirmReschedule) return;
        this.rescheduleSubmitting = true;

        try {
            const resp = await fetch(`/api/${config.slug}/bookings/${this.manageBookingId}/reschedule`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken,
                },
                body: JSON.stringify(this._buildReschedulePayload()),
            });
            const data = await resp.json();

            if (!resp.ok || data.error) {
                this.showToast(data.message || t('errors.generic'), 'error');
                return;
            }

            // Success
            this.rescheduleNewBooking = data.new_booking;
            this.goToStep('reschedule-confirmed');
        } catch {
            this.showToast(t('errors.connection'), 'error');
        } finally {
            this.rescheduleSubmitting = false;
        }
    },

    // ── Resource reschedule methods ──

    async loadRescheduleResourceDates() {
        if (!this.managedBooking?.resource_id) return;
        const params = new URLSearchParams({
            year: this.rescheduleYear,
            month: this.rescheduleMonth + 1,
        });
        const data = await this.api(`/resources/${this.managedBooking.resource_id}/availability?${params}`);
        this.rescheduleResourceDates = data.dates || [];
    },

    get rescheduleResourceCalendarCells() {
        const year = this.rescheduleYear;
        const month = this.rescheduleMonth;
        const today = new Date();
        const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const weekStart = fmt.week_start ?? 0;
        const rawDay = new Date(year, month, 1).getDay();
        const firstDay = (rawDay - weekStart + 7) % 7;
        const cells = [];
        for (let i = 0; i < firstDay; i++) {
            cells.push({ day: '', dateStr: '', disabled: true, today: false, hasSlots: false, selected: false, inRange: false });
        }
        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const isPast = new Date(dateStr) < new Date(today.toDateString());
            const hasSlots = this.rescheduleResourceDates.includes(dateStr);
            const isCheckIn = dateStr === this.rescheduleCheckIn;
            const isCheckOut = dateStr === this.rescheduleCheckOut;
            const inRange = this.rescheduleCheckIn && this.rescheduleCheckOut
                && dateStr > this.rescheduleCheckIn && dateStr < this.rescheduleCheckOut;
            cells.push({
                day, dateStr,
                disabled: !hasSlots || isPast,
                today: dateStr === todayStr,
                hasSlots,
                selected: isCheckIn || isCheckOut,
                inRange,
            });
        }
        return cells;
    },

    selectRescheduleResourceDate(cell) {
        if (cell.disabled || !cell.day) return;
        if (!this.rescheduleCheckIn || (this.rescheduleCheckIn && this.rescheduleCheckOut)) {
            this.rescheduleCheckIn = cell.dateStr;
            this.rescheduleCheckOut = null;
        } else {
            if (cell.dateStr <= this.rescheduleCheckIn) {
                this.rescheduleCheckIn = cell.dateStr;
            } else {
                this.rescheduleCheckOut = cell.dateStr;
            }
        }
    },

    confirmRescheduleResource() {
        if (!this.rescheduleCheckIn || !this.rescheduleCheckOut) return;
        this.goToStep('reschedule-review');
    },

    async rescheduleResourcePrevMonth() {
        this.rescheduleCalendarFading = true;
        this.rescheduleMonth--;
        if (this.rescheduleMonth < 0) { this.rescheduleMonth = 11; this.rescheduleYear--; }
        await this.loadRescheduleResourceDates();
        setTimeout(() => { this.rescheduleCalendarFading = false; }, 180);
    },

    async rescheduleResourceNextMonth() {
        this.rescheduleCalendarFading = true;
        this.rescheduleMonth++;
        if (this.rescheduleMonth > 11) { this.rescheduleMonth = 0; this.rescheduleYear++; }
        await this.loadRescheduleResourceDates();
        setTimeout(() => { this.rescheduleCalendarFading = false; }, 180);
    },

    get rescheduleResourceLabel() {
        if (!this.rescheduleCheckIn) return '';
        const cin = this.formatDateDisplay(this.rescheduleCheckIn);
        if (!this.rescheduleCheckOut) return cin;
        return `${cin} → ${this.formatDateDisplay(this.rescheduleCheckOut)}`;
    },

    // ── Capacity reschedule methods ──

    async loadRescheduleCapacityDates() {
        if (!this.managedBooking) return;
        const ps = this.managedBooking.party_size || 1;
        const res = await this.api(`/capacity/available-dates?year=${this.rescheduleYear}&month=${this.rescheduleMonth + 1}&party_size=${ps}`);
        this.rescheduleDates = res.dates || [];
    },

    async selectRescheduleCapacityDate(cell) {
        if (cell.disabled || !cell.day) return;
        this.rescheduleDate = cell.dateStr;
        this.rescheduleCapacitySlot = null;
        this.rescheduleCapacitySlots = [];
        const ps = this.managedBooking?.party_size || 1;
        const res = await this.api(`/capacity/slots?date=${cell.dateStr}&party_size=${ps}`);
        this.rescheduleCapacitySlots = res.slots || [];
    },

    selectRescheduleCapacitySlot(slot) {
        this.rescheduleCapacitySlot = slot;
    },

    confirmRescheduleCapacity() {
        if (!this.rescheduleDate || !this.rescheduleCapacitySlot) return;
        this.goToStep('reschedule-review');
    },

    async rescheduleCapacityPrevMonth() {
        this.rescheduleCalendarFading = true;
        this.rescheduleMonth--;
        if (this.rescheduleMonth < 0) { this.rescheduleMonth = 11; this.rescheduleYear--; }
        this.rescheduleDate = null;
        this.rescheduleCapacitySlot = null;
        this.rescheduleCapacitySlots = [];
        await this.loadRescheduleCapacityDates();
        setTimeout(() => { this.rescheduleCalendarFading = false; }, 180);
    },

    async rescheduleCapacityNextMonth() {
        this.rescheduleCalendarFading = true;
        this.rescheduleMonth++;
        if (this.rescheduleMonth > 11) { this.rescheduleMonth = 0; this.rescheduleYear++; }
        this.rescheduleDate = null;
        this.rescheduleCapacitySlot = null;
        this.rescheduleCapacitySlots = [];
        await this.loadRescheduleCapacityDates();
        setTimeout(() => { this.rescheduleCalendarFading = false; }, 180);
    },

    get rescheduleCapacitySlotLabel() {
        if (!this.rescheduleCapacitySlot || !this.rescheduleDate) return '';
        const sl = this.rescheduleCapacitySlot;
        const dateLabel = this.formatDateDisplay(this.rescheduleDate);
        const time = sl.time && sl.end_time ? `${sl.time} – ${sl.end_time}` : '';
        return [dateLabel, sl.label, time].filter(Boolean).join('  ·  ');
    },

    // ── Event reschedule methods ──

    async loadRescheduleEvents() {
        const data = await this.api('/events');
        // Exclude the exact same occurrence (same event_id + same date) but allow
        // same event on a different date (recurring-event reschedule to another occurrence).
        const currentEventId = this.managedBooking?.event_id;
        const currentDate = this.managedBooking?.date;
        const now = new Date();
        this.rescheduleEvents = (data.events || []).filter(ev => {
            if (ev.id === currentEventId && ev.date === currentDate) return false;
            if (ev.start_datetime && new Date(ev.start_datetime) < now) return false;
            return true;
        });
    },

    selectRescheduleEvent(event) {
        this.rescheduleSelectedEvent = event;
    },

    confirmRescheduleEvent() {
        if (!this.rescheduleSelectedEvent) return;
        this.goToStep('reschedule-review');
    },

    /**
     * Summary rows for the reschedule confirmed state.
     */
    get rescheduleConfirmedRows() {
        const rows = [];
        if (!this.rescheduleNewBooking) return rows;
        const nb = this.rescheduleNewBooking;
        const pattern = this.managedBooking?.booking_pattern || 'timeslot';

        if (pattern === 'resource') {
            if (this.managedBooking?.resource_name) {
                rows.push({ label: t('summary.resource_label'), value: this.managedBooking.resource_name });
            }
            if (nb.check_in) rows.push({ label: t('resource.check_in_label'), value: this.formatDateDisplay(nb.check_in) });
            if (nb.check_out) rows.push({ label: t('resource.check_out_label'), value: this.formatDateDisplay(nb.check_out) });
            return rows;
        }

        if (pattern === 'event') {
            if (nb.event_name) rows.push({ label: t('event.events_title').replace(/s$/, ''), value: nb.event_name });
            if (nb.date) rows.push({ label: t('summary.date_label'), value: this.formatDateDisplay(nb.date) });
            if (nb.time) rows.push({ label: t('summary.time_label'), value: `${nb.time} – ${nb.end_time}` });
            return rows;
        }

        // Timeslot / Capacity
        if (this.managedBooking?.service_name) {
            rows.push({ label: t('summary.service_label'), value: this.managedBooking.service_name });
        }
        if (this.managedBooking?.staff_name) {
            rows.push({ label: t('summary.with_label'), value: this.managedBooking.staff_name });
        }
        if (nb.date) {
            rows.push({ label: t('summary.date_label'), value: this.formatDateDisplay(nb.date) });
        }
        if (nb.time) {
            rows.push({ label: t('summary.time_label'), value: `${nb.time} – ${nb.end_time}` });
        }
        return rows;
    },

    /**
     * Manage page summary rows for the booking detail card.
     */
    get manageSummaryRows() {
        if (!this.managedBooking) return [];
        const b = this.managedBooking;
        const rows = [];

        // Service / Resource / Event name
        if (b.service_name) rows.push({ label: t('summary.service_label'), value: b.service_name });
        if (b.resource_name) rows.push({ label: t('summary.resource_label'), value: b.resource_name });
        if (b.event_name) rows.push({ label: t('event.events_title').replace(/s$/, ''), value: b.event_name });

        // Staff
        if (b.staff_name) rows.push({ label: t('summary.with_label'), value: b.staff_name });

        // Date — resource shows check-in/check-out, others show date + time
        if (b.booking_pattern === 'resource') {
            if (b.check_in) rows.push({ label: t('resource.check_in_label'), value: this.formatDateDisplay(b.check_in) });
            if (b.check_out) rows.push({ label: t('resource.check_out_label'), value: this.formatDateDisplay(b.check_out) });
        } else {
            if (b.date) rows.push({ label: t('summary.date_label'), value: this.formatDateDisplay(b.date) });
            if (b.time) rows.push({ label: t('summary.time_label'), value: `${b.time} – ${b.end_time}` });
        }

        // Party size (capacity/event)
        if (b.party_size > 1) {
            rows.push({ label: t('capacity.party_size_label'), value: `${b.party_size}` });
        }

        return rows;
    },

    /**
     * Manage page status label.
     */
    get manageStatusLabel() {
        if (!this.managedBooking) return '';
        const s = this.managedBooking.status;
        const key = `manage.status_${s}`;
        return t(key) || s;
    },

    // ── Format helpers ──
    formatPrice(price) {
        const num = parseFloat(price);
        if (isNaN(num)) return '';
        const currency = config.currency || 'EUR';

        // Use resolved separators from PHP Locale engine (tenant → system → locale)
        const decSep = fmt.decimal_sep;
        const thousSep = fmt.thousands_sep;

        if (decSep !== undefined) {
            // Manual formatting using resolved separators
            const fixed = Math.abs(num).toFixed(2);
            const [intPart, decPart] = fixed.split('.');
            const grouped = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, '\x00');
            const formatted = (num < 0 ? '-' : '') + grouped.replace(/\x00/g, thousSep || '') + decSep + decPart;

            // Symbol from PHP currency registry (config/currencies.php)
            const symbol = fmt.currency_symbol || currency;
            const space = fmt.currency_space ? ' ' : '';
            const pos = fmt.currency_position || 'before';
            return pos === 'before' ? symbol + space + formatted : formatted + space + symbol;
        }

        // Fallback: Intl.NumberFormat
        try {
            return new Intl.NumberFormat(fmt.intl_locale || config.locale || 'en', {
                style: 'currency', currency,
            }).format(num);
        } catch {
            return `${currency} ${num.toFixed(2)}`;
        }
    },

    formatDuration(minutes) {
        if (!minutes) return '';
        if (minutes >= 60) {
            const h = Math.floor(minutes / 60);
            const m = minutes % 60;
            return m > 0 ? `${h}${t('duration.hours')} ${m}${t('duration.minutes')}` : `${h}${t('duration.hours')}`;
        }
        return `${minutes} ${t('duration.minutes')}`;
    },

    formatDateDisplay(dateStr) {
        if (!dateStr) return '';
        const d = new Date(dateStr + 'T00:00:00');
        const label = d.toLocaleDateString(fmt.intl_locale || config.locale || 'en', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric',
        });
        return label.charAt(0).toUpperCase() + label.slice(1);
    },

    // ── Details step: compact context summary (pattern-aware) ──
    get detailsContextSummary() {
        const shortDate = (dateStr) => {
            if (!dateStr) return '';
            const d = new Date(dateStr + 'T00:00:00');
            return d.toLocaleDateString(fmt.intl_locale || config.locale || 'en', {
                weekday: 'short', day: 'numeric', month: 'short',
            });
        };

        // Resource pattern: resource + check-in/check-out
        if (config.booking_pattern === 'resource') {
            const parts = [];
            if (this.selectedResource) parts.push(this.selectedResource.name);
            if (this.checkInDate) parts.push(shortDate(this.checkInDate));
            if (this.checkOutDate) parts.push('→ ' + shortDate(this.checkOutDate));
            return parts.join('  ·  ');
        }

        // Capacity pattern: slot label + date + time range
        if (config.booking_pattern === 'capacity') {
            const parts = [];
            if (this.selectedCapacitySlot?.label) parts.push(this.selectedCapacitySlot.label);
            if (this.selectedDate) parts.push(shortDate(this.selectedDate));
            if (this.selectedCapacitySlot) {
                const start = formatSlotDisplay(this.selectedCapacitySlot.time, this.selectedDate, this.tenantTz, this.customerTz);
                const end = this.selectedCapacitySlot.end_time
                    ? formatSlotDisplay(this.selectedCapacitySlot.end_time, this.selectedDate, this.tenantTz, this.customerTz)
                    : null;
                parts.push(end ? `${start}–${end}` : start);
            }
            return parts.join('  ·  ');
        }

        // Event pattern: event name + date + time range
        if (config.booking_pattern === 'event') {
            const parts = [];
            if (this.selectedEvent) {
                parts.push(this.selectedEvent.name);
                if (this.selectedEvent.start_datetime) {
                    parts.push(this.formatEventDate(this.selectedEvent.start_datetime));
                    parts.push(this.formatEventTime(this.selectedEvent.start_datetime)
                        + '–' + this.formatEventTime(this.selectedEvent.end_datetime));
                }
            }
            return parts.join('  ·  ');
        }

        // Timeslot pattern (default): service + date + time range
        const parts = [];
        if (this.selectedService) parts.push(this.selectedService.name);
        if (this.selectedDate) parts.push(shortDate(this.selectedDate));
        if (this.selectedSlot) {
            const start = formatSlotDisplay(this.selectedSlot.time, this.selectedDate, this.tenantTz, this.customerTz);
            const end = this.selectedSlot.end_time
                ? formatSlotDisplay(this.selectedSlot.end_time, this.selectedDate, this.tenantTz, this.customerTz)
                : null;
            parts.push(end ? `${start}–${end}` : start);
        }
        return parts.join('  ·  ');
    },

    // ── Time slot grouping (Morning / Afternoon / Evening) ──
    // Uses customer-displayed time, not raw tenant-timezone slot.time
    get groupedSlots() {
        if (!this.availableSlots || this.availableSlots.length === 0) return [];
        const groups = [];
        let currentGroup = null;

        for (const slot of this.availableSlots) {
            // Resolve the displayed time in the customer's timezone
            const displayTime = this.selectedDate
                ? formatSlotDisplay(slot.time, this.selectedDate, this.tenantTz, this.customerTz)
                : slot.time;
            const hour = parseInt(displayTime.split(':')[0], 10);
            let period;
            if (hour < 12) period = t('time_periods.morning') || 'Morning';
            else if (hour < 17) period = t('time_periods.afternoon') || 'Afternoon';
            else period = t('time_periods.evening') || 'Evening';

            if (!currentGroup || currentGroup.label !== period) {
                currentGroup = { label: period, slots: [] };
                groups.push(currentGroup);
            }
            currentGroup.slots.push(slot);
        }
        return groups;
    },

    // ── Navigation helpers ──
    get showStaffBackLink() {
        return this.services.length > 1;
    },

    get dateBackTarget() {
        if (this.staff.length > 1) return 'staff';
        if (this.services.length > 1) return 'service';
        return null;
    },

    goBack(target) {
        if (target === 'service') this.loadServices();
        else if (target === 'staff') this.loadStaff();
        else if (target === 'date') this.loadDates();
        else if (target === 'details') this.goToStep('details');
        else if (target === 'resource') this.loadResources();
        else if (target === 'resource-date') this.goToStep('resource-date');
        else if (target === 'guests') this.goToStep('guests');
        // Capacity pattern
        else if (target === 'party-size') this.goToStep('party-size');
        else if (target === 'capacity-date') this.loadCapacityDates();
        else if (target === 'capacity-time') this.goToStep('capacity-time');
        // Event pattern
        else if (target === 'event-list') this.loadEvents();
        else if (target === 'event-detail') this.goToStep('event-detail');
        else if (target === 'event-spots') this.goToStep('event-spots');
    },

    // ── Resource booking flow ──

    async loadResources() {
        const data = await this.api('/resources');
        this.resources = data.resources || [];

        if (this.resources.length === 0) {
            this.clearExitStates();
            this.step = 'empty';
            return;
        }

        if (this.resources.length === 1) {
            this.selectedResource = this.resources[0];
            this.guestCount = 1;
            this.loadResourceDates();
            return;
        }

        // Use goToStep for proper exit animation when called from goBack().
        this.goToStep('resource');
    },

    selectResource(resource) {
        this.selectedResource = resource;
        this.guestCount = 1;
        this.checkInDate = null;
        this.checkOutDate = null;
        this.resourceAvailability = null;
        setTimeout(() => this.loadResourceDates(), 200);
    },

    isResourceSelected(resource) {
        return this.selectedResource?.id === resource.id;
    },

    async loadResourceDates() {
        const params = new URLSearchParams({
            year: this.checkInYear,
            month: this.checkInMonth + 1,
        });

        const data = await this.api(`/resources/${this.selectedResource.id}/availability?${params}`);
        this.resourceDates = data.dates || [];

        // Store day-of-week restrictions from API (also available on selectedResource)
        if (data.check_in_days !== undefined) {
            this.selectedResource._check_in_days = data.check_in_days;
        }
        if (data.check_out_days !== undefined) {
            this.selectedResource._check_out_days = data.check_out_days;
        }

        this.goToStep('resource-date');
    },

    isResourceDateAvailable(dateStr) {
        return this.resourceDates.includes(dateStr);
    },

    selectCheckIn(dateStr) {
        if (!this.isResourceDateAvailable(dateStr)) return;
        this.checkInDate = dateStr;
        this.checkOutDate = null;
        this.resourceAvailability = null;
    },

    selectCheckOut(dateStr) {
        if (!this.isResourceDateAvailable(dateStr)) return;
        if (dateStr <= this.checkInDate) return;
        this.checkOutDate = dateStr;
        this.checkResourceAvailability();
    },

    async checkResourceAvailability() {
        if (!this.checkInDate || !this.checkOutDate) return;

        const data = await this.api(
            `/resources/${this.selectedResource.id}/availability?check_in=${this.checkInDate}&check_out=${this.checkOutDate}&guests=${this.guestCount}`
        );

        if (data.available) {
            this.resourceAvailability = data;
            this.goToStep('guests');
        } else {
            this.resourceAvailability = null;
            this.showToast(t(`resource.error_${data.error}`) || t('errors.generic'), 'warn');
        }
    },

    setGuestCount(val) {
        this.guestCount = Math.max(1, Math.min(parseInt(val) || 1, this.guestMax));
    },

    get guestMax() {
        return (this.selectedResource && this.selectedResource.capacity) || 10;
    },

    incrementGuests() {
        if (this.guestCount < this.guestMax) {
            this.guestCount++;
        }
    },

    decrementGuests() {
        if (this.guestCount > 1) {
            this.guestCount--;
        }
    },

    submitGuests() {
        this.goToStep('details');
    },

    // ── Resource calendar navigation ──

    get resourceMonthLabel() {
        const label = new Date(this.checkInYear, this.checkInMonth, 1)
            .toLocaleDateString(fmt.intl_locale || config.locale || 'en', { month: 'long', year: 'numeric' });
        return label.charAt(0).toUpperCase() + label.slice(1);
    },

    get canPrevResourceMonth() {
        const now = new Date();
        return !(this.checkInYear === now.getFullYear() && this.checkInMonth === now.getMonth());
    },

    async prevResourceMonth() {
        this.checkInMonth--;
        if (this.checkInMonth < 0) { this.checkInMonth = 11; this.checkInYear--; }
        await this.loadResourceDates();
    },

    async nextResourceMonth() {
        this.checkInMonth++;
        if (this.checkInMonth > 11) { this.checkInMonth = 0; this.checkInYear++; }
        await this.loadResourceDates();
    },

    get resourceCalendarCells() {
        const year = this.checkInYear;
        const month = this.checkInMonth;
        const today = new Date();
        const todayStr = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}-${String(today.getDate()).padStart(2, '0')}`;
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const weekStart = fmt.week_start ?? 0;
        const rawDay = new Date(year, month, 1).getDay();
        const firstDay = (rawDay - weekStart + 7) % 7;

        // Day restrictions from selected resource
        const ciDays = this.selectedResource?.check_in_days ?? this.selectedResource?._check_in_days ?? null;
        const coDays = this.selectedResource?.check_out_days ?? this.selectedResource?._check_out_days ?? null;
        const isSelectingCheckOut = !!this.checkInDate && !this.checkOutDate;

        const cells = [];

        for (let i = 0; i < firstDay; i++) {
            cells.push({ day: '', dateStr: '', disabled: true, today: false, hasSlots: false, selected: false, inRange: false, _key: 'empty-' + i });
        }

        for (let day = 1; day <= daysInMonth; day++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
            const isPast = new Date(dateStr) < new Date(today.toDateString());
            const available = this.resourceDates.includes(dateStr);
            const isCheckIn = dateStr === this.checkInDate;
            const isCheckOut = dateStr === this.checkOutDate;
            const inRange = this.checkInDate && this.checkOutDate && dateStr > this.checkInDate && dateStr < this.checkOutDate;

            // Day-of-week restriction: dim dates that aren't valid for the current selection mode
            const dow = new Date(year, month, day).getDay();
            let dayRestricted = false;
            if (available && !isPast) {
                if (isSelectingCheckOut) {
                    // When selecting check-out, dim days not in check_out_days
                    if (coDays && !coDays.includes(dow)) {
                        dayRestricted = true;
                    }
                } else if (!this.checkInDate || (this.checkInDate && this.checkOutDate)) {
                    // When selecting check-in, dim days not in check_in_days
                    if (ciDays && !ciDays.includes(dow)) {
                        dayRestricted = true;
                    }
                }
            }

            cells.push({
                day,
                dateStr,
                disabled: !available || isPast,
                today: dateStr === todayStr,
                hasSlots: available,
                selected: isCheckIn || isCheckOut,
                isCheckIn,
                isCheckOut,
                inRange,
                dayRestricted,
            });
        }

        return cells;
    },

    clickResourceDate(cell) {
        if (cell.disabled || !cell.day) return;

        const ciDays = this.selectedResource?.check_in_days ?? this.selectedResource?._check_in_days ?? null;
        const coDays = this.selectedResource?.check_out_days ?? this.selectedResource?._check_out_days ?? null;
        const dow = new Date(cell.dateStr).getDay();

        if (!this.checkInDate || (this.checkInDate && this.checkOutDate)) {
            // Selecting check-in
            if (ciDays && !ciDays.includes(dow)) {
                this.showToast(t('resource.error_invalid_check_in_day'), 'warn');
                return;
            }
            this.selectCheckIn(cell.dateStr);
        } else {
            // Check-in is set, selecting check-out
            if (cell.dateStr <= this.checkInDate) {
                // Clicked before check-in, reset to this as new check-in
                if (ciDays && !ciDays.includes(dow)) {
                    this.showToast(t('resource.error_invalid_check_in_day'), 'warn');
                    return;
                }
                this.selectCheckIn(cell.dateStr);
            } else {
                if (coDays && !coDays.includes(dow)) {
                    this.showToast(t('resource.error_invalid_check_out_day'), 'warn');
                    return;
                }
                this.selectCheckOut(cell.dateStr);
            }
        }
    },

    // ── Pattern-aware submit handler ──
    activeSubmitHandler() {
        if (config.booking_pattern === 'resource') {
            this.submitResourceBooking();
        } else if (config.booking_pattern === 'capacity') {
            this.submitCapacityBooking();
        } else if (config.booking_pattern === 'event') {
            this.submitEventBooking();
        } else {
            this.submitBooking();
        }
    },

    get activeReviewRows() {
        let rows;
        if (config.booking_pattern === 'resource') rows = this.resourceSummaryRows;
        else if (config.booking_pattern === 'capacity') rows = this.capacitySummaryRows;
        else if (config.booking_pattern === 'event') rows = this.eventSummaryRows;
        else rows = this.summaryRows;

        // Append contact details so customer can catch typos before confirming
        const contact = [];
        if (this.customerName.trim()) {
            contact.push({ label: t('summary.contact_name'), value: this.customerName.trim() });
        }
        if (this.customerEmail.trim()) {
            contact.push({ label: t('summary.contact_email'), value: this.customerEmail.trim() });
        }
        if (this.customerPhone.trim()) {
            contact.push({ label: t('summary.contact_phone'), value: this.customerPhone.trim() });
        }

        return [...rows, ...contact];
    },

    // Pattern-aware back target from details step
    get activeDetailsBackTarget() {
        if (config.booking_pattern === 'resource') return 'guests';
        if (config.booking_pattern === 'capacity') return 'capacity-time';
        if (config.booking_pattern === 'event') return 'event-spots';
        return 'date';
    },

    get activeDetailsBackLabel() {
        if (config.booking_pattern === 'resource') return t('back.change_guests');
        if (config.booking_pattern === 'capacity') return t('back.change_date');
        if (config.booking_pattern === 'event') return t('back.change_spots');
        return t('back.change_date');
    },

    async submitResourceBooking() {


        this.submitting = true;

        const payload = {
            resource_id: this.selectedResource.id,
            check_in: this.checkInDate,
            check_out: this.checkOutDate,
            guest_count: this.guestCount,
            customer: {
                name: this.customerName.trim(),
                email: this.customerEmail.trim(),
                phone: this.customerPhone.trim() || '',
            },
            notes: this.customerNotes.trim() || '',
            custom_fields: Object.keys(this.customFields).length > 0 ? this.customFields : null,
            consent_given: this.consentGiven,
            customer_timezone: this.customerTz,
            __ts: window.__VB_TS__,
            __hp: this.$el.querySelector('[name="__hp"]')?.value || '',
        };

        try {
            const data = await this.api('/bookings', {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            if (data.error) {
                this.submitting = false;
                this.showToast(data.message || t('errors.generic'), 'error');
                return;
            }

            this.booking = data.booking;
            this.bookingIsPending = (data.booking.status === 'pending');
            this.goToStep('confirmed');
            this.$nextTick(() => window.scrollTo({ top: 0, behavior: scrollBehavior() }));
        } catch {
            this.submitting = false;
            this.showToast(t('errors.connection'), 'error');
        }
    },

    // Resource confirmation summary
    get resourceConfirmRows() {
        if (!this.booking) return [];
        const b = this.booking;
        const rows = [];
        if (b.resource) rows.push({ label: t('resource.summary_resource'), value: b.resource });
        if (b.check_in) rows.push({ label: t('resource.check_in_label'), value: this.formatDateDisplay(b.check_in) });
        if (b.check_out) rows.push({ label: t('resource.check_out_label'), value: this.formatDateDisplay(b.check_out) });
        if (b.nights) rows.push({ label: t('resource.nights_label'), value: String(b.nights) });
        if (b.total) rows.push({ label: t('resource.total_label'), value: this.formatPrice(b.total) });
        return rows;
    },

    get activeConfirmRows() {
        if (config.booking_pattern === 'resource') return this.resourceConfirmRows;
        if (config.booking_pattern === 'capacity') return this.capacityConfirmRows;
        if (config.booking_pattern === 'event') return this.eventConfirmRows;
        return this.confirmSummaryRows;
    },

    // Resource date back target
    get resourceDateBackTarget() {
        if (this.resources.length > 1) return 'resource';
        return null;
    },

    // ── Capacity pattern methods ──

    initCapacity() {
        // Set party size bounds from config
        this.minPartySize = config.min_party_size || 1;
        this.maxPartySize = config.max_party_size || 8;
        this.partySize = resolveInitialPartySize(this.minPartySize, this.maxPartySize);
        this.goToStep('party-size');
    },

    selectPartySize(size) {
        this.partySize = size;
    },

    incrementPartySize() {
        if (this.partySize < this.maxPartySize) {
            this.partySize++;
        }
    },

    decrementPartySize() {
        if (this.partySize > this.minPartySize) {
            this.partySize--;
        }
    },

    confirmPartySize() {
        this.loadCapacityDates();
    },

    async loadCapacityDates() {
        // Transition to loading shimmer via orchestrator for proper exit animation.
        // On init this is harmless (loading → loading is a no-op exit).
        this.goToStep('loading');
        try {
            const year = this.capacityYear;
            const month = this.capacityMonth + 1;
            const res = await this.api(`/capacity/available-dates?year=${year}&month=${month}&party_size=${this.partySize}`);
            this.capacityDates = res.dates || [];
            this.selectedDate = null;
            this.goToStep('capacity-date');
        } catch (e) {
            this.showToast(t('errors.connection'), 'error');
            this.goToStep('party-size');
        }
    },

    get capacityCalendarGrid() {
        const year = this.capacityYear;
        const month = this.capacityMonth;
        const firstDay = new Date(year, month, 1);
        const daysInMonth = new Date(year, month + 1, 0).getDate();
        const weekStart = fmt.week_start ?? 0;
        let startDow = (firstDay.getDay() - weekStart + 7) % 7;
        const today = new Date();
        today.setHours(0, 0, 0, 0);

        const cells = [];
        for (let i = 0; i < startDow; i++) cells.push({ day: null, disabled: true });
        for (let d = 1; d <= daysInMonth; d++) {
            const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(d).padStart(2, '0')}`;
            const dateObj = new Date(year, month, d);
            const isPast = dateObj < today;
            const isAvailable = this.capacityDates.includes(dateStr);
            cells.push({
                day: d,
                dateStr,
                disabled: isPast || !isAvailable,
                isToday: dateObj.getTime() === today.getTime(),
                isSelected: this.selectedDate === dateStr,
            });
        }
        return cells;
    },

    prevCapacityMonth() {
        if (this.capacityMonth === 0) {
            this.capacityMonth = 11;
            this.capacityYear--;
        } else {
            this.capacityMonth--;
        }
        this.loadCapacityDates();
    },

    nextCapacityMonth() {
        if (this.capacityMonth === 11) {
            this.capacityMonth = 0;
            this.capacityYear++;
        } else {
            this.capacityMonth++;
        }
        this.loadCapacityDates();
    },

    get capacityMonthLabel() {
        const label = new Date(this.capacityYear, this.capacityMonth).toLocaleDateString(fmt.intl_locale || config.locale || 'en', { month: 'long', year: 'numeric' });
        return label.charAt(0).toUpperCase() + label.slice(1);
    },

    selectCapacityDate(cell) {
        if (cell.disabled || !cell.day) return;
        this.selectedDate = cell.dateStr;
        this.loadCapacitySlots();
    },

    async loadCapacitySlots() {
        this.goToStep('loading');
        try {
            const res = await this.api(`/capacity/slots?date=${this.selectedDate}&party_size=${this.partySize}`);
            this.capacitySlots = res.slots || [];
            this.selectedCapacitySlot = null;
            this.goToStep('capacity-time');
        } catch (e) {
            this.showToast(t('errors.connection'), 'error');
            this.goToStep('capacity-date');
        }
    },

    selectCapacitySlot(slot) {
        this.selectedCapacitySlot = slot;
    },

    confirmCapacitySlot() {
        if (!this.selectedCapacitySlot) return;
        this.goToStep('details');
    },

    get capacitySlotLabel() {
        if (!this.selectedCapacitySlot || !this.selectedDate) return '';
        const slot = this.selectedCapacitySlot;
        const time = this.formatCapacitySlotTime(slot.time) + ' – ' + this.formatCapacitySlotTime(slot.end_time);
        return this.formatDateDisplay(this.selectedDate) + '  ·  ' + time;
    },

    formatCapacitySlotTime(time) {
        return formatSlotDisplay(time, this.selectedDate, this.tenantTz, this.customerTz);
    },

    get capacitySummaryRows() {
        const rows = [];
        if (this.selectedCapacitySlot) {
            if (this.selectedCapacitySlot.label) {
                rows.push({ label: t('summary.service_label'), value: this.selectedCapacitySlot.label });
            }
            rows.push({ label: t('summary.date_label'), value: this.formatDateDisplay(this.selectedDate) });
            const displayStart = formatSlotDisplay(this.selectedCapacitySlot.time, this.selectedDate, this.tenantTz, this.customerTz);
            const displayEnd   = formatSlotDisplay(this.selectedCapacitySlot.end_time, this.selectedDate, this.tenantTz, this.customerTz);
            rows.push({ label: t('summary.time_label'), value: `${displayStart} – ${displayEnd}` });
            rows.push({ label: t('capacity.party_size_label'), value: `${this.partySize} ${this.partySize === 1 ? t('capacity.guest') : t('capacity.guests')}` });
        }
        return rows;
    },

    get capacityConfirmRows() {
        if (!this.booking) return [];
        const b = this.booking;
        const rows = [];
        if (b.label) rows.push({ label: t('summary.service_label'), value: b.label });
        if (b.date) rows.push({ label: t('summary.date_label'), value: this.formatDateDisplay(b.date) });
        if (b.time) {
            const displayStart = formatSlotDisplay(b.time, b.date, this.tenantTz, this.customerTz);
            const displayEnd   = formatSlotDisplay(b.end_time, b.date, this.tenantTz, this.customerTz);
            rows.push({ label: t('summary.time_label'), value: `${displayStart} – ${displayEnd}` });
        }
        rows.push({ label: t('capacity.party_size_label'), value: `${b.party_size} ${b.party_size === 1 ? t('capacity.guest') : t('capacity.guests')}` });
        return rows;
    },

    async submitCapacityBooking() {

        if (this.submitting) return;
        this.submitting = true;

        const payload = {
            slot_id: this.selectedCapacitySlot.id,
            date: this.selectedDate,
            party_size: this.partySize,
            customer: {
                name: this.customerName.trim(),
                email: this.customerEmail.trim(),
                phone: this.customerPhone.trim() || '',
            },
            notes: this.customerNotes.trim() || '',
            custom_fields: Object.keys(this.customFields).length > 0 ? this.customFields : null,
            consent_given: this.consentGiven,
            customer_timezone: this.customerTz,
            __ts: window.__VB_TS__,
            __hp: this.$el.querySelector('[name="__hp"]')?.value || '',
        };

        try {
            const data = await this.api('/bookings', {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            if (data.error) {
                this.submitting = false;
                this.showToast(data.message || t('errors.generic'), 'error');
                return;
            }

            this.booking = data.booking;
            this.bookingIsPending = (data.booking.status === 'pending');
            this.goToStep('confirmed');
            this.$nextTick(() => window.scrollTo({ top: 0, behavior: scrollBehavior() }));
        } catch {
            this.submitting = false;
            this.showToast(t('errors.connection'), 'error');
        }
    },

    // ── Event pattern methods ──

    async loadEvents() {
        this.goToStep('loading');
        try {
            const data = await this.api('/events');
            this.eventList = data.events || [];
            if (this.eventList.length === 0) {
                this.step = 'empty'; // Terminal state, no prior visible step
            } else {
                this.goToStep('event-list');
            }
        } catch {
            this.showToast(t('errors.connection'), 'error');
            this.step = 'empty'; // Terminal state
        }
    },

    selectEvent(event) {
        this.selectedEvent = event;
        // Initialize spot count to event's minimum per booking
        this.eventSpotCount = event.min_spot_count || 1;
        this.goToStep('event-detail');
    },

    confirmEventDetail() {
        this.goToStep('event-spots');
    },

    // Effective max spots for the counter (per-booking cap vs remaining capacity)
    get eventMaxSpots() {
        if (!this.selectedEvent) return 1;
        const remaining = this.selectedEvent.remaining || 0;
        const perBookingMax = this.selectedEvent.max_spot_count;
        if (perBookingMax !== null && perBookingMax !== undefined) {
            return Math.min(perBookingMax, remaining);
        }
        return remaining;
    },

    // Effective min spots for the counter
    get eventMinSpots() {
        if (!this.selectedEvent) return 1;
        return this.selectedEvent.min_spot_count || 1;
    },

    incrementEventSpots() {
        if (this.eventSpotCount < this.eventMaxSpots) {
            this.eventSpotCount++;
        }
    },

    decrementEventSpots() {
        if (this.eventSpotCount > this.eventMinSpots) {
            this.eventSpotCount--;
        }
    },

    confirmEventSpots() {
        this.goToStep('details');
    },

    formatEventDate(dateStr) {
        try {
            const d = new Date(dateStr);
            const label = d.toLocaleDateString(fmt.intl_locale || config.locale || 'en', { weekday: 'short', month: 'short', day: 'numeric' });
            return label.charAt(0).toUpperCase() + label.slice(1);
        } catch {
            return dateStr;
        }
    },

    formatEventTime(dateStr) {
        try {
            const d = new Date(dateStr);
            return d.toLocaleTimeString(config.locale || 'en', { hour: '2-digit', minute: '2-digit' });
        } catch {
            return '';
        }
    },

    formatEventPrice(price) {
        if (!price || parseFloat(price) === 0) return t('event.free');
        return this.formatPrice(price);
    },

    get eventSummaryRows() {
        const rows = [];
        if (this.selectedEvent) {
            rows.push({ label: t('summary.service_label'), value: this.selectedEvent.name });
            rows.push({ label: t('event.date_label'), value: this.formatEventDate(this.selectedEvent.start_datetime) });
            rows.push({
                label: t('event.time_label'),
                value: `${this.formatEventTime(this.selectedEvent.start_datetime)} – ${this.formatEventTime(this.selectedEvent.end_datetime)}`
            });
            if (this.selectedEvent.location) {
                rows.push({ label: t('event.location_label'), value: this.selectedEvent.location });
            }
            rows.push({
                label: t('event.spots_title'),
                value: `${this.eventSpotCount} ${this.eventSpotCount === 1 ? t('event.spot') : t('event.spots')}`
            });
            if (this.selectedEvent.price && parseFloat(this.selectedEvent.price) > 0) {
                rows.push({ label: t('event.price_label'), value: this.formatPrice(this.selectedEvent.price) });
            }
        }
        return rows;
    },

    get eventConfirmRows() {
        if (!this.booking) return [];
        const b = this.booking;
        const rows = [];
        if (b.event_name) rows.push({ label: t('summary.service_label'), value: b.event_name });
        if (b.date) rows.push({ label: t('event.date_label'), value: this.formatDateDisplay(b.date) });
        if (b.time) {
            rows.push({ label: t('event.time_label'), value: `${b.time} – ${b.end_time}` });
        }
        rows.push({
            label: t('event.spots_title'),
            value: `${b.spot_count} ${b.spot_count === 1 ? t('event.spot') : t('event.spots')}`
        });
        if (b.waitlisted) {
            rows.push({ label: t('event.waitlist_badge'), value: t('event.waitlisted_message') });
        }
        return rows;
    },

    async submitEventBooking() {

        if (this.submitting) return;
        this.submitting = true;

        // Determine event date for the API
        const eventDate = this.selectedEvent.date
            || this.selectedEvent.start_datetime.substring(0, 10);

        const payload = {
            event_id: this.selectedEvent.id,
            date: eventDate,
            spot_count: this.eventSpotCount,
            customer: {
                name: this.customerName.trim(),
                email: this.customerEmail.trim(),
                phone: this.customerPhone.trim() || '',
            },
            notes: this.customerNotes.trim() || '',
            custom_fields: Object.keys(this.customFields).length > 0 ? this.customFields : null,
            consent_given: this.consentGiven,
            customer_timezone: this.customerTz,
            __ts: window.__VB_TS__,
            __hp: this.$el.querySelector('[name="__hp"]')?.value || '',
        };

        try {
            const data = await this.api('/bookings', {
                method: 'POST',
                body: JSON.stringify(payload),
            });

            if (data.error) {
                this.submitting = false;
                this.showToast(data.message || t('errors.generic'), 'error');
                return;
            }

            this.booking = data.booking;
            this.eventIsWaitlisted = data.booking.waitlisted || false;
            this.bookingIsPending = (!data.booking.waitlisted && data.booking.status === 'pending');
            this.goToStep('confirmed');
            this.$nextTick(() => window.scrollTo({ top: 0, behavior: scrollBehavior() }));
        } catch {
            this.submitting = false;
            this.showToast(t('errors.connection'), 'error');
        }
    },

    // ── Translation passthrough for templates ──
    t,
    esc,

    // ── Keyboard navigation passthroughs ──
    radiogroupKeydown,
    radiogroupKeydownFocusOnly,
    calendarGridKeydown,
}));


// ── Alpine: start ──
window.Alpine = Alpine;
Alpine.start();

// ── Lucide: initial render ──
createIcons({ icons: ICON_SET });

// ── Refresh helper for dynamically rendered content ──
window.refreshIcons = () => createIcons({ icons: ICON_SET });
