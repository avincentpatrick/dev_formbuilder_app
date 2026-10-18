import { readdirSync, readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

import { previewLimitations } from './preview-model';

/**
 * M177 (`R-b05fbaae`) — the testers (2026-10-11, comment 1) asked for wording a smart high-schooler understands. DSR §1.5
 * ("Voice", M176) is the rule; this is its guard over the builder's own help, hint and note text: every `help="…"` and
 * `hint="…"` written in a builder component, the Calculated and advanced-type notes, and the preview's list of limits.
 *
 * ⚠️ A GUARD, NOT A SPELL-CHECKER. It refuses the words the testers' screenshots and the census found — the ones that
 * name how the software works rather than what happens to the answers — so one creeping back in goes red. A word the
 * screen itself shows as a control's name is allowed by §1.5, and none of these is one.
 */
const BANNED = [
    /\bevaluated\b/i,
    /\bexpressions?\b/i,
    /\breferenced?\b/i,
    /\breferences\b/i,
    /\bMIME\b/,
    /\bglobs?\b/i,
    /\bplatform default\b/i,
    /\bbaseline\b/i,
    /\bruntime\b/i,
    /\bconfig panel\b/i,
    /\bconstrain\b/i,
    /\bstructural change\b/i,
    /^Blank\b/,
    /\bsoft reminder\b/i,
    /\bset by the server\b/i,
];

const dir = join(process.cwd(), 'resources', 'js', 'components', 'builder');

function helpTexts(): { where: string; text: string }[] {
    const found: { where: string; text: string }[] = [];
    for (const file of readdirSync(dir).filter((name) => name.endsWith('.vue'))) {
        const source = readFileSync(join(dir, file), 'utf-8');
        for (const match of source.matchAll(/\b(?:help|hint)="([^"]+)"/g)) {
            found.push({ where: file, text: match[1] });
        }
        for (const match of source.matchAll(/<p[^>]*class="(?:config__note|cond__help)"[^>]*>([\s\S]*?)<\/p>/g)) {
            found.push({ where: `${file} (note)`, text: match[1].replace(/\s+/g, ' ').trim() });
        }
    }

    return found;
}

describe('the builder speaks in the DSR §1.5 voice (M177)', () => {
    it('reads a non-trivial set of help text, so the guard below is not vacuous', () => {
        expect(helpTexts().length).toBeGreaterThanOrEqual(20);
    });

    it('uses none of the developer words in any help, hint or note', () => {
        const offenders = helpTexts().filter(({ text }) => BANNED.some((word) => word.test(text)));

        expect(offenders).toEqual([]);
    });

    it('lists what the preview cannot do in the same voice', () => {
        expect(previewLimitations().filter((line) => BANNED.some((word) => word.test(line)))).toEqual([]);
    });
});
