import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';

import { field } from '../../../public-runtime/__tests__/fixtures';
import type { RawField } from '../../../public-runtime/lib/types';
import type { ScanReview } from '@/components/ocr/scan-review';

/**
 * M129 — the encode page in SCAN mode (single-form OCR groundwork 2): the review screen.
 *
 * What is new here is the mode, not the form: the answers start from what the scan read, autosave never runs,
 * each answer carries the scan's note, and Save posts the reviewer's answers — and nothing else — to the scan's
 * own route. Every absence assertion is paired with a positive one, the encode suite's anti-vacuity rule.
 */

const mocks = vi.hoisted(() => ({
    pageProps: {
        errors: {} as Record<string, string>,
        flash: {} as Record<string, unknown>,
        auth: { can: { manageForms: true } },
    },
    post: vi.fn(),
    patch: vi.fn(),
    visit: vi.fn(),
    beforeVisit: null as null | ((event: Event) => boolean | void),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router: {
        post: mocks.post,
        patch: mocks.patch,
        visit: mocks.visit,
        on: (type: string, callback: (event: Event) => boolean | void) => {
            if (type === 'before') {
                mocks.beforeVisit = callback;
            }

            return () => undefined;
        },
    },
    usePage: () => ({ props: mocks.pageProps }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: {
        name: 'PageHeader',
        props: ['title'],
        template: '<header><h1>{{ title }}</h1><slot name="breadcrumbs" /><slot name="actions" /></header>',
    },
}));

const Encode = (await import('./Encode.vue')).default;

function blockField(key: string, type: string, label: string, extra: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        key,
        field_type: type,
        label,
        hint: null,
        placeholder: null,
        required: false,
        options: [],
        cascade: null,
        matrix: null,
        geo: null,
        media: null,
        upload: null,
        prefill: null,
        prefill_value: null,
        supported: true,
        ...extra,
    };
}

const FIELDS: RawField[] = [
    field({ key: 'name', label: 'Name', sequence: 1 }),
    field({ key: 'age', field_type: 'integer', label: 'Age', sequence: 2 }),
    field({ key: 'symptoms', label: 'Symptoms', sequence: 3 }),
    field({ key: 'consent', field_type: 'yes_no', label: 'Consent', sequence: 4 }),
    // Shown only when the name is "show me" — so a carried answer here is hidden by the OTHER answers.
    field({ key: 'details', label: 'Details', sequence: 5, relevant_expression: "${name} = 'show me'" }),
];

function scan(overrides: Partial<ScanReview> = {}): ScanReview {
    return {
        id: 'scan-1',
        submit_url: '/forms/form-1/ocr/scans/scan-1/confirm',
        answers: { name: 'Maria Santos', age: 41, details: 'Read from the paper' },
        fields: {
            name: { state: 'read', tier: 'auto', confidence: 96, text: 'MARIA SANTOS', page: 1, carried: true, reason: null },
            age: { state: 'read', tier: 'review', confidence: 82, text: '41', page: 1, carried: true, reason: null },
            symptoms: { state: 'read', tier: 'manual', confidence: 52, text: 'fever?', page: 1, carried: false, reason: null },
            consent: { state: 'blank', tier: null, confidence: null, text: null, page: 1, carried: false, reason: null },
            details: { state: 'read', tier: 'auto', confidence: 93, text: 'Read from the paper', page: 1, carried: true, reason: null },
        },
        pages: [{ number: 1, url: '/forms/form-1/ocr/scans/scan-1/pages/1', mime: 'image/png', servable: true }],
        notices: [
            {
                code: 'not_carried',
                tone: 'warning',
                message: 'One answer on the paper could not be filled in:',
                items: [{ label: 'Time spent', text: '1 30', message: 'Answers to this kind of question cannot be entered yet.' }],
            },
        ],
        version: { number: 1, current_number: 1 },
        ...overrides,
    };
}

