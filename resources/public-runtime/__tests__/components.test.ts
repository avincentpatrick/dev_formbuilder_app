import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import RuntimeSession from '../components/RuntimeSession.vue';
import type { ApiClient } from '../lib/api-client';
import { ApiError } from '../lib/api-client';
import { normalizeError } from '../lib/error-normalizer';
import type { Bootstrap } from '../lib/types';
import { field, schemaResponse, section } from './fixtures';

const SUBMISSION_ID = '0192f1a2-b3c4-7d5e-8f90-1a2b3c4d5e6f';
// Increment J2e — the server-issued handle now rides on the submit result and on the `submitted` emit, so a
// fixture that omits it makes `reference` undefined on the confirmation screen rather than failing loudly.
const SUBMISSION_REFERENCE = '7K4M-2QXB';

function fakeClient(overrides: Partial<ApiClient> = {}): ApiClient {
    return {
        fetchSchema: vi.fn(async () => {
            throw new Error('unused in these tests');
        }),
        submit: vi.fn(async () => ({ id: SUBMISSION_ID, reference: SUBMISSION_REFERENCE, status: 'submitted', created: true })),
        saveDraft: vi.fn(async () => ({
            id: SUBMISSION_ID,
            completenessPercent: 64,
            resumeToken: 'rt',
            resumeUrl: 'https://acme/f/resume/rt',
            expiresAt: '2026-08-01T00:00:00Z',
        })),
        remint: vi.fn(async () => ({ shareToken: 't2', expiresAt: '', form: { id: 'f', title: 'T' } })),
        token: () => 't1',
        ...overrides,
    };
}

const bootstrap: Bootstrap = {
    shareToken: 't1',
    expiresAt: '',
    formId: 'f',
    formTitle: 'T',
    slug: 's',
    defaultLocale: 'en',
    resumeToken: '',
};

beforeEach(() => {
    window.localStorage.clear();
});

// The GeoInput chunk is lazy (defineAsyncComponent) — its dynamic import settles on a macrotask, so a
// microtask flushPromises() never advances it; poll with real timers until the `.geo` root has mounted.
async function waitForGeoInput(wrapper: { find: (s: string) => { exists: () => boolean } }): Promise<void> {
    await vi.waitFor(
        () => {
            if (!wrapper.find('.geo').exists()) {
                throw new Error('GeoInput not mounted yet');
            }
        },
        { timeout: 3000, interval: 20 },
    );
}

