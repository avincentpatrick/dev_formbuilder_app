import { effectScope, ref } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { jsonResponse, serverField, serverSection } from './builder-store-fixtures';
import type { LocalField, LocalSection } from './types';
import type { BuilderStore } from './useBuilderStore';
import { useGraphNotices } from './useGraphNotices';

/**
 * The Logic rail's re-check trigger, for a question whose TYPE changes (Increment M123).
 *
 * ⚠️ WHY A TYPE CHANGE MUST RE-ASK. The server's empty-at-open notice reads each field's type — a hidden or calculated
 * question is never a visible step — so converting the last visible question of a section to hidden changes the
 * answer with no condition, key, sequence or section moving. Before M123 nothing could change a type in place, so the
 * fingerprint never needed it; now that something can, a rail that does not re-check shows a stale clean bill.
 */
function storeDouble(): { store: BuilderStore; fields: ReturnType<typeof ref<LocalField[]>> } {
    const fields = ref<LocalField[]>([{ ...serverField({ key: 'age', field_type: 'integer' }), uid: 'u1' }]);
    const sections = ref<LocalSection[]>([{ ...serverSection(), uid: 'u2' }]);
    const store = { fields, sections, whenIdle: () => Promise.resolve() } as unknown as BuilderStore;

    return { store, fields };
}

async function settle(): Promise<void> {
    await vi.advanceTimersByTimeAsync(500);
}

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
});

describe('useGraphNotices — what makes the rail re-check', () => {
    it('re-asks the server when a question changes type, and not when only its label does', async () => {
        vi.useFakeTimers();
        const fetch = vi.fn().mockImplementation(() => Promise.resolve(jsonResponse(200, { notices: [] })));
        vi.stubGlobal('fetch', fetch);
        const { store, fields } = storeDouble();
        const scope = effectScope();
        scope.run(() => useGraphNotices('form-1', store, ref(true)));
        await settle();
        const afterOpen = fetch.mock.calls.length;
        expect(afterOpen).toBe(1);

        // The control: a label cannot create or clear a notice, so it must not cost a request.
        fields.value![0].label = 'How old are you?';
        await settle();
        expect(fetch.mock.calls.length).toBe(afterOpen);

        fields.value![0].field_type = 'hidden';
        await settle();
        expect(fetch.mock.calls.length).toBe(afterOpen + 1);
        expect(fetch).toHaveBeenLastCalledWith('/forms/form-1/graph', expect.objectContaining({ method: 'GET' }));

        scope.stop();
    });
});
