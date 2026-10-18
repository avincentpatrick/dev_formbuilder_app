import { ref } from 'vue';

import type { BuilderStore } from './useBuilderStore';

/**
 * M172 (`R-a44638b6`, `D133`) — a delete asks first only when responses hold answers to the question, and says how many
 * and that they keep them; with none it goes at once (undo still brings it back). M177 (`R-71509975`): lifted out of
 * `BuilderCanvas` so the Options tab's "Other, please specify" switch asks the same question, the same way, before it
 * removes the Please specify question.
 */
export function useAnsweredDelete(store: Pick<BuilderStore, 'answersFor'>) {
    const pending = ref<{ label: string; count: number | null } | null>(null);
    let act: (() => void) | null = null;

    async function request(uid: string, label: string, onDelete: () => void): Promise<boolean> {
        const count = await store.answersFor(uid);
        if (count === 0) {
            onDelete();
            return true;
        }
        act = onDelete;
        pending.value = { label, count };

        return false;
    }

    function confirm(): void {
        const run = act;
        act = null;
        pending.value = null;
        run?.();
    }

    function cancel(): void {
        act = null;
        pending.value = null;
    }

    return { pending, request, confirm, cancel };
}
