/**
 * M134 (R-62b638e1) — the TypeScript twin of tests/Unit/Expressions/TemporalTest.php. The golden corpus pins the
 * cross-engine verdicts; this pins the parse against an independent calendar (`Date.UTC`, never a local-time
 * parse) so a wrong day count cannot hide behind a vector comparing two nearby dates.
 */

import { describe, expect, it } from 'vitest';

import { ordersTemporally, parseTemporal } from '../temporal';

describe('the strict temporal parse', () => {
    it('parses the three shapes and nothing else', () => {
        expect(parseTemporal('2026-07-11')).toMatchObject({ kind: 'date', zoned: false });
        expect(parseTemporal('09:30:15')).toMatchObject({ kind: 'time', zoned: false });
        expect(parseTemporal('2026-07-11T09:30')).toMatchObject({ kind: 'datetime', zoned: false });
        expect(parseTemporal('2026-07-11T09:30:00+00:00')).toMatchObject({ kind: 'datetime', zoned: true });
    });

    it.each([
        20260711, '2026', null, ['2026-07-11'], true, '2026-07-11\n', ' 2026-07-11', '2026-07-11 09:30', '2026-07-11t09:30',
        '2026-07-11T09:30z', '09:30:00.5', '24:00', '09:60', '09:30:60', '2026-7-11', '20260-07-11', '0000-01-01', '2026-13-01',
        '2026-01-32', '2026-02-29', '1900-02-29', '2026-04-31', '2026-07-11T09:30+08', '2026-07-11T09:30+24:00',
        '2026-07-11+08:00', '09:30Z',
    ])('refuses %j rather than guessing', (value) => {
        expect(parseTemporal(value as never)).toBeNull();
    });

    it('counts days exactly as Date.UTC does, across every kind of month and leap boundary', () => {
        const epoch = Date.UTC(2000, 0, 1);
        const epochKey = parseTemporal('2000-01-01')!.key;
        let checked = 0;

        for (const year of [1, 2, 3, 4, 99, 100, 101, 399, 400, 401, 1582, 1600, 1700, 1900, 2000, 2024, 2026, 2100, 2400, 9999]) {
            for (const [month, day] of [[1, 1], [2, 28], [2, 29], [3, 1], [6, 30], [12, 31]]) {
                const iso = `${String(year).padStart(4, '0')}-${String(month).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const oracle = new Date(Date.UTC(2000, month - 1, day));
                oracle.setUTCFullYear(year);
                const real = oracle.getUTCMonth() === month - 1 && oracle.getUTCDate() === day;

                if (!real) {
                    expect(parseTemporal(iso), iso).toBeNull();
                    continue;
                }

                expect(((parseTemporal(iso)!.key - epochKey) / 86400), iso).toBe((oracle.getTime() - epoch) / 86_400_000);
                checked++;
            }
        }

        expect(checked).toBeGreaterThan(100);
    });

    it('reads an offset as an instant, and a date as its midnight', () => {
        expect(parseTemporal('2026-07-11T10:00+08:00')!.key).toBe(parseTemporal('2026-07-11T02:00Z')!.key);
        expect(parseTemporal('2026-07-10T23:30-03:00')!.key).toBe(parseTemporal('2026-07-11T02:30:00+00:00')!.key);
        expect(parseTemporal('2026-07-11')!.key).toBe(parseTemporal('2026-07-11T00:00')!.key);
    });

    it('orders only comparable pairs — never naive against zoned, never a time against a date', () => {
        expect(ordersTemporally('2026-07-11', '2026-07-10', 'gt')).toBe(true);
        expect(ordersTemporally('2026-07-11T09:00', '2026-07-11', 'gt')).toBe(true);
        expect(ordersTemporally('09:00', '08:59:59', 'gt')).toBe(true);

        for (const op of ['gt', 'lt', 'gte', 'lte'] as const) {
            expect(ordersTemporally('2026-07-11', '2026-07-11T00:00:00+00:00', op)).toBe(false);
            expect(ordersTemporally('09:00', '2026-07-11', op)).toBe(false);
            expect(ordersTemporally('09:00', '09:00Z', op)).toBe(false);
        }
    });
});
