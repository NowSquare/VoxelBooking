function calendarDate(value) {
    if (typeof value !== 'string' || !/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
    const [year, month, day] = value.split('-').map(Number);
    if (year < 1) return null;

    // Civil-date arithmetic uses UTC components throughout, never a parsed UTC
    // timestamp combined with local getters or an elapsed 24-hour interval.
    const date = new Date(0);
    date.setUTCFullYear(year, month - 1, day);
    if (date.getUTCFullYear() !== year || date.getUTCMonth() !== month - 1 || date.getUTCDate() !== day) return null;
    return date;
}

/** Return the civil date reached by a calendar navigation key. */
export function nextCalendarDate(dateStr, key, { weekStart = 0, shiftKey = false } = {}) {
    const date = calendarDate(dateStr);
    if (!date) return null;
    const weekday = date.getUTCDay();
    const start = Number.isInteger(Number(weekStart)) && Number(weekStart) >= 0 && Number(weekStart) <= 6
        ? Number(weekStart) : 0;
    const offset = (weekday - start + 7) % 7;
    const dayOffsets = { ArrowLeft: -1, ArrowRight: 1, ArrowUp: -7, ArrowDown: 7, Home: -offset, End: 6 - offset };

    if (Object.hasOwn(dayOffsets, key)) {
        date.setUTCDate(date.getUTCDate() + dayOffsets[key]);
    } else if (key === 'PageUp' || key === 'PageDown') {
        const day = date.getUTCDate();
        const months = (key === 'PageUp' ? -1 : 1) * (shiftKey ? 12 : 1);
        date.setUTCDate(1);
        date.setUTCMonth(date.getUTCMonth() + months);
        const lastDay = new Date(date.getTime());
        lastDay.setUTCMonth(lastDay.getUTCMonth() + 1, 0);
        date.setUTCDate(Math.min(day, lastDay.getUTCDate()));
    } else {
        return null;
    }

    if (date.getUTCFullYear() < 1 || date.getUTCFullYear() > 9999) return null;
    return date.toISOString().slice(0, 10);
}

/** Navigate focus only; the date button's activation handler owns selection. */
export function calendarGridKeydown(event, { onMonthChange } = {}) {
    if (event.altKey || event.ctrlKey || event.metaKey) return null;
    const grid = event.currentTarget;
    const current = grid?.ownerDocument.activeElement;
    const cells = Array.from(grid?.querySelectorAll('[data-date]') || []);
    if (!cells.includes(current)) return null;

    const targetDate = nextCalendarDate(current.dataset.date, event.key, {
        weekStart: grid.dataset.weekStart ?? 0,
        shiftKey: event.shiftKey,
    });
    if (!targetDate) return null;

    event.preventDefault();
    const next = cells.find(cell => cell.dataset.date === targetDate);
    if (next) {
        cells.forEach(cell => { cell.tabIndex = cell === next ? 0 : -1; });
        next.focus();
    } else if (onMonthChange) {
        onMonthChange(targetDate);
    }
    return targetDate;
}

const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const fieldText = value => String(value ?? '').trim();

function numberConstraint(value) {
    if (value == null || fieldText(value) === '') return null;
    const number = Number(value);
    return Number.isFinite(number) ? number : null;
}

function validNumber(value, field) {
    if (!/^-?(?:\d+|\d*\.\d+)(?:[eE][+-]?\d+)?$/.test(value)) return false;
    const number = Number(value);
    if (!Number.isFinite(number)) return false;
    const min = numberConstraint(field.min);
    const max = numberConstraint(field.max);
    if ((min !== null && number < min) || (max !== null && number > max)) return false;
    if (field.step === 'any') return true;
    const configuredStep = numberConstraint(field.step);
    const step = configuredStep !== null && configuredStep > 0 ? configuredStep : 1;
    const steps = (number - (min ?? 0)) / step;
    return Math.abs(steps - Math.round(steps)) < 1e-7;
}

function customFieldError(value, field) {
    const text = fieldText(value);
    const empty = field.type === 'checkbox' ? value !== true : !text;
    if (empty) return field.required ? 'errors.required_field' : null;

    if (field.type === 'email' && !emailPattern.test(text)) return 'errors.invalid_email';
    if (field.type === 'number' && !validNumber(text, field)) return 'errors.invalid_field';
    if (field.type === 'date') {
        if (!calendarDate(text)) return 'errors.invalid_field';
        if ((calendarDate(field.min) && text < field.min) || (calendarDate(field.max) && text > field.max)) return 'errors.invalid_field';
    }
    if (field.type === 'select') {
        const options = Array.isArray(field.options) ? field.options : [];
        if (!options.some(option => String(typeof option === 'object' && option !== null ? option.value : option) === text)) return 'errors.invalid_field';
    }
    return null;
}

/** Build a fresh translation-key error map without changing customer values. */
export function validateBookingDetails(values = {}, config = {}) {
    const errors = {};
    if (!fieldText(values.name)) errors.name = 'errors.required_name';
    const email = fieldText(values.email);
    if (!email) errors.email = 'errors.required_email';
    else if (!emailPattern.test(email)) errors.email = 'errors.invalid_email';
    if (config.require_phone && !fieldText(values.phone)) errors.phone = 'errors.required_phone';
    if (config.requires_consent && values.consent !== true) errors.consent = 'errors.required_consent';

    for (const field of config.custom_fields || []) {
        const name = field.name || field.key;
        if (!name) continue;
        const customValues = values.customFields || {};
        const value = Object.hasOwn(customValues, name) ? customValues[name] : undefined;
        const error = customFieldError(value, field);
        if (error) errors[`custom.${name}`] = error;
    }
    return errors;
}

export function createLatestRequest() {
    let sequence = 0;
    let current = null;
    return {
        next() {
            current = ++sequence;
            return current;
        },
        isCurrent(id) {
            return current !== null && id === current;
        },
        invalidate() {
            current = null;
        },
    };
}