// Increment G8b — submit() now awaits an IndexedDB enqueue (a macrotask) BEFORE the network call, so a
// microtask-only flushPromises() no longer settles the whole chain; advance a few macrotasks too.
async function settle(): Promise<void> {
    for (let i = 0; i < 5; i += 1) {
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

describe('RuntimeSession (component wiring)', () => {
    it('renders fields via the shared FieldInput and submits effective answers', async () => {
        const schema = schemaResponse({
            fields: [field({ key: 'name', label: 'Full name', is_required: 'required' })],
        });
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });

        expect(wrapper.text()).toContain('Full name');

        // Submit while empty → blocked + inline required error; the network is not called.
        await wrapper.find('form').trigger('submit');
        await settle();
        expect(client.submit).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('This field is required.');

        // Fill + submit → client.submit is called with the answer, and 'submitted' fires with the id.
        await wrapper.find('input').setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();
        expect(client.submit).toHaveBeenCalledWith(expect.objectContaining({ answers: { name: 'Ada' } }));
        // The second payload is Increment H6b's rendered confirmation copy — null here because this form
        // sets no `confirmation_message`, which is what keeps App.vue's hardcoded default in place.
        expect(wrapper.emitted('submitted')?.[0]).toEqual([SUBMISSION_ID, SUBMISSION_REFERENCE, null]);

        wrapper.unmount();
    });

    it('in resolve mode, offers a discard escape hatch and seeds the reviewed answers (Increment G8c)', async () => {
        const schema = schemaResponse({
            fields: [field({ key: 'name', label: 'Full name', is_required: 'required' })],
        });
        const wrapper = mount(RuntimeSession, {
            props: {
                schema,
                bootstrap,
                client: fakeClient(),
                initialAnswers: { name: 'Ada' },
                notice: 'This form was updated. Please review and resubmit.',
                resolving: true,
            },
        });
        await settle();

        // The saved answers are pre-filled onto the (new) schema, and the resolve notice is shown.
        expect((wrapper.find('input').element as HTMLInputElement).value).toBe('Ada');
        expect(wrapper.text()).toContain('This form was updated');

        // A discard control is offered and emits 'discard'.
        const discard = wrapper.get('.session-notice__discard');
        await discard.trigger('click');
        expect(wrapper.emitted('discard')).toHaveLength(1);

        wrapper.unmount();
    });

    it('in resume mode, shows the welcome-back banner and offers Save-and-finish-later (Increment H10)', async () => {
        const schema = schemaResponse({
            fields: [field({ key: 'name', label: 'Full name' })],
            form: { save_and_resume: true },
        });
        const wrapper = mount(RuntimeSession, {
            props: {
                schema,
                bootstrap,
                client: fakeClient(),
                initialAnswers: { name: 'Ada' },
                resume: {
                    uuid: 'draft-uuid-1',
                    locale: null,
                    stepKey: null,
                    completeness: 64,
                    note: 'You have more recent answers saved on this device, so we kept those.',
                },
            },
        });
        await settle();

        // The welcome-back banner confirms the restore + carries the reconciliation note, and the answers are
        // back. Increment H21b, Doc #27 §5.2 — it no longer quotes a percentage: `completeness_percent` is
        // coverage of the AUTHORED form and stays relevance-unaware, which makes it false for a respondent on
        // a branch. Asserted as an absence so a re-added "%" cannot slip back in unnoticed.
        expect(wrapper.text()).toContain('Welcome back');
        expect(wrapper.text()).not.toContain('64%');
        expect(wrapper.text()).toContain('more recent answers saved on this device');
        expect((wrapper.find('input').element as HTMLInputElement).value).toBe('Ada');

        // The save-and-finish-later control is present because the form opted in.
        expect(wrapper.text()).toContain('Save and finish later');

        wrapper.unmount();
    });

    it('hides Save-and-finish-later when the form has not opted in (Increment H10)', () => {
        const wrapper = mount(RuntimeSession, {
            props: {
                schema: schemaResponse({ fields: [field({ key: 'a' })] }), // save_and_resume undefined → off
                bootstrap,
                client: fakeClient(),
            },
        });
        expect(wrapper.text()).not.toContain('Save and finish later');
        wrapper.unmount();
    });

    it('shows the language switcher only for a multi-locale form', () => {
        const single = mount(RuntimeSession, {
            props: {
                schema: schemaResponse({ fields: [field({ key: 'a' })], form: { supported_locales: ['en'] } }),
                bootstrap,
                client: fakeClient(),
            },
        });
        // Field 'a' is a text input, so a <select> would only be the switcher.
        expect(single.findAll('select')).toHaveLength(0);
        single.unmount();

        const multi = mount(RuntimeSession, {
            props: {
                schema: schemaResponse({ fields: [field({ key: 'a' })], form: { supported_locales: ['en', 'es'] } }),
                bootstrap,
                client: fakeClient(),
            },
        });
        expect(multi.findAll('select')).toHaveLength(1);
        multi.unmount();
    });

    it('renders a repeat group: adds an instance, fills it, and submits nested answers', async () => {
        const schema = schemaResponse({
            sections: [
                section({ key: 'hh', label: 'Household members', is_repeatable: true, min_instances: 0, max_instances: 3 }),
            ],
            fields: [field({ key: 'member_name', label: 'Member name', section_key: 'hh', section_sequence: 0 })],
        });
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });

        // No instances yet → the member input is not rendered; the Add button is.
        expect(wrapper.find('input').exists()).toBe(false);
        const addButton = wrapper.findAll('button').find((b) => b.text().includes('Add Household members'))!;
        await addButton.trigger('click');
        await settle();

        // One instance now renders its member input; fill it and submit.
        const input = wrapper.find('input');
        expect(input.exists()).toBe(true);
        await input.setValue('Bob');
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(client.submit).toHaveBeenCalledWith(
            expect.objectContaining({ answers: { hh: [{ member_name: 'Bob' }] } }),
        );
        wrapper.unmount();
    });

    it('binds a server 422 error onto the addressed repeat-instance field', async () => {
        const { ApiError } = await import('../lib/error-normalizer');
        const client = fakeClient({
            submit: vi.fn(async () => {
                throw new ApiError({
                    httpStatus: 422,
                    code: 'submission_invalid',
                    message: 'invalid',
                    fieldErrors: { 'hh[0].member_age': ['Age must be a number.'] },
                    kind: 'field',
                    retryAfterSeconds: null,
                });
            }),
        });
        const schema = schemaResponse({
            sections: [section({ key: 'hh', label: 'People', is_repeatable: true, min_instances: 0, max_instances: 3 })],
            fields: [
                field({ key: 'member_age', label: 'Age', field_type: 'integer', section_key: 'hh', section_sequence: 0 }),
            ],
        });
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });

        const addButton = wrapper.findAll('button').find((b) => b.text().includes('Add People'))!;
        await addButton.trigger('click');
        await settle();
        await wrapper.find('input').setValue('40');
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(wrapper.text()).toContain('Age must be a number.');
        wrapper.unmount();
    });

    it('renders a likert_scale as a radio group and submits the chosen score (G4a)', async () => {
        const scaleConfig = {
            options: [
                { value: '1', label: 'Low' },
                { value: '2', label: 'Mid' },
                { value: '3', label: 'High' },
            ],
        };
        const schema = schemaResponse({
            fields: [field({ key: 'rating', label: 'Satisfaction', field_type: 'likert_scale', config: scaleConfig })],
        });
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });

        const radios = wrapper.findAll('input[type="radio"]');
        expect(radios).toHaveLength(3);

        await wrapper.find('input[value="3"]').setValue();
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(client.submit).toHaveBeenCalledWith(expect.objectContaining({ answers: { rating: '3' } }));
        wrapper.unmount();
    });

    it('renders a cascading select as dependent selects and submits the chosen path (G4a)', async () => {
        const cascadeConfig = {
            levels: [
                { key: 'region', label: 'Region' },
                { key: 'province', label: 'Province' },
            ],
            options: [
                { value: 'ncr', label: 'NCR', level: 'region', parent: null },
                { value: 'cebu', label: 'Cebu', level: 'region', parent: null },
                { value: 'manila', label: 'Manila', level: 'province', parent: 'ncr' },
                { value: 'cebu_city', label: 'Cebu City', level: 'province', parent: 'cebu' },
            ],
        };
        const schema = schemaResponse({
            fields: [field({ key: 'loc', label: 'Location', field_type: 'cascading_select', config: cascadeConfig })],
        });
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });

        // Two level selects (single-locale form ⇒ no language switcher select).
        expect(wrapper.findAll('select')).toHaveLength(2);
        // The province select is disabled until a region is chosen.
        expect(wrapper.findAll('select')[1].attributes('disabled')).toBeDefined();

        await wrapper.findAll('select')[0].setValue('ncr');
        await settle();

        // Province is now enabled and filtered to NCR's children only (manila, not cebu_city).
        const provinceValues = wrapper
            .findAll('select')[1]
            .findAll('option')
            .map((o) => (o.element as HTMLOptionElement).value)
            .filter((v) => v !== '');
        expect(provinceValues).toEqual(['manila']);

        await wrapper.findAll('select')[1].setValue('manila');
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(client.submit).toHaveBeenCalledWith(expect.objectContaining({ answers: { loc: ['ncr', 'manila'] } }));
        wrapper.unmount();
    });

    it('renders a likert_matrix as one radio group per row and submits the object (G4b)', async () => {
        const config = {
            rows: [
                { value: 'clean', label: 'Cleanliness' },
                { value: 'staff', label: 'Staff' },
            ],
            columns: [
                { value: '1', label: 'Poor' },
                { value: '2', label: 'OK' },
                { value: '3', label: 'Great' },
            ],
        };
        const schema = schemaResponse({
            fields: [field({ key: 'sat', label: 'Rate us', field_type: 'likert_matrix', config })],
        });
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });

        // 2 rows × 3 columns = 6 radios, grouped by a per-row name.
        expect(wrapper.findAll('input[type="radio"]')).toHaveLength(6);

        await wrapper.find('input[name="lm-sat-clean"][value="3"]').setValue();
        await wrapper.find('input[name="lm-sat-staff"][value="2"]').setValue();
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(client.submit).toHaveBeenCalledWith(
            expect.objectContaining({ answers: { sat: { clean: '3', staff: '2' } } }),
        );
        wrapper.unmount();
    });

    it('renders a matrix as per-cell selects and submits the nested object (G4b)', async () => {
        const config = {
            rows: [{ value: 'a', label: 'Row A' }],
            columns: [
                { value: 'q1', label: 'Q1' },
                { value: 'q2', label: 'Q2' },
            ],
            cells: [
                { value: 'ok', label: 'OK' },
                { value: 'no', label: 'No' },
            ],
        };
        const schema = schemaResponse({
            fields: [field({ key: 'svc', label: 'Grid', field_type: 'matrix', config })],
        });
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });

        // 1 row × 2 columns = 2 cell selects (single-locale form ⇒ no language switcher select).
        const selects = wrapper.findAll('select');
        expect(selects).toHaveLength(2);

        await selects[0].setValue('ok');
        await selects[1].setValue('no');
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(client.submit).toHaveBeenCalledWith(
            expect.objectContaining({ answers: { svc: { a: { q1: 'ok', q2: 'no' } } } }),
        );
        wrapper.unmount();
    });

    it('renders a geopoint as lat/lon inputs and submits a lon-first Point envelope (G5b2)', async () => {
        const schema = schemaResponse({
            fields: [field({ key: 'loc', label: 'Location', field_type: 'geopoint' })],
        });
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });
        await waitForGeoInput(wrapper); // the GeoInput chunk is lazy (defineAsyncComponent)

        // The a11y baseline is two labelled number inputs — Latitude then Longitude. The Leaflet map is a
        // progressive enhancement that stays absent under happy-dom (no layout); the inputs alone drive the value.
        const inputs = wrapper.findAll('input[type="number"]');
        expect(inputs.length).toBeGreaterThanOrEqual(2);
        await inputs[0].setValue('14.5995'); // latitude
        await inputs[1].setValue('120.9842'); // longitude

        await wrapper.find('form').trigger('submit');
        await settle();

        // Stored envelope is lon-first (GeoJSON/PostGIS), display was lat-first.
        expect(client.submit).toHaveBeenCalledWith(
            expect.objectContaining({ answers: { loc: { type: 'Point', coordinates: [120.9842, 14.5995] } } }),
        );
        wrapper.unmount();
    });

    it('builds a geotrace LineString (≥2 vertices, lon-first) (G5b2)', async () => {
        const schema = schemaResponse({
            fields: [field({ key: 'path', label: 'Route', field_type: 'geotrace' })],
        });
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });
        await waitForGeoInput(wrapper); // the GeoInput chunk is lazy (defineAsyncComponent)

        const addButton = wrapper.findAll('button').find((b) => b.text().includes('Add point'));
        expect(addButton).toBeDefined();
        await addButton!.trigger('click');
        await addButton!.trigger('click');

        const inputs = wrapper.findAll('input[type="number"]'); // 2 vertices × (lat, lon)
        expect(inputs).toHaveLength(4);
        await inputs[0].setValue('1'); // v0 lat
        await inputs[1].setValue('2'); // v0 lon
        await inputs[2].setValue('3'); // v1 lat
        await inputs[3].setValue('4'); // v1 lon

        await wrapper.find('form').trigger('submit');
        await settle();

        expect(client.submit).toHaveBeenCalledWith(
            expect.objectContaining({
                answers: { path: { type: 'LineString', coordinates: [[2, 1], [4, 3]] } },
            }),
        );
        wrapper.unmount();
    });

    it('builds a geoshape Polygon with an auto-closed ring (G5b2)', async () => {
        const schema = schemaResponse({
            fields: [field({ key: 'area', label: 'Boundary', field_type: 'geoshape' })],
        });
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });
        await waitForGeoInput(wrapper); // the GeoInput chunk is lazy (defineAsyncComponent)

        const addButton = wrapper.findAll('button').find((b) => b.text().includes('Add point'));
        expect(addButton).toBeDefined();
        await addButton!.trigger('click');
        await addButton!.trigger('click');
        await addButton!.trigger('click');

        const inputs = wrapper.findAll('input[type="number"]'); // 3 vertices × (lat, lon)
        expect(inputs).toHaveLength(6);
        await inputs[0].setValue('0'); // v0 lat
        await inputs[1].setValue('0'); // v0 lon
        await inputs[2].setValue('0'); // v1 lat
        await inputs[3].setValue('1'); // v1 lon
        await inputs[4].setValue('1'); // v2 lat
        await inputs[5].setValue('0'); // v2 lon

        await wrapper.find('form').trigger('submit');
        await settle();

        // The UI edits the OPEN ring; the emitted Polygon closes it (first == last), lon-first.
        expect(client.submit).toHaveBeenCalledWith(
            expect.objectContaining({
                answers: {
                    area: { type: 'Polygon', coordinates: [[[0, 0], [1, 0], [0, 1], [0, 0]]] },
                },
            }),
        );
        wrapper.unmount();
    });

    it('maps a server 422 field error back onto the field', async () => {
        const { ApiError } = await import('../lib/error-normalizer');
        const client = fakeClient({
            submit: vi.fn(async () => {
                throw new ApiError({
                    httpStatus: 422,
                    code: 'submission_invalid',
                    message: 'invalid',
                    fieldErrors: { name: ['Server says no.'] },
                    kind: 'field',
                    retryAfterSeconds: null,
                });
            }),
        });
        const schema = schemaResponse({ fields: [field({ key: 'name', label: 'Full name' })] });
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });

        await wrapper.find('input').setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();
        expect(wrapper.text()).toContain('Server says no.');
        wrapper.unmount();
    });
});

