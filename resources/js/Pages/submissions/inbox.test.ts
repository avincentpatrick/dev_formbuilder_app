import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * The submissions inbox — `/submissions` AND `/forms/{form}/submissions` (Increment J2c).
 *
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * ⚠️ THIS PAGE HAD NO VITEST FILE AT ALL UNTIL J2c, AND IT IS ONE COMPONENT SERVING TWO ROUTES.
 * ══════════════════════════════════════════════════════════════════════════════════════════════════════
 * The mode is keyed off the PRESENCE of the `form` prop, which the server omits entirely on the global
 * route. That makes the interesting contract "does each mode show only what belongs to it" — and every one
 * of the differences below is a defect the obvious implementation ships:
 *
 *   · a hard-coded `/submissions` in the filter visit or "Clear filters" throws the reader off the form;
 *   · an export URL built from `selected.form_id` 404s after Clear filters, from a button that looks live;
 *   · a Form column and a Form dropdown that are pure noise on a page that is already one form.
 *
 * Pest proves the SERVER sends the right payload for each route; nothing but this file proves the page
 * reacts to the difference rather than rendering the global inbox with a heading changed.
 */

const mocks = vi.hoisted(() => ({ get: vi.fn(), visit: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    // `formsCrumb()` reads `auth.can.manageForms` to decide whether the leading crumb is a LINK — /forms
    // 403s for a Reviewer and a Viewer. True here so the link branch is the one under test.
    usePage: () => ({ props: { auth: { can: { manageForms: true } } } }),
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: mocks.get, visit: mocks.visit },
}));

// Both slots render: the per-form mode puts a breadcrumb in one and the export buttons in the other.
vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: {
        name: 'PageHeader',
        props: ['title'],
        template: '<header><h1>{{ title }}</h1><slot name="breadcrumbs" /><slot name="actions" /></header>',
    },
}));

const Inbox = (await import('./Inbox.vue')).default;

const FORM_ID = '018f0000-0000-7000-8000-0000000000aa';
const OTHER_FORM_ID = '018f0000-0000-7000-8000-0000000000bb';

type Props = Record<string, unknown>;

function row(overrides: Props = {}): Props {
    return {
        id: 'sub-1',
        form_id: FORM_ID,
        form_title: 'Clinic Intake',
        status: 'submitted',
        source: 'guest',
        source_label: 'Guest link',
        respondent: 'Guest',
        submitted_at: '2026-08-10T14:30:00+00:00',
        completeness_percent: null,
        last_saved_at: null,
        draft_expires_at: null,
        can: { resume: false, open_form: true },
        ...overrides,
    };
}

/** The GLOBAL inbox payload: a form dropdown, no `form`, no `tabs`. */
function globalProps(overrides: Props = {}): Props {
    return {
        data: [row()],
        meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 },
        filters: {
            forms: [
                { value: FORM_ID, label: 'Clinic Intake' },
                { value: OTHER_FORM_ID, label: 'Nobody Has Answered This' },
            ],
            statuses: [{ value: 'submitted', label: 'Submitted' }],
            sources: [{ value: 'guest', label: 'Guest link' }],
            applied: { form_id: null, status: null, source: null, q: null },
        },
        can: { export: true },
        empty_reason: null,
        ...overrides,
    };
}

/** The PER-FORM payload: `form` + `tabs` present, `filters.forms` absent. */
function perFormProps(overrides: Props = {}): Props {
    const base = globalProps() as { filters: Record<string, unknown> };
    const { forms: _dropped, ...filtersWithoutForms } = base.filters as Record<string, unknown>;

    return {
        ...globalProps(),
        filters: { ...filtersWithoutForms, applied: { form_id: FORM_ID, status: null, source: null, q: null } },
        form: { id: FORM_ID, title: 'Clinic Intake' },
        // J2d — server-resolved. The GLOBAL inbox deliberately gets no `crumbs` key at all (a root page has
        // no trail), which is what keeps the "renders neither a breadcrumb nor a tab strip" case meaningful.
        crumbs: [
            { label: 'Forms', href: '/forms' },
            { label: 'Clinic Intake', href: `/forms/${FORM_ID}` },
            { label: 'Responses' },
        ],
        tabs: [
            { key: 'overview', label: 'Overview', href: `/forms/${FORM_ID}`, icon: 'forms' },
            { key: 'submissions', label: 'Responses', href: `/forms/${FORM_ID}/submissions`, icon: 'submissions' },
        ],
        ...overrides,
    };
}

