// @vitest-environment jsdom
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';

const registration = vi.hoisted(() => ({ factory: null }));
vi.mock('@alpinejs/csp', () => ({ default: {
    data: (name, factory) => { if (name === 'bookingWizard') registration.factory = factory; },
    start: () => {},
} }));
vi.mock('lucide', async (original) => ({ ...await original(), createIcons: () => {} }));

let wizard;
const deferred = () => {
    let resolve;
    const promise = new Promise(r => { resolve = r; });
    return { promise, resolve };
};

beforeEach(async () => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-10-20T10:00:00Z'));
    vi.resetModules();
    window.matchMedia = vi.fn(query => ({ matches: query.includes('reduced-motion'), addEventListener() {} }));
    window.scrollTo = vi.fn();
    HTMLElement.prototype.scrollIntoView = vi.fn();
    window.__VB_CONFIG__ = {
        slug: 'test', name: 'Studio', booking_pattern: 'timeslot', timezone: 'Europe/Amsterdam',
        custom_fields: [], requires_consent: false, require_phone: false,
    };
    window.__VB_I18N__ = {
        'summary.date_label': 'Date', 'summary.time_label': 'Time',
        'errors.connection': 'Connection lost', 'errors.required_name': 'Enter your name',
        'errors.invalid_email': 'Enter a valid email', 'errors.required_field': 'Complete this field',
    };
    window.__VB_FMT__ = { intl_locale: 'en-GB', week_start: 1, time_format: 'H:i' };
    document.body.innerHTML = '<div id="wizard"><div class="vb-book-step"><h2 tabindex="-1">Your details</h2><form><input id="vb-name"><input id="vb-email"><button>Review</button></form></div></div>';
    await import('../../resources/js/booking/app.js');
    wizard = registration.factory();
    wizard.$el = document.getElementById('wizard');
    wizard.$refs = {};
    wizard.$nextTick = cb => cb();
    wizard.customerTz = 'Europe/Amsterdam';
});

afterEach(() => { wizard.destroy(); vi.useRealTimers(); vi.restoreAllMocks(); });

describe('booking details recovery', () => {
    it('provides a named correction and focuses the first invalid field', () => {
        wizard.submitDetails();
        expect(wizard.formErrors.name).toBe('errors.required_name');
        expect(document.activeElement.id).toBe('vb-name');
    });
    it('blocks review when a required custom field is missing', () => {
        wizard.config.custom_fields = [{ name: 'allergies', label: 'Allergies', required: true }];
        wizard.customerName = 'Alex Example';
        wizard.customerEmail = 'alex@example.com';
        wizard.goToStep = vi.fn();
        wizard.submitDetails();
        expect(wizard.formErrors['custom.allergies']).toBe('errors.required_field');
        expect(wizard.goToStep).not.toHaveBeenCalled();
    });
    it('clears a field correction when the customer edits it', () => {
        wizard.formErrors = { name: 'errors.required_name', email: 'errors.invalid_email' };
        wizard.setCustomerName('Alex');
        expect(wizard.formErrors.name).toBeUndefined();
        expect(wizard.formErrors.email).toBe('errors.invalid_email');
    });
});