/*
 * Increment H6b — piping through the real component tree, i.e. through the SHARED `FieldInput.vue` the
 * authenticated encode channel also uses. The cross-engine formatting contract is the golden corpus's job;
 * these assert the wiring and the two obligations Doc #26 puts on this surface specifically.
 */
describe('RuntimeSession — piping (Increment H6b, Doc #26)', () => {
    function pipedSchema(form: Partial<import('../lib/types').SchemaResponse['form']> = {}) {
        return schemaResponse({
            form,
            fields: [
                field({ key: 'name', label: 'Your name', sequence: 0 }),
                field({ key: 'confirm_it', label: 'Confirm this is you, ${name}', sequence: 1 }),
            ],
        });
    }

    it('renders a piped label into the DOM and updates it as the source is typed', async () => {
        const wrapper = mount(RuntimeSession, { props: { schema: pipedSchema(), bootstrap, client: fakeClient() } });

        expect(wrapper.text()).toContain('Confirm this is you,');

        await wrapper.findAll('input')[0].setValue('Ada');
        await settle();

        expect(wrapper.text()).toContain('Confirm this is you, Ada');
        wrapper.unmount();
    });

    // ── The output-encoding test Doc #26 §5 owes this increment ────────────────────────────────────
    it('renders a piped answer containing markup as visible TEXT, never as live markup', async () => {
        // §5's first table row: guest SPA + encode page, DOM text context, escaper = Vue's own text
        // interpolation / prop binding, required test = exactly this. It is a regression guard rather
        // than a fix — `resources/` has one `v-html` in the entire tree and it is a 2FA QR SVG that must
        // never be given form content. CSP is NOT a second layer here: `PublicRuntimeSecurityHeaders`
        // sets no `script-src`, so output encoding is the sole control.
        //
        // The payload is markup-shaped and never token-shaped, per §7's note that gitleaks scans the tree.
        const wrapper = mount(RuntimeSession, { props: { schema: pipedSchema(), bootstrap, client: fakeClient() } });
        const scriptsBefore = document.querySelectorAll('script').length;

        await wrapper.findAll('input')[0].setValue('<script>alert(1)</script>');
        await settle();

        expect(wrapper.text()).toContain('Confirm this is you, <script>alert(1)</script>');
        expect(wrapper.find('script').exists()).toBe(false);
        expect(document.querySelectorAll('script').length).toBe(scriptsBefore);
        expect(wrapper.html()).toContain('&lt;script&gt;');

        wrapper.unmount();
    });

    // ── The same obligation for H7's net-new untrusted source ─────────────────────────────────────
    it('renders a URL-prefilled hidden value containing markup as visible TEXT (Increment H7)', async () => {
        // A piped ANSWER is untrusted because a respondent typed it; a piped PREFILL is untrusted because
        // whoever handed out the link chose it — and unlike a typed answer it is present on the very first
        // paint, before any interaction, on a surface with no `script-src`. Same escaper, different entry
        // point, so §5 owes this surface its own test rather than assuming the answer test covers it.
        const wrapper = mount(RuntimeSession, {
            props: {
                schema: schemaResponse({
                    fields: [
                        field({ key: 'promo', field_type: 'hidden', config: { prefill_source: 'url' }, sequence: 0 }),
                        field({ key: 'full_name', label: 'Your name (offer ${promo})', sequence: 1 }),
                    ],
                }),
                bootstrap,
                client: fakeClient(),
                search: '?promo=%3Cscript%3Ealert(1)%3C%2Fscript%3E',
            },
        });
        const scriptsBefore = document.querySelectorAll('script').length;
        await settle();

        expect(wrapper.text()).toContain('Your name (offer <script>alert(1)</script>)');
        expect(wrapper.find('script').exists()).toBe(false);
        expect(document.querySelectorAll('script').length).toBe(scriptsBefore);
        expect(wrapper.html()).toContain('&lt;script&gt;');
        // And the hidden field itself contributed no row of its own.
        expect(wrapper.text()).not.toContain('Not available for manual entry');

        wrapper.unmount();
    });

    it('emits the author confirmation message, locale-resolved and hole-filled', async () => {
        const wrapper = mount(RuntimeSession, {
            props: { schema: pipedSchema({ confirmation_message: 'Thanks, ${name}!' }), bootstrap, client: fakeClient() },
        });

        await wrapper.findAll('input')[0].setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(wrapper.emitted('submitted')?.[0]).toEqual([SUBMISSION_ID, SUBMISSION_REFERENCE, 'Thanks, Ada!']);
        wrapper.unmount();
    });

    it('emits the Filipino variant when the session is in Filipino', async () => {
        const schema = pipedSchema({
            supported_locales: ['en', 'fil'],
            default_locale: 'fil',
            confirmation_message: 'Thanks, ${name}!',
            confirmation_message_translations: { fil: 'Salamat, ${name}!' },
        });
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap: { ...bootstrap, defaultLocale: 'fil' }, client: fakeClient() } });

        await wrapper.findAll('input')[0].setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(wrapper.emitted('submitted')?.[0]).toEqual([SUBMISSION_ID, SUBMISSION_REFERENCE, 'Salamat, Ada!']);
        wrapper.unmount();
    });

    it('emits null when there is no message, so App.vue keeps its hardcoded default', async () => {
        const wrapper = mount(RuntimeSession, { props: { schema: pipedSchema(), bootstrap, client: fakeClient() } });

        await wrapper.findAll('input')[0].setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(wrapper.emitted('submitted')?.[0]).toEqual([SUBMISSION_ID, SUBMISSION_REFERENCE, null]);
        wrapper.unmount();
    });

    it('emits null when the message renders to nothing, rather than an empty heading', async () => {
        // A message that is entirely unanswered holes would give ConfirmationScreen's focused `<h1>` an
        // empty accessible name — an axe `empty-heading` violation on a page the e2e gate scans.
        const wrapper = mount(RuntimeSession, {
            props: { schema: pipedSchema({ confirmation_message: '${name}' }), bootstrap, client: fakeClient() },
        });

        await wrapper.find('form').trigger('submit');
        await settle();

        expect(wrapper.emitted('submitted')?.[0]).toEqual([SUBMISSION_ID, SUBMISSION_REFERENCE, null]);
        wrapper.unmount();
    });

    it('pipes each repeat instance against its OWN answers, in the rendered DOM', async () => {
        const schema = schemaResponse({
            sections: [{ ...section({ key: 'roster', label: 'Member', sequence: 1 }), is_repeatable: true, min_instances: 0, max_instances: 5 }],
            fields: [
                field({ key: 'member_name', section_key: 'roster', sequence: 1 }),
                field({ key: 'member_age', section_key: 'roster', sequence: 2, label: 'Age of ${member_name}' }),
            ],
        });
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client: fakeClient() } });

        await wrapper.find('.repeat-group__add button').trigger('click');
        await wrapper.find('.repeat-group__add button').trigger('click');
        await settle();

        const nameInputs = wrapper.findAll('[data-repeat-instance] input');
        await nameInputs[0].setValue('Ana');
        await nameInputs[2].setValue('Beni');
        await settle();

        const instances = wrapper.findAll('[data-repeat-instance]');
        expect(instances[0].text()).toContain('Age of Ana');
        expect(instances[1].text()).toContain('Age of Beni');
        expect(instances[0].text()).not.toContain('Beni');

        wrapper.unmount();
    });
});