function render(props: Props): VueWrapper {
    return mount(Inbox, { props, global: { stubs: { teleport: true } } });
}

describe('submissions inbox — the global list', () => {
    it('links a row’s form title to that form’s hub', () => {
        // ⚠️ THE DEAD END J2c REMOVES, and it needed a SERVER change to fix: the row payload carried
        // `form_title` but not `form_id`, so the inbox printed a form's name on every row and could link
        // none of them. A page that rendered the title as plain text passes every other gate.
        const wrapper = render(globalProps());
        const link = wrapper.findAll('a').find((a) => a.text().includes('Clinic Intake'));

        expect(link?.attributes('href')).toBe(`/forms/${FORM_ID}`);

        wrapper.unmount();
    });

    it('renders the form title as PLAIN TEXT when the reader may not open that form', () => {
        // ⚠️ THE ROW SET IS WIDER THAN FORM READABILITY — `Submission::scopeVisibleTo()` has a respondent
        // arm `FormPolicy::viewOverview()` has no counterpart for, and a soft-deleted form renders its title
        // as an em dash. J2c's first draft linked unconditionally, so those rows shipped a live link to a
        // 403 (and, for the em dash, "—" as a hyperlink to a 404). Found by the adversarial review.
        const wrapper = render(globalProps({ data: [row({ can: { resume: false, open_form: false } })] }));

        expect(wrapper.text()).toContain('Clinic Intake');
        expect(wrapper.findAll('a').some((a) => a.attributes('href') === `/forms/${FORM_ID}`)).toBe(false);

        wrapper.unmount();
    });

    it('renders an href-less Forms crumb as text rather than defaulting it back to /forms', () => {
        /*
         * ⚠️ `/forms` GATES ON `can:viewAny,Form` — `forms.create | forms.edit.any | forms.edit.own` — and a
         * Reviewer and a Viewer hold NONE of them while both reach this page. A hard `href: '/forms'` hands
         * exactly those readers a bare 403 with no way back.
         *
         * ⚠️ J2d MOVED THE DECISION TO THE SERVER, so this case moved with it: it no longer asserts which
         * branch a client composable picked (there is no composable), but that the page RENDERS a refused
         * crumb as refused. `CrumbTrailGateTest` proves the server withholds it for the right roles; this is
         * the other end of the same wire, and it is what would catch a page "helpfully" restoring the href.
         */
        const wrapper = render(
            perFormProps({
                crumbs: [
                    { label: 'Forms' },
                    { label: 'Clinic Intake', href: `/forms/${FORM_ID}` },
                    { label: 'Responses' },
                ],
            }),
        );

        const trail = wrapper.findComponent({ name: 'Breadcrumb' });

        // One link only — the form's hub. `Forms` is text and the tail always is.
        expect(trail.findAll('a')).toHaveLength(1);
        expect(trail.findAll('a')[0]?.attributes('href')).toBe(`/forms/${FORM_ID}`);

        wrapper.unmount();
    });

    it('shows the Form column and the Form dropdown', () => {
        // ⚠️ `findAll('th')`, NOT `wrapper.text()).toContain('Form')`. `MdsFormField label="Form"` renders a
        // visible `<label>Form</label>` for the dropdown, so a text assertion stays green with the COLUMN
        // deleted — it would have been measuring the control it is not about. Its per-form twin below got
        // this right first; this one did not, which is how the asymmetry was found.
        const wrapper = render(globalProps());

        expect(wrapper.findAll('th').map((th) => th.text())).toContain('Form');
        expect(wrapper.find('#inbox-form').exists()).toBe(true);

        wrapper.unmount();
    });

    it('renders neither a breadcrumb nor a tab strip', () => {
        // The absent half. Without this, a `v-if="form"` changed to `v-if="true"` would render an empty
        // strip on the global inbox and nothing would notice.
        const wrapper = render(globalProps());

        expect(wrapper.findComponent({ name: 'Breadcrumb' }).exists()).toBe(false);
        expect(wrapper.findComponent({ name: 'TabNav' }).exists()).toBe(false);

        wrapper.unmount();
    });

    it('navigates to /submissions when filters change', () => {
        const wrapper = render(globalProps());
        mocks.get.mockClear();

        wrapper.findComponent({ name: 'SearchField' }).vm.$emit('submit');

        expect(mocks.get.mock.calls[0]?.[0]).toBe('/submissions');

        wrapper.unmount();
    });
});