describe('availability reliability', () => {
    it('keeps event rescheduling in a loading state until its catalog read finishes', async () => {
        const response = deferred();
        wizard.step = 'reschedule-event';
        wizard.api = vi.fn().mockReturnValue(response.promise);
        const request = wizard.loadRescheduleEvents();
        expect(wizard.calendarLoading).toBe(true);
        response.resolve({ events: [] }); await request;
        expect(wizard.calendarLoading).toBe(false);
    });
    it('does not return to the calendar after the customer leaves during a read', async () => {
        const response = deferred();
        wizard.step = 'date'; wizard.api = vi.fn().mockReturnValue(response.promise);
        const request = wizard.loadDates();
        wizard.goToStep('service');
        response.resolve({ dates: ['2026-10-22'] }); await request;
        expect(wizard.step).toBe('service');
        expect(wizard.availableDates).toEqual([]);
    });
    it('keeps an availability failure visible when only its display timezone changes', () => {
        wizard.loadError = 'Connection lost';
        wizard.selectTimezone('America/New_York');
        expect(wizard.loadError).toBe('Connection lost');
    });
    it('does not abort a booking write while the server may still be committing it', async () => {
        vi.useFakeTimers();
        const response = deferred();
        const fetchMock = vi.fn().mockReturnValue(response.promise);
        vi.stubGlobal('fetch', fetchMock);
        const request = wizard.api('/bookings', { method: 'POST', body: '{}' });
        vi.advanceTimersByTime(16000);
        expect(fetchMock.mock.calls[0][1].signal.aborted).toBe(false);
        response.resolve({ ok: true, json: async () => ({ booking: { id: 'saved' } }) });
        await request;
    });
    it('keeps the latest calendar loading state when an older read completes', async () => {
        const first = deferred(), second = deferred();
        wizard.selectedResource = { id: 'a' };
        wizard.api = vi.fn().mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
        const a = wizard.loadResourceDates();
        wizard.checkInMonth++;
        const b = wizard.loadResourceDates();
        first.resolve({ dates: [] }); await a;
        expect(wizard.calendarLoading).toBe(true);
        second.resolve({ dates: [] }); await b;
        expect(wizard.calendarLoading).toBe(false);
    });
    it('requests authorized reschedule availability and hides the original time', async () => {
        wizard.manageBookingId = 'original'; wizard.step = 'reschedule-date';
        wizard.managedBooking = { service_id: 'svc', staff_id: 'staff', date: '2026-10-22', time: '09:00' };
        wizard.api = vi.fn().mockResolvedValue({ slots: [{ time: '09:00' }, { time: '09:30' }] });
        await wizard.selectRescheduleDate({ dateStr: '2026-10-22', day: 22, disabled: false });
        expect(wizard.api.mock.calls[0][0]).toContain('reschedule_booking_id=original');
        expect(wizard.rescheduleSlots).toEqual([{ time: '09:30' }]);
    });
    it('discards resource availability when the selected resource changes', async () => {
        const first = deferred(), second = deferred();
        wizard.selectedResource = { id: 'a' };
        wizard.api = vi.fn().mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
        const a = wizard.loadResourceDates();
        wizard.selectedResource = { id: 'b' };
        const b = wizard.loadResourceDates();
        second.resolve({ dates: ['2026-10-22'], check_in_days: [4] }); await b;
        first.resolve({ dates: ['2026-10-21'], check_in_days: [3] }); await a;
        expect(wizard.resourceDates).toEqual(['2026-10-22']);
        expect(wizard.selectedResource._check_in_days).toEqual([4]);
    });
    it('ignores a failed date request after a service change', async () => {
        let reject;
        wizard.selectedService = { id: 'a' };
        wizard.api = vi.fn(() => new Promise((resolve, fail) => { reject = fail; }));
        const pending = wizard.selectDate('2026-10-21');
        wizard.selectedService = { id: 'b' };
        reject(new Error('offline')); await pending;
        expect(wizard.loadError).toBe('');
    });
    it('ignores a late failure after a newer date has succeeded', async () => {
        let reject;
        const failed = new Promise((resolve, fail) => { reject = fail; });
        wizard.api = vi.fn().mockReturnValueOnce(failed).mockResolvedValueOnce({ slots: [{ time: '11:00' }] });
        const previous = wizard.selectDate('2026-10-21');
        await wizard.selectDate('2026-10-22');
        reject(new Error('late failure'));
        await previous;
        expect(wizard.loadError).toBe('');
        expect(wizard.slotsLoading).toBe(false);
    });
    it('discards a late response for a date the customer has left', async () => {
        const first = deferred(), second = deferred();
        wizard.api = vi.fn().mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
        const a = wizard.selectDate('2026-10-21');
        const b = wizard.selectDate('2026-10-22');
        second.resolve({ slots: [{ time: '11:00', end_time: '11:30' }] });
        await b;
        first.resolve({ slots: [{ time: '09:00', end_time: '09:30' }] });
        await a;
        expect(wizard.selectedDate).toBe('2026-10-22');
        expect(wizard.availableSlots[0].time).toBe('11:00');
    });
    it('renders a retryable initial failure instead of an empty catalog', async () => {
        wizard.api = vi.fn().mockRejectedValue(new TypeError('offline'));
        await wizard.loadServices();
        expect(wizard.isLoadError).toBe(true);
        expect(wizard.isEmpty).toBe(false);
        expect(wizard.retryAction).toBe('loadServices');
    });
    it('rejects an HTTP error without treating its response as availability', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 429, json: async () => ({ error: 'rate_limited' }) }));
        await expect(wizard.api('/availability')).rejects.toMatchObject({ status: 429 });
    });
});

