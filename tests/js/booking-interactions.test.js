/** @vitest-environment jsdom */
import { afterEach, describe, expect, it } from 'vitest';
import { calendarGridKeydown, createLatestRequest, nextCalendarDate, validateBookingDetails } from '../../resources/js/booking/interactions.js';

describe('shared booking interaction contract', () => {
    it('exports the calendar, details and request helpers', async () => {
        await expect(import('../../resources/js/booking/interactions.js')).resolves.toEqual(expect.objectContaining({
            nextCalendarDate: expect.any(Function),
            calendarGridKeydown: expect.any(Function),
            validateBookingDetails: expect.any(Function),
            createLatestRequest: expect.any(Function),
        }));
    });
});

describe('nextCalendarDate', () => {
    it.each([
        ['2026-10-20', 'ArrowLeft', '2026-10-19'],
        ['2026-10-20', 'ArrowRight', '2026-10-21'],
        ['2026-10-20', 'ArrowUp', '2026-10-13'],
        ['2026-10-20', 'ArrowDown', '2026-10-27'],
        ['2026-12-31', 'ArrowRight', '2027-01-01'],
        ['2026-01-01', 'ArrowLeft', '2025-12-31'],
        ['2026-03-28', 'ArrowDown', '2026-04-04'],
        ['2026-10-25', 'ArrowUp', '2026-10-18'],
    ])('moves %s with %s to the calendar date %s', (date, key, expected) => {
        expect(nextCalendarDate(date, key)).toBe(expected);
    });

    it.each([
        ['Home', 0, '2026-10-18'],
        ['End', 0, '2026-10-24'],
        ['Home', 1, '2026-10-19'],
        ['End', 1, '2026-10-25'],
        ['Home', 6, '2026-10-17'],
        ['End', 6, '2026-10-23'],
    ])('uses %s with the configured week start %s', (key, weekStart, expected) => {
        expect(nextCalendarDate('2026-10-20', key, { weekStart })).toBe(expected);
    });

    it.each([
        ['2026-01-31', 'PageDown', {}, '2026-02-28'],
        ['2024-01-31', 'PageDown', {}, '2024-02-29'],
        ['2026-03-31', 'PageUp', {}, '2026-02-28'],
        ['2026-12-20', 'PageDown', {}, '2027-01-20'],
        ['2024-02-29', 'PageDown', { shiftKey: true }, '2025-02-28'],
        ['2024-02-29', 'PageUp', { shiftKey: true }, '2023-02-28'],
    ])('preserves or clamps the day when paging from %s with %s', (date, key, options, expected) => {
        expect(nextCalendarDate(date, key, options)).toBe(expected);
    });

    it.each(['2026-02-29', '2026-13-01', '2026-00-10', '2026-10-00', '2026-1-01', '', null])('rejects invalid ISO date %s', date => {
        expect(nextCalendarDate(date, 'ArrowRight')).toBeNull();
    });

    it('leaves selection and unrelated keys outside the date navigation contract', () => {
        expect(nextCalendarDate('2026-10-20', 'Enter')).toBeNull();
    });
});

