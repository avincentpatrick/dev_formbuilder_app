import { describe, expect, it } from 'vitest';
import { readdirSync, readFileSync } from 'node:fs';
import { join, relative, sep } from 'node:path';

/**
 * Increment M10 — EVERY FORM IN THIS APPLICATION IS EITHER PREVENTED OR A GET, AND NOTHING ELSE COULD SEE IT.
 *
 * ── WHAT THIS EXISTS TO CATCH ────────────────────────────────────────────────────────────────────────────
 * A native browser form submission carries no `_token` field and no `X-XSRF-TOKEN` header — only Inertia's
 * axios layer supplies those — and `bootstrap/app.php` exempts exactly one path from CSRF, the SAML ACS.
 * So a form that posts natively does not "mostly work": it 419s, every time, for everybody. M10 found one
 * on `Pages/auth/VerifyEmail.vue`, where it was the ONLY exit from the email-verification interstitial —
 * a lockout for every newly registered account, live since PR #6.
 *
 * ── ⚠️ WHY A SOURCE SCAN, WHEN THIS REPOSITORY HAS SIX CI JOBS ───────────────────────────────────────────
 * Not one of them can see this. The axe gate renders the page and scans it WITHOUT CLICKING, so a button
 * that 419s is indistinguishable from one that works. Pest cannot assert the 419 at all — Laravel disables
 * `ValidateCsrfToken` under `runningUnitTests()`, so every feature test in the suite submits without a
 * token by construction. `vue-tsc` type-checks the script block and never reads an attribute. And a mount
 * test only ever covers the one page somebody thought to write a mount test for; the defect was found by
 * grepping the SHAPE, so the gate is the shape too.
 *
 * ── THE RULE, AND THE CASE THAT CONSTRAINS IT ────────────────────────────────────────────────────────────
 * "No `action` attributes" would be the easy rule and it is the wrong one: `components/shell/TopNav.vue`
 * carries a deliberate `method="GET" action="/search"` so that workspace search still works with JavaScript
 * disabled, and a GET submission has nothing to protect — CSRF guards state changes. So the rule is
 * NATIVE SUBMISSIONS MUST BE GET: a form either declares `@submit.prevent` (Inertia handles it, tokens and
 * all) or explicitly declares `method="GET"`.
 *
 * ⚠️ REQUIRING THE METHOD *EXPLICITLY* IS DELIBERATE, even though HTML defaults it to GET. A bare form
 * around a submit button is the shape one attribute away from the defect, and an author who writes the
 * method down has decided something. All 36 forms in the tree already satisfy this, so the strict rule
 * costs nothing today and is the only version still worth having in a year.
 *
 * ⚠️ AND THE ALTERNATIVE THAT MUST NOT BE TAKEN, recorded here because it is the tempting one-line fix:
 * adding `/logout` to `validateCsrfTokens(except: …)`. That resolves the 419 by REMOVING a control rather
 * than by using it — the `EnforcePlatformMaintenance` path-list lesson, in the direction that costs you
 * something. The comment beside that array in `bootstrap/app.php` says so from the other end.
 */

/** Blank a region out while preserving its newlines, so match indices still map to real line numbers. */
function blank(region: string): string {
    return region.replace(/[^\n]/g, ' ');
}

/**
 * Everything outside the SFC's top-level template block, and every HTML comment inside it, masked away.
 *
 * ⚠️ THIS IS LOAD-BEARING, NOT TIDINESS. `components/shell/ImpersonationBanner.vue` spells a form element
 * inside a `<script setup>` block comment while explaining why it did NOT use one — so a naive scan reports
 * the one file that documents the correct decision, and a gate that cries wolf about prose gets deleted.
 * The last case in this file pins that. All 140 `.vue` files under `resources/` open at column 0.
 */
function templateOnly(source: string): string {
    const open = /^<template(?:\s[^>]*)?>/m.exec(source);
    if (open === null) return blank(source);

    const start = open.index + open[0].length;

    let end = -1;
    for (const close of source.matchAll(/^<\/template>/gm)) {
        if (close.index !== undefined && close.index >= start) end = close.index;
    }
    if (end === -1) return blank(source);

    const masked = blank(source.slice(0, start)) + source.slice(start, end) + blank(source.slice(end));

    return masked.replace(/<!--[\s\S]*?-->/g, blank);
}

