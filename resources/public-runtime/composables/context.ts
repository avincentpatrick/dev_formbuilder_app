import { inject, type InjectionKey, type Ref } from 'vue';
import type { MediaAttachmentRef } from '@/components/submissions/FieldInput.vue';
import type { Announcer } from './useAnnouncer';
import type { FormRuntime } from './useFormRuntime';
import type { SyncOutbox } from './useSyncOutbox';
import type { MeridianDb } from '../lib/db';

/** Increment G8b — stash a picked media file offline (guest SPA), returning a `local:` placeholder ref. */
export type OfflineMediaStash = (file: File, fieldKey: string) => Promise<MediaAttachmentRef>;

/**
 * The result of a submit attempt, as the flow views interpret it. `queued` (Increment G8b) means the finalized
 * answers were saved to the offline outbox and will replay on reconnect — distinct from a live `success`.
 */
export type SubmitOutcome = 'success' | 'field-errors' | 'blocked' | 'queued';

export interface SubmitFlow {
    submitting: Ref<boolean>;
    submit: () => Promise<SubmitOutcome>;
}

/**
 * Increment H10 — the "Save and finish later" flow. `saveDraft` upserts the durable server draft (the FULL
 * retained answers, not the relevance-pruned submit set) and returns the resume handle for the dialog to show
 * (or null when a version drift routed the caller into the reschema path). `saving` drives the control's
 * pending state; `completeness` is the latest server-reported progress, refreshed on each save.
 */
export interface DraftFlow {
    saving: Ref<boolean>;
    completeness: Ref<number | null>;
    saveDraft: (options: { email?: string | null; finishLater: boolean }) => Promise<DraftSaveResult | null>;
}

export interface DraftSaveResult {
    resumeUrl: string;
    emailed: boolean;
}

/** Provided by `RuntimeSession.vue`; consumed by every runtime component. */
export const RuntimeKey: InjectionKey<FormRuntime> = Symbol('public-runtime');
export const AnnouncerKey: InjectionKey<Announcer> = Symbol('public-runtime-announcer');
export const SubmitFlowKey: InjectionKey<SubmitFlow> = Symbol('public-runtime-submit');
/** Increment H10 — the "Save and finish later" flow, provided by RuntimeSession, consumed by the fill views. */
export const DraftFlowKey: InjectionKey<DraftFlow> = Symbol('public-runtime-draft');
/** Resolves the guest media-upload URL from the api-client's current share token (Increment G6). */
export const UploadUrlKey: InjectionKey<() => string> = Symbol('public-runtime-upload-url');
/** The shared offline database (Increment G8b), provided by App.vue for the outbox submit + autosave paths. */
export const DbKey: InjectionKey<MeridianDb> = Symbol('public-runtime-db');
/** The app-level offline replay driver (Increment G8b) — live counts + Sync-now, provided by App.vue. */
export const SyncOutboxKey: InjectionKey<SyncOutbox> = Symbol('public-runtime-sync-outbox');
/** Offline media staging (Increment G8b), provided by App.vue; threaded into the media control's upload config. */
export const OfflineMediaKey: InjectionKey<OfflineMediaStash> = Symbol('public-runtime-offline-media');
/**
 * Increment M15 — which VISIT to this device is on screen, provided by App.vue so a finalized submission is
 * stamped with its owner and the outbox surface can show a respondent their own rows rather than the
 * previous respondent's.
 *
 * ⚠️ A PLAIN STRING, PROVIDED RATHER THAN IMPORTED, AND THAT IS A TYPE-CHECK CONSTRAINT RATHER THAN A
 * STYLE PREFERENCE. `lib/respondent-session.ts` reads `sessionStorage`; `sw.ts` imports `lib/db.ts` and
 * `lib/replay.ts` → `lib/outbox.ts`, and `tsconfig.sw.json` re-checks that graph under
 * `lib: ["ESNext", "WebWorker"], types: []`, where `Storage` does not exist. The id therefore crosses into
 * the data layer as an argument. `DbKey` above is the same shape for the same kind of reason.
 */
export const RespondentSessionKey: InjectionKey<string> = Symbol('public-runtime-respondent-session');
/** Open the conflict-review UX for the oldest parked conflict (Increment G8c), provided by App.vue for SyncStatus. */
/**
 * Open the conflict-review flow. The uuid is OPTIONAL and, when given, names the row to review.
 *
 * ⚠️ It was `() => void` until I10d, and that signature was a bug the moment the list shipped: every row
 * grew its own Review button while the resolver still picked `nextConflict()` — the OLDEST conflict — and
 * the list renders NEWEST-first, so the two were guaranteed to disagree whenever more than one conflict
 * existed. A respondent could review and resubmit a submission they had not selected while the one they did
 * select stayed parked.
 */
export const ConflictReviewKey: InjectionKey<(uuid?: string) => void> = Symbol('public-runtime-conflict-review');

export function useRuntime(): FormRuntime {
    const runtime = inject(RuntimeKey);
    if (runtime === undefined) {
        throw new Error('FormRuntime was not provided.');
    }
    return runtime;
}

export function useAnnouncer(): Announcer {
    const announcer = inject(AnnouncerKey);
    if (announcer === undefined) {
        throw new Error('Announcer was not provided.');
    }
    return announcer;
}

export function useSubmitFlow(): SubmitFlow {
    const flow = inject(SubmitFlowKey);
    if (flow === undefined) {
        throw new Error('SubmitFlow was not provided.');
    }
    return flow;
}

/** The draft flow if a save-and-resume-enabled session provided one; null when the form has it disabled. */
export function useDraftFlow(): DraftFlow | null {
    return inject(DraftFlowKey, null);
}