describe('stay boundaries and guest limits', () => {
    it('allows checkout on the next booking arrival when the preceding nights are free', () => {
        wizard.selectedResource = { id: 'room', min_stay_nights: 1 };
        wizard.checkInYear = 2026; wizard.checkInMonth = 9;
        wizard.resourceDates = ['2026-10-21', '2026-10-22'];
        wizard.checkInDate = '2026-10-21';
        const boundary = wizard.resourceCalendarCells.find(cell => cell.dateStr === '2026-10-23');
        expect(boundary.disabled).toBe(false);
        wizard.checkResourceAvailability = vi.fn();
        wizard.clickResourceDate(boundary);
        expect(wizard.checkOutDate).toBe('2026-10-23');
    });
    it('keeps a checkout beyond an occupied intervening night unavailable', () => {
        wizard.selectedResource = { id: 'room', min_stay_nights: 1 };
        wizard.checkInYear = 2026; wizard.checkInMonth = 9;
        wizard.resourceDates = ['2026-10-21', '2026-10-23'];
        wizard.checkInDate = '2026-10-21';
        expect(wizard.resourceCalendarCells.find(cell => cell.dateStr === '2026-10-24').disabled).toBe(true);
    });
    it('never decrements a stay below one guest', () => {
        wizard.guestCount = 1;
        wizard.decrementGuests();
        expect(wizard.guestCount).toBe(1);
    });
});

describe('event availability and approval', () => {
    it('offers the waitlist quantity within the remaining queue and per-booking limit', () => {
        wizard.selectedEvent = { remaining: 0, allow_waitlist: true, waitlist_max: 3, waitlist_count: 1, min_spot_count: 1, max_spot_count: 2 };
        expect(wizard.eventMaxSpots).toBe(2);
        expect(wizard.eventCanBook(wizard.selectedEvent)).toBe(true);
    });
    it('does not offer a full waitlist as an available booking action', () => {
        const event = { remaining: 0, allow_waitlist: true, waitlist_max: 2, waitlist_count: 2, min_spot_count: 1 };
        expect(wizard.eventCanBook(event)).toBe(false);
    });
    it('names an approval request before submitting it', () => {
        wizard.config.requires_approval = true;
        expect(wizard.reviewSubmitLabel).toBe('buttons.request');
        wizard.bookingIsPending = true;
        wizard.config.confirmation_message = 'Confirmed!';
        expect(wizard.confirmCustomMessage).toBe('');
    });
});

