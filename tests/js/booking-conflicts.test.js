// @vitest-environment jsdom
import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest';

const registration = vi.hoisted(() => ({ factory: null }));
vi.mock('@alpinejs/csp', () => ({ default: {
    data: (name, factory) => { if (name === 'bookingWizard') registration.factory = factory; },
    start: () => {},
} }));
vi.mock('lucide', async (original) => ({ ...await original(), createIcons: () => {} }));

let wizard;

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
    window.__VB_I18N__ = { 'errors.generic': 'Something went wrong', 'errors.slot_taken': 'Taken' };
    window.__VB_FMT__ = { intl_locale: 'en-GB', week_start: 1, time_format: 'H:i' };
    document.body.innerHTML = '<div id="wizard"><div class="vb-book-step"><form><input id="vb-name"></form></div></div>';
    await import('../../resources/js/booking/app.js');
    wizard = registration.factory();
    wizard.$el = document.getElementById('wizard');
    wizard.$refs = {};
    wizard.$nextTick = cb => cb();
    wizard.customerTz = 'Europe/Amsterdam';
    wizard.customerName = 'Alex Example';
    wizard.customerEmail = 'alex@example.com';
    wizard.step = 'review';
    wizard.goToStep = vi.fn();

    // Each pattern needs only the selection its own submit handler reads.
    wizard.selectedService = { id: 'service' };
    wizard.selectedSlot = { time: '14:00', staff_id: null };
    wizard.selectedDate = '2026-10-27';
    wizard.selectedResource = { id: 'resource' };
    wizard.checkInDate = '2026-11-01';
    wizard.checkOutDate = '2026-11-03';
    wizard.guestCount = 2;
    wizard.selectedCapacitySlot = { id: 'slot' };
    wizard.partySize = 6;
    wizard.selectedEvent = { id: 'event', date: '2026-11-05' };
    wizard.eventSpotCount = 1;
});

afterEach(() => { wizard.destroy(); vi.useRealTimers(); vi.restoreAllMocks(); });

const submitters = {
    timeslot: 'submitBooking',
    resource: 'submitResourceBooking',
    capacity: 'submitCapacityBooking',
    event: 'submitEventBooking',
};

const conflicts = [
    ['resource', 'already_booked', 'This room is already booked for those dates.'],
    ['resource', 'min_stay_violation', 'Minimum stay not met.'],
    ['capacity', 'capacity_exceeded', 'Not enough spots remaining for your party size.'],
    ['capacity', 'party_too_small', 'Party size is below the minimum for this slot.'],
    ['event', 'event_full', 'This event is full.'],
    ['event', 'waitlist_full', 'The waitlist for this event is full.'],
    ['event', 'spot_count_too_many', 'Maximum spots per booking is 4.'],
    ['timeslot', 'max_bookings_exceeded', 'You have reached the maximum number of bookings for this day.'],
];

describe('booking conflicts keep the customer on the review step with the reason', () => {
    // The shared operation layer once sent `errors: []` with every conflict.
    // An empty list must never be read as field corrections.
    for (const shape of [{ errors: [] }, { errors: {} }, {}]) {
        for (const [pattern, code, message] of conflicts) {
            it(`${pattern} ${code} with ${JSON.stringify(shape)}`, async () => {
                wizard.api = vi.fn().mockResolvedValue({ error: code, message, ...shape });
                wizard.showToast = vi.fn();
                await wizard[submitters[pattern]]();
                expect(wizard.showToast).toHaveBeenCalledWith(message, 'error');
                expect(wizard.goToStep).not.toHaveBeenCalledWith('details');
                expect(wizard.submitting).toBe(false);
            });
        }
    }

    it('offers refreshed times when a timeslot was taken', async () => {
        const alternatives = [{ time: '15:00', staff_id: null }, { time: '15:30', staff_id: null }];
        wizard.api = vi.fn().mockResolvedValue({ error: 'slot_unavailable', message: 'This time slot was just taken.', alternatives, errors: [] });
        await wizard.submitBooking();
        expect(wizard.slotAlternatives).toEqual(alternatives);
        expect(wizard.goToStep).not.toHaveBeenCalled();
    });

    it('returns to the calendar with the reason when no refreshed times exist', async () => {
        wizard.api = vi.fn().mockResolvedValue({ error: 'slot_unavailable', message: 'This time slot was just taken.', alternatives: [] });
        wizard.showToast = vi.fn();
        await wizard.submitBooking();
        expect(wizard.showToast).toHaveBeenCalledWith('This time slot was just taken.', 'warn');
        expect(wizard.goToStep).toHaveBeenCalledWith('date');
    });
});

describe('server field corrections', () => {
    it('returns to the details step only for real field errors', async () => {
        wizard.api = vi.fn().mockResolvedValue({
            error: 'validation_failed', message: 'Check the highlighted fields before continuing.',
            errors: { 'customer.email': 'Please enter a valid value.', 'custom_fields.allergies': 'Please complete this field.' },
        });
        await wizard.submitBooking();
        expect(wizard.goToStep).toHaveBeenCalledWith('details');
        expect(wizard.formErrors).toEqual({ email: 'Please enter a valid value.', 'custom.allergies': 'Please complete this field.' });
    });

    it('ignores empty error collections', () => {
        expect(wizard.applyServerErrors({ errors: [] })).toBe(false);
        expect(wizard.applyServerErrors({ errors: {} })).toBe(false);
        expect(wizard.applyServerErrors({ errors: null })).toBe(false);
        expect(wizard.goToStep).not.toHaveBeenCalled();
    });
});

describe('confirmation email line', () => {
    beforeEach(() => {
        window.__VB_I18N__['confirmed.email_sent'] = 'A confirmation has been sent to :email.';
        window.__VB_I18N__['confirmed.email_queued'] = "We'll send your confirmation to :email.";
    });

    it('says sent only when the email left', () => {
        wizard.booking = { email_sent: true, notification_state: 'delivered' };
        expect(wizard.confirmEmailSent).toBe('A confirmation has been sent to alex@example.com.');
    });

    it('says queued when delivery will be retried', () => {
        wizard.booking = { email_sent: false, notification_state: 'queued' };
        expect(wizard.confirmEmailSent).toBe("We'll send your confirmation to alex@example.com.");
    });

    it('says nothing when no email was queued or delivery failed', () => {
        for (const state of ['none', 'failed', undefined]) {
            wizard.booking = { email_sent: false, notification_state: state };
            expect(wizard.confirmEmailSent).toBe('');
        }
    });
});
