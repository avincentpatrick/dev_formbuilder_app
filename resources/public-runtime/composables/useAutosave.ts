/**
 * Client-only draft autosave (Increment F6b, UX §5.1). Answers + locale + current step are persisted so a
 * respondent can leave and resume on the same device. Increment G8b moves the backing store from localStorage
 * to the Dexie `draft_answers` table (keyed by `[form_version_id + slug]`) so all offline state lives in one
 * place; a one-time migration lifts any pre-existing localStorage draft into Dexie on first restore. Triggers:
 * debounced on any answer/locale/step change plus a 30s backstop. Restore is guarded by the schema `checksum`
 * (a republish/version drift discards the stale draft) and a 7-day inactivity expiry.
 *
 * ── M21: THE SPEC SAID "SESSION/DEVICE-SCOPED" AND ONLY THE DEVICE HALF WAS EVER BUILT ─────────────────
 * `docs/ux/form-filling-ux-flow.md` §5.1 has always described this save as *"session/device-scoped, not a
 * durable resume-later feature… deliberately positioned to respondents as 'your progress is saved while
 * you're filling this out,' never as 'come back later'"*, with the come-back-later promise reserved for
 * §5.2's emailed resume link. The key was `[form_version_id + slug]` — a device, with no visit in it — so
 * an ABANDONED fill was restored into the NEXT respondent's form, silently, with their answers on screen:
 * no banner (`WelcomeBackBanner` needs a resume seed), no "Saved" pill (the Dexie branch never sets
 * `savedAt`), no announcement. Rows now carry `respondent_session_id` and a restore is refused unless the
 * visit matches.
 *
 * ⛔ THE REFUSAL IS ON THE READ, NEVER ON THE WRITE, AND NOTHING IS DELETED FOR PRIVACY. A foreign row is
 * left exactly where it is: the primary key is shared, so this respondent's first keystroke overwrites it
 * within 800ms, which makes containment and collection the same mechanism. That is why the visit is NOT in
 * the key — see the field's own note in `lib/db.ts`.
 *
 * ⚠️ AND THE HARM WAS NEVER ONLY ON SCREEN. A restored stranger's draft carries a FRESH
 * `client_submission_uuid` and a NULL baseline, so "Save and finish later" POSTs those answers as a new
 * SERVER draft under this respondent's identity and emails them a 30-day resume link to it; and its
 * `local:` media refs let `attachToSubmission()` re-point the previous respondent's photo or signature onto
 * this submission. Both close here, because both are reached only through the restore.
 */