describe('a revisable booking object', () => {
    it('holds the reviewed commitment while a confirmation is in flight', () => {
        wizard.step = 'review'; wizard.submitting = true;
        wizard.goBack('details');
        wizard.editChoice(); wizard.editWhen();
        expect(wizard.step).toBe('review');
    });
    it('uses the saved service title after confirmation', () => {
        wizard.step = 'confirmed'; wizard.booking = { service: 'Saved consultation' };
        wizard.selectedService = { name: 'Unrelated draft' };
        expect(wizard.bookingObjectTitle).toBe('Saved consultation');
    });
    it('presents checked custom fields without exposing boolean implementation values', () => {
        wizard.config.custom_fields = [{ name: 'updates', label: 'Updates', type: 'checkbox' }];
        wizard.customFields.updates = false;
        expect(wizard.customFieldRows).toEqual([]);
        wizard.customFields.updates = true;
        expect(wizard.customFieldRows).toEqual([{ label: 'Updates', value: 'form.checked_value' }]);
    });
    it('rejects an old finder choice when the service has changed', () => {
        const result = { date: '2026-10-21', slot: { time: '11:00' }, service: 'a', staff: '' };
        wizard.finderResults = [result];
        wizard.selectedService = { id: 'b' };
        wizard.chooseFinderResult(result);
        expect(wizard.selectedSlot).toBeNull();
        wizard.cancelFinder();
        expect(wizard.finderResults).toEqual([]);
    });
    it('keeps the chosen appointment and customer details when returning to its calendar', () => {
        wizard.selectedService = { id: 'svc', name: 'Consultation', duration_minutes: 30, price: null };
        wizard.selectedDate = '2026-10-22';
        wizard.selectedSlot = { time: '11:00', end_time: '11:30' };
        wizard.customerName = 'Alex';
        wizard.step = 'review';
        wizard.editWhen();
        expect(wizard.step).toBe('date');
        expect(wizard.selectedSlot.time).toBe('11:00');
        expect(wizard.customerName).toBe('Alex');
        expect(wizard.bookingObjectTitle).toBe('Consultation');
        expect(wizard.showBookingContext).toBe(true);
    });
    it('shows the title once in a review object', () => {
        wizard.selectedService = { id: 'svc', name: 'Consultation', duration_minutes: 30, price: null };
        expect(wizard.bookingObjectReviewRows.some(row => row.value === 'Consultation')).toBe(false);
    });
    it('bounds the availability experiment and selects a complete result', async () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-20T10:00:00Z'));
        wizard.selectedService = { id: 'svc', name: 'Consultation' };
        wizard.finderOpen = true;
        wizard.api = vi.fn(path => Promise.resolve(path.startsWith('/available-dates') ?
            { dates: ['2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23'] } : { slots: [{ time: '11:00', end_time: '11:30' }] }));
        await wizard.findTimes();
        expect(wizard.finderResults).toHaveLength(3);
        expect(wizard.api).toHaveBeenCalledTimes(4);
        wizard.chooseFinderResult(wizard.finderResults[1]);
        expect(wizard.selectedDate).toBe('2026-10-21');
        expect(wizard.selectedSlot.time).toBe('11:00');
        expect(wizard.step).toBe('details');
    });
});

