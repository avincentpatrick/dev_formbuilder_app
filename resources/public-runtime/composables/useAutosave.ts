/**
 * Lightweight, client-only draft autosave (Increment F6b, UX §5.1). Answers + locale + current step are
 * written to localStorage — device/browser-scoped, NOT a durable resume link (the server draft row is
 * deferred). Triggers: debounced on any answer/locale/step change (covers blur + step navigation) plus a
 * 30s backstop timer. Restore is guarded by the schema `checksum` (a republish/version drift discards the
 * stale draft) and a 7-day inactivity expiry.
 */

import { ref, watch, type Ref, type WatchStopHandle } from 'vue';
import type { AnswerMap, DraftBlob } from '../lib/types';

const DRAFT_TTL_MS = 7 * 24 * 60 * 60 * 1000;
const DEBOUNCE_MS = 800;
const BACKSTOP_MS = 30_000;

export function draftKey(formId: string, slug: string): string {
    return `meridian:draft:${formId}:${slug}`;
}

export interface AutosaveOptions {
    formId: string;
    slug: string;
    checksum: string;
    /** The reactive answer map from the runtime store (flat + nested repeat-section instances, G2). */
    answers: AnswerMap;
    locale: Ref<string>;
    currentStepKey: Ref<string>;
    storage?: Storage;
    now?: () => number;
}

export interface Autosave {
    savedAt: Ref<string | null>;
    saving: Ref<boolean>;
    restore(): DraftBlob | null;
    flush(): void;
    clear(): void;
    dispose(): void;
}

export function createAutosave(options: AutosaveOptions): Autosave {
    const storage = options.storage ?? window.localStorage;
    const now = options.now ?? (() => Date.now());
    const key = draftKey(options.formId, options.slug);

    const savedAt = ref<string | null>(null);
    const saving = ref(false);
    let debounce: ReturnType<typeof setTimeout> | null = null;

    function persist(): void {
        const blob: DraftBlob = {
            checksum: options.checksum,
            locale: options.locale.value,
            currentStepKey: options.currentStepKey.value,
            answers: { ...options.answers },
            savedAt: new Date(now()).toISOString(),
        };
        try {
            storage.setItem(key, JSON.stringify(blob));
            savedAt.value = blob.savedAt;
        } catch {
            // Quota exceeded / private-mode denial — autosave is best-effort, never block the fill.
        }
        saving.value = false;
    }

    function schedule(): void {
        saving.value = true;
        if (debounce !== null) {
            clearTimeout(debounce);
        }
        debounce = setTimeout(persist, DEBOUNCE_MS);
    }

    function flush(): void {
        if (debounce !== null) {
            clearTimeout(debounce);
            debounce = null;
        }
        persist();
    }

    function clear(): void {
        try {
            storage.removeItem(key);
        } catch {
            // ignore
        }
        savedAt.value = null;
    }

    function restore(): DraftBlob | null {
        let raw: string | null = null;
        try {
            raw = storage.getItem(key);
        } catch {
            return null;
        }
        if (raw === null) {
            return null;
        }
        let blob: DraftBlob;
        try {
            blob = JSON.parse(raw) as DraftBlob;
        } catch {
            clear();
            return null;
        }
        if (blob === null || typeof blob !== 'object' || blob.checksum !== options.checksum) {
            clear(); // version drift (or garbage) — do not restore across a republish
            return null;
        }
        const age = now() - Date.parse(blob.savedAt);
        if (!Number.isFinite(age) || age > DRAFT_TTL_MS) {
            clear();
            return null;
        }
        return blob;
    }

    const stop: WatchStopHandle = watch(
        [() => ({ ...options.answers }), options.locale, options.currentStepKey],
        schedule,
        { deep: true },
    );
    const backstop = setInterval(persist, BACKSTOP_MS);

    function dispose(): void {
        stop();
        clearInterval(backstop);
        if (debounce !== null) {
            clearTimeout(debounce);
        }
    }

    return { savedAt, saving, restore, flush, clear, dispose };
}
