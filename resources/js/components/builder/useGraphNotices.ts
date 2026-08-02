/**
 * The Logic view's sidecar driver (Increment H21d1, Doc #27 §8): fetches `GET /forms/{form}/graph` and keeps
 * it roughly in step with the draft as the author edits.
 *
 * ── WHY THE NOTICES COME FROM THE SERVER WHEN THE REST OF THE RAIL DOES NOT ─────────────────────────
 * Everything else on the canvas is derived client-side from the store, live, between keystrokes: node order,
 * each condition's raw text, its reading, its `${key}` chips, its syntax errors. The three §6 notices are
 * different in kind — a forward reference, a cycle and an empty-at-open form are facts about the WHOLE
 * graph, and none of them can be decided from the node you happen to be editing.
 *
 * Mirroring `StepGraphInspector` in TypeScript was rejected: it would be a sixth R3-style mirror pair with
 * no corpus standing over it, which is the hazard Doc #27 §9 and H21a's amendment A6 argue against at
 * length. So the analysis stays in the one place it is tested, and the price is that it lags the rail by one
 * save. That price is visible in the UI rather than hidden — {@link GraphNoticesDriver.stale} drives a
 * "rechecking" line — because a stale clean bill of health that LOOKS current is the failure worth avoiding.
 *
 * ── THE REFETCH IS GATED ON `whenIdle()`, NOT ON A TIMER ────────────────────────────────────────────
 * The endpoint reads the persisted draft, so fetching before the builder's optimistic writes have landed
 * would analyse the previous version of the form and report notices about text the author has already
 * changed. `store.whenIdle()` is the existing flush seam — `Builder.vue` already waits on it before
 * publishing and before save-as-template — and the debounce on top of it is what keeps a burst of
 * keystrokes from becoming a burst of requests.
 */

import { onScopeDispose, ref, watch, type Ref } from 'vue';
import { builderClient, BuilderRequestError } from './builderClient';
import type { GraphNotice } from './logic-rail';
import type { BuilderStore } from './useBuilderStore';

const DEBOUNCE_MS = 400;

export interface GraphNoticesDriver {
    notices: Ref<GraphNotice[]>;
    /** True while a fetch is in flight AND something has already been shown — i.e. a re-check, not a load. */
    stale: Ref<boolean>;
    loading: Ref<boolean>;
    /** Set when the last fetch failed. A failed CHECK must never be mistaken for a clean form. */
    error: Ref<string | null>;
    refresh: () => void;
}

export function useGraphNotices(formId: string, store: BuilderStore, active: Ref<boolean>): GraphNoticesDriver {
    const notices = ref<GraphNotice[]>([]);
    const stale = ref(false);
    const loading = ref(false);
    const error = ref<string | null>(null);

    let loaded = false;
    let timer: ReturnType<typeof setTimeout> | null = null;
    // Every fetch takes a ticket; only the newest one may write. Two requests in flight can land out of
    // order, and the older one would otherwise overwrite the newer with an older reading of the draft.
    let ticket = 0;

    async function fetchNow(): Promise<void> {
        const mine = ++ticket;
        loading.value = true;
        stale.value = loaded;

        try {
            const result = await builderClient.get<{ notices: GraphNotice[] }>(`/forms/${formId}/graph`);
            if (mine !== ticket) return;

            // A read can never 409 — there is no concurrency token on it — but `BuilderResult` is a union
            // and the narrowing is what makes that a fact rather than an assumption.
            if (!result.conflict) {
                notices.value = result.data.notices;
                error.value = null;
                loaded = true;
            }
        } catch (e) {
            if (mine !== ticket) return;
            // The LAST GOOD notices stay on screen. Blanking them would turn "we could not check" into
            // "nothing to report", which is the one reading this must never produce.
            error.value = e instanceof BuilderRequestError ? e.message : 'Could not check this form’s conditions.';
        } finally {
            if (mine === ticket) {
                loading.value = false;
                stale.value = false;
            }
        }
    }

    function schedule(): void {
        if (timer !== null) clearTimeout(timer);
        timer = setTimeout(() => {
            timer = null;
            // Wait for the builder's own queue to drain: the endpoint reads the PERSISTED draft, so a fetch
            // that overtakes an in-flight PATCH analyses the form as it was a keystroke ago.
            void store.whenIdle().then(fetchNow);
        }, DEBOUNCE_MS);
    }

    function refresh(): void {
        if (!active.value) return;
        schedule();
    }

    // Fetch on first open, and on every re-open only if something changed meanwhile (the change watcher
    // below has already scheduled one in that case).
    watch(
        active,
        (isActive) => {
            if (isActive && !loaded) schedule();
        },
        { immediate: true },
    );

    // Re-check when the *logic* changes — the conditions and the structure they hang on. Deliberately not a
    // deep watch of the whole store: a label edit cannot create or clear a forward reference or a cycle, and
    // re-running the analysis on every character typed into a placeholder would be a request per keystroke
    // for an answer that cannot have moved.
    watch(
        () => logicFingerprint(store),
        () => refresh(),
    );

    onScopeDispose(() => {
        if (timer !== null) clearTimeout(timer);
        // Invalidate any in-flight response so a late resolve cannot touch refs after teardown.
        ticket++;
    });

    return { notices, stale, loading, error, refresh };
}

/**
 * Everything the graph analysis actually depends on, as one string: each node's key, its condition, and —
 * for a field — which section holds it, because containment is one of the three edge kinds the cycle
 * detector walks.
 *
 * Sequence is in, too: the forward-reference rule is positional, so re-ordering two sections can create or
 * clear a notice without any expression changing.
 */
function logicFingerprint(store: BuilderStore): string {
    // The separators are written as escapes and are load-bearing rather than decorative: without them
    // `key: 'a', sequence: 11` and `key: 'a1', sequence: 1` produce the same string, and a re-order that
    // ought to re-check the graph would be invisible.
    const sections = store.sections.value
        .map((s) => `${s.key}\u001f${s.sequence}\u001f${s.relevant_expression ?? ''}`)
        .join('\u001e');
    const fields = store.fields.value
        .map((f) => `${f.key}\u001f${f.sequence}\u001f${f.form_section_id ?? ''}\u001f${f.relevant_expression ?? ''}`)
        .join('\u001e');

    return `${sections}\u001d${fields}`;
}