describe('calendarGridKeydown', () => {
    afterEach(() => { document.body.replaceChildren(); });

    function calendar(dates, options = {}) {
        const grid = document.createElement('div');
        grid.dataset.weekStart = '1';
        grid.setAttribute('role', 'grid');
        grid.innerHTML = dates.map((date, index) => `<button type="button" role="gridcell" data-date="${date}" tabindex="${index === 0 ? 0 : -1}" aria-disabled="${index === 1}"><span>${date}</span></button>`).join('');
        document.body.append(grid);
        grid.addEventListener('keydown', event => calendarGridKeydown(event, options));
        return grid;
    }

    function press(grid, date, key, modifiers = {}) {
        const cell = grid.querySelector(`[data-date="${date}"]`);
        cell.focus();
        const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...modifiers });
        cell.querySelector('span').dispatchEvent(event);
        return event;
    }

    it('focuses the next actual day even when it is unavailable, without selecting it', () => {
        const grid = calendar(['2026-10-20', '2026-10-21', '2026-10-23', '2026-10-27']);
        const selected = [];
        grid.addEventListener('click', event => selected.push(event.target.dataset.date));

        const event = press(grid, '2026-10-20', 'ArrowRight');

        expect(document.activeElement.dataset.date).toBe('2026-10-21');
        expect(document.activeElement.getAttribute('aria-disabled')).toBe('true');
        expect(selected).toEqual([]);
        expect(event.defaultPrevented).toBe(true);
    });

    it('moves down one calendar week regardless of missing or unavailable dates', () => {
        const grid = calendar(['2026-10-20', '2026-10-21', '2026-10-23', '2026-10-27']);
        press(grid, '2026-10-20', 'ArrowDown');

        expect(document.activeElement.dataset.date).toBe('2026-10-27');
    });

    it('maintains one tab stop as focus moves across dates', () => {
        const grid = calendar(['2026-10-20', '2026-10-21', '2026-10-22']);
        press(grid, '2026-10-20', 'ArrowRight');
        press(grid, '2026-10-21', 'ArrowRight');

        expect(Array.from(grid.querySelectorAll('[tabindex="0"]'), cell => cell.dataset.date)).toEqual(['2026-10-22']);
        expect(grid.querySelector('[data-date="2026-10-20"]').tabIndex).toBe(-1);
    });

    it('uses the calendar week start for Home and End', () => {
        const grid = calendar(['2026-10-19', '2026-10-20', '2026-10-25']);
        press(grid, '2026-10-20', 'Home');
        expect(document.activeElement.dataset.date).toBe('2026-10-19');

        press(grid, '2026-10-20', 'End');
        expect(document.activeElement.dataset.date).toBe('2026-10-25');
    });

    it.each([
        ['2026-10-31', 'ArrowRight', false, '2026-11-01'],
        ['2026-10-20', 'PageUp', false, '2026-09-20'],
        ['2026-10-20', 'PageDown', false, '2026-11-20'],
        ['2026-10-20', 'PageDown', true, '2027-10-20'],
    ])('requests the target month from %s via %s when the date is outside the grid', (date, key, shiftKey, target) => {
        const monthChanges = [];
        const grid = calendar([date], { onMonthChange: nextDate => monthChanges.push(nextDate) });
        const event = press(grid, date, key, { shiftKey });

        expect(monthChanges).toEqual([target]);
        expect(event.defaultPrevented).toBe(true);
        expect(document.activeElement.dataset.date).toBe(date);
    });

    it('moves between rendered months without requesting a render', () => {
        const monthChanges = [];
        const grid = calendar(['2026-10-31', '2026-11-01'], { onMonthChange: date => monthChanges.push(date) });
        press(grid, '2026-10-31', 'ArrowRight');

        expect(document.activeElement.dataset.date).toBe('2026-11-01');
        expect(monthChanges).toEqual([]);
    });

    it.each(['Enter', ' ', 'Tab'])('leaves %s to the date button or browser', key => {
        const grid = calendar(['2026-10-20', '2026-10-21']);
        const event = press(grid, '2026-10-20', key);

        expect(event.defaultPrevented).toBe(false);
        expect(document.activeElement.dataset.date).toBe('2026-10-20');
    });

    it('does not consume browser modifier shortcuts', () => {
        const grid = calendar(['2026-10-20', '2026-10-21']);
        const event = press(grid, '2026-10-20', 'ArrowRight', { altKey: true });

        expect(event.defaultPrevented).toBe(false);
        expect(document.activeElement.dataset.date).toBe('2026-10-20');
    });
});

