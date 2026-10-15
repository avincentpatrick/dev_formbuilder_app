/**
 * M172 (`R-81756e95`) — a builder page left open across a publish.
 *
 * The testers' report: "The key has already been taken. … has a change that was not saved. Select it to see why", then
 * "That item belongs to a published version and can no longer be edited". A publish clones the draft with new ids and the
 * same keys, so the page's next save named an id of the frozen version. Now every write names the draft the page was
 * loaded with, the server refuses a superseded one 409 `draft_superseded`, the page says so once and offers the reload —
 * and the edits it was holding come back in the new draft, found by the key a publish keeps.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import DraftSupersededDialog from './DraftSupersededDialog.vue';
import { fetchMock, jsonResponse, pageProps, requestLog, serverField } from './builder-store-fixtures';
import { useBuilderStore, type BuilderStore } from './useBuilderStore';
import type { ServerField } from './types';

const SUPERSEDED = {
    code: 'draft_superseded',
    message: 'This form was published since this page loaded. Reload to keep editing.',
    draft: { id: 'v-2', version_number: 2 },
};

let mock: ReturnType<typeof vi.fn>;

/** The server keeps whatever it is sent and answers with the next token. */
function serverKeepsEverything(): void {
    let version = 10;
    mock.mockImplementation((url: string, init?: RequestInit) => {
        const body = typeof init?.body === 'string' ? (JSON.parse(init.body) as Record<string, unknown>) : {};
        version += 1;
        const id = url.split('/').pop();

        return Promise.resolve(jsonResponse(200, { ...serverField(), ...body, id, version: `v${version}` }));
    });
}

/** A label edited in the settings pane, and the debounce running out. */
async function editLabel(store: BuilderStore, label: string): Promise<void> {
    const field = store.fields.value[0];
    field.label = label;
    store.touch(field.uid, 'field');
    await vi.advanceTimersByTimeAsync(600);
    await store.whenIdle();
    await flushPromises();
}

function writes() {
    return requestLog(mock).filter((request) => request.method !== 'GET');
}

beforeEach(() => {
    vi.useFakeTimers();
    sessionStorage.clear();
    mock = fetchMock();
    serverKeepsEverything();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    sessionStorage.clear();
});

describe('every builder write names the draft the page was loaded with', () => {
    it('sends it on a save, a delete and a reorder, and on no read', async () => {
        const store = useBuilderStore(pageProps());

        await editLabel(store, 'Job');
        await store.refreshLibrary();
        await store.deleteField(store.fields.value[0].uid);
        await flushPromises();

        const log = requestLog(mock);
        expect(log.map((request) => request.method)).toEqual(['PATCH', 'GET', 'DELETE']);
        expect(log.filter((request) => request.method !== 'GET').map((request) => request.headers['X-Builder-Draft'])).toEqual(['v-1', 'v-1']);
        expect(log.find((request) => request.method === 'GET')!.headers['X-Builder-Draft']).toBeUndefined();
    });
});

describe('a page whose draft a publish moved past', () => {
    it('is told once, sends nothing more, never reads "saved", and offers no Retry that could only fail', async () => {
        mock.mockResolvedValueOnce(jsonResponse(409, SUPERSEDED));
        const store = useBuilderStore(pageProps());

        await editLabel(store, 'Job');

        expect(store.superseded.value).toEqual({ draft: { id: 'v-2', version_number: 2 } });
        expect(store.saveState.value).toBe('failed');
        expect(store.saveError.value).toBe(SUPERSEDED.message);
        expect(store.hasUnsaved.value).toBe(false);
        expect(store.conflict.value).toBeNull(); // not the "changed elsewhere" dialog: there is no row to compare

        await editLabel(store, 'Job title');
        await store.addField('short_text');
        await flushPromises();
        expect(writes()).toHaveLength(1); // only the first, refused, save ever left the page
    });

    it('keeps a question whose delete was refused that way', async () => {
        mock.mockResolvedValueOnce(jsonResponse(409, SUPERSEDED));
        const store = useBuilderStore(pageProps());

        await store.deleteField(store.fields.value[0].uid);
        await flushPromises();

        expect(store.fields.value.map((field) => field.key)).toEqual(['occupation']);
        expect(store.superseded.value).not.toBeNull();
    });

    it('puts the kept edit back after the reload into the new draft, found by its key, and saves it there', async () => {
        mock.mockResolvedValueOnce(jsonResponse(409, SUPERSEDED));
        const stale = useBuilderStore(pageProps());
        await editLabel(stale, 'Job title');
        expect(stale.superseded.value).not.toBeNull();

        // The reload: the new draft holds the same question under a NEW id and token, and the SAME key.
        const cloned: ServerField = serverField({ id: 'f-new', version: 'v-new' });
        const fresh = useBuilderStore(pageProps({ draft: { id: 'v-2', version_number: 2 }, fields: [cloned] }));

        expect(fresh.fields.value[0].label).toBe('Job title');
        await fresh.whenIdle();
        await flushPromises();

        const replay = writes().slice(1);
        expect(replay.map((request) => [request.method, request.url])).toEqual([['PATCH', '/forms/form-1/fields/f-new']]);
        expect(replay[0].headers['X-Builder-Draft']).toBe('v-2');
        expect((replay[0].body as { label: string; version: string }).label).toBe('Job title');
        expect((replay[0].body as { version: string }).version).toBe('v-new');
        expect(fresh.saveState.value).toBe('saved');

        // Once the new draft holds it, a further reload has nothing to put back.
        const again = useBuilderStore(pageProps({ draft: { id: 'v-2', version_number: 2 }, fields: [serverField({ id: 'f-new', label: 'Job title', version: 'v99' })] }));
        await again.whenIdle();
        expect(writes()).toHaveLength(2);
    });

    it('still puts back an edit an earlier page kept under the draft key, then forgets that key', async () => {
        const { id: _id, version: _v, sequence: _s, section_sequence: _ss, form_section_id: _f, ...snapshot } = serverField({ label: 'Kept before the deploy' });
        sessionStorage.setItem(
            'meridian:builder-edits:v-1',
            JSON.stringify([{ kind: 'field', id: 'f1', base: 'v1', snapshot, sent: [], at: Date.now() }]),
        );

        const store = useBuilderStore(pageProps());
        expect(store.fields.value[0].label).toBe('Kept before the deploy');
        await store.whenIdle();
        await flushPromises();

        expect(writes().map((request) => (request.body as { label: string }).label)).toEqual(['Kept before the deploy']);
        expect(sessionStorage.getItem('meridian:builder-edits:v-1')).toBeNull();
    });
});

describe('DraftSupersededDialog', () => {
    it('says what happened and that the unsaved changes come back, and reloads from its button or its close', async () => {
        const wrapper = mount(DraftSupersededDialog, { props: { open: true, versionNumber: 2 }, attachTo: document.body });
        await flushPromises();

        const text = document.body.textContent ?? '';
        expect(text).toContain('This form was published since this page loaded. Reload to keep editing.');
        expect(text).toContain('put back after the reload into draft version 2');

        const reload = [...document.body.querySelectorAll('button')].find((button) => button.textContent?.trim() === 'Reload');
        expect(reload, 'a Reload button').toBeDefined();
        reload!.click();
        expect(wrapper.emitted('reload')).toHaveLength(1);

        wrapper.unmount();
        document.body.innerHTML = '';
    });
});