// ─────────────────────────────────────────────────────────────────────────────────────────────────
// Increment H21b — non-linear execution, through the RENDERED DOM.
//
// Doc #27 §9 requires the view half explicitly: "the store and the view fail differently", and warns that the
// empty-graph assertions in particular "pass trivially against a component that renders nothing at all". Each
// empty-graph case therefore pairs its absences with a POSITIVE (the Submit control, by its exact label) and
// with a non-empty control.
// ─────────────────────────────────────────────────────────────────────────────────────────────────

/** The single aria-live region every announcement lands in (`RuntimeShell`). */
function announced(wrapper: ReturnType<typeof mount>): string {
    return wrapper.find('.runtime__sr-live').text();
}

describe('RuntimeSession — the empty graph renders a terminal state (H21b §4.1)', () => {
    /** A router form: the only section is gated on a hidden field that is never set. */
    const emptySchema = () =>
        schemaResponse({
            form: { single_page_mode: false },
            sections: [section({ key: 's1', label: 'Only section', sequence: 1, relevant_expression: "${gate} = 'go'" })],
            fields: [
                field({ key: 'gate', sequence: 0, field_type: 'hidden' }),
                field({ key: 'one', section_key: 's1', label: 'Only question', sequence: 1 }),
            ],
        });

    it('suppresses the counter, renders no section, and offers a labelled Submit', () => {
        const wrapper = mount(RuntimeSession, { props: { schema: emptySchema(), bootstrap, client: fakeClient() } });

        // 1. No "Step 1 of 0" — the whole progress nav is gone, not merely re-worded.
        expect(wrapper.find('nav[aria-label="Form progress"]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Step 1 of');
        // 2. No section renders.
        expect(wrapper.find('[data-section]').exists()).toBe(false);
        expect(wrapper.text()).not.toContain('Only question');
        // 3. The POSITIVE half — a terminal explanation and Submit as the single action, explicitly labelled
        //    as submitting what has been answered. Without this the test would pass against a blank component.
        expect(wrapper.text()).toContain('Nothing further to complete');
        expect(wrapper.find('button[type="submit"]').text()).toBe('Submit my answers');
        expect(wrapper.text()).not.toContain('Next');
        expect(wrapper.text()).not.toContain('Back');

        wrapper.unmount();
    });

    it('renders the ordinary step machinery as soon as one step exists (the control)', async () => {
        const wrapper = mount(RuntimeSession, {
            props: { schema: emptySchema(), bootstrap, client: fakeClient(), initialAnswers: { gate: 'go' } },
        });
        await flushPromises();

        expect(wrapper.find('nav[aria-label="Form progress"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('Step 1 of 1');
        expect(wrapper.text()).toContain('Only question');
        expect(wrapper.find('button[type="submit"]').text()).toBe('Submit');

        wrapper.unmount();
    });
});

describe('RuntimeSession — predicate 3 through the DOM (H21b, Doc #27 §2.2)', () => {
    /** A relevant section whose every field is individually gated off — the grayed-out step §3.2 rejects. */
    const schema = () =>
        schemaResponse({
            form: { single_page_mode: false },
            sections: [
                section({ key: 's1', label: 'First', sequence: 1 }),
                section({ key: 's2', label: 'Second', sequence: 2 }),
            ],
            fields: [
                field({ key: 'gate', section_key: 's1', label: 'Gate', sequence: 1 }),
                field({ key: 'inner', section_key: 's2', label: 'Inner', sequence: 2, relevant_expression: "${gate} = 'go'" }),
            ],
        });

    it('does not render a heading over zero questions, and does not count the step', async () => {
        const wrapper = mount(RuntimeSession, { props: { schema: schema(), bootstrap, client: fakeClient() } });
        await flushPromises();

        expect(wrapper.text()).toContain('Step 1 of 1');
        expect(wrapper.text()).not.toContain('Second');

        wrapper.unmount();
    });

    it('renders and counts the step once one of its fields becomes relevant', async () => {
        const wrapper = mount(RuntimeSession, {
            props: { schema: schema(), bootstrap, client: fakeClient(), initialAnswers: { gate: 'go' } },
        });
        await flushPromises();

        expect(wrapper.text()).toContain('Step 1 of 2');

        wrapper.unmount();
    });
});

describe('RuntimeSession — Submit is no longer a silent dead-end (H21b §5.5)', () => {
    it('shows the off-step failure in the banner and announces the real count', async () => {
        // The shape §5.5 says branching makes routine: an answer on the LAST step makes a field on an
        // already-passed step relevant and required. A backward reference, entirely legal (§3.1).
        const schema = schemaResponse({
            form: { single_page_mode: false },
            sections: [
                section({ key: 's1', label: 'First', sequence: 1 }),
                section({ key: 's2', label: 'Second', sequence: 2 }),
            ],
            fields: [
                // An always-relevant companion, so predicate 3 does not drop step 1 outright while `detail`
                // is gated off — that would make this a test of predicate 3 rather than of §5.5.
                field({ key: 'always', section_key: 's1', label: 'Always here', sequence: 1 }),
                field({
                    key: 'detail',
                    section_key: 's1',
                    label: 'First answer',
                    is_required: 'required',
                    relevant_expression: "${trigger} = 'yes'",
                    sequence: 2,
                }),
                field({ key: 'trigger', section_key: 's2', label: 'Second answer', sequence: 3 }),
            ],
        });
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });
        await flushPromises();

        // Step 1 passes: `detail` is irrelevant while `trigger` is unanswered, so nothing is required yet.
        await wrapper.findAll('button').filter((b) => b.text() === 'Next')[0].trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Step 2 of 2');

        // Answering here reaches back and makes the step-1 field relevant, required and empty.
        await wrapper.find('input').setValue('yes');
        await flushPromises();

        await wrapper.find('form').trigger('submit');
        await settle();

        // Before H21b: `bannerItems` was step-scoped, so nothing rendered and the announcement was literally
        // "0 fields need your attention before submitting." Submit did nothing and said nothing, permanently.
        expect(wrapper.find('.summary-banner').exists()).toBe(true);
        // The failing field lives on step 1 while the respondent is on step 2 — the exact case that used to
        // render nothing at all.
        expect(wrapper.find('.summary-banner').text()).toContain('First answer');
        expect(announced(wrapper)).toContain('1 field needs your attention before submitting.');
        expect(client.submit).not.toHaveBeenCalled();

        // And the jump link crosses the step boundary: navigate first, then land on the field (§5.5).
        await wrapper.find('.summary-banner__link').trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Step 1 of 2');

        wrapper.unmount();
    });

    it('keeps a blocked Next scoped to the current step', async () => {
        const schema = schemaResponse({
            form: { single_page_mode: false },
            sections: [
                section({ key: 's1', label: 'First', sequence: 1 }),
                section({ key: 's2', label: 'Second', sequence: 2 }),
            ],
            fields: [
                field({ key: 'first', section_key: 's1', label: 'First answer', is_required: 'required', sequence: 1 }),
                field({ key: 'second', section_key: 's2', label: 'Second answer', is_required: 'required', sequence: 2 }),
            ],
        });
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client: fakeClient() } });
        await flushPromises();

        await wrapper.findAll('button').filter((b) => b.text() === 'Next')[0].trigger('click');
        await flushPromises();

        // Both fields are failing, but Next is a statement about THIS step: one item, not two (§5.5).
        expect(wrapper.find('.summary-banner').text()).toContain('First answer');
        expect(wrapper.find('.summary-banner').text()).not.toContain('Second answer');
        expect(announced(wrapper)).toContain('1 field needs your attention before continuing.');

        wrapper.unmount();
    });
});

