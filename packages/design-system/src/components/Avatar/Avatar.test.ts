import { mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import Avatar from './Avatar.vue';
import { avatarInitials } from './initials';

/**
 * MdsAvatar (DSR §3.12, J4a).
 *
 * The derivation is table-tested against the helper directly, because most of the rules are about SCRIPTS
 * and an ASCII-only fixture set cannot tell a correct implementation from the broken one this component
 * replaces — `AccountMenu` indexed with `[0]`, which takes half a surrogate pair.
 */
describe('avatarInitials', () => {
    it.each([
        [null, '?', 'a missing name renders a placeholder, never an empty chip'],
        ['', '?', 'an empty string is the same case'],
        ['   ', '?', 'and so is whitespace'],
        ['Demo Owner', 'DO', 'the ordinary two-word case'],
        ['maria cruz', 'MC', 'upper-cased for the chip'],
        ['Maria de los Santos', 'MS', 'FIRST and LAST, not the first two words'],
        ['Prince', 'P', 'one word gives ONE grapheme, never a two-letter acronym'],
        ['山田太郎', '山', 'a CJK mononym is one grapheme, which is the surname'],
        ['أحمد علي', 'أع', 'a script with no case mapping passes through unchanged'],
        ['ana.cruz', 'A', 'the invite placeholder the server derives from an email'],
        ['!!!', '?', 'a name with no letter or number at all falls back'],
        ['🔬 Curie', 'C', 'a leading emoji is skipped rather than framed in a circle'],
        ['4chan Poster', '4P', 'a digit is a legitimate initial'],
    ])('turns %j into %j — %s', (input, expected) => {
        expect(avatarInitials(input)).toBe(expected);
    });

    it('takes a whole grapheme, not half a surrogate pair', () => {
        // ⭐ THE ASSERTION THE ASCII ROWS CANNOT MAKE, AND THE ONE THAT PINS THE LIVE BUG. `AccountMenu`
        // indexed with `[0]`, which takes a lone high surrogate for any name outside the BMP and renders
        // U+FFFD — the replacement character, in the workspace's own account menu. Every ASCII row above
        // passes against that implementation too, so without this case the suite would be green on the
        // defect it exists to fix.
        //
        // ⚠️ The fixture is an astral LETTER (MATHEMATICAL BOLD CAPITAL A, U+1D400) rather than an emoji:
        // the letter/number filter now skips a leading emoji, so an emoji fixture would prove the filter
        // works and say nothing about surrogate handling.
        const result = avatarInitials('\u{1D400}lice Smith');

        expect(result).not.toContain('�');
        expect(result).toBe('\u{1D400}S');
    });
});

describe('MdsAvatar', () => {
    it('is decorative for every prop combination, with no way to opt out', () => {
        // ⭐ THE WHOLE ACCESSIBILITY CONTRACT. The name is always rendered beside the chip, so announcing
        // the initials too is duplication; and there is deliberately no `label` prop, because an avatar
        // that must carry a name is a link whose accessible name is the person, not an avatar.
        for (const props of [
            { name: 'Demo Owner' },
            { name: null },
            { name: 'Guest', tone: 'neutral' as const },
            { name: 'Demo Owner', size: 'lg' as const },
        ]) {
            const wrapper = mount(Avatar, { props });

            expect(wrapper.attributes('aria-hidden')).toBe('true');
            expect(wrapper.attributes('role')).toBeUndefined();
            expect(wrapper.attributes('aria-label')).toBeUndefined();
        }
    });

    it('renders exactly what the helper derives, rather than re-implementing it', () => {
        const wrapper = mount(Avatar, { props: { name: 'Maria de los Santos' } });

        expect(wrapper.text()).toBe(avatarInitials('Maria de los Santos'));
    });

    it('defaults to the small size and the brand tone', () => {
        const wrapper = mount(Avatar, { props: { name: 'Demo Owner' } });

        expect(wrapper.classes()).toContain('mds-avatar--sm');
        expect(wrapper.classes()).toContain('mds-avatar--brand');
    });
});

describe('MdsAvatar — the geometry and colour contracts, held in source text', () => {
    const source = readFileSync(
        join(process.cwd(), 'packages/design-system/src/components/Avatar/Avatar.vue'),
        'utf8',
    );

    // Scoped to the stylesheet: the docblock names the token families it rejects, and a whole-file negative
    // would match the reasoning rather than the code. See `Progress.test.ts` for the three times this repo
    // has paid for quoting a forbidden literal in the prose warning against it.
    const stylesheet = source.slice(source.indexOf('<style'));

    const block = (selector: string): string =>
        stylesheet.match(new RegExp(`${selector.replace(/\./g, '\\.')}\\s*\\{([^}]*)\\}`))?.[1] ?? '';

    it('sizes with a minimum and a ratio, never a fixed box', () => {
        // ⭐ `NotificationBell.vue:288` records what a pinned box costs: the caption role is 12px by default
        // and 15px under `[data-font-size="extra_large"]`, which put 17px of text in an 18px line box and
        // clipped descenders (WCAG 1.4.12). happy-dom computes no layout, so nothing else in the repo can
        // see this — `personalization-axe` renders the axis but cannot fail on a clipped glyph.
        for (const size of ['sm', 'md', 'lg']) {
            expect(block(`.mds-avatar--${size}`)).toMatch(/min-inline-size:\s*\d+px/);
            expect(block(`.mds-avatar--${size}`)).toMatch(/min-block-size:\s*\d+px/);
            expect(block(`.mds-avatar--${size}`)).toMatch(/aspect-ratio:\s*1/);
        }

        // ⚠️ `\b` IS THE WRONG BOUNDARY HERE AND THE FIRST DRAFT USED IT. A hyphen is a non-word character,
        // so `\bheight:` matches inside `line-height:` — the assertion failed against a correct stylesheet
        // and would have been "fixed" by deleting a line-height the chip needs. The lookbehind asks the
        // question that was actually meant: a property named exactly `width` or `height`.
        expect(stylesheet).not.toMatch(/(?<![-\w])width:/);
        expect(stylesheet).not.toMatch(/(?<![-\w])height:/);
    });

    it('keeps the md size at the 28px the account menu already wore', () => {
        // The shell must stay pixel-identical through the migration; `NotificationBell`'s count bubble is
        // dimensioned as this element's sibling and would be thrown off by a change here.
        expect(block('.mds-avatar--md')).toMatch(/min-inline-size:\s*28px/);
    });

    it('paints from two measured pairs and never from the form-identity scale', () => {
        // ⭐ Reusing `--mds-form-identity-*` measures 2.91:1 under white text in dark, and would put a
        // person and a form at 0° in a scale whose own suite proves every member is 30° from every other.
        expect(block('.mds-avatar--brand')).toMatch(/background-color:\s*var\(--mds-color-action-primary-bg\)/);
        expect(block('.mds-avatar--brand')).toMatch(/color:\s*var\(--mds-color-text-on-primary\)/);
        expect(block('.mds-avatar--neutral')).toMatch(/background-color:\s*var\(--mds-color-status-neutral-bg\)/);
        expect(block('.mds-avatar--neutral')).toMatch(/color:\s*var\(--mds-color-status-neutral-fg\)/);
        expect(stylesheet).not.toMatch(/form-identity/);
    });

    it('hides nothing with the clip idiom, so it needs no containing-block guard', () => {
        expect(stylesheet).not.toContain('clip: rect(0 0 0 0)');
    });
});