describe('orientation and persistent feedback', () => {
    it('omits calendar timezone notes and business-date wording for equal-clock aliases', () => {
        wizard.step = 'date';
        wizard.selectedService = { is_virtual: true };
        wizard.selectedDate = '2026-10-25';
        wizard.selectedSlot = { time: '02:30', start_utc: '2026-10-25T01:30:00Z' };
        wizard.customerTz = 'Europe/Berlin';
        expect(wizard.hasTimezoneDifference).toBe(false);
        expect(wizard.isDisplayTimezoneDifferent).toBe(false);
        expect(wizard.selectedTimesHeading).toBe(wizard.formatDateDisplay(wizard.selectedDate));
        wizard.customerTz = 'America/New_York';
        expect(wizard.isDisplayTimezoneDifferent).toBe(true);
        wizard.selectedService.is_virtual = false;
        expect(wizard.isDisplayTimezoneDifferent).toBe(false);
    });
    it('shows timezone context only when the appointment clocks differ', () => {
        wizard.step = 'service';
        wizard.customerTz = 'Europe/Berlin';
        expect(wizard.showVenueTimezone).toBe(false);
        wizard.customerTz = 'America/New_York';
        expect(wizard.showVenueTimezone).toBe(true);
        wizard.selectedService = { is_virtual: true };
        expect(wizard.showTimezoneSelector).toBe(true);
        wizard.selectTimezone('Europe/Amsterdam');
        expect(wizard.hasTimezoneDifference).toBe(false);
        expect(wizard.showTimezoneSelector).toBe(true); // Keep an explicitly used control available.
    });
    it('uses the same authoritative repeated-hour instant through review, confirmation, manage and rescheduling', () => {
        const booking = { id: 'saved', service: 'Consultation', service_name: 'Consultation', date: '2026-10-25', time: '02:30', end_time: '03:30', start_utc: '2026-10-25T01:30:00Z', end_utc: '2026-10-25T02:30:00Z', is_virtual: true };
        wizard.customerTz = 'UTC';
        wizard.selectedService = { name: 'Consultation', duration_minutes: 60, is_virtual: true };
        wizard.selectedDate = booking.date;
        wizard.selectedSlot = { ...booking };
        wizard.booking = booking;
        wizard.managedBooking = { ...booking, booking_pattern: 'timeslot' };
        wizard.rescheduleNewBooking = booking;
        wizard.rescheduleDate = booking.date;
        wizard.rescheduleSlot = { ...booking };
        for (const rows of [wizard.summaryRows, wizard.confirmSummaryRows, wizard.manageSummaryRows, wizard.rescheduleConfirmedRows]) {
            expect(rows.find(row => row.label === 'Time').value).toBe('01:30 – 02:30');
        }
        expect(wizard.displaySlotTime(wizard.selectedSlot)).toBe('01:30');
        expect(wizard.rescheduleSlotLabel).toContain('01:30 – 02:30');
        expect(wizard.tzDisplayLabel('Europe/Amsterdam')).toBe('Amsterdam (GMT+1)');
        const dates = new URL(wizard.gcalUrl).searchParams.get('dates');
        expect(dates).toBe('20261025T013000Z/20261025T023000Z');
    });
    it('exports the canonical start and complete overnight end as UTC calendar fields', () => {
        wizard.booking = { id: 'overnight', date: '2026-10-24', time: '23:00', end_time: '01:00', start_utc: '2026-10-24T21:00:00Z', end_utc: '2026-10-24T23:00:00Z' };
        let contents;
        vi.stubGlobal('Blob', class { constructor(parts) { contents = parts.join(''); } });
        vi.stubGlobal('URL', Object.assign(URL, { createObjectURL: vi.fn(() => 'blob:calendar'), revokeObjectURL: vi.fn() }));
        vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {});
        wizard.downloadIcs();
        expect(contents).toContain('DTSTART:20261024T210000Z');
        expect(contents).toContain('DTEND:20261024T230000Z');
        expect(contents).not.toContain('DTSTART;TZID=');
    });
    it('restores a virtual booking timezone and its converted calendar date on the manage page', async () => {
        wizard.api = vi.fn().mockResolvedValue({ booking: { status: 'pending', is_virtual: true, date: '2026-10-23', time: '00:30', customer_timezone: 'America/New_York' } });
        await wizard.loadManagedBooking();
        expect(wizard.customerTz).toBe('America/New_York');
        expect(wizard.displayDate(wizard.managedBooking.date, wizard.managedBooking.time)).toBe('2026-10-22');
    });
    it.each([null, '', 'not-a-timezone'])('ignores legacy invalid saved timezone %s', async customer_timezone => {
        wizard.api = vi.fn().mockResolvedValue({ booking: { status: 'confirmed', is_virtual: true, customer_timezone } });
        await wizard.loadManagedBooking();
        expect(wizard.customerTz).toBe('Europe/Amsterdam');
    });
    it('shows the booking-date offset after daylight saving changes', () => {
        wizard.selectedEvent = { start_datetime: '2026-11-04 14:00:00' };
        expect(wizard.tzDisplayLabel('Europe/Berlin')).toBe('Berlin (GMT+1)');
    });
    it('uses the wizard root when an action is invoked from a button', async () => {
        wizard.setupExperience();
        wizard.$el = wizard.rootElement.querySelector('button');
        wizard.goToStep('details');
        await new Promise(resolve => window.requestAnimationFrame(resolve));
        expect(document.activeElement.tagName).toBe('H2');
    });
    it('changes step immediately for reduced motion and focuses its rendered heading', async () => {
        wizard.goToStep('details');
        expect(wizard.step).toBe('details');
        await new Promise(resolve => window.requestAnimationFrame(resolve));
        expect(document.activeElement.tagName).toBe('H2');
    });
    it('keeps actionable error feedback until the customer dismisses it', () => {
        vi.useFakeTimers();
        wizard.showToast('Connection lost', 'error');
        vi.advanceTimersByTime(10000);
        expect(wizard.toast?.message).toBe('Connection lost');
        wizard.dismissToast();
        expect(wizard.toast).toBeNull();
    });
    it('formats the date from the same converted instant as the selected time', () => {
        wizard.selectedService = { name: 'Consultation', duration_minutes: 30, price: null, is_virtual: true };
        wizard.selectedDate = '2026-10-21';
        wizard.selectedSlot = { time: '00:30', end_time: '01:00' };
        wizard.customerTz = 'America/New_York';
        expect(wizard.summaryRows.find(row => row.label === 'Date').value).toBe(wizard.formatDateDisplay('2026-10-20'));
    });
    it('uses venue time for a physical appointment', () => {
        wizard.selectedService = { name: 'Haircut', is_virtual: false };
        wizard.customerTz = 'America/New_York';
        wizard.selectedDate = '2026-10-21';
        expect(wizard.displaySlotTime({ time: '09:00' })).toBe('09:00');
    });
});