describe('validateBookingDetails', () => {
    const valid = { name: 'Robin Example', email: 'robin@example.test' };

    it('returns translated field errors for missing required details', () => {
        expect(validateBookingDetails({ name: '  ', email: '', phone: '\t', consent: false }, {
            require_phone: true,
            requires_consent: true,
        })).toEqual({
            name: 'errors.required_name',
            email: 'errors.required_email',
            phone: 'errors.required_phone',
            consent: 'errors.required_consent',
        });
    });

    it('distinguishes malformed email from missing email', () => {
        expect(validateBookingDetails({ ...valid, email: 'robin at example.test' }, {})).toEqual({ email: 'errors.invalid_email' });
    });

    it('trims detail values without mutating the values used for submission', () => {
        const values = { name: ' Robin Example ', email: ' robin@example.test ', phone: ' +31 6 0000 0000 ', consent: true };
        expect(validateBookingDetails(values, { require_phone: true, requires_consent: true })).toEqual({});
        expect(values.name).toBe(' Robin Example ');
    });

    it('keeps phone and consent optional when the business does not require them', () => {
        expect(validateBookingDetails(valid, {})).toEqual({});
    });

    it('returns fresh errors after a customer corrects their details', () => {
        const values = { ...valid, name: '' };
        expect(validateBookingDetails(values, {}).name).toBe('errors.required_name');
        values.name = valid.name;
        expect(validateBookingDetails(values, {})).toEqual({});
    });

    it('supports legacy field.name and schema field.key without colliding with customer details', () => {
        expect(validateBookingDetails({ ...valid, customFields: { notes: '  ', email: '' } }, {
            custom_fields: [
                { name: 'notes', required: true, type: 'textarea' },
                { key: 'email', required: true, type: 'text' },
            ],
        })).toEqual({ 'custom.notes': 'errors.required_field', 'custom.email': 'errors.required_field' });
    });

    it('uses the legacy name when a field also supplies a key', () => {
        expect(validateBookingDetails({ ...valid, customFields: { old_name: 'present' } }, {
            custom_fields: [{ name: 'old_name', key: 'new_key', required: true }],
        })).toEqual({});
    });

    it('requires checked custom checkboxes and accepts the checked boolean', () => {
        const config = { custom_fields: [{ key: 'terms', type: 'checkbox', required: true }] };
        expect(validateBookingDetails({ ...valid, customFields: { terms: false } }, config)).toEqual({ 'custom.terms': 'errors.required_field' });
        expect(validateBookingDetails({ ...valid, customFields: { terms: true } }, config)).toEqual({});
    });

    it('does not treat the string false as a checked checkbox', () => {
        expect(validateBookingDetails({ ...valid, customFields: { terms: 'false' } }, {
            custom_fields: [{ key: 'terms', type: 'checkbox', required: true }],
        })).toEqual({ 'custom.terms': 'errors.required_field' });
    });

    it('allows empty optional custom fields', () => {
        expect(validateBookingDetails(valid, {
            custom_fields: ['email', 'number', 'date', 'select', 'tel', 'checkbox'].map(type => ({ key: type, type })),
        })).toEqual({});
    });

    it('validates nonempty optional custom email fields', () => {
        expect(validateBookingDetails({ ...valid, customFields: { secondary: 'invalid' } }, {
            custom_fields: [{ key: 'secondary', type: 'email' }],
        })).toEqual({ 'custom.secondary': 'errors.invalid_email' });
    });

    it.each([
        ['3', { min: 1, max: 5 }, true],
        [0, { min: 0, max: 5 }, true],
        ['0', { min: 1 }, false],
        ['6', { max: 5 }, false],
        ['NaN', {}, false],
        ['Infinity', {}, false],
        ['0x10', {}, false],
        ['1.5', {}, false],
        ['1.5', { step: 'any' }, true],
        ['1.5', { min: 0.5, step: 0.5 }, true],
        ['1.2', { step: 0.5 }, false],
    ])('validates custom number %s against HTML number constraints %o', (value, constraints, accepted) => {
        expect(validateBookingDetails({ ...valid, customFields: { count: value } }, {
            custom_fields: [{ key: 'count', type: 'number', required: true, ...constraints }],
        })).toEqual(accepted ? {} : { 'custom.count': 'errors.invalid_field' });
    });

    it.each([
        ['2024-02-29', {}, true],
        ['2026-02-29', {}, false],
        ['20/10/2026', {}, false],
        ['2026-10-20', { min: '2026-10-21' }, false],
        ['2026-10-20', { max: '2026-10-19' }, false],
        ['2026-10-20', { min: '2026-10-20', max: '2026-10-20' }, true],
    ])('validates custom date %s against date constraints %o', (value, constraints, accepted) => {
        expect(validateBookingDetails({ ...valid, customFields: { birthday: value } }, {
            custom_fields: [{ key: 'birthday', type: 'date', ...constraints }],
        })).toEqual(accepted ? {} : { 'custom.birthday': 'errors.invalid_field' });
    });

    it('validates select values against configured options', () => {
        const config = { custom_fields: [{ key: 'occasion', type: 'select', options: ['birthday', 'anniversary'] }] };
        expect(validateBookingDetails({ ...valid, customFields: { occasion: 'retirement' } }, config)).toEqual({ 'custom.occasion': 'errors.invalid_field' });
        expect(validateBookingDetails({ ...valid, customFields: { occasion: 'birthday' } }, config)).toEqual({});
    });

    it('accepts object option values used by labelled select controls', () => {
        expect(validateBookingDetails({ ...valid, customFields: { occasion: 'birthday' } }, {
            custom_fields: [{ key: 'occasion', type: 'select', options: [{ value: 'birthday', label: 'Birthday' }] }],
        })).toEqual({});
    });

    it('leaves unconstrained telephone formats valid, like native type tel', () => {
        expect(validateBookingDetails({ ...valid, customFields: { whatsapp: '+31 (0)6 0000 0000' } }, {
            custom_fields: [{ key: 'whatsapp', type: 'tel', required: true }],
        })).toEqual({});
    });
});

