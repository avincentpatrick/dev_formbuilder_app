/**
 * Scheduled-form UI logic for the guest runtime (Increment H12b) — the one place the copy, the fail-closed
 * predicate, and the 403-reason mapping live, so App.vue / RuntimeSession stay declarative and this is unit
 * testable. No Vue, no DOM: `formatInstant` is injected so the copy builder stays locale/timezone agnostic.
 */
import type { ScheduleAcceptance, ScheduleBlock } from './types';

/**
 * Whether a form carries any schedule constraint (a window bound or a response cap). Drives the offline
 * fail-closed rule: only constrained forms block an offline submit — their load-time `acceptance` can flip
 * (close / fill up) while offline and can't be re-verified, so we fail closed. An unconstrained form keeps
 * the G8b offline-outbox behaviour untouched.
 */
export function hasScheduleConstraint(schedule: ScheduleBlock | undefined): boolean {
    if (schedule === undefined) return false;
    return schedule.opens_at !== null || schedule.closes_at !== null || schedule.max_responses !== null;
}

/**
 * A write-path 403 reason code → the acceptance state it represents, for the mid-fill case where a form open
 * at load closes / fills before submit. Null for a non-schedule code (handled as a generic terminal error).
 */
export function acceptanceForReasonCode(code: string): ScheduleAcceptance | null {
    switch (code) {
        case 'form_closed':
            return 'closed';
        case 'form_not_open':
            return 'opens_soon';
        case 'max_responses_reached':
            return 'capacity_reached';
        default:
            return null;
    }
}

export interface ScheduleStateCopy {
    /** MdsEmptyState illustration name (the DS set is 'default' | 'search' | 'lock'). */
    illustration: 'default' | 'lock';
    headline: string;
    description: string;
}

/**
 * The full-screen unavailable-state copy for a non-open acceptance. Returns null for `open` (there is no
 * state to show). `formatInstant` renders an ISO instant in the form's timezone for the opens-soon/closed
 * detail line.
 */
export function scheduleStateCopy(
    schedule: ScheduleBlock,
    formatInstant: (iso: string, timeZone: string) => string,
): ScheduleStateCopy | null {
    switch (schedule.acceptance) {
        case 'opens_soon':
            return {
                illustration: 'default',
                headline: 'This form isn’t open yet',
                description: schedule.opens_at
                    ? `It opens on ${formatInstant(schedule.opens_at, schedule.timezone)}.`
                    : 'Please check back later.',
            };
        case 'closed':
            return {
                illustration: 'lock',
                headline: 'This form is closed',
                description: schedule.closes_at
                    ? `It closed on ${formatInstant(schedule.closes_at, schedule.timezone)}.`
                    : 'It is no longer accepting responses.',
            };
        case 'capacity_reached':
            return {
                illustration: 'lock',
                headline: 'This form is full',
                description: 'It has reached its response limit and is no longer accepting responses.',
            };
        default:
            return null;
    }
}

/**
 * Format an ISO instant as a human date-time in the given IANA timezone, appending the zone label so a
 * respondent in another zone isn't misled (e.g. "1 Aug 2026, 09:00 (Asia/Manila)"). Falls back to the raw
 * instant if Intl rejects the zone.
 */
export function formatInstantInZone(iso: string, timeZone: string, locale = 'en'): string {
    try {
        const formatted = new Intl.DateTimeFormat(locale, {
            timeZone,
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(iso));
        return `${formatted} (${timeZone})`;
    } catch {
        return iso;
    }
}
