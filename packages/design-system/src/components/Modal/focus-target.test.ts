import { afterEach, describe, expect, it } from 'vitest';
import { canTakeFocus, firstFocusable, isInert } from './focus-target';

/**
 * `focus-target.ts` (J6) — the one definition of "will `.focus()` actually do anything here?".
 *
 * ⚠️ THE `inert` CASES ARE THE REASON THIS FILE EXISTS, AND THEY ARE THE ONES happy-dom CAN ACTUALLY
 * ANSWER. It implements no layout, so the RENDERING half (`display: none`, a zero box) is not testable
 * here and never was — that is what stranded focus at 375px in J1a with a green unit suite, and the e2e
 * spec is its gate. `inert` is different: it is an ATTRIBUTE and inheritance is a DOM question, so the half
 * that was missing from the palette's private predicate is precisely the half a unit test can pin.
 */

function build(html: string): void {
    document.body.innerHTML = html;
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('isInert', () => {
    it('is true for an element carrying the attribute itself', () => {
        build('<button id="target" inert>Go</button>');

        expect(isInert(document.querySelector('#target') as HTMLElement)).toBe(true);
    });

    it('is true for a DESCENDANT of an inert element, which is the case that actually occurs', () => {
        // ⭐ `inert-stack` marks whole regions — a top nav, a `<main>` — never the individual control. So a
        // predicate checking only the element itself would answer `false` for every real occurrence, which
        // is exactly as useless as not checking at all.
        build('<nav inert><a id="target" href="/x">Search</a></nav>');

        expect(isInert(document.querySelector('#target') as HTMLElement)).toBe(true);
        expect(canTakeFocus(document.querySelector('#target') as HTMLElement)).toBe(false);
    });

    it('is false for a sibling of an inert element', () => {
        build('<nav inert><a href="/x">Nav</a></nav><button id="target">Go</button>');

        expect(isInert(document.querySelector('#target') as HTMLElement)).toBe(false);
        expect(canTakeFocus(document.querySelector('#target') as HTMLElement)).toBe(true);
    });
});

describe('firstFocusable', () => {
    it('honours PREFERENCE order rather than document order', () => {
        // ⭐ The reason the signature takes a list instead of one comma-joined selector. `querySelector`
        // with a comma returns whichever match comes first in the DOCUMENT, which would silently invert the
        // consumer's intent — and the one consumer's intent is "the full field if present, else the compact
        // icon", two different elements at two different breakpoints.
        build('<i id="second"></i><b id="first"></b>');

        expect(firstFocusable(['#first', '#second'])?.id).toBe('first');
        expect(firstFocusable(['#second', '#first'])?.id).toBe('second');
    });

    it('skips a candidate that matches but is inert, and takes the next one that is not', () => {
        // ⭐ THE J4b1 FINDING-2 CASE. With the mobile drawer holding the page the whole top nav is inert, so
        // a resolver that only asked "does it match?" handed back a control `.focus()` cannot move to — a
        // silent no-op, after which MdsModal captures whatever the drawer happened to have.
        build('<nav inert><a id="navsearch" href="/s">Search</a></nav><button id="elsewhere">Go</button>');

        expect(firstFocusable(['#navsearch', '#elsewhere'])?.id).toBe('elsewhere');
    });

    it('returns null rather than an unfocusable element when every candidate is inert', () => {
        // Null is a usable answer: the caller then leaves `document.activeElement` alone instead of
        // believing it moved focus. Returning the inert element would be worse than returning nothing.
        build('<nav inert><a id="a" href="/s">A</a><a id="b" href="/t">B</a></nav>');

        expect(firstFocusable(['#a', '#b'])).toBeNull();
    });

    it('skips an invalid selector instead of throwing, and keeps walking the list', () => {
        // ⭐ `querySelector('[')` raises a DOMException. Both call sites run where an escaping throw would
        // abandon focus management entirely — one of them on a page that is already inert.
        build('<button id="target">Go</button>');

        expect(() => firstFocusable(['['])).not.toThrow();
        expect(firstFocusable(['[', '#target'])?.id).toBe('target');
    });

    it('returns null for an empty list', () => {
        expect(firstFocusable([])).toBeNull();
    });

    it('walks ALL matches of a selector, not just its first', () => {
        // ⭐ FOUND BY THE ADVERSARIAL PASS READING THE DOCSTRING AGAINST THE CODE. With `querySelector` the
        // behaviour was really "the first SELECTOR whose FIRST match can take focus", so one inert leading
        // element made the whole selector answer null and skipped reachable siblings the caller had asked
        // for. Both shipped call sites pass selectors matching a single element, so nothing was broken —
        // but the docstring promised more than the code did.
        build('<div><span inert><b class="c" id="first"></b></span><b class="c" id="second"></b></div>');

        expect(firstFocusable(['.c'])?.id).toBe('second');
    });

    it('still prefers an earlier SELECTOR over a later one, even when both have reachable matches', () => {
        // The guard against "fix the walk, lose the preference order" — the two properties are independent
        // and a loop over matches inside a loop over selectors has to keep both.
        build('<b class="late" id="late"></b><b class="early" id="early"></b>');

        expect(firstFocusable(['.early', '.late'])?.id).toBe('early');
    });

    it('can be scoped to a subtree, and then ignores a match outside it', () => {
        build('<div id="scope"><button id="inside">In</button></div><button id="outside">Out</button>');
        const scope = document.querySelector('#scope') as HTMLElement;

        expect(firstFocusable(['#outside', '#inside'], scope)?.id).toBe('inside');
    });
});
