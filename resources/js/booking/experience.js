import { slotDisplayDatetime } from './timezone.js';
import { timezoneOffsetName } from './timezone.js';
import { calendarGridKeydown as navigateCalendar, validateBookingDetails } from './interactions.js';
import { bookingPresentation } from './presentation.js';

const READ_ACTIONS = [
    'loadServices', 'loadStaff', 'loadDates', 'selectDate', 'loadResources',
    'loadResourceDates', 'checkResourceAvailability', 'loadCapacityDates', 'loadCapacitySlots',
    'loadEvents', 'loadRescheduleDates', 'selectRescheduleDate', 'loadRescheduleResourceDates',
    'loadRescheduleCapacityDates', 'selectRescheduleCapacityDate', 'loadRescheduleEvents', 'confirmRescheduleResource', 'loadManagedBooking',
];
const CALENDAR_ACTIONS = new Set(['loadDates', 'loadResourceDates', 'loadCapacityDates', 'loadRescheduleDates', 'loadRescheduleResourceDates', 'loadRescheduleCapacityDates', 'loadRescheduleEvents']);

// All pattern loaders share the same recovery contract. Submission handlers keep
// ownership of domain errors such as a slot conflict or a validation response.
export function enhanceBookingWizard(wizard) {
    const experience = createBookingExperience(wizard.experienceConfig);
    delete wizard.experienceConfig;
    // Copy descriptors so reactive getters run against the completed wizard,
    // rather than being evaluated while composing its pieces.
    Object.defineProperties(wizard, Object.getOwnPropertyDescriptors(experience));
    Object.defineProperties(wizard, Object.getOwnPropertyDescriptors(bookingPresentation({ config: wizard.config, t: wizard.t })));
    wizard._readRequests = {};
    for (const name of READ_ACTIONS) {
        const action = wizard[name];
        if (!action) continue;
        wizard[name] = async function (...args) {
            const request = (this._readRequests[name] || 0) + 1;
            this._readRequests[name] = request;
            const recovering = !!this.loadError;
            const calendarRequest = CALENDAR_ACTIONS.has(name) ? ++this._calendarRead : null;
            if (calendarRequest !== null) this.calendarLoading = true;
            let context;
            try {
                this.loadError = '';
                const promise = action.apply(this, args);
                context = this.requestContext(name);
                const result = await promise;
                if (recovering && request === this._readRequests[name] && context === this.requestContext(name) && !this.loadError) this.goToStep(this.step, { forceFocus: true });
                return result;
            } catch (error) {
                if (request !== this._readRequests[name] || context !== this.requestContext(name)) return;
                if (error.name === 'AbortError' && this._destroyed) return; // i18n-ignore: DOM exception identifier
                this.retryAction = name;
                this.retryArgs = args;
                this.loadError = error.status === 429 ? this.t('errors.rate_limited') : error.status === 404 && this.manageMode ? this.t('manage.not_found') : this.t('errors.connection');
                this.slotsLoading = false;
                this.calendarFading = false;
                this.rescheduleCalendarFading = false;
                if (this.step === 'loading') this.goToStep('load-error');
                this.announcement = this.loadError;
            } finally {
                if (calendarRequest !== null && calendarRequest === this._calendarRead) this.calendarLoading = false;
            }
        };
    }
    return wizard;
}