describe('RuntimeSession — a step that changes behind the respondent (H21b §3.1, §4.4)', () => {
    it('announces the new count and does not offer a done dot for a step never seen', async () => {
        // The gate lives on the LAST step and controls the middle one — a forward reference at authoring time
        // and, at fill time, exactly §3.1's "a step becomes relevant behind the respondent".
        const schema = schemaResponse({
            form: { single_page_mode: false },
            sections: [
                section({ key: 's1', label: 'First', sequence: 1 }),
                section({ key: 's2', label: 'Second', sequence: 2, relevant_expression: "${gate} = 'go'" }),
                section({ key: 's3', label: 'Third', sequence: 3 }),
            ],
            fields: [
                field({ key: 'one', section_key: 's1', label: 'First answer', sequence: 1 }),
                field({ key: 'two', section_key: 's2', label: 'Second answer', sequence: 2 }),
                field({ key: 'gate', section_key: 's3', label: 'Gate', sequence: 3 }),
            ],
        });
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client: fakeClient() } });
        await flushPromises();

        await wrapper.findAll('button').filter((b) => b.text() === 'Next')[0].trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Step 2 of 2'); // on s3; s2 is hidden

        await wrapper.find('input').setValue('go');
        await flushPromises();

        expect(wrapper.text()).toContain('Step 3 of 3');
        expect(announced(wrapper)).toContain('This form now has 3 steps.');
        // "Done" dots are buttons inside the progress nav. Only s1 has actually been visited, so exactly one
        // is offered — `index < currentIndex` would have offered two, one of them for a step never seen.
        expect(wrapper.findAll('nav[aria-label="Form progress"] button')).toHaveLength(1);

        wrapper.unmount();
    });

    it('announces the reason when the respondent’s own step vanishes, and says where they went', async () => {
        // s2 is gated on a field INSIDE s2, so answering it removes the step under the respondent.
        const schema = schemaResponse({
            form: { single_page_mode: false },
            sections: [
                section({ key: 's1', label: 'First', sequence: 1 }),
                section({ key: 's2', label: 'Second', sequence: 2, relevant_expression: "${leave} != 'stop'" }),
                section({ key: 's3', label: 'Third', sequence: 3 }),
            ],
            fields: [
                field({ key: 'one', section_key: 's1', label: 'First answer', sequence: 1 }),
                field({ key: 'leave', section_key: 's2', label: 'Second answer', sequence: 2 }),
                field({ key: 'three', section_key: 's3', label: 'Third answer', sequence: 3 }),
            ],
        });
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client: fakeClient() } });
        await flushPromises();

        await wrapper.findAll('button').filter((b) => b.text() === 'Next')[0].trigger('click');
        await flushPromises();
        expect(wrapper.text()).toContain('Step 2 of 3');

        await wrapper.find('input').setValue('stop');
        await flushPromises();
        await flushPromises();

        // §4.2 — the rescue walks to the nearest surviving PREDECESSOR (s1), the announcement says WHY they
        // moved rather than merely naming a destination, and the retained answer on the vanished step is
        // acknowledged (§4.4) rather than silently dropped.
        expect(wrapper.text()).toContain('Step 1 of 2');
        expect(announced(wrapper)).toContain('The step you were on no longer applies');
        expect(wrapper.find('.relevance-note').text()).toContain('Second');

        wrapper.unmount();
    });
});

