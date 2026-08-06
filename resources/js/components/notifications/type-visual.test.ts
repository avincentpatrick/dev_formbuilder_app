import { describe, expect, it } from 'vitest';
import { icons } from '@meridian/design-system';
import { NOTIFICATION_VISUAL, notificationVisual } from './type-visual';

/**
 * The one map in I4 that TypeScript owns rather than PHP, and the two ways it can be silently wrong.
 *
 * A typo'd glyph name renders an empty 24×24 box — `MdsIcon` looks the name up in a record and gets
 * `undefined`, which is not an error anywhere. Nothing else in the pipeline would catch it: the icon is
 * `aria-hidden`, so axe has no opinion, and no Playwright assertion reads it.
 *
 * And the BANDS are a design decision that reads like an accident: two types share `info`, two share
 * `success`, two share `warning`. Writing them down here is what stops the next reader "fixing" the
 * collisions by inventing variants — `BadgeVariant` is closed at five.
 */
const SERVER_TYPES = [
    'submission_received',
    'submission_returned',
    'submission_approved',
    'review_requested',
    'export_ready',
    'member_invited',
    'webhook_failed',
] as const;

describe('notificationVisual', () => {
    it('gives all seven server types a visual', () => {
        expect(Object.keys(NOTIFICATION_VISUAL).sort()).toEqual([...SERVER_TYPES].sort());
    });

    it('uses only glyphs that exist in the shared icon registry', () => {
        for (const type of SERVER_TYPES) {
            const { icon } = notificationVisual(type);

            expect(icons, `${type} names a glyph the icon set does not have: ${icon}`).toHaveProperty(icon);
        }
    });

    it('gives no two types the same glyph, so the popover is scannable', () => {
        const glyphs = SERVER_TYPES.map((type) => notificationVisual(type).icon);

        expect(new Set(glyphs).size).toBe(SERVER_TYPES.length);
    });

    it('falls back to a neutral bell for an unrecognised type instead of throwing', () => {
        // The shell is mounted on EVERY page, so a server-first eighth enum case must render quietly
        // rather than crash the chrome. This is also why NotificationRow.type is typed `string`.
        expect(notificationVisual('submission_teleported')).toEqual({ icon: 'bell', variant: 'neutral' });
    });

    it('reserves danger for the one type that means data is being lost right now', () => {
        const danger = SERVER_TYPES.filter((type) => notificationVisual(type).variant === 'danger');

        expect(danger).toEqual(['webhook_failed']);
    });

    it('bands the two “you owe an action” types warning, and the two “finished” types success', () => {
        const byVariant = (variant: string): string[] =>
            SERVER_TYPES.filter((type) => notificationVisual(type).variant === variant);

        expect(byVariant('warning').sort()).toEqual(['review_requested', 'submission_returned']);
        expect(byVariant('success').sort()).toEqual(['export_ready', 'submission_approved']);
        expect(byVariant('info').sort()).toEqual(['member_invited', 'submission_received']);
    });
});