describe('submissions inbox — one form’s responses', () => {
    it('passes the server trail through and marks Responses current in the strip', () => {
        // Pass-through, not a spelled-out expectation: J2d made `CrumbTrail` the author of these three
        // crumbs, so what this page owes is to render them untouched.
        const wrapper = render(perFormProps());

        const crumbs = wrapper.findComponent({ name: 'Breadcrumb' });
        expect(crumbs.props('items')).toEqual([
            { label: 'Forms', href: '/forms' },
            { label: 'Clinic Intake', href: `/forms/${FORM_ID}` },
            { label: 'Responses' },
        ]);

        // ⚠️ SCOPED TO THE STRIP. This page now carries TWO `aria-current="page"` elements — the trail's
        // tail and the active tab — and a page-wide locator matches the BREADCRUMB first, which is how the
        // first draft of the hub's equivalent case asserted the wrong element.
        const nav = wrapper.findComponent({ name: 'TabNav' });
        expect(nav.props('current')).toBe('submissions');
        expect(nav.get('[aria-current="page"]').text()).toContain('Responses');

        wrapper.unmount();
    });

    it('drops the Form column and the Form dropdown, which are noise here', () => {
        const wrapper = render(perFormProps());

        expect(wrapper.find('#inbox-form').exists()).toBe(false);
        // The form's own name still appears — as the breadcrumb and the strip's label — so the assertion is
        // on the CONTROL, not on the text.
        expect(wrapper.findAll('th').map((th) => th.text())).not.toContain('Form');

        wrapper.unmount();
    });

    it('keeps every navigation on the form', () => {
        // ⚠️ THE REGRESSION THIS FILE EXISTS FOR. Three call sites hard-coded `/submissions`; on this page
        // the first two would have silently widened the list to every form in the tenant while the
        // breadcrumb still named one.
        const wrapper = render(perFormProps());
        mocks.get.mockClear();

        wrapper.findComponent({ name: 'SearchField' }).vm.$emit('submit');
        expect(mocks.get.mock.calls[0]?.[0]).toBe(`/forms/${FORM_ID}/submissions`);

        wrapper.unmount();
    });

    it('sends no form_id query param, because the route already carries it', () => {
        const wrapper = render(perFormProps());
        mocks.get.mockClear();

        wrapper.findComponent({ name: 'SearchField' }).vm.$emit('submit');

        // ⚠️ ASSERT THE VISIT HAPPENED FIRST. `expect(undefined).not.toHaveProperty(...)` passes, so without
        // this line a broken `@submit` binding — no visit at all — would satisfy the case below.
        expect(mocks.get).toHaveBeenCalledTimes(1);
        expect(mocks.get.mock.calls[0][1]).not.toHaveProperty('form_id');

        wrapper.unmount();
    });

    it('offers Export with NO applied form_id at all, which the old gate required', () => {
        // ⚠️ `filters.applied.form_id: null` IS THE POINT OF THIS FIXTURE. `perFormProps()` normally seeds it
        // with the bound form (which is what the server sends), and that seeds `selected.form_id` truthy —
        // so the OLD `v-if="can.export && selected.form_id"` renders Export too and the case cannot tell the
        // implementations apart. Nulling it isolates the route-bound form as the only thing that can be
        // satisfying the condition.
        const wrapper = render(
            perFormProps({
                filters: {
                    statuses: [{ value: 'submitted', label: 'Submitted' }],
                    sources: [{ value: 'guest', label: 'Guest link' }],
                    applied: { form_id: null, status: null, source: null, q: null },
                },
            }),
        );

        expect(wrapper.text()).toContain('Export CSV');

        wrapper.unmount();
    });

    it('still builds a valid export URL after Clear filters', async () => {
        // ⚠️ THE BUG THIS CAUGHT WHILE BEING WRITTEN. The export href was built from `selected.form_id`,
        // which `clearFilters()` blanks — so an Export taken after clearing produced
        // `/forms//submissions/export`, a 404 from a button that still looked live. It now prefers the
        // route-bound form, which cannot be cleared.
        //
        // ⚠️ AND THE FIRST DRAFT OF THIS CASE WAS VACUOUS, which is worth more than the bug. It reached for
        // `wrapper.vm.clearFilters?.()` — but `<script setup>` exposes nothing without `defineExpose`, so
        // the optional call silently no-opped and the assertion ran over a page that had never been
        // cleared. It passed against BOTH implementations. Drive the real button; `?.()` on a component
        // internal is a test that cannot fail.
        const wrapper = render(
            perFormProps({
                data: [],
                meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 },
                empty_reason: 'no_matches',
            }),
        );
        const originalLocation = window.location;
        const href = { value: '' };
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: {
                get href() {
                    return href.value;
                },
                set href(v: string) {
                    href.value = v;
                },
            },
        });

        const clear = wrapper.findAllComponents({ name: 'Button' }).find((b) => b.text() === 'Clear filters');
        expect(clear, 'the no_matches empty state must offer Clear filters').toBeTruthy();
        await clear!.trigger('click');

        const exportButton = wrapper.findAllComponents({ name: 'Button' }).find((b) => b.text() === 'Export CSV');
        expect(exportButton, 'Export must still be offered after clearing').toBeTruthy();
        await exportButton!.trigger('click');

        expect(href.value).toContain(`/forms/${FORM_ID}/submissions/export`);
        expect(href.value).not.toContain('/forms//');

        Object.defineProperty(window, 'location', { configurable: true, value: originalLocation });
        wrapper.unmount();
    });

    it('says NO RESPONSES YET rather than the global "No submissions" when this form has none', () => {
        // The server sends `empty_reason: 'no_rows'` for a form with no responses (it does not count the
        // route-bound form as a filter). The page must then speak about THIS form, not about the inbox.
        const wrapper = render(perFormProps({ data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 }, empty_reason: 'no_rows' }));

        expect(wrapper.text()).toContain('No responses yet');
        expect(wrapper.text()).not.toContain('No submissions');

        wrapper.unmount();
    });

    it('keeps a heading above the empty state so heading-order cannot break', () => {
        // `PageHeader` renders the h1 and `MdsEmptyState` an h3 — the same gap the hub's panels have. A
        // brand-new form is the state that reaches it, and no seeded e2e fixture can construct one.
        const wrapper = render(perFormProps({ data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 }, empty_reason: 'no_rows' }));

        const levels = wrapper.findAll('h1, h2, h3').map((h) => h.element.tagName);
        const h3 = levels.indexOf('H3');

        expect(h3).toBeGreaterThan(-1);
        expect(levels.indexOf('H1')).toBeLessThan(h3);

        wrapper.unmount();
    });
});
