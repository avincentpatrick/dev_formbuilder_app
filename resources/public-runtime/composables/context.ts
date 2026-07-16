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

/** Provided by `RuntimeSession.vue`; consumed by every runtime component. */
export const RuntimeKey: InjectionKey<FormRuntime> = Symbol('public-runtime');
export const AnnouncerKey: InjectionKey<Announcer> = Symbol('public-runtime-announcer');
export const SubmitFlowKey: InjectionKey<SubmitFlow> = Symbol('public-runtime-submit');
/** Resolves the guest media-upload URL from the api-client's current share token (Increment G6). */
export const UploadUrlKey: InjectionKey<() => string> = Symbol('public-runtime-upload-url');
/** The shared offline database (Increment G8b), provided by App.vue for the outbox submit + autosave paths. */
export const DbKey: InjectionKey<MeridianDb> = Symbol('public-runtime-db');
/** The app-level offline replay driver (Increment G8b) — live counts + Sync-now, provided by App.vue. */
export const SyncOutboxKey: InjectionKey<SyncOutbox> = Symbol('public-runtime-sync-outbox');
/** Offline media staging (Increment G8b), provided by App.vue; threaded into the media control's upload config. */
export const OfflineMediaKey: InjectionKey<OfflineMediaStash> = Symbol('public-runtime-offline-media');
/** Open the conflict-review UX for the oldest parked conflict (Increment G8c), provided by App.vue for SyncStatus. */
export const ConflictReviewKey: InjectionKey<() => void> = Symbol('public-runtime-conflict-review');

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