function payload(scanReview: ScanReview | null, draftUrl: string | null = null): Record<string, unknown> {
    return {
        form: {
            id: 'form-1',
            title: 'Clinic Visit',
            description: null,
            default_locale: 'en',
            supported_locales: ['en'],
            single_page_mode: true,
            schedule: { opens_at: null, closes_at: null, timezone: 'UTC', max_responses: null, acceptance: 'open', remaining: null },
        },
        version: {
            id: 'ver-1',
            version_number: 1,
            checksum: 'checksum-abc',
            schema: { sections: [], fields: FIELDS },
            now: '2026-10-03T09:30:00+00:00',
        },
        blocks: [
            {
                id: null,
                key: null,
                label: null,
                description: null,
                repeatable: false,
                min_instances: null,
                max_instances: null,
                fields: [
                    blockField('name', 'short_text', 'Name'),
                    blockField('age', 'integer', 'Age'),
                    blockField('symptoms', 'short_text', 'Symptoms'),
                    blockField('consent', 'yes_no', 'Consent'),
                    blockField('details', 'short_text', 'Details'),
                ],
            },
        ],
        steps: [],
        draft: null,
        editing: null,
        update_url: null,
        draft_url: draftUrl,
        crumbs: [
            { label: 'Forms', href: '/forms' },
            { label: 'Clinic Visit', href: '/forms/form-1' },
            { label: 'Responses', href: '/forms/form-1/submissions' },
            { label: 'Scanned forms', href: '/forms/form-1/ocr/scans' },
            { label: 'Review a scan' },
        ],
        cancel_url: '/forms/form-1/ocr/scans',
        scan: scanReview,
    };
}

const mounted: VueWrapper[] = [];

function mountScan(props: Record<string, unknown>): VueWrapper {
    const wrapper = mount(Encode, { props: props as never, attachTo: document.body });
    mounted.push(wrapper);
    return wrapper;
}

function inputFor(wrapper: VueWrapper, label: string): HTMLInputElement {
    const labelEl = wrapper.findAll('label').find((el) => el.text().trim().startsWith(label));
    expect(labelEl, `no input labelled "${label}"`).toBeDefined();
    return wrapper.find(`#${labelEl!.attributes('for')}`).element as HTMLInputElement;
}

function rowFor(wrapper: VueWrapper, key: string) {
    return wrapper.find(`#encode-field-${key}`);
}

const fetchSpy = vi.fn(() => Promise.resolve({ ok: true, status: 204, json: () => Promise.resolve({}) }));

beforeEach(() => {
    mocks.pageProps.errors = {};
    mocks.pageProps.flash = {};
    mocks.post.mockReset();
    mocks.beforeVisit = null;
    fetchSpy.mockClear();
    vi.stubGlobal('fetch', fetchSpy);
});