describe('RuntimeSession — single-page section removal (H21b §4.4)', () => {
    const schema = () =>
        schemaResponse({
            form: { single_page_mode: true },
            sections: [
                section({ key: 's1', label: 'First', sequence: 1 }),
                section({ key: 's2', label: 'Second', sequence: 2, relevant_expression: "${gate} = 'go'" }),
            ],
            fields: [
                field({ key: 'gate', section_key: 's1', label: 'Gate', sequence: 1 }),
                field({ key: 'two', section_key: 's2', label: 'Second answer', sequence: 2 }),
            ],
        });

    it('announces the removal and rescues focus to a reachable element', async () => {
        const wrapper = mount(RuntimeSession, {
            props: { schema: schema(), bootstrap, client: fakeClient(), initialAnswers: { gate: 'go' } },
            attachTo: document.body,
        });
        await flushPromises();
        expect(wrapper.text()).toContain('Second answer');

        // Put the caret inside the section that is about to vanish, then close its gate.
        const inner = wrapper.findAll('input')[1];
        inner.element.focus();
        inner.element.dispatchEvent(new FocusEvent('focusin', { bubbles: true }));
        expect(document.activeElement).toBe(inner.element);

        await wrapper.findAll('input')[0].setValue('stop');
        await flushPromises();
        await flushPromises();

        expect(wrapper.text()).not.toContain('Second answer');
        // An axe pass cannot catch a focus reset to <body>; assert the landing spot explicitly.
        expect(document.activeElement).not.toBe(document.body);
        expect(document.activeElement?.hasAttribute('data-section-heading')).toBe(true);
        expect(announced(wrapper)).toContain('no longer applies');

        wrapper.unmount();
    });

    it('says the retained answers are kept when the removed section held one', async () => {
        const wrapper = mount(RuntimeSession, {
            props: {
                schema: schema(),
                bootstrap,
                client: fakeClient(),
                initialAnswers: { gate: 'go', two: 'already typed' },
            },
        });
        await flushPromises();

        await wrapper.findAll('input')[0].setValue('stop');
        await flushPromises();
        await flushPromises();

        expect(wrapper.find('.relevance-note').text()).toContain('won’t be included');
        expect(wrapper.find('.relevance-note').text()).toContain('Second');
        expect(announced(wrapper)).toContain('saved in case');

        wrapper.unmount();
    });
});