import { ref, watch, type Ref, type WatchStopHandle } from 'vue';
import { DRAFT_TTL_MS, draftBelongsToVisit, plainClone, type MeridianDb } from '../lib/db';
import type { AnswerMap, DraftBlob } from '../lib/types';

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
    /**
     * Increment G8c — when false, autosave is fully inert (no restore, no writes, no watcher). Used by the
     * conflict-review session, whose durable copy is the parked outbox row: a transient review must never
     * write a `draft_answers` row that could clobber a live fill sharing the same `[form_version_id+slug]` key.
     */
    enabled?: boolean;
    /**
     * Increment M21 — the current visit (`lib/respondent-session.ts`), stamped onto every write and
     * compared on every restore, so an abandoned draft is not handed to the next respondent.
     *
     * ⛔ AN ARGUMENT, NEVER AN IMPORT, AND UNDEFINED MEANS "DO NOT SCOPE" — both halves are M15's
     * convention rather than a new one. The id is read once at the composition root (`App.vue`) and
     * threaded down, because `lib/respondent-session.ts` reads `sessionStorage` and this module's
     * neighbours are type-checked a second time under `tsconfig.sw.json`, where `Storage` does not exist.
     * `undefined` preserves every pre-M21 call shape verbatim — which is what keeps the forty bare
     * `RuntimeSession` mounts in `components.test.ts` behaving exactly as they did.
     */
    sessionId?: string;
    /**
     * Increment M21 — "the respondent is still here", fired on real input rather than on a timer.
     *
     * A CALLBACK rather than an import, for the reason `sessionId` is an argument: this composable stays
     * pure and db-injected, and the storage write lives in the component layer with the rest of the visit
     * plumbing. `RuntimeSession` passes `touchRespondentSession`.
     *
     * ⚠️ IT HANGS OFF `schedule()`, NOT OFF `persist()`, AND THAT IS THE DIFFERENCE BETWEEN AN IDLE WINDOW
     * AND A HEARTBEAT. `schedule()` runs only when an answer, the locale or the step actually changed;
     * `persist()` also runs from the 30-second backstop, so touching there would keep an ABANDONED tab's
     * visit alive forever and defeat the rotation entirely.
     */
    onActivity?: () => void;
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
    // Increment G8c — an inert autosave (conflict-review session): no watcher, no restore, no writes.
    if (options.enabled === false) {
        return {
            savedAt: ref<string | null>(null),
            saving: ref(false),
            restore: async () => null,
            flush: () => {},
            clear: async () => {},
            dispose: () => {},
        };
    }

    const db = options.db;
    const now = options.now ?? (() => Date.now());
    const legacyStorage = options.storage ?? (typeof window !== 'undefined' ? window.localStorage : undefined);
    const legacyKey = draftKey(options.formId, options.slug);
    const pk: [string, string] = [options.formVersionId, options.slug];

    const savedAt = ref<string | null>(null);
    const saving = ref(false);
    let debounce: ReturnType<typeof setTimeout> | null = null;

    /**
     * Increment M21 — what was last written, so the 30-second backstop stops being a HEARTBEAT.
     *
     * `persist()` used to write unconditionally, and `setInterval(persist, BACKSTOP_MS)` fires whether or
     * not anybody typed — so an open tab pushed `updated_at` forward every thirty seconds forever. Three
     * consequences, and the first two are this row's own subject. (1) The seven-day TTL could never fire on
     * an abandoned tab: the only thing that expires a draft is a timestamp that stops moving. (2)
     * `reconcile.ts`'s newest-wins compared a HEARTBEAT against a real save, so a dead draft on a kiosk beat
     * a genuinely newer server draft written from another device, by construction. (3) It is latent e2e
     * nondeterminism — a run slower than thirty seconds writes an empty-answer draft row mid-spec.
     */
    let lastWritten: string | null = null;

    async function persist(): Promise<void> {
        const stampedAt = new Date(now()).toISOString();
        // The identity of the draft's CONTENT, deliberately excluding `updated_at` — that is the field
        // whose spurious movement is the defect.
        const signature = JSON.stringify([
            options.checksum,
            options.locale.value,
            options.currentStepKey.value,
            plainClone(options.answers),
        ]);
        if (signature === lastWritten) {
            saving.value = false;
            return;
        }
        try {
            await db.draft_answers.put({
                form_version_id: options.formVersionId,
                local_draft_id: options.slug,
                checksum: options.checksum,
                locale: options.locale.value,
                current_step_key: options.currentStepKey.value,
                answers: plainClone(options.answers),
                updated_at: stampedAt,
                // Increment M21 — stamped at the ONE place a draft row is ever created, the same discipline
                // `RuntimeSession`'s `enqueue()` applies to the outbox. `null` when unscoped, which reads as
                // an earlier visit to every scoped reader and is the safe direction.
                respondent_session_id: options.sessionId ?? null,
            });
            savedAt.value = stampedAt;
            // Only after the write COMMITTED. Recording it before would let a failed put suppress the
            // retry that the next change would otherwise make.
            lastWritten = signature;
        } catch {
            // Quota exceeded / storage denied — autosave is best-effort, never block the fill.
        }
        saving.value = false;
    }

    function schedule(): void {
        // Increment M21 — the respondent typed, so the visit is not idle. See `onActivity`.
        options.onActivity?.();
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
        // Increment M21 — `lastWritten` describes what is IN THE TABLE, and the row has just gone. Leaving
        // it set would make the skip above suppress the write that re-creates it.
        lastWritten = null;
    }

    function fresh(checksum: string, savedIso: string): boolean {
        if (checksum !== options.checksum) {
            return false; // version drift (or garbage) — do not restore across a republish
        }
        const age = now() - Date.parse(savedIso);
        return Number.isFinite(age) && age <= DRAFT_TTL_MS;
    }

    /**
     * ⚠️ INCREMENT M21 — THE ORDER OF THE TWO CHECKS IN `restore()` IS LOAD-BEARING AND IT IS FRESHNESS
     * FIRST. An EXPIRED row is deleted whoever wrote it, because that is the seven-day contract this table
     * has always had and `restore()` is the only sweeper it possesses; an unexpired FOREIGN row is left
     * exactly where it is. If ownership were tested first, an expired stranger's draft would never be
     * collected at all — the guard would make the leak smaller and the litter permanent.
     */
    async function restore(): Promise<DraftBlob | null> {
        // 1. The Dexie draft (the G8b home).
        try {
            const row = await db.draft_answers.get(pk);
            if (row !== undefined) {
                if (!fresh(row.checksum, row.updated_at)) {
                    await clear();
                    return null;
                }
                // Increment M21 — an ABANDONED fill belongs to whoever walked away, and handing it to the
                // next respondent puts a stranger's answers on their screen, into the server draft their
                // "Save and finish later" writes, and into the resume link that gets emailed to them.
                //
                // ⛔ IT IS NOT DELETED, AND THAT IS THE DECISION. The primary key is shared, so this
                // respondent's first keystroke overwrites the row 800ms later — containment and collection
                // are the SAME mechanism, which is why the visit does not belong in the key. Deleting here
                // would destroy a stranger's work on a read, which is the harm `lib/outbox.ts` names.
                if (!draftBelongsToVisit(row, options.sessionId)) {
                    return null;
                }
                return {
                    checksum: row.checksum,
                    locale: row.locale,
                    currentStepKey: row.current_step_key,
                    answers: row.answers,
                    savedAt: row.updated_at,
                };
            }
        } catch {
            // fall through to the legacy path
        }

        // 2. One-time migration of a pre-G8b localStorage draft → Dexie.
        //
        // ⛔ INCREMENT M21 — A SCOPED SESSION DOES NOT TAKE THIS PATH AT ALL, AND THE REASON IS THAT IT IS
        // THE SAME LEAK THROUGH A SECOND DOOR. `draftKey(formId, slug)` is respondent-blind exactly as the
        // Dexie key was, and a draft written before the visit concept existed cannot be shown to belong to
        // the visit on screen — so under the null-means-an-earlier-visit rule it never can be restored.
        // Guarding only the Dexie read above would have left this branch reachable, because it is entered
        // whenever the Dexie `get` returns undefined OR THROWS.
        if (options.sessionId !== undefined) {
            return null;
        }

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
                // Unreachable while scoped (the guard above returns first), so this is always `null` today.
                // Written from `options.sessionId` anyway rather than hard-coded, so the two writes cannot
                // drift apart if the guard above is ever relaxed.
                respondent_session_id: options.sessionId ?? null,
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