afterEach(() => {
    while (mounted.length > 0) {
        mounted.pop()?.unmount();
    }
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('Encode.vue in scan mode', () => {
    it('starts from the answers the scan read, a number as a number', () => {
        const wrapper = mountScan(payload(scan()));

        expect(inputFor(wrapper, 'Name').value).toBe('Maria Santos');
        expect(inputFor(wrapper, 'Age').value).toBe('41');
        expect(inputFor(wrapper, 'Symptoms').value).toBe('');
    });

    it('names the page for what it is, and the button for what it does', () => {
        const wrapper = mountScan(payload(scan()));

        expect(wrapper.find('h1').text()).toBe('Review a scanned response');
        expect(wrapper.find('button[type="submit"]').text()).toContain('Save response');
        expect(wrapper.text()).toContain('Compare each answer with the paper');
    });

    it('never autosaves a review, even when a draft endpoint is present', async () => {
        vi.useFakeTimers();
        const wrapper = mountScan(payload(scan(), '/forms/form-1/submissions/draft'));

        const input = wrapper.find(`#${wrapper.findAll('label').find((el) => el.text().trim().startsWith('Name'))!.attributes('for')}`);
        await input.setValue('Maria S. Santos');
        await nextTick();
        await vi.advanceTimersByTimeAsync(5000);

        const draftCalls = fetchSpy.mock.calls.filter((call) => String((call as unknown[])[0]).includes('/draft'));
        expect(draftCalls).toHaveLength(0);
        // The positive half: the edit itself landed, so the absence above is not an inert page.
        expect(inputFor(wrapper, 'Name').value).toBe('Maria S. Santos');
    });

    it('posts the reviewer\'s answers, and nothing else, to the scan\'s own route', async () => {
        const wrapper = mountScan(payload(scan()));

        await wrapper.find('form').trigger('submit');

        expect(mocks.post).toHaveBeenCalledTimes(1);
        const [url, body] = mocks.post.mock.calls[0] as [string, Record<string, unknown>];
        expect(url).toBe('/forms/form-1/ocr/scans/scan-1/confirm');
        expect(Object.keys(body)).toEqual(['answers']);
        expect((body.answers as Record<string, unknown>).name).toBe('Maria Santos');
        expect((body.answers as Record<string, unknown>).age).toBe(41);
    });

    it('marks what the scan needs a person for, in words, and leaves a confident answer unmarked', () => {
        const wrapper = mountScan(payload(scan()));

        expect(rowFor(wrapper, 'age').classes()).toContain('encode__scan-row--warning');
        expect(rowFor(wrapper, 'age').text()).toContain('Check this answer: it was read at 82% confidence.');
        expect(rowFor(wrapper, 'symptoms').classes()).toContain('encode__scan-row--danger');
        expect(rowFor(wrapper, 'symptoms').text()).toContain('Needs manual entry: the scan read “fever?”');
        expect(rowFor(wrapper, 'consent').classes()).toContain('encode__scan-row--neutral');
        expect(rowFor(wrapper, 'consent').text()).toContain('Left blank on the paper.');

        expect(rowFor(wrapper, 'name').exists()).toBe(true);
        expect(rowFor(wrapper, 'name').classes()).not.toContain('encode__scan-row');
        expect(rowFor(wrapper, 'name').find('.encode__scan-note').exists()).toBe(false);
    });

    it('lists every answer that could not be filled in, in the server\'s words', () => {
        const wrapper = mountScan(payload(scan()));
        const notice = wrapper.find('[data-notice="not_carried"]');

        expect(notice.exists()).toBe(true);
        expect(notice.text()).toContain('One answer on the paper could not be filled in:');
        expect(notice.text()).toContain('Time spent');
        expect(notice.text()).toContain('the scan read “1 30”');
    });

    it('says which filled-in answers the other answers hide, before they are pruned at save', () => {
        const wrapper = mountScan(payload(scan()));
        const live = wrapper.find('.encode__scan-hidden');

        expect(live.attributes('aria-live')).toBe('polite');
        expect(live.text()).toContain('Details');

        const none = mountScan(payload(scan({ answers: { name: 'Maria Santos' } })));
        expect(none.find('.encode__scan-hidden').exists()).toBe(true);
        expect(none.find('.encode__scan-hidden').text()).toBe('');
    });

    it('shows the scanned page beside the form', () => {
        const wrapper = mountScan(payload(scan()));
        const image = wrapper.find('.encode__scan-pages img');

        expect(image.attributes('src')).toBe('/forms/form-1/ocr/scans/scan-1/pages/1');
        expect(image.attributes('alt')).toBe('Scanned page 1');
    });

    it('shows a save refused for no single answer as an alert, keeping the page', async () => {
        mocks.pageProps.errors = { scan: 'This form is closed.' };
        const wrapper = mountScan(payload(scan()));
        await nextTick();

        expect(wrapper.text()).toContain('This response was not saved');
        expect(wrapper.text()).toContain('This form is closed.');
    });

    it('asks before leaving a review whose corrections are not saved', async () => {
        const wrapper = mountScan(payload(scan()));

        await wrapper.find(`#${wrapper.findAll('label').find((el) => el.text().trim().startsWith('Age'))!.attributes('for')}`).setValue('42');
        await nextTick();

        const event = new CustomEvent('before', { detail: { visit: { url: new URL('http://acme.test/forms'), method: 'get' } } });
        expect(mocks.beforeVisit).not.toBeNull();
        expect(mocks.beforeVisit!(event)).toBe(false);
        await nextTick();

        expect(wrapper.text()).toContain('Your corrections to this scan are not saved until you choose Save response.');
    });
});

describe('Encode.vue outside scan mode', () => {
    it('renders no scan notes, notices, pages or live region when there is no scan', () => {
        const wrapper = mountScan({ ...payload(null), scan: undefined });

        expect(wrapper.find('.encode__scan-pages').exists()).toBe(false);
        expect(wrapper.find('.encode__scan-hidden').exists()).toBe(false);
        expect(wrapper.find('.encode__scan-row').exists()).toBe(false);
        expect(wrapper.find('h1').text()).toBe('New submission');
        // The positive half: the form itself rendered.
        expect(inputFor(wrapper, 'Name').value).toBe('');
    });
});
