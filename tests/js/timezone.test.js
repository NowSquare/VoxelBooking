/**
 * Timezone conversion engine tests.
 *
 * These tests exercise the actual customer-time display path:
 * convertTime(), getTimezoneOffsetMinutes(), and formatSlotDisplay().
 *
 * Coverage focus:
 * - Same-timezone passthrough (no conversion)
 * - Standard offset conversion (Amsterdam → New York during winter / CET→EST)
 * - DST transition edges (Amsterdam → New York during summer / CEST→EDT)
 * - Cross-date conversion: late-night tenant slots that fall on the previous
 *   or next local day for the customer
 * - Half-hour offset zones (Asia/Kolkata = UTC+5:30)
 * - Extreme offset zones (Pacific/Auckland = UTC+12/+13)
 * - formatSlotDisplay() same-zone short-circuit
 * - formatSlotDisplay() cross-zone time transformation
 *
 * IMPORTANT: These tests use fixed dates to pin DST state. Europe/Amsterdam
 * observes CET (UTC+1) in winter and CEST (UTC+2) in summer. America/New_York
 * observes EST (UTC-5) in winter and EDT (UTC-4) in summer.
 *
 * 2026 DST transitions:
 * - US: Spring forward 2nd Sunday March (Mar 8), Fall back 1st Sunday Nov (Nov 1)
 * - EU: Spring forward last Sunday March (Mar 29), Fall back last Sunday Oct (Oct 25)
 */
import { describe, it, expect } from 'vitest';
import { execFileSync } from 'node:child_process';
import { convertTime, getTimezoneOffsetMinutes, formatSlotDisplay, slotDisplayDatetime, timezoneOffsetName } from '../../resources/js/booking/timezone.js';

describe('appointment offset labels', () => {
    it('uses the authoritative server instant for a repeated Amsterdam hour', () => {
        expect(slotDisplayDatetime('02:30', '2026-10-25', 'Europe/Amsterdam', 'UTC', '2026-10-25T01:30:00Z'))
            .toEqual({ date: '2026-10-25', time: '01:30' });
        expect(timezoneOffsetName('2026-10-25', '02:30', 'Europe/Amsterdam', 'Europe/Amsterdam', '2026-10-25T01:30:00Z')).toBe('GMT+1');
    });
    it('retains the complete end date for an overnight event', () => {
        expect(slotDisplayDatetime('01:00', '2026-10-24', 'Europe/Amsterdam', 'UTC', '2026-10-24T23:00:00Z'))
            .toEqual({ date: '2026-10-24', time: '23:00' });
    });
    it.each([
        ['2026-10-25', '00:30', 'UTC', 'Europe/Berlin', 'GMT+2'],
        ['2026-10-25', '01:30', 'UTC', 'Europe/Berlin', 'GMT+1'],
        ['2026-11-01', '05:30', 'UTC', 'America/New_York', 'GMT-4'],
        ['2026-11-01', '06:30', 'UTC', 'America/New_York', 'GMT-5'],
    ])('uses the offset at %s %s in %s for %s', (date, time, source, target, expected) => {
        expect(timezoneOffsetName(date, time, source, target)).toBe(expected);
    });
});

// ════════════════════════════════════════════════════════════════
// convertTime()
// ════════════════════════════════════════════════════════════════

