import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import RuntimeSession from '../components/RuntimeSession.vue';
import type { ApiClient } from '../lib/api-client';
import type { Bootstrap } from '../lib/types';
import { field, schemaResponse, section } from './fixtures';

const SUBMISSION_ID = '0192f1a2-b3c4-7d5e-8f90-1a2b3c4d5e6f';

function fakeClient(overrides: Partial<ApiClient> = {}): ApiClient {
    return {
        fetchSchema: vi.fn(async () => {
            throw new Error('unused in these tests');
        }),
        submit: vi.fn(async () => ({ id: SUBMISSION_ID, status: 'submitted', created: true })),
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
        expect(wrapper.emitted('submitted')?.[0]).toEqual([SUBMISSION_ID, null]);

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

        // The welcome-back banner reports progress + the reconciliation note, and the answers are restored.
        expect(wrapper.text()).toContain('Welcome back');
        expect(wrapper.text()).toContain('64%');
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

        expect(wrapper.emitted('submitted')?.[0]).toEqual([SUBMISSION_ID, 'Thanks, Ada!']);
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

        expect(wrapper.emitted('submitted')?.[0]).toEqual([SUBMISSION_ID, 'Salamat, Ada!']);
        wrapper.unmount();
    });

    it('emits null when there is no message, so App.vue keeps its hardcoded default', async () => {
        const wrapper = mount(RuntimeSession, { props: { schema: pipedSchema(), bootstrap, client: fakeClient() } });

        await wrapper.findAll('input')[0].setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(wrapper.emitted('submitted')?.[0]).toEqual([SUBMISSION_ID, null]);
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

        expect(wrapper.emitted('submitted')?.[0]).toEqual([SUBMISSION_ID, null]);
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