describe('RuntimeSession — the 409 a respondent is told the truth about (Increment M14)', () => {
    const conflictSchema = () =>
        schemaResponse({
            form: { save_and_resume: true },
            fields: [field({ key: 'name', label: 'Full name' })],
        });

    /**
     * ⚠️ `settle()`'s FIXED FIVE MACROTASK TICKS ARE NOT ENOUGH HERE, AND THE FLAKE PROVED IT RATHER THAN
     * THEORY. Every case below drives a submit, which awaits a Dexie enqueue BEFORE the network call and a
     * `discardRow` after it; under load that chain occasionally outlasts the ticks, and the assertion then
     * read `undefined` on a component that was about to emit. One case in three failed that way — a real
     * defect in the test, not a run to re-roll until it passes. Waiting for the emit makes the case
     * deterministic without weakening what it asserts.
     */
    async function waitForEmit(wrapper: { emitted: (e: string) => unknown[] | undefined }, event: string): Promise<void> {
        await vi.waitFor(
            () => {
                if (wrapper.emitted(event) === undefined) {
                    throw new Error(`no ${event} emit yet`);
                }
            },
            { timeout: 3000, interval: 10 },
        );
    }

    function rejecting(code: string, message: string): () => never {
        return () => {
            throw new ApiError(normalizeError(409, { error: { code, message } }));
        };
    }

    it('re-reads the DRAFT on a submit refused for a stale baseline, instead of re-fetching the schema', async () => {
        // ⚠️ THE FIRST BACKLOG ROW, PINNED. `draft_conflict` means another device wrote this draft. Before
        // M14 it normalized to `refresh` and took the drift branch: re-mint, re-fetch a schema nobody
        // republished, then remount under a fresh uuid with `draftBaseline` null — abandoning the server
        // draft mid-edit and resubmitting with no baseline at all.
        const client = fakeClient({ submit: vi.fn(rejecting('draft_conflict', 'This draft was updated on another device.')) });
        const wrapper = mount(RuntimeSession, {
            props: { schema: conflictSchema(), bootstrap: { ...bootstrap, resumeToken: 'rt-boot' }, client },
        });

        await wrapper.find('input').setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();
        await waitForEmit(wrapper, 'redraft');

        expect(wrapper.emitted('redraft')?.[0]).toEqual([{ resumeToken: 'rt-boot' }]);
        // NOT the drift path — the schema was never in question, and asking for it again is what made the
        // recovery discard the uuid.
        expect(wrapper.emitted('reschema')).toBeUndefined();
        expect(client.remint).not.toHaveBeenCalled();
        expect(client.fetchSchema).not.toHaveBeenCalled();

        wrapper.unmount();
    });

    it('carries the resume token minted by the LAST SAVE, not only one from a resume link', async () => {
        // ⚠️ THE LINE THIS PINS IS A DELETION THAT WAS NEVER NOTICED: `saveDraftAction` read three fields off
        // the save result and dropped `resumeToken`, which `GuestDraftController` returns on EVERY save. So a
        // respondent who had never opened a resume LINK — the ordinary case — had nothing to re-read with.
        // `resumeToken: ''` here is what makes the bootstrap source unavailable and forces the save source.
        const client = fakeClient({
            saveDraft: vi
                .fn()
                .mockResolvedValueOnce({
                    id: SUBMISSION_ID,
                    completenessPercent: 50,
                    resumeToken: 'rt-from-save',
                    resumeUrl: 'https://acme.test/f/resume/rt-from-save',
                    expiresAt: '',
                    contentChecksum: 'sum-1',
                })
                .mockImplementationOnce(rejecting('draft_conflict', 'This draft was updated on another device.')),
        });
        const wrapper = mount(RuntimeSession, {
            props: { schema: conflictSchema(), bootstrap: { ...bootstrap, resumeToken: '' }, client },
        });
        await settle();

        // Two passes through the panel: the first mints the token, the second is refused.
        await wrapper.findAll('button').filter((b) => b.text().includes('Save and finish later'))[0].trigger('click');
        await settle();
        await wrapper.findAll('button').filter((b) => b.text().includes('Save and finish later'))[0].trigger('click');
        await settle();
        await waitForEmit(wrapper, 'redraft');

        expect(wrapper.emitted('redraft')?.[0]).toEqual([{ resumeToken: 'rt-from-save' }]);

        wrapper.unmount();
    });

    it('hands the SAVE channel’s token to the SUBMIT channel, which is the only thing either fold shares', async () => {
        // ⚠️ THIS CASE EXISTS BECAUSE THE MUTATION MATRIX SAID IT HAD TO. Reverting the save fold's routing
        // (M6) and deleting the token capture (M8) reddened the IDENTICAL single case, which means that case
        // was pinning "the save path emits redraft" and "the token is kept" as ONE observable — nothing could
        // tell the two apart, exactly the shape this project has recorded before.
        //
        // They separate here: the token is minted by a SAVE and spent by a SUBMIT. Deleting the capture
        // reddens this; changing the save fold's routing does not, because this refusal arrives on the other
        // channel entirely. It also pins the property that actually matters to a respondent — one that fills
        // in, saves for later, comes back and submits, all on one device, and never sees a resume link.
        const client = fakeClient({
            saveDraft: vi.fn(async () => ({
                id: SUBMISSION_ID,
                completenessPercent: 50,
                resumeToken: 'rt-from-save',
                resumeUrl: 'https://acme.test/f/resume/rt-from-save',
                expiresAt: '',
                contentChecksum: 'sum-1',
            })),
            submit: vi.fn(rejecting('draft_conflict', 'This draft was updated on another device.')),
        });
        const wrapper = mount(RuntimeSession, {
            props: { schema: conflictSchema(), bootstrap: { ...bootstrap, resumeToken: '' }, client },
        });
        await settle();

        // Save first, so the only token in the session is the one the save returned.
        await wrapper.findAll('button').filter((b) => b.text().includes('Save and finish later'))[0].trigger('click');
        await settle();

        await wrapper.find('input').setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();
        await waitForEmit(wrapper, 'redraft');

        expect(wrapper.emitted('redraft')?.[0]).toEqual([{ resumeToken: 'rt-from-save' }]);

        wrapper.unmount();
    });

    it('names the CAUSE when a content conflict remounts, rather than asserting a republish', async () => {
        // `submission_conflict` keeps the remount — a fresh uuid is a fine remedy for it — so the code, not
        // the recovery, is what M14 changes here. The emit carries it so App.vue can pick a true sentence
        // instead of "This form was updated", which is the false half of the first backlog row.
        const client = fakeClient({
            submit: vi.fn(rejecting('submission_conflict', 'This response conflicts with a copy already saved.')),
            // `fakeClient`'s default `fetchSchema` throws, which `handleDrift` would swallow into its notice
            // arm — so a case about the emit has to supply one or it measures the catch block instead.
            fetchSchema: vi.fn(async () => conflictSchema()),
        });
        const wrapper = mount(RuntimeSession, { props: { schema: conflictSchema(), bootstrap, client } });

        await wrapper.find('input').setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();
        await waitForEmit(wrapper, 'reschema');

        expect(wrapper.emitted('reschema')?.[0]).toEqual([
            expect.objectContaining({ conflictCode: 'submission_conflict' }),
        ]);
        expect(wrapper.emitted('redraft')).toBeUndefined();

        wrapper.unmount();
    });

    it('still calls a republish a republish, which is the one cause the old copy was right about', async () => {
        // ⚠️ THE CONTROL, AND IT GUARDS A STRING AN E2E THIS HOST CANNOT RUN ASSERTS ON. `form_updated` must
        // keep emitting a NULL code so App.vue reaches the unchanged drift wording;
        // `tests/e2e/public-runtime-offline.spec.ts` matches /this form was updated/i against it.
        const client = fakeClient({
            submit: vi.fn(rejecting('form_updated', 'This form has been updated.')),
            fetchSchema: vi.fn(async () => conflictSchema()),
        });
        const wrapper = mount(RuntimeSession, { props: { schema: conflictSchema(), bootstrap, client } });

        await wrapper.find('input').setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();
        await waitForEmit(wrapper, 'reschema');

        expect(client.remint).toHaveBeenCalled();
        expect(wrapper.emitted('reschema')?.[0]).toEqual([expect.objectContaining({ conflictCode: null })]);
        expect(wrapper.emitted('redraft')).toBeUndefined();

        wrapper.unmount();
    });

    /*
     * Increment M66 — the recovery path's own failure, which until now collapsed into one sentence.
     *
     * `handleDrift`'s `catch` took no argument, so a dropped connection during `remint()` read as "This form
     * is no longer available." The comment two cases above names the swallow and works AROUND it — supplying
     * a `fetchSchema` so the case under test does not fall into the catch block — which is as close as this
     * suite ever came to asserting on it.
     *
     * ⛔ THE TWO CASES BELOW ARE MUTATION-DISTINCT ON PURPOSE, AND THE SECOND IS THE ONE THAT MATTERS MOST.
     * Deleting the network arm reddens the first; deleting the terminal arm reddens the second. Without the
     * second, "stop saying the form is gone" could be satisfied by never saying it — which would turn a
     * genuinely dead form into a "check your connection" lie, the same defect pointing the other way.
     */
    it('calls a dropped connection a dropped connection, not a dead form', async () => {
        const client = fakeClient({
            submit: vi.fn(rejecting('form_updated', 'This form has been updated.')),
            // A raw fetch rejection — what the browser throws when the connection drops. NOT an ApiError,
            // which is the discriminator this component already uses in `handleSubmitError`'s tail.
            remint: vi.fn(async () => {
                throw new TypeError('Failed to fetch');
            }),
        });
        const wrapper = mount(RuntimeSession, { props: { schema: conflictSchema(), bootstrap, client } });

        await wrapper.find('input').setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();

        const notice = wrapper.find('.session-notice').text();

        expect(notice).not.toContain('no longer available');
        expect(notice).toContain('check your connection');
        expect(wrapper.emitted('reschema')).toBeUndefined();

        wrapper.unmount();
    });

    it('still says the form is gone when the server says the form is gone', async () => {
        const client = fakeClient({
            submit: vi.fn(rejecting('form_updated', 'This form has been updated.')),
            remint: vi.fn(() => {
                throw new ApiError(normalizeError(404, { error: { code: 'form_not_found', message: 'Not found.' } }));
            }),
        });
        const wrapper = mount(RuntimeSession, { props: { schema: conflictSchema(), bootstrap, client } });

        await wrapper.find('input').setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();

        // Verbatim, and asserted as the whole string: this is the one cause the original sentence was
        // always right about, and keeping it byte-identical is what makes the change a narrowing rather
        // than a rewrite.
        expect(wrapper.find('.session-notice').text()).toBe('This form is no longer available.');

        wrapper.unmount();
    });

    it('does not promise a resolving respondent that their answers are saved on this device', async () => {
        // ⛔ THE BRANCH EXISTS BECAUSE THE REASSURING SENTENCE WOULD BE FALSE HERE. `handleSubmitError`
        // discards the durable outbox row before calling `handleDrift`, and a resolving session has autosave
        // disabled — the parked row WAS its durable copy. So in this branch the reviewed answers are in
        // memory only, and "saved on this device" would be the same error this increment came to remove.
        const client = fakeClient({
            submit: vi.fn(rejecting('form_updated', 'This form has been updated.')),
            remint: vi.fn(async () => {
                throw new TypeError('Failed to fetch');
            }),
        });
        const wrapper = mount(RuntimeSession, {
            props: { schema: conflictSchema(), bootstrap, client, resolving: true },
        });

        await wrapper.find('input').setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();

        const notice = wrapper.find('.session-notice').text();

        expect(notice).not.toContain('saved on this device');
        expect(notice).toContain('keep this page open');

        wrapper.unmount();
    });
});

