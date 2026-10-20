// ── Timezone conversion engine ──
// Extracted from app.js for testability.
// All functions are pure: no DOM, no Alpine, no side effects.

const MINUTE_MS = 60000;
const DAY_MS = 24 * 60 * MINUTE_MS;
const formatters = new Map();

function zonedParts(instant, timeZone) {
    if (!formatters.has(timeZone)) {
        formatters.set(timeZone, new Intl.DateTimeFormat('en-US', {
            timeZone,
            calendar: 'gregory',
            numberingSystem: 'latn',
            year: 'numeric', month: '2-digit', day: '2-digit',
            hour: '2-digit', minute: '2-digit', second: '2-digit',
            hourCycle: 'h23',
        }));
    }

    const parts = {};
    for (const part of formatters.get(timeZone).formatToParts(instant)) {
        parts[part.type] = part.value;
    }

    return {
        date: `${parts.year}-${parts.month}-${parts.day}`,
        time: `${parts.hour}:${parts.minute}`,
    };
}

function wallTimeAsUtc(date, time) {
    return Date.parse(`${date}T${time}:00Z`);
}

function offsetAtInstant(instant, timeZone) {
    const local = zonedParts(instant, timeZone);
    return instant - wallTimeAsUtc(local.date, local.time);
}

/**
 * Resolve an IANA wall time without interpreting it in the host timezone.
 * Check offsets on both sides of a transition: repeated times use the earlier
 * occurrence, and nonexistent times advance by the gap (compatible semantics).
 */
function resolveInstant(date, time, timeZone) {
    const wall = wallTimeAsUtc(date, time);
    const offsets = new Set([-2, -1, 0, 1, 2].map(days => offsetAtInstant(wall + days * DAY_MS, timeZone)));
    const candidates = [...offsets].map(offset => wall + offset).sort((a, b) => a - b);

    for (const instant of candidates) {
        const local = zonedParts(instant, timeZone);
        if (local.date === date && local.time === time) return instant;
    }

    // A wall time in a forward gap has no exact match. The later candidate
    // preserves its minutes while moving it forward by the offset change.
    return candidates[candidates.length - 1];
}

function authoritativeInstant(value) {
    if (typeof value !== 'string' || !/T.*Z$/.test(value)) return null;
    const timestamp = Date.parse(value);
    return Number.isFinite(timestamp) ? timestamp : null;
}

/**
 * Convert a date+time from one IANA timezone to another.
 * Returns { date: 'YYYY-MM-DD', time: 'HH:MM' } in the target timezone.
 * Repeated source times use their earlier occurrence; missing source times
 * move forward by the DST gap. Matching zones preserve the original input.
 *
 * @param {string} date - 'YYYY-MM-DD' in fromTz
 * @param {string} time - 'HH:MM' in fromTz
 * @param {string} fromTz - IANA timezone identifier (source)
 * @param {string} toTz - IANA timezone identifier (target)
 * @returns {{ date: string, time: string }}
 */
export function convertTime(date, time, fromTz, toTz) {
    if (fromTz === toTz) return { date, time };

    return zonedParts(resolveInstant(date, time, fromTz), toTz);
}

/**
 * Get the UTC offset in minutes for a given date/time in a timezone.
 * Positive = behind UTC (e.g. +300 for EST); CET returns -60.
 *
 * Uses Intl.DateTimeFormat to resolve the offset without any library.
 *
 * @param {string} date - 'YYYY-MM-DD'
 * @param {string} time - 'HH:MM'
 * @param {string} tz - IANA timezone identifier
 * @returns {number} Offset in minutes
 */
export function getTimezoneOffsetMinutes(date, time, tz) {
    return offsetAtInstant(resolveInstant(date, time, tz), tz) / MINUTE_MS;
}

/** Name the target offset at the appointment instant, including DST changes. */
export function timezoneOffsetName(date, time, sourceTimezone, displayTimezone, utcInstant = null) {
    return new Intl.DateTimeFormat('en-US', {
        timeZone: displayTimezone, timeZoneName: 'shortOffset',
    }).formatToParts(authoritativeInstant(utcInstant) ?? resolveInstant(date, time, sourceTimezone)).find(part => part.type === 'timeZoneName')?.value || '';
}

/**
 * Return both display fields together so timezone conversion cannot discard
 * the customer calendar date. Slot storage and submission stay in tenant time.
 *
 * @param {string} slotTime - 'HH:MM' in tenant timezone
 * @param {string} slotDate - 'YYYY-MM-DD' in tenant timezone
 * @param {string} tenantTz - IANA timezone identifier
 * @param {string} customerTz - IANA timezone identifier
 * @returns {{ date: string, time: string }}
 */
export function slotDisplayDatetime(slotTime, slotDate, tenantTz, customerTz, utcInstant = null) {
    const instant = authoritativeInstant(utcInstant);
    if (instant !== null) return zonedParts(instant, customerTz);
    return convertTime(slotDate, slotTime, tenantTz, customerTz);
}

/**
 * Format a slot time for display in the customer's timezone.
 *
 * @param {string} slotTime - 'HH:MM' in tenant timezone
 * @param {string} slotDate - 'YYYY-MM-DD' in tenant timezone
 * @param {string} tenantTz - IANA timezone identifier
 * @param {string} customerTz - IANA timezone identifier
 * @returns {string} 'HH:MM' in customer timezone
 */
export function formatSlotDisplay(slotTime, slotDate, tenantTz, customerTz) {
    return slotDisplayDatetime(slotTime, slotDate, tenantTz, customerTz).time;
}