function vueFilesUnder(root: string): string[] {
    const found: string[] = [];

    for (const entry of readdirSync(root, { withFileTypes: true })) {
        if (entry.name === 'node_modules') continue;

        const path = join(root, entry.name);
        if (entry.isDirectory()) found.push(...vueFilesUnder(path));
        else if (entry.name.endsWith('.vue')) found.push(path);
    }

    return found;
}

type FormTag = { file: string; line: number; tag: string; prevented: boolean; method: string | null };

function formsIn(path: string): FormTag[] {
    const scannable = templateOnly(readFileSync(path, 'utf8'));
    const file = relative(process.cwd(), path).split(sep).join('/');

    return [...scannable.matchAll(/<form\b[^>]*>/g)].map((match) => {
        const tag = match[0];
        const method = /\bmethod\s*=\s*["']([^"']*)["']/i.exec(tag);

        return {
            file,
            line: scannable.slice(0, match.index).split('\n').length,
            tag: tag.replace(/\s+/g, ' '),
            // `@submit.prevent`, `v-on:submit.prevent`, and modifier chains such as `@submit.stop.prevent`.
            prevented: /(?:@|v-on:)submit[^\s=]*\.prevent/.test(tag),
            method: method === null ? null : method[1].trim().toLowerCase(),
        };
    });
}

describe('resources/**/*.vue — a native form submission must be a GET', () => {
    const files = vueFilesUnder(join(process.cwd(), 'resources'));
    const forms = files.flatMap(formsIn);

    /*
    | ⚠️ THE NON-VACUITY BLOCK, AND IT IS NOT CEREMONY.
    |
    | A scan whose walk silently matched nothing reports `passed` and is INDISTINGUISHABLE from a scan that
    | ran — the same failure mode as a bare `{"tool":"pint","result":"passed"}`, which this project has
    | already been bitten by. A gate that cannot fail is worse than a missing one, because it also claims
    | coverage. So the two cases below prove the walk found files and the parser found real form elements,
    | and the second proves the parser can tell the two legitimate shapes apart rather than passing
    | everything it does not understand.
    */
    it('actually walked the tree and actually parsed forms', () => {
        expect(files.length, 'no .vue files found — the walk is broken, not the tree (140 today)').toBeGreaterThan(100);
        expect(forms.length, 'no form elements parsed — the tag regex is broken (36 today)').toBeGreaterThan(20);
    });

    it('recognises both legitimate shapes, so a pass means something', () => {
        // The deliberate no-JavaScript GET, which is exactly what the rule must NOT forbid.
        const search = forms.find((form) => form.file === 'resources/js/components/shell/TopNav.vue');
        expect(search, 'TopNav no longer carries the progressive-enhancement search form').toBeDefined();
        expect(search?.prevented).toBe(false);
        expect(search?.method).toBe('get');

        // And the ordinary case, which is every other form in the application.
        expect(
            forms.filter((form) => form.prevented).length,
            'the @submit.prevent detector matches nothing',
        ).toBeGreaterThan(20);
    });

    it('never submits natively with anything but GET', () => {
        const offenders = forms
            .filter((form) => !form.prevented && form.method !== 'get')
            .map((form) => `${form.file}:${form.line} — ${form.tag}`);

        expect(
            offenders,
            'A form that is neither @submit.prevent-guarded nor an explicit method="GET" submits natively, ' +
                'which carries no CSRF token and therefore 419s. Route it through Inertia — useForm().post() ' +
                'behind @submit.prevent, or <Link method="post" as="button"> — and do NOT reach for the CSRF ' +
                'exemption list instead.',
        ).toEqual([]);
    });

    it('reads the template block only, so prose about a form is not a form', () => {
        // ImpersonationBanner's script comment names a form element while recording why it uses router.post()
        // instead. If this ever fails, `templateOnly()` has stopped masking and the gate is about to start
        // reporting comments — at which point somebody deletes it and the real rule goes with it.
        const banner = 'resources/js/components/shell/ImpersonationBanner.vue';

        expect(readFileSync(join(process.cwd(), banner), 'utf8')).toContain('<form>');
        expect(forms.filter((form) => form.file === banner)).toEqual([]);
    });
});