describe('convertTime', () => {
    it.each([
        ['2026-03-08', '03:30', 'America/New_York', 'UTC', '2026-03-08', '07:30'],
        ['2026-03-29', '01:30', 'UTC', 'Europe/Amsterdam', '2026-03-29', '03:30'],
        ['2026-10-25', '02:30', 'Europe/Amsterdam', 'UTC', '2026-10-25', '00:30'],
        ['2026-11-01', '06:30', 'UTC', 'America/New_York', '2026-11-01', '01:30'],
    ])('uses the offset at the resolved instant for %s %s in %s', (date, time, from, to, displayDate, displayTime) => {
        expect(convertTime(date, time, from, to)).toEqual({ date: displayDate, time: displayTime });
    });

    it('moves nonexistent spring-forward wall times ahead by the gap', () => {
        expect(convertTime('2026-03-08', '02:30', 'America/New_York', 'UTC'))
            .toEqual({ date: '2026-03-08', time: '07:30' });
        expect(convertTime('2026-03-29', '02:30', 'Europe/Amsterdam', 'UTC'))
            .toEqual({ date: '2026-03-29', time: '01:30' });
    });

    it('uses the earlier occurrence of an ambiguous fall-back wall time', () => {
        expect(convertTime('2026-11-01', '01:30', 'America/New_York', 'UTC'))
            .toEqual({ date: '2026-11-01', time: '05:30' });
    });

    it.each(['UTC', 'America/New_York', 'Europe/Amsterdam', 'Pacific/Auckland'])('does not depend on the process timezone %s', (hostTz) => {
        const moduleUrl = new URL('../../resources/js/booking/timezone.js', import.meta.url).href;
        const source = `import { convertTime, getTimezoneOffsetMinutes } from ${JSON.stringify(moduleUrl)};
            console.log(JSON.stringify({
                spring: convertTime('2026-03-08', '03:30', 'America/New_York', 'UTC'),
                midnight: convertTime('2026-10-21', '00:30', 'Europe/Amsterdam', 'America/New_York'),
                offset: getTimezoneOffsetMinutes('2026-01-15', '14:00', 'Europe/Amsterdam'),
            }));`;
        const result = execFileSync(process.execPath, ['--input-type=module', '-e', source], {
            env: { ...process.env, TZ: hostTz }, encoding: 'utf8',
        });

        expect(JSON.parse(result)).toEqual({
            spring: { date: '2026-03-08', time: '07:30' },
            midnight: { date: '2026-10-20', time: '18:30' },
            offset: -60,
        });
    });

    it('returns input unchanged when fromTz === toTz', () => {
        const result = convertTime('2026-06-15', '14:30', 'Europe/Amsterdam', 'Europe/Amsterdam');
        expect(result).toEqual({ date: '2026-06-15', time: '14:30' });
    });

    // ── Winter (standard time): CET (UTC+1) → EST (UTC-5) ──
    // Difference: 6 hours behind
    describe('winter (standard time) — Amsterdam CET to New York EST', () => {
        // Jan 15 2026: Amsterdam = CET (UTC+1), New York = EST (UTC-5)
        // 14:00 CET → 08:00 EST (same day)
        it('converts afternoon Amsterdam to morning New York (same day)', () => {
            const result = convertTime('2026-01-15', '14:00', 'Europe/Amsterdam', 'America/New_York');
            expect(result.date).toBe('2026-01-15');
            expect(result.time).toBe('08:00');
        });

        // 09:00 CET → 03:00 EST (same day)
        it('converts morning Amsterdam to early morning New York', () => {
            const result = convertTime('2026-01-15', '09:00', 'Europe/Amsterdam', 'America/New_York');
            expect(result.date).toBe('2026-01-15');
            expect(result.time).toBe('03:00');
        });

        // 03:00 CET → 21:00 EST previous day (cross-date backward)
        it('converts early morning Amsterdam to previous evening New York (cross-date)', () => {
            const result = convertTime('2026-01-15', '03:00', 'Europe/Amsterdam', 'America/New_York');
            expect(result.date).toBe('2026-01-14');
            expect(result.time).toBe('21:00');
        });
    });

    // ── Summer (daylight saving): CEST (UTC+2) → EDT (UTC-4) ──
    // Difference: 6 hours behind
    describe('summer (DST) — Amsterdam CEST to New York EDT', () => {
        // Jul 15 2026: Amsterdam = CEST (UTC+2), New York = EDT (UTC-4)
        // 14:00 CEST → 08:00 EDT (same day)
        it('converts afternoon Amsterdam to morning New York (same day)', () => {
            const result = convertTime('2026-07-15', '14:00', 'Europe/Amsterdam', 'America/New_York');
            expect(result.date).toBe('2026-07-15');
            expect(result.time).toBe('08:00');
        });

        // 20:30 CEST → 14:30 EDT (same day, late slot)
        it('converts evening Amsterdam to afternoon New York', () => {
            const result = convertTime('2026-07-15', '20:30', 'Europe/Amsterdam', 'America/New_York');
            expect(result.date).toBe('2026-07-15');
            expect(result.time).toBe('14:30');
        });

        // 03:00 CEST → 21:00 EDT previous day (cross-date backward)
        it('converts early morning Amsterdam to previous evening New York (cross-date)', () => {
            const result = convertTime('2026-07-15', '03:00', 'Europe/Amsterdam', 'America/New_York');
            expect(result.date).toBe('2026-07-14');
            expect(result.time).toBe('21:00');
        });
    });

    // ── Reverse: New York → Amsterdam ──
    describe('reverse direction — New York to Amsterdam', () => {
        // Winter: 14:00 EST → 20:00 CET (same day)
        it('converts afternoon New York to evening Amsterdam (winter)', () => {
            const result = convertTime('2026-01-15', '14:00', 'America/New_York', 'Europe/Amsterdam');
            expect(result.date).toBe('2026-01-15');
            expect(result.time).toBe('20:00');
        });

        // Winter: 20:00 EST → 02:00 CET next day (cross-date forward)
        it('converts late evening New York to next morning Amsterdam (cross-date)', () => {
            const result = convertTime('2026-01-15', '20:00', 'America/New_York', 'Europe/Amsterdam');
            expect(result.date).toBe('2026-01-16');
            expect(result.time).toBe('02:00');
        });

        // Summer: 14:00 EDT → 20:00 CEST (same day)
        it('converts afternoon New York to evening Amsterdam (summer)', () => {
            const result = convertTime('2026-07-15', '14:00', 'America/New_York', 'Europe/Amsterdam');
            expect(result.date).toBe('2026-07-15');
            expect(result.time).toBe('20:00');
        });
    });

    // ── DST transition edges ──
    // The critical case: dates where one zone has switched but the other hasn't
    describe('DST transition edges (asymmetric switches)', () => {
        // Mar 15 2026: US has sprung forward (EDT, UTC-4), EU still on CET (UTC+1)
        // Offset = 5 hours (not the usual 6)
        // 14:00 CET → 09:00 EDT
        it('handles asymmetric DST: US on DST, EU not yet (Mar 15)', () => {
            const result = convertTime('2026-03-15', '14:00', 'Europe/Amsterdam', 'America/New_York');
            expect(result.date).toBe('2026-03-15');
            expect(result.time).toBe('09:00');
        });

        // Nov 2 2026: US has fallen back (EST, UTC-5), EU still on CEST (UTC+2)
        // Wait — EU falls back Oct 25, so by Nov 2 both are on standard.
        // Let's use Oct 26 2025 instead: EU has fallen back (CET, UTC+1),
        // US still on EDT (UTC-4). Offset = 5 hours.
        // Actually in 2026: EU falls back last Sun Oct = Oct 25,
        // US falls back first Sun Nov = Nov 1.
        // So Oct 27 2026: EU on CET (UTC+1), US on EDT (UTC-4). Offset = 5.
        it('handles asymmetric DST: EU fell back, US still on DST (Oct 27)', () => {
            const result = convertTime('2026-10-27', '14:00', 'Europe/Amsterdam', 'America/New_York');
            expect(result.date).toBe('2026-10-27');
            expect(result.time).toBe('09:00');
        });
    });

    // ── Half-hour offset zones ──
    describe('half-hour offset zones', () => {
        // Asia/Kolkata is UTC+5:30 year-round (no DST)
        // Amsterdam winter (CET, UTC+1): 14:00 → 18:30 IST
        it('converts Amsterdam to Kolkata with +4:30 offset (winter)', () => {
            const result = convertTime('2026-01-15', '14:00', 'Europe/Amsterdam', 'Asia/Kolkata');
            expect(result.date).toBe('2026-01-15');
            expect(result.time).toBe('18:30');
        });

        // Amsterdam summer (CEST, UTC+2): 14:00 → 17:30 IST
        it('converts Amsterdam to Kolkata with +3:30 offset (summer)', () => {
            const result = convertTime('2026-07-15', '14:00', 'Europe/Amsterdam', 'Asia/Kolkata');
            expect(result.date).toBe('2026-07-15');
            expect(result.time).toBe('17:30');
        });

        // Late night Kolkata → early morning Amsterdam (cross-date)
        // 23:30 IST (UTC+5:30) in winter → 19:00 CET (UTC+1) same day
        it('converts late Kolkata to evening Amsterdam', () => {
            const result = convertTime('2026-01-15', '23:30', 'Asia/Kolkata', 'Europe/Amsterdam');
            expect(result.date).toBe('2026-01-15');
            expect(result.time).toBe('19:00');
        });
    });

    // ── Extreme offset: Pacific/Auckland ──
    describe('extreme offset zones (Pacific/Auckland)', () => {
        // Auckland winter (NZST, UTC+12): Amsterdam CET (UTC+1)
        // 14:00 CET → 02:00+1 NZST (11 hours ahead, crosses date)
        it('converts Amsterdam afternoon to next morning Auckland (winter, cross-date)', () => {
            const result = convertTime('2026-01-15', '14:00', 'Europe/Amsterdam', 'Pacific/Auckland');
            // Auckland is UTC+13 in January (NZDT — NZ summer is Jan)
            // 14:00 CET (UTC+1) = 13:00 UTC = 02:00+1 NZDT (UTC+13)
            expect(result.date).toBe('2026-01-16');
            expect(result.time).toBe('02:00');
        });

        // Auckland in July (NZST, UTC+12): Amsterdam CEST (UTC+2)
        // 09:00 CEST = 07:00 UTC = 19:00 NZST
        it('converts Amsterdam morning to Auckland evening (July)', () => {
            const result = convertTime('2026-07-15', '09:00', 'Europe/Amsterdam', 'Pacific/Auckland');
            expect(result.date).toBe('2026-07-15');
            expect(result.time).toBe('19:00');
        });
    });

    // ── Cross-date: late-night tenant slots ──
    describe('late-night slots that cross date boundaries', () => {
        // 23:30 Amsterdam CET → 17:30 EST same day (no cross)
        it('late night Amsterdam → afternoon New York (winter, no cross)', () => {
            const result = convertTime('2026-01-15', '23:30', 'Europe/Amsterdam', 'America/New_York');
            expect(result.date).toBe('2026-01-15');
            expect(result.time).toBe('17:30');
        });

        // 23:00 New York EST → 05:00+1 Amsterdam CET (cross-date forward)
        it('late night New York → next morning Amsterdam (winter, cross forward)', () => {
            const result = convertTime('2026-01-15', '23:00', 'America/New_York', 'Europe/Amsterdam');
            expect(result.date).toBe('2026-01-16');
            expect(result.time).toBe('05:00');
        });

        // 00:30 Amsterdam CET → 18:30 previous day EST (cross-date backward)
        it('just after midnight Amsterdam → previous evening New York (cross backward)', () => {
            const result = convertTime('2026-01-15', '00:30', 'Europe/Amsterdam', 'America/New_York');
            expect(result.date).toBe('2026-01-14');
            expect(result.time).toBe('18:30');
        });
    });

    // ── London-specific: UTC+0 in winter, UTC+1 in summer ──
    describe('Europe/London (UTC+0 winter / UTC+1 summer)', () => {
        // Winter: 14:00 Amsterdam CET (UTC+1) → 13:00 London GMT (UTC+0)
        it('converts Amsterdam to London in winter (1hr behind)', () => {
            const result = convertTime('2026-01-15', '14:00', 'Europe/Amsterdam', 'Europe/London');
            expect(result.date).toBe('2026-01-15');
            expect(result.time).toBe('13:00');
        });

        // Summer: 14:00 Amsterdam CEST (UTC+2) → 13:00 London BST (UTC+1)
        it('converts Amsterdam to London in summer (1hr behind)', () => {
            const result = convertTime('2026-07-15', '14:00', 'Europe/Amsterdam', 'Europe/London');
            expect(result.date).toBe('2026-07-15');
            expect(result.time).toBe('13:00');
        });
    });
});