describe('createLatestRequest', () => {
    it('has no current identity before the first request starts', () => {
        const requests = createLatestRequest();
        expect(requests.isCurrent(undefined)).toBe(false);
        expect(requests.isCurrent(0)).toBe(false);
    });

    it('recognizes only the most recent identity', () => {
        const requests = createLatestRequest();
        const first = requests.next();
        expect(requests.isCurrent(first)).toBe(true);
        const latest = requests.next();

        expect(latest).not.toBe(first);
        expect(requests.isCurrent(first)).toBe(false);
        expect(requests.isCurrent(latest)).toBe(true);
    });

    it('invalidates a pending request when selection dependencies change', () => {
        const requests = createLatestRequest();
        const pending = requests.next();
        requests.invalidate();

        expect(requests.isCurrent(pending)).toBe(false);
        const next = requests.next();
        expect(next).not.toBe(pending);
        expect(requests.isCurrent(next)).toBe(true);
    });

    it('discards an older response that arrives after the current response', async () => {
        const requests = createLatestRequest();
        const applied = [];
        let resolveOlder;
        const older = new Promise(resolve => { resolveOlder = resolve; });
        const oldId = requests.next();
        const oldTask = older.then(result => {
            if (requests.isCurrent(oldId)) applied.push(result);
        });
        const latestId = requests.next();
        const latest = Promise.resolve('current availability').then(result => {
            if (requests.isCurrent(latestId)) applied.push(result);
        });
        await latest;
        resolveOlder('stale availability');
        await oldTask;

        expect(applied).toEqual(['current availability']);
    });

    it('keeps the current loading state when an older request finishes', async () => {
        const requests = createLatestRequest();
        const oldId = requests.next();
        requests.next();
        let loading = true;
        await Promise.resolve().finally(() => {
            if (requests.isCurrent(oldId)) loading = false;
        });

        expect(loading).toBe(true);
    });
});
