import { describe, expect, it, vi } from 'vitest';

/**
 * `formsCrumb()` — the leading `Forms` breadcrumb (Increment J2c).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ THE DEFECT THIS GUARDS IS INVISIBLE TO EVERY OTHER GATE WE RUN.
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * `/forms` gates on `can:viewAny,Form` — `forms.create | forms.edit.any | forms.edit.own` — and a **Reviewer
 * and a Viewer hold none of the three**, while both can reach the form hub, a form's responses list, a
 * submission's detail page and the encode screen, all of which now open their trail with this crumb. A hard
 * `href: '/forms'` therefore hands exactly those readers a **bare 403 with no way back** (tenant 403s render
 * a Blade page, not an Inertia view inside the app shell).
 *
 * `MdsBreadcrumb` renders an href-less item as plain text, so the broken and the correct version are
 * pixel-identical: vue-tsc sees a valid `BreadcrumbItem` either way, axe scans a perfectly good trail, and
 * every page snapshot passes. The only thing that can tell them apart is an assertion on the `href` key,
 * which is what this file is.
 *
 * It lives here rather than in the five page specs because the ability is the variable: mocking `usePage`
 * per page would give five copies of one rule, and J1e's finding is that copies do not disagree with each
 * other — they all agree with something that has moved.
 */

const mocks = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mocks.props }),
}));

const { formsCrumb } = await import('../useFormsCrumb');

describe('formsCrumb', () => {
    it('links to /forms for a reader who may open it', () => {
        mocks.props = { auth: { can: { manageForms: true } } };

        expect(formsCrumb()).toEqual({ label: 'Forms', href: '/forms' });
    });

    it('drops the href — but keeps the crumb — for a reader who may not', () => {
        // ⚠️ THE CRUMB STAYS. Removing it entirely would renumber the trail and make the same page render a
        // different depth per role, which is a worse answer than an unlinked ancestor: the reader still
        // needs to know where they are, they simply cannot go to the top level.
        mocks.props = { auth: { can: { manageForms: false } } };

        expect(formsCrumb()).toEqual({ label: 'Forms' });
    });

    it('fails CLOSED when the ability map is absent entirely', () => {
        // A guest shell, an error page, or any response `ShellAbilities` did not decorate. Optional-chaining
        // to `undefined` must read as "no", not throw and not link — the same fail-closed direction the
        // server-side guards take.
        mocks.props = {};

        expect(formsCrumb()).toEqual({ label: 'Forms' });
    });
});