// ════════════════════════════════════════════════════════════════
// getTimezoneOffsetMinutes()
// ════════════════════════════════════════════════════════════════

describe('getTimezoneOffsetMinutes', () => {
    it.each([
        ['2026-01-15', '14:00', 'Europe/Amsterdam', -60],
        ['2026-07-15', '14:00', 'Europe/Amsterdam', -120],
        ['2026-03-08', '01:30', 'America/New_York', 300],
        ['2026-03-08', '03:30', 'America/New_York', 240],
        ['2026-11-01', '01:30', 'America/New_York', 240],
        ['2026-01-15', '14:00', 'Asia/Kolkata', -330],
    ])('returns the UTC-minus-local offset for %s %s in %s', (date, time, zone, expected) => {
        expect(getTimezoneOffsetMinutes(date, time, zone)).toBe(expected);
    });
    // Amsterdam CET = UTC+1 → offset should cause local to be 1 hour ahead
    it('returns correct offset for Amsterdam in winter (CET, UTC+1)', () => {
        const offset = getTimezoneOffsetMinutes('2026-01-15', '14:00', 'Europe/Amsterdam');
        expect(offset).toBe(-60);
    });

    // The offsets between two zones should produce a consistent difference
    it('Amsterdam-NY offset difference is 6 hours in winter (CET-EST)', () => {
        const amsOffset = getTimezoneOffsetMinutes('2026-01-15', '14:00', 'Europe/Amsterdam');
        const nyOffset = getTimezoneOffsetMinutes('2026-01-15', '14:00', 'America/New_York');
        // CET (UTC+1) vs EST (UTC-5) = 6 hours = 360 minutes
        expect(amsOffset - nyOffset).toBe(-360);
    });

    it('Amsterdam-NY offset difference is 6 hours in summer (CEST-EDT)', () => {
        const amsOffset = getTimezoneOffsetMinutes('2026-07-15', '14:00', 'Europe/Amsterdam');
        const nyOffset = getTimezoneOffsetMinutes('2026-07-15', '14:00', 'America/New_York');
        // CEST (UTC+2) vs EDT (UTC-4) = 6 hours = 360 minutes
        expect(amsOffset - nyOffset).toBe(-360);
    });

    it('Amsterdam-NY offset difference is 5 hours during asymmetric DST (Mar 15)', () => {
        // US has sprung forward (EDT), EU still on CET
        // CET (UTC+1) vs EDT (UTC-4) = 5 hours = 300 minutes
        const amsOffset = getTimezoneOffsetMinutes('2026-03-15', '14:00', 'Europe/Amsterdam');
        const nyOffset = getTimezoneOffsetMinutes('2026-03-15', '14:00', 'America/New_York');
        expect(amsOffset - nyOffset).toBe(-300);
    });

    it('Kolkata offset includes 30-minute component', () => {
        const amsOffset = getTimezoneOffsetMinutes('2026-01-15', '14:00', 'Europe/Amsterdam');
        const kolkataOffset = getTimezoneOffsetMinutes('2026-01-15', '14:00', 'Asia/Kolkata');
        // CET (UTC+1) vs IST (UTC+5:30) = 4:30 = 270 minutes
        expect(kolkataOffset - amsOffset).toBe(-270);
    });
});