describe('RuntimeSession — resume drift explains itself (H21b §5.3)', () => {
    it('says the stored step no longer applies and names where the respondent landed', async () => {
        const schema = schemaResponse({
            form: { single_page_mode: false, save_and_resume: true },
            sections: [
                section({ key: 's1', label: 'First', sequence: 1 }),
                section({ key: 's2', label: 'Second', sequence: 2, relevant_expression: "${gate} = 'go'" }),
            ],
            fields: [
                field({ key: 'gate', section_key: 's1', label: 'Gate', sequence: 1 }),
                field({ key: 'two', section_key: 's2', label: 'Second answer', sequence: 2 }),
            ],
        });
        const wrapper = mount(RuntimeSession, {
            props: {
                schema,
                bootstrap,
                client: fakeClient(),
                initialAnswers: {},
                // The cursor was saved on s2; the replayed answers put this respondent on a branch without it.
                resume: { uuid: 'draft-1', locale: null, stepKey: 's2', completeness: 40, note: null },
            },
        });
        await settle();

        // `goToStep` used to be a guarded no-op here, so the respondent silently got step 1 under a banner
        // claiming a percentage. A test asserting only "did not throw" would be vacuously green.
        expect(wrapper.text()).toContain('Welcome back');
        expect(wrapper.text()).toContain('no longer applies to your answers');
        expect(wrapper.text()).toContain('First');
        expect(wrapper.text()).toContain('Step 1 of 1');

        wrapper.unmount();
    });

    it('says nothing extra when the stored step resolves exactly', async () => {
        const schema = schemaResponse({
            form: { single_page_mode: false, save_and_resume: true },
            sections: [
                section({ key: 's1', label: 'First', sequence: 1 }),
                section({ key: 's2', label: 'Second', sequence: 2 }),
            ],
            fields: [
                field({ key: 'one', section_key: 's1', label: 'First answer', sequence: 1 }),
                field({ key: 'two', section_key: 's2', label: 'Second answer', sequence: 2 }),
            ],
        });
        const wrapper = mount(RuntimeSession, {
            props: {
                schema,
                bootstrap,
                client: fakeClient(),
                initialAnswers: {},
                resume: { uuid: 'draft-1', locale: null, stepKey: 's2', completeness: 40, note: null },
            },
        });
        await settle();

        expect(wrapper.text()).toContain('Welcome back');
        expect(wrapper.text()).not.toContain('no longer applies to your answers');
        expect(wrapper.text()).toContain('Step 2 of 2');

        wrapper.unmount();
    });
});
