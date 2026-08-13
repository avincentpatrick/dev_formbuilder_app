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
/**
 * ⚠️ DERIVED FROM THE MAP, NOT RE-TYPED BESIDE IT — AND THE PREVIOUS VERSION IS WHY.
 * This was a hand-written list of seven literals, and a test literally named "gives all seven server types
 * a visual" that compared the map's keys against it. Both were edited together, so when I11b added
 * `impersonation_started` to the PHP enum and touched neither, the assertion went on passing: it only ever
 * proved that two hand-maintained copies of the same list agreed with each other.
 *
 * Completeness is now a COMPILE-TIME property — `NOTIFICATION_VISUAL` is `Record<NotificationTypeKey, …>`,
 * so a missing case fails `npm run type-check` — and parity with the PHP enum is `NotificationTypeParityTest`
 * on the server side, which is the only place that can read both. What is left for this file is what it was
 * always uniquely able to check: that the glyphs exist, that they are distinct, and that the colour bands
 * still say what the docblock claims.
 */
const SERVER_TYPES = Object.keys(NOTIFICATION_VISUAL);

describe('notificationVisual', () => {
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

    it('bands each type by the question it asks the reader, per the map’s own docblock', () => {
        const byVariant = (variant: string): string[] =>
            SERVER_TYPES.filter((type) => notificationVisual(type).variant === variant).sort();

        // warning is now THREE: the two "you owe an action" types plus `impersonation_started`, which is a
        // disclosure the Owner is meant to notice rather than a routine happening.
        expect(byVariant('warning')).toEqual(['impersonation_started', 'review_requested', 'submission_returned']);
        expect(byVariant('success')).toEqual(['export_ready', 'submission_approved']);
        expect(byVariant('info')).toEqual(['member_invited', 'member_joined', 'submission_received']);
    });

    it('gives the invited/joined pair different glyphs, because they are a sequence and not a synonym', () => {
        // The same person is usually both, minutes apart, in the same popover. Sharing `user-plus` would
        // make the second row look like a duplicate of the first — which is also what the uniqueness
        // assertion above enforces generally, restated here because THIS pair is the one likely to be
        // "tidied" by someone who reads them as the same event.
        expect(notificationVisual('member_invited').icon).not.toBe(notificationVisual('member_joined').icon);
    });
});