describe('slotDisplayDatetime', () => {
    it('retains the customer calendar date when a slot falls on the previous day', () => {
        expect(slotDisplayDatetime('00:30', '2026-10-21', 'Europe/Amsterdam', 'America/New_York'))
            .toEqual({ date: '2026-10-20', time: '18:30' });
    });

    it('retains the customer calendar date when a slot falls on the next day', () => {
        expect(slotDisplayDatetime('23:30', '2026-01-15', 'America/New_York', 'Europe/Amsterdam'))
            .toEqual({ date: '2026-01-16', time: '05:30' });
    });

    it('returns a complete datetime unchanged when the zones match', () => {
        expect(slotDisplayDatetime('14:30', '2026-01-15', 'Europe/Amsterdam', 'Europe/Amsterdam'))
            .toEqual({ date: '2026-01-15', time: '14:30' });
    });
});

// ════════════════════════════════════════════════════════════════
// formatSlotDisplay()
// ════════════════════════════════════════════════════════════════

describe('formatSlotDisplay', () => {
    it('returns slotTime unchanged when timezones match', () => {
        const result = formatSlotDisplay('14:30', '2026-01-15', 'Europe/Amsterdam', 'Europe/Amsterdam');
        expect(result).toBe('14:30');
    });

    it('converts slot time from tenant to customer timezone (winter)', () => {
        // 14:00 CET → 08:00 EST
        const result = formatSlotDisplay('14:00', '2026-01-15', 'Europe/Amsterdam', 'America/New_York');
        expect(result).toBe('08:00');
    });

    it('converts slot time from tenant to customer timezone (summer)', () => {
        // 14:00 CEST → 08:00 EDT
        const result = formatSlotDisplay('14:00', '2026-07-15', 'Europe/Amsterdam', 'America/New_York');
        expect(result).toBe('08:00');
    });

    it('converts with half-hour offset (Kolkata)', () => {
        // 14:00 CET → 18:30 IST
        const result = formatSlotDisplay('14:00', '2026-01-15', 'Europe/Amsterdam', 'Asia/Kolkata');
        expect(result).toBe('18:30');
    });

    it('handles cross-date scenario (returns only time portion)', () => {
        // 03:00 CET → 21:00 EST (previous day) — formatSlotDisplay returns time only
        const result = formatSlotDisplay('03:00', '2026-01-15', 'Europe/Amsterdam', 'America/New_York');
        expect(result).toBe('21:00');
    });
});
