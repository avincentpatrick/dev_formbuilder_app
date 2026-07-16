/**
 * Client-only draft autosave (Increment F6b, UX §5.1). Answers + locale + current step are persisted so a
 * respondent can leave and resume on the same device. Increment G8b moves the backing store from localStorage
 * to the Dexie `draft_answers` table (keyed by `[form_version_id + slug]`) so all offline state lives in one
 * place; a one-time migration lifts any pre-existing localStorage draft into Dexie on first restore. Triggers:
 * debounced on any answer/locale/step change plus a 30s backstop. Restore is guarded by the schema `checksum`
 * (a republish/version drift discards the stale draft) and a 7-day inactivity expiry.
 */

import { ref, watch, type Ref, type WatchStopHandle } from 'vue';
import { plainClone, type MeridianDb } from '../lib/db';
import type { AnswerMap, DraftBlob } from '../lib/types';

const DRAFT_TTL_MS = 7 * 24 * 60 * 60 * 1000;
const DEBOUNCE_MS = 800;
const BACKSTOP_MS = 30_000;

/** The legacy localStorage key (Increment F6b) — read once during the G8b migration, then removed. */
export function draftKey(formId: string, slug: string): string {
    return `meridian:draft:${formId}:${slug}`;
}

export interface AutosaveOptions {
    db: MeridianDb;
    /** Legacy localStorage migration key part (F6b). */
    formId: string;
    /** The pinned version — the `draft_answers` primary-key part (G8b). */
    formVersionId: string;
    /** The share link's slug — the `local_draft_id` + legacy key part. */
    slug: string;
    checksum: string;
    /** The reactive answer map from the runtime store (flat + nested repeat-section instances, G2). */
    answers: AnswerMap;
    locale: Ref<string>;
    currentStepKey: Ref<string>;
    now?: () => number;
    /** For the one-time localStorage → Dexie migration read (default `window.localStorage`). */
    storage?: Storage;
}

export interface Autosave {
    savedAt: Ref<string | null>;
    saving: Ref<boolean>;
    restore(): Promise<DraftBlob | null>;
    flush(): void;
    clear(): Promise<void>;
    dispose(): void;
}

export function createAutosave(options: AutosaveOptions): Autosave {
    const db = options.db;
    const now = options.now ?? (() => Date.now());
    const legacyStorage = options.storage ?? (typeof window !== 'undefined' ? window.localStorage : undefined);
    const legacyKey = draftKey(options.formId, options.slug);
    const pk: [string, string] = [options.formVersionId, options.slug];

    const savedAt = ref<string | null>(null);
    const saving = ref(false);
    let debounce: ReturnType<typeof setTimeout> | null = null;

    async function persist(): Promise<void> {
        const stampedAt = new Date(now()).toISOString();
        try {
            await db.draft_answers.put({
                form_version_id: options.formVersionId,
                local_draft_id: options.slug,
                checksum: options.checksum,
                locale: options.locale.value,
                current_step_key: options.currentStepKey.value,
                answers: plainClone(options.answers),
                updated_at: stampedAt,
            });
            savedAt.value = stampedAt;
        } catch {
            // Quota exceeded / storage denied — autosave is best-effort, never block the fill.
        }
        saving.value = false;
    }

    function schedule(): void {
        saving.value = true;
        if (debounce !== null) {
            clearTimeout(debounce);
        }
        debounce = setTimeout(() => void persist(), DEBOUNCE_MS);
    }

    function flush(): void {
        if (debounce !== null) {
            clearTimeout(debounce);
            debounce = null;
        }
        void persist();
    }

    async function clear(): Promise<void> {
        try {
            await db.draft_answers.delete(pk);
        } catch {
            // ignore
        }
        try {
            legacyStorage?.removeItem(legacyKey);
        } catch {
            // ignore
        }
        savedAt.value = null;
    }

    function fresh(checksum: string, savedIso: string): boolean {
        if (checksum !== options.checksum) {
            return false; // version drift (or garbage) — do not restore across a republish
        }
        const age = now() - Date.parse(savedIso);
        return Number.isFinite(age) && age <= DRAFT_TTL_MS;
    }

    async function restore(): Promise<DraftBlob | null> {
        // 1. The Dexie draft (the G8b home).
        try {
            const row = await db.draft_answers.get(pk);
            if (row !== undefined) {
                if (fresh(row.checksum, row.updated_at)) {
                    return {
                        checksum: row.checksum,
                        locale: row.locale,
                        currentStepKey: row.current_step_key,
                        answers: row.answers,
                        savedAt: row.updated_at,
                    };
                }
                await clear();
                return null;
            }
        } catch {
            // fall through to the legacy path
        }

        // 2. One-time migration of a pre-G8b localStorage draft → Dexie.
        const migrated = readLegacy();
        if (migrated === null) {
            return null;
        }
        if (!fresh(migrated.checksum, migrated.savedAt)) {
            await clear();
            return null;
        }
        try {
            await db.draft_answers.put({
                form_version_id: options.formVersionId,
                local_draft_id: options.slug,
                checksum: migrated.checksum,
                locale: migrated.locale,
                current_step_key: migrated.currentStepKey,
                answers: migrated.answers,
                updated_at: migrated.savedAt,
            });
            legacyStorage?.removeItem(legacyKey);
            savedAt.value = migrated.savedAt;
        } catch {
            // Migration write failed — still hand the draft back so the fill isn't lost this session.
        }
        return migrated;
    }

    function readLegacy(): DraftBlob | null {
        let raw: string | null = null;
        try {
            raw = legacyStorage?.getItem(legacyKey) ?? null;
        } catch {
            return null;
        }
        if (raw === null) {
            return null;
        }
        try {
            const blob = JSON.parse(raw) as DraftBlob;
            if (blob === null || typeof blob !== 'object' || typeof blob.checksum !== 'string') {
                return null;
            }
            return blob;
        } catch {
            return null;
        }
    }

    const stop: WatchStopHandle = watch(
        [() => ({ ...options.answers }), options.locale, options.currentStepKey],
        schedule,
        { deep: true },
    );
    const backstop = setInterval(() => void persist(), BACKSTOP_MS);

    function dispose(): void {
        stop();
        clearInterval(backstop);
        if (debounce !== null) {
            clearTimeout(debounce);
        }
    }

    return { savedAt, saving, restore, flush, clear, dispose };
}
