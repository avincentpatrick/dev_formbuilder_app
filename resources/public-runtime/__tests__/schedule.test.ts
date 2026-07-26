import { describe, expect, it } from 'vitest';
import {
    acceptanceForReasonCode,
    formatInstantInZone,
    hasScheduleConstraint,
    scheduleStateCopy,
} from '../lib/schedule';
import type { ScheduleBlock } from '../lib/types';

function block(overrides: Partial<ScheduleBlock> = {}): ScheduleBlock {
    return {
        opens_at: null,
        closes_at: null,
        timezone: 'UTC',
        max_responses: null,
        acceptance: 'open',
        remaining: null,
        ...overrides,
    };
}

describe('hasScheduleConstraint', () => {
    it('is false for an undefined block or an unconstrained form', () => {
        expect(hasScheduleConstraint(undefined)).toBe(false);
        expect(hasScheduleConstraint(block())).toBe(false);
    });

    it('is true when any window bound or the cap is set', () => {
        expect(hasScheduleConstraint(block({ opens_at: '2026-08-01T00:00:00Z' }))).toBe(true);
        expect(hasScheduleConstraint(block({ closes_at: '2026-08-01T00:00:00Z' }))).toBe(true);
        expect(hasScheduleConstraint(block({ max_responses: 10 }))).toBe(true);
    });
});

describe('acceptanceForReasonCode', () => {
    it('maps write-path 403 reason codes to acceptance states', () => {
        expect(acceptanceForReasonCode('form_closed')).toBe('closed');
        expect(acceptanceForReasonCode('form_not_open')).toBe('opens_soon');
        expect(acceptanceForReasonCode('max_responses_reached')).toBe('capacity_reached');
        expect(acceptanceForReasonCode('something_else')).toBeNull();
    });
});

describe('scheduleStateCopy', () => {
    const fmt = (iso: string, tz: string): string => `${iso}@${tz}`;

    it('returns null for an open form (nothing to show)', () => {
        expect(scheduleStateCopy(block({ acceptance: 'open' }), fmt)).toBeNull();
    });

    it('builds opens-soon copy with the formatted open time', () => {
        const copy = scheduleStateCopy(block({ acceptance: 'opens_soon', opens_at: '2026-08-01T09:00:00Z' }), fmt);
        expect(copy?.headline).toContain('isn’t open');
        expect(copy?.description).toContain('2026-08-01T09:00:00Z@UTC');
        expect(copy?.illustration).toBe('default');
    });

    it('builds closed copy with the formatted close time', () => {
        const copy = scheduleStateCopy(block({ acceptance: 'closed', closes_at: '2026-08-01T09:00:00Z' }), fmt);
        expect(copy?.headline).toContain('closed');
        expect(copy?.description).toContain('2026-08-01T09:00:00Z@UTC');
        expect(copy?.illustration).toBe('lock');
    });

    it('builds capacity copy', () => {
        const copy = scheduleStateCopy(block({ acceptance: 'capacity_reached' }), fmt);
        expect(copy?.headline).toContain('full');
        expect(copy?.illustration).toBe('lock');
    });
});

describe('formatInstantInZone', () => {
    it('renders an instant in the given zone with the zone label', () => {
        // 01:00Z is 09:00 in Asia/Manila (UTC+8).
        const out = formatInstantInZone('2026-08-01T01:00:00Z', 'Asia/Manila', 'en');
        expect(out).toContain('Asia/Manila');
        expect(out).toContain('9:00');
    });

    it('falls back to the raw instant for an invalid zone', () => {
        expect(formatInstantInZone('2026-08-01T01:00:00Z', 'Not/AZone')).toBe('2026-08-01T01:00:00Z');
    });
});