export function createBookingExperience({ config, fmt, t, refreshIcons }) {
    return {
        loadError: '',
        retryAction: '',
        retryArgs: [],
        slotsLoading: false,
        calendarLoading: false,
        _calendarRead: 0,
        announcement: '',
        keyboardNavigation: false,
        timezoneWasEdited: false,
        demoNoticeVisible: true,
        rootElement: null,
        _slotRequest: 0,
        _dateRequest: 0,
        _staffRequest: 0,
        _resourceRequest: 0,
        _capacityRequest: 0,
        _rescheduleRequest: 0,
        _navigationId: 0,

        requestContext(name) {
            return this._navigationId + '|' + this.selectionContext(name);
        },
        selectionContext(name) {
            if (name === 'loadServices' || name === 'loadResources' || name === 'loadEvents') return config.slug;
            if (name === 'loadStaff') return this.selectedService?.id;
            if (name === 'loadDates') return [this.selectedService?.id, this.selectedStaff?.id, this.currentYear, this.currentMonth].join('|');
            if (name === 'selectDate') return [this.selectedService?.id, this.selectedStaff?.id, this.selectedDate].join('|');
            if (name === 'loadResourceDates') return [this.selectedResource?.id, this.checkInYear, this.checkInMonth].join('|');
            if (name === 'checkResourceAvailability') return [this.selectedResource?.id, this.checkInDate, this.checkOutDate, this.guestCount].join('|');
            if (name === 'loadCapacityDates') return [this.partySize, this.capacityYear, this.capacityMonth].join('|');
            if (name === 'loadCapacitySlots') return [this.partySize, this.selectedDate].join('|');
            return [this.manageBookingId, this.rescheduleYear, this.rescheduleMonth, this.rescheduleDate, this.rescheduleCheckIn, this.rescheduleCheckOut, this.step.startsWith('reschedule')].join('|');
        },
        readGuard(name) {
            const request = this._readRequests[name], context = this.requestContext(name);
            return () => !this._destroyed && request === this._readRequests[name] && context === this.requestContext(name);
        },

        get isLoadError() { return this.step === 'load-error'; },
        get hasLoadError() { return !!this.loadError; },
        get hasNoSlots() { return !this.slotsLoading && this.availableSlots.length === 0 && !!this.selectedDate && !this.loadError; },
        get hasNoRescheduleEvents() { return !this.calendarLoading && !this.loadError && this.rescheduleEvents.length === 0; },
        rescheduleEventTabindex(event) { return event.id === (this.rescheduleSelectedEvent?.id || this.rescheduleEvents[0]?.id) ? 0 : -1; },
        get displayTimezone() {
            return this.selectedService?.is_virtual || this.managedBooking?.is_virtual ? this.customerTz : this.tenantTz;
        },
        get timezoneReference() {
            const replacement = this.step.startsWith('reschedule') && this.rescheduleDate
                ? { date: this.rescheduleDate, ...(this.rescheduleSlot || this.rescheduleCapacitySlot) } : null;
            const saved = this.step === 'confirmed' ? this.booking : this.step === 'reschedule-confirmed' ? this.rescheduleNewBooking : replacement || (this.manageMode ? this.managedBooking : null);
            const date = saved?.date || this.selectedEvent?.start_datetime?.slice(0, 10) || this.selectedDate || this.checkInDate;
            return {
                date: date || this.tenantToday,
                time: saved?.time || this.selectedEvent?.start_datetime?.slice(11, 16) || this.selectedSlot?.time || this.selectedCapacitySlot?.time || '12:00',
                utc: saved?.start_utc || this.selectedEvent?.start_utc || this.selectedSlot?.start_utc || this.selectedCapacitySlot?.start_utc || (!date ? new Date().toISOString() : null),
            };
        },
        get hasTimezoneDifference() {
            if (this.customerTz === this.tenantTz) return false;
            const ref = this.timezoneReference;
            return timezoneOffsetName(ref.date, ref.time, this.tenantTz, this.customerTz, ref.utc) !== timezoneOffsetName(ref.date, ref.time, this.tenantTz, this.tenantTz, ref.utc);
        },
        get showTimezoneSelector() {
            return this.showProgress && config.booking_pattern === 'timeslot' && !!this.selectedService?.is_virtual && (this.hasTimezoneDifference || this.timezoneWasEdited);
        },
        get showVenueTimezone() { return !this.showTimezoneSelector && this.showProgress && this.hasTimezoneDifference; },
        get isTzMismatch() { return this.showTimezoneSelector && this.hasTimezoneDifference; },
        get venueTimeLabel() { return t('timezone.venue_time', { timezone: this.tzDisplayLabel(this.tenantTz) }); },
        get isDisplayTimezoneDifferent() { return this.displayTimezone !== this.tenantTz && this.hasTimezoneDifference; },
        get selectedTimesHeading() {
            if (!this.selectedDate) return t('availability.choose_date');
            return this.isDisplayTimezoneDifferent ? t('timezone.business_date', { date: this.formatDateDisplay(this.selectedDate) }) : this.formatDateDisplay(this.selectedDate);
        },
        get calendarTimezoneLabel() { return t('timezone.calendar_dates', { timezone: this.tzDisplayLabel(this.tenantTz) }); },
        get venueTimeOrCustomerLabel() { return this.showTimezoneSelector ? t('timezone.notice', { tz: this.tzDisplayLabel(this.displayTimezone) }) : this.venueTimeLabel; },
        get tenantToday() {
            const parts = new Intl.DateTimeFormat('en', { timeZone: this.tenantTz, year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(new Date());
            const part = type => parts.find(p => p.type === type).value;
            return `${part('year')}-${part('month')}-${part('day')}`;
        },
        rescheduleCapacityTimeLabel(slot) {
            return [slot.label, this.formatDisplayTime(slot.time, this.rescheduleDate, slot.start_utc) + ' – ' + this.formatDisplayTime(slot.end_time, this.rescheduleDate, slot.end_utc)].filter(Boolean).join(' · ');
        },
        get resourceStayHint() {
            const min = Number(this.selectedResource?.min_stay_nights) || 1;
            const max = Number(this.selectedResource?.max_stay_nights);
            return max ? t('resource.stay_hint_range', { min, max }) : t('resource.stay_hint_min', { min });
        },
        get rescheduleStayTotal() {
            const stay = this.rescheduleResourceAvailability;
            return stay ? t('resource.stay_total', { nights: stay.nights, total: this.formatPrice(stay.total) }) : '';
        },
        get phaseSteps() {
            const choice = config.booking_pattern === 'timeslot'
                ? this.services.length > 1 || this.staff.length > 1
                : config.booking_pattern === 'resource' ? this.resources.length > 1 : true;
            const phases = [];
            if (choice) phases.push({ id: 'choose', label: t(config.booking_pattern === 'event' ? 'progress.event' : 'progress.choose') });
            if (config.booking_pattern !== 'event') phases.push({ id: 'when', label: t('progress.when') });
            phases.push({ id: 'details', label: t('progress.details') }, { id: 'review', label: t('progress.review') });
            return phases;
        },
        get activePhase() {
            if (['details', 'review'].includes(this.step)) return this.step;
            if (['service', 'staff', 'resource', 'party-size', 'event-list', 'event-detail', 'event-spots'].includes(this.step)) return 'choose';
            return 'when';
        },
        phaseCurrent(phase) { return phase.id === this.activePhase ? 'step' : null; },
        phaseComplete(phase) {
            return this.phaseSteps.findIndex(p => p.id === phase.id) < this.phaseSteps.findIndex(p => p.id === this.activePhase);
        },
        get showProgress() {
            return !this.manageMode && !['loading', 'confirmed', 'unsupported', 'empty', 'load-error'].includes(this.step);
        },

        setupExperience() {
            this.rootElement = this.$el;
            this._onKeyboard = () => { this.keyboardNavigation = true; };
            this._onPointer = () => { this.keyboardNavigation = false; };
            this.rootElement.addEventListener('keydown', this._onKeyboard, true);
            this.rootElement.addEventListener('pointerdown', this._onPointer, true);
        },
        destroy() {
            this._destroyed = true;
            this._slotRequest++;
            this._dateRequest++;
            if (this.toastTimer) clearTimeout(this.toastTimer);
            this.rootElement?.removeEventListener('keydown', this._onKeyboard, true);
            this.rootElement?.removeEventListener('pointerdown', this._onPointer, true);
        },
        retryLoad() {
            if (!this.retryAction || typeof this[this.retryAction] !== 'function') return;
            const action = this.retryAction, args = this.retryArgs;
            this.loadError = '';
            if (this.isLoadError) this.step = 'loading';
            return this[action](...args);
        },
        goToStep(name, { forceFocus = false } = {}) {
            if (this.submitting && name !== 'confirmed') return;
            if (this.rescheduleSubmitting && name !== 'reschedule-confirmed') return;
            if (this.step === name && !forceFocus) return;
            if (this.step !== name) this._navigationId++;
            this.clearExitStates();
            if (this.toast?.type === 'warn') this.dismissToast();
            this.step = name;
            const orient = () => {
                if (this._destroyed || this.step !== name) return;
                refreshIcons();
                const root = this.rootElement || this.$el;
                const step = root.querySelector('[data-book-step="' + name + '"]') || Array.from(root.querySelectorAll('.vb-book-step'))
                    .find(el => el.style.display !== 'none' && !el.hidden);
                const heading = step?.querySelector('h2, h3, .vb-book-step-title, .vb-book-confirm-heading');
                if (heading) {
                    heading.setAttribute('tabindex', '-1');
                    heading.focus({ preventScroll: true });
                    const rect = heading.getBoundingClientRect();
                    if (rect.top < 0 || rect.bottom > window.innerHeight) {
                        heading.scrollIntoView({ block: 'start', behavior: this.keyboardNavigation || window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth' });
                    }
                    this.announcement = heading.textContent;
                }
                if (name === 'confirmed' && window.__VB_EMBED__ && window.parent !== window) {
                    window.parent.postMessage({ type: 'booking:confirmed' }, '*');
                }
            };
            this.$nextTick(() => {
                if (typeof window.requestAnimationFrame === 'function') window.requestAnimationFrame(orient);
                else orient();
            });
        },

        clearFieldError(field) {
            const { [field]: ignored, ...remaining } = this.formErrors;
            this.formErrors = remaining;
        },
        setCustomerName(value) { this.customerName = value; this.clearFieldError('name'); },
        setCustomerEmail(value) { this.customerEmail = value; this.clearFieldError('email'); },
        setCustomerPhone(value) { this.customerPhone = value; this.clearFieldError('phone'); },
        setConsentGiven(value) { this.consentGiven = value; this.clearFieldError('consent'); },
        errorMessage(field) {
            const message = this.formErrors[field];
            return message ? (message.startsWith('errors.') ? t(message) : message) : '';
        },
        fieldName(field) { return field.name || field.key; },
        customFieldId(field) { return 'vb-cf-' + this.fieldName(field); },
        customErrorKey(field) { return 'custom.' + this.fieldName(field); },
        customErrorId(field) { return this.customFieldId(field) + '-error'; },
        customErrorMessage(field) { return this.errorMessage(this.customErrorKey(field)); },
        customHasError(field) { return this.hasError(this.customErrorKey(field)); },
        updateCustomField(event) {
            const el = event.target;
            const name = el.dataset.bookCustom;
            if (!name) return;
            this.customFields[name] = el.type === 'checkbox' ? el.checked : el.value;
            this.clearFieldError('custom.' + name);
        },
        customValue(field) { return this.customFields[this.fieldName(field)] ?? ''; },
        get customFieldRows() {
            return (config.custom_fields || []).flatMap(field => {
                const value = this.customValue(field);
                if (field.type === 'checkbox') return value ? [{ label: field.label, value: t('form.checked_value') }] : [];
                return String(value).trim() ? [{ label: field.label, value: String(value).trim() }] : [];
            });
        },
        customOptionValue(option) { return typeof option === 'object' ? option.value : option; },
        customOptionLabel(option) { return typeof option === 'object' ? option.label : option; },
        customOptionSelected(field, option) { return String(this.customValue(field)) === String(this.customOptionValue(option)); },
        customInputType(field) { return ['email', 'tel', 'number', 'date'].includes(field.type) ? field.type : 'text'; },
        isSelect(field) { return field.type === 'select'; },
        isCheckbox(field) { return field.type === 'checkbox'; },
        isPlainInput(field) { return !['textarea', 'select', 'checkbox'].includes(field.type); },
        collectCustomFields() {
            (this.rootElement || this.$el).querySelectorAll('[data-book-custom]').forEach(el => {
                this.customFields[el.dataset.bookCustom] = el.type === 'checkbox' ? el.checked : el.value.trim();
            });
        },
        focusFirstError() {
            this.$nextTick(() => {
                const field = Object.keys(this.formErrors)[0];
                if (!field) return;
                const id = field.startsWith('custom.') ? 'vb-cf-' + field.slice(7) : 'vb-' + field;
                const el = (this.rootElement || this.$el).querySelector('[id="' + id + '"]');
                el?.focus();
                el?.scrollIntoView({ block: 'nearest', behavior: 'auto' });
            });
        },
        submitDetails() {
            this.collectCustomFields();
            this.formErrors = validateBookingDetails({
                name: this.customerName, email: this.customerEmail, phone: this.customerPhone,
                consent: this.consentGiven, customFields: this.customFields,
            }, config);
            if (Object.keys(this.formErrors).length) {
                this.announcement = t('errors.correct_fields');
                this.focusFirstError();
                return;
            }
            this.goToStep('review');
        },
        applyServerErrors(data) {
            if (!data.errors || typeof data.errors !== 'object') return false;
            const errors = {};
            for (const [key, value] of Object.entries(data.errors)) {
                const field = key.replace(/^customer\./, '').replace(/^custom_fields\./, 'custom.');
                errors[field] = Array.isArray(value) ? value.join(' ') : String(value);
            }
            this.formErrors = errors;
            this.goToStep('details');
            this.focusFirstError();
            return true;
        },

        openCancellation() {
            if (this.manageCancelling) return;
            const root = this.rootElement || this.$el;
            this._cancelReturnFocus = root.ownerDocument.activeElement;
            this.manageCancelModalOpen = true;
            this.$nextTick(() => {
                this._cancelInert = Array.from(root.querySelectorAll('.vb-book-header, .vb-book-tz-bar, .vb-book-footer, .vb-book-theme-toggle, main > .vb-book-step'))
                    .map(el => ({ el, inert: el.inert }));
                this._cancelInert.forEach(({ el }) => { el.inert = true; });
                root.querySelector('[data-cancel-keep]')?.focus();
            });
        },
        closeCancellation() {
            if (this.manageCancelling) return;
            this.manageCancelModalOpen = false;
            (this._cancelInert || []).forEach(({ el, inert }) => { el.inert = inert; });
            this._cancelInert = [];
            this._cancelReturnFocus?.focus();
        },
        cancelDialogKeydown(event) {
            if (event.key === 'Escape') { event.preventDefault(); this.closeCancellation(); return; }
            if (event.key !== 'Tab') return;
            const controls = [...event.currentTarget.querySelectorAll('button:not(:disabled), textarea')];
            const first = controls[0], last = controls[controls.length - 1];
            if (event.shiftKey && event.target === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && event.target === last) { event.preventDefault(); first?.focus(); }
        },

        async api(path, options = {}) {
            const controller = new AbortController();
            // A read can be retried. A timed-out write may already have committed.
            const timeout = options.method === 'POST' ? null : setTimeout(() => controller.abort(), 15000);
            try {
                const response = await fetch(`/api/${config.slug}${path}`, {
                    ...options,
                    signal: options.signal || controller.signal,
                    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', // i18n-ignore: HTTP protocol headers
                        'X-CSRF-Token': window.__VB_CSRF__ || '', ...options.headers },
                });
                const data = await response.json();
                if (!response.ok && options.method !== 'POST') {
                    const error = new Error(data.message || t('errors.connection'));
                    error.status = response.status;
                    throw error;
                }
                return data;
            } finally { clearTimeout(timeout); }
        },
        showToast(message, type = 'error') {
            if (this.toastTimer) clearTimeout(this.toastTimer);
            this.toast = { message, type };
            if (type === 'success' || type === 'info') this.toastTimer = setTimeout(() => { this.toast = null; }, 5000);
        },

        calendarGridKeydown(event) {
            const grid = event.currentTarget;
            navigateCalendar(event, { onMonthChange: async date => {
                const scope = grid.dataset.calendar || 'timeslot';
                const [year, month] = date.split('-').map(Number);
                if (scope === 'resource') {
                    this.checkInYear = year; this.checkInMonth = month - 1;
                    await this.loadResourceDates();
                } else if (scope === 'capacity') {
                    this.capacityYear = year; this.capacityMonth = month - 1;
                    await this.loadCapacityDates();
                } else if (scope.startsWith('reschedule')) {
                    this.rescheduleYear = year; this.rescheduleMonth = month - 1;
                    if (scope === 'reschedule-resource') await this.loadRescheduleResourceDates();
                    else if (scope === 'reschedule-capacity') await this.loadRescheduleCapacityDates();
                    else await this.loadRescheduleDates();
                } else {
                    this.currentYear = year; this.currentMonth = month - 1;
                    await this.loadDates();
                }
                this.$nextTick(() => {
                    const target = grid.querySelector('[data-date="' + date + '"]');
                    if (target) {
                        grid.querySelectorAll('[data-date]').forEach(el => { el.tabIndex = -1; });
                        target.tabIndex = 0; target.focus();
                    }
                });
            } });
        },
        calendarWeeks(cells) {
            const padded = [...cells];
            while (padded.length % 7) padded.push({ day: '', dateStr: '', disabled: true });
            return Array.from({ length: padded.length / 7 }, (_, index) => padded.slice(index * 7, index * 7 + 7));
        },
        calendarHasAvailability(cells) { return cells.some(cell => cell.day && !cell.disabled && !cell.dayRestricted); },
        canResourceCheckout(checkIn, checkOut, dates, year, month, min = 1, max = Infinity) {
            const nights = (Date.parse(checkOut + 'T00:00:00Z') - Date.parse(checkIn + 'T00:00:00Z')) / 86400000;
            if (!Number.isFinite(nights) || nights < min || nights > max) return false;
            // Only the displayed month's nights are known. The range endpoint
            // checks the complete stay, including months not currently displayed.
            const prefix = `${year}-${String(month + 1).padStart(2, '0')}`;
            let night = checkIn > prefix + '-01' ? checkIn : prefix + '-01';
            while (night < checkOut && night.startsWith(prefix)) {
                if (!dates.includes(night)) return false;
                night = new Date(Date.parse(night + 'T00:00:00Z') + 86400000).toISOString().slice(0, 10);
            }
            return true;
        },
        calendarWeekdayLabel(index) { return t('days.' + ((index + (fmt.week_start ?? 0)) % 7)); },
        calendarCellClasses(cell) {
            return {
                'is-disabled': cell.disabled || cell.dayRestricted,
                'is-today': cell.today || cell.isToday,
                'has-slots': !cell.disabled && !!cell.day,
                'is-selected': cell.selected || cell.isSelected,
                'is-range': cell.inRange,
                'is-check-in': cell.isCheckIn && !!this.checkOutDate,
                'is-check-out': cell.isCheckOut && !!this.checkInDate,
                'is-blank': !cell.day,
            };
        },
        calendarToday(cell) { return cell.today || cell.isToday ? 'date' : null; },
        slotTabindex(slot) { return slot.time === (this.selectedSlot?.time || this.availableSlots[0]?.time) ? 0 : -1; },
        calendarDateLabel(cell) {
            return cell.dateStr ? this.formatDateDisplay(cell.dateStr) + (cell.disabled || cell.dayRestricted ? ', ' + t('calendar.unavailable') : '') : '';
        },
        calendarCellTabindex(cell, cells) {
            if (!cell.day) return -1;
            const target = cells.find(c => c.selected || c.isSelected) || cells.find(c => c.today || c.isToday) || cells.find(c => c.day && !c.disabled) || cells.find(c => c.day);
            return target?.dateStr === cell.dateStr ? 0 : -1;
        },
        formatDisplayTime(time, date, utcInstant = null) {
            if (!time || !date) return '';
            const display = slotDisplayDatetime(time, date, this.tenantTz, this.displayTimezone, utcInstant);
            return this.formatClock(display.time);
        },
        formatClock(time) {
            if (!time) return '';
            const hour12 = /12|[aA]/.test(fmt.time_format || '');
            return new Intl.DateTimeFormat(fmt.intl_locale || config.locale || 'en', {
                hour: hour12 ? 'numeric' : '2-digit', minute: '2-digit', hour12, timeZone: 'UTC',
            }).format(new Date('2000-01-01T' + time.slice(0, 5) + ':00Z'));
        },
        calendarUtc(value) {
            if (typeof value !== 'string' || !/T.*Z$/.test(value) || !Number.isFinite(Date.parse(value))) return '';
            return new Date(value).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}Z$/, 'Z');
        },
        displayDate(date, time, utcInstant = null) {
            return date && time ? slotDisplayDatetime(time, date, this.tenantTz, this.displayTimezone, utcInstant).date : date;
        },
        formatFinderDatetime(date, time, utcInstant = null) { return slotDisplayDatetime(time, date, this.tenantTz, this.displayTimezone, utcInstant); },
        selectedDisplayDate() {
            const slot = this.selectedSlot || this.selectedCapacitySlot;
            return this.displayDate(this.selectedDate, slot?.time, slot?.start_utc);
        },
        slotDateNote(slot) {
            const date = this.displayDate(this.selectedDate, slot.time, slot.start_utc);
            return date !== this.selectedDate ? this.formatShortDate(date) : '';
        },
        rescheduleSlotDateNote(slot) {
            const date = this.displayDate(this.rescheduleDate, slot.time, slot.start_utc);
            return date !== this.rescheduleDate ? this.formatShortDate(date) : '';
        },
        formatShortDate(date) {
            return new Intl.DateTimeFormat(fmt.intl_locale || config.locale || 'en', { timeZone: 'UTC', weekday: 'short', month: 'short', day: 'numeric' }).format(new Date(date + 'T12:00:00Z'));
        },
        dismissDemoNotice() {
            this.demoNoticeVisible = false;
            this.goToStep(this.step, { forceFocus: true });
        },
    };
}
