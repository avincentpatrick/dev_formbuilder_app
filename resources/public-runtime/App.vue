<script setup lang="ts">
/**
 * Root of the public-runtime SPA (Increment F6b). A small state machine — loading → ready → confirmation, with
 * an error terminal — around one long-lived, stateful `ApiClient`. It fetches the schema once, hosts the fill
 * session (re-keyed on a version-drift `reschema` so the store rebuilds cleanly), and shows the confirmation.
 */
import { onMounted, provide, ref, shallowRef } from 'vue';
import { MdsEmptyState, MdsSpinner } from '@meridian/design-system';
import ConfirmationScreen from './components/ConfirmationScreen.vue';
import RuntimeSession from './components/RuntimeSession.vue';
import { ConflictReviewKey, DbKey, OfflineMediaKey, SyncOutboxKey, UploadUrlKey } from './composables/context';
import { createSyncOutbox } from './composables/useSyncOutbox';
import { createApiClient } from './lib/api-client';
import { openDb } from './lib/db';
import { localMediaRefId, stash } from './lib/media-queue';
import { uuidv7 } from './lib/uuid';
import { ApiError } from './lib/error-normalizer';
import { deriveReference } from './lib/reference-number';
import type { AnswerMap, Bootstrap, SchemaResponse } from './lib/types';

const props = defineProps<{ bootstrap: Bootstrap }>();

type Phase = 'loading' | 'ready' | 'confirmation' | 'error';

const CONFIRM_MESSAGE = 'Thanks — your response has been recorded.';
const QUEUED_MESSAGE = "Saved on this device — we'll submit it automatically when you're back online.";
const RESOLVED_MESSAGE = 'Thanks — your reviewed response has been submitted.';

// Increment G8c — the resolve-mode banner, keyed by which 409 parked the row.
const DRIFT_RESOLVE_NOTICE =
    'This form was updated after this response was saved. Your answers were kept where possible — please review and resubmit.';
const CONTENT_RESOLVE_NOTICE =
    'This response conflicts with a copy already saved. Please review your answers and submit again.';

function resolveNotice(code: string | null): string {
    return code === 'submission_conflict' ? CONTENT_RESOLVE_NOTICE : DRIFT_RESOLVE_NOTICE;
}

const phase = ref<Phase>('loading');
const schema = shallowRef<SchemaResponse | null>(null);
const errorMessage = ref('');
const reference = ref('');
const confirmationMessage = ref(CONFIRM_MESSAGE);
const sessionKey = ref(0);
const retainedAnswers = shallowRef<AnswerMap | undefined>(undefined);
const driftNotice = ref<string | null>(null);
// Increment G8c — while resolving a parked conflict, the uuid of the row being reviewed (discarded on success).
const resolvingUuid = ref<string | null>(null);
const resolveMode = ref(false);

const client = createApiClient({ token: props.bootstrap.shareToken, slug: props.bootstrap.slug });

// Increment G8b — the offline database + replay driver are created once here and shared with every session
// (and, via the same DB name, with the service worker's Background-Sync replay). The slug scopes G8c conflict
// resolution to rows this form's share-token client can resubmit.
const db = openDb();
const syncOutbox = createSyncOutbox(db, { slug: props.bootstrap.slug });
provide(DbKey, db);
provide(SyncOutboxKey, syncOutbox);
provide(ConflictReviewKey, beginConflictReview);

// Media uploads (Increment G6) POST to the same token-scoped guest surface, resolved live so a re-minted token
// is picked up. The manual-encode channel instead gets its form-scoped URL from EncodeFormPresenter.
provide(UploadUrlKey, () => `/api/v1/public/f/${encodeURIComponent(client.token())}/attachments`);

// Offline media staging (Increment G8b): when a pick can't upload, keep the blob in the Dexie media queue and
// hand back a `local:` placeholder ref that the outbox replay swaps for a real attachment id on reconnect.
provide(OfflineMediaKey, async (file, fieldKey) => {
    const localId = uuidv7();
    await stash(db, {
        attachment_local_id: localId,
        field_key: fieldKey,
        blob: file,
        name: file.name,
        mime: file.type,
        size: file.size,
    });
    return { id: localMediaRefId(localId), name: file.name, mime: file.type };
});

onMounted(load);

async function load(): Promise<void> {
    phase.value = 'loading';
    try {
        schema.value = await client.fetchSchema();
        phase.value = 'ready';
    } catch (error) {
        errorMessage.value =
            error instanceof ApiError ? error.normalized.message : 'We could not load this form. Please try again.';
        phase.value = 'error';
    }
}

function onSubmitted(id: string): void {
    // Increment G8c — a resolved conflict: drop the parked row now that its reviewed answers are recorded.
    const resolved = resolvingUuid.value !== null;
    if (resolved) {
        void syncOutbox.discardConflict(resolvingUuid.value as string);
        clearResolveState();
    }
    reference.value = deriveReference(id);
    confirmationMessage.value = resolved ? RESOLVED_MESSAGE : CONFIRM_MESSAGE;
    phase.value = 'confirmation';
}

// Increment G8b — an offline (or dropped-mid-submit) finalize: the answers are safely queued, so show a
// "saved on this device" confirmation with a local reference derived from the client submission id.
function onQueued(clientUuid: string): void {
    // Increment G8c — a resolve that went offline: the reviewed answers are safely re-queued under the new
    // uuid, so the old parked conflict row can be dropped.
    if (resolvingUuid.value !== null) {
        void syncOutbox.discardConflict(resolvingUuid.value);
        clearResolveState();
    }
    reference.value = deriveReference(clientUuid);
    confirmationMessage.value = QUEUED_MESSAGE;
    phase.value = 'confirmation';
}

function onReschema(payload: { schema: SchemaResponse; answers: AnswerMap }): void {
    schema.value = payload.schema;
    retainedAnswers.value = payload.answers;
    // Increment G8c — a fresh republish DURING a conflict review keeps the resolve context (notice + row), so the
    // loop re-maps against the newer schema until the resubmit succeeds; otherwise it's the normal live-drift copy.
    driftNotice.value = resolveMode.value
        ? DRIFT_RESOLVE_NOTICE
        : 'This form was updated. Your answers were kept where possible — please review and resubmit.';
    sessionKey.value += 1;
    phase.value = 'ready';
}

// Increment G8c — open the review UX for the oldest parked conflict on this form: re-mint the token, re-fetch
// the current published schema, and re-mount the fill session seeded with the saved answers (re-mapped onto the
// new schema; a fresh client_submission_uuid is minted by the store). The user reviews and resubmits (or discards).
async function beginConflictReview(): Promise<void> {
    const row = await syncOutbox.nextConflict();
    if (row === null) {
        await syncOutbox.refresh();
        return;
    }
    try {
        await client.remint();
        schema.value = await client.fetchSchema();
        retainedAnswers.value = row.answers;
        resolvingUuid.value = row.client_submission_uuid;
        resolveMode.value = true;
        driftNotice.value = resolveNotice(row.conflict_code);
        sessionKey.value += 1;
        phase.value = 'ready';
    } catch (error) {
        errorMessage.value =
            error instanceof ApiError
                ? error.normalized.message
                : 'We could not load the latest form to resolve this response. Please try again.';
        phase.value = 'error';
    }
}

// Increment G8c — the "discard this response instead" escape hatch: drop the parked row, then move on to the
// next conflict if any, else reload into a fresh fill of the current form.
async function onDiscard(): Promise<void> {
    const uuid = resolvingUuid.value;
    if (uuid === null) {
        return;
    }
    if (typeof window !== 'undefined' && !window.confirm('Discard this saved response? This cannot be undone.')) {
        return;
    }
    await syncOutbox.discardConflict(uuid);
    clearResolveState();
    if ((await syncOutbox.nextConflict()) !== null) {
        void beginConflictReview();
        return;
    }
    window.location.reload();
}

function clearResolveState(): void {
    resolvingUuid.value = null;
    resolveMode.value = false;
    driftNotice.value = null;
    retainedAnswers.value = undefined;
}

function onRestart(): void {
    window.location.reload();
}
</script>

<template>
    <div v-if="phase === 'loading'" class="app-state">
        <MdsSpinner size="lg" label="Loading form" />
    </div>
    <div v-else-if="phase === 'error'" class="app-state">
        <MdsEmptyState illustration="lock" headline="This form isn’t available" :description="errorMessage" />
    </div>
    <ConfirmationScreen
        v-else-if="phase === 'confirmation'"
        :reference="reference"
        :message="confirmationMessage"
        @restart="onRestart"
    />
    <RuntimeSession
        v-else-if="schema"
        :key="sessionKey"
        :schema="schema"
        :bootstrap="bootstrap"
        :client="client"
        :initial-answers="retainedAnswers"
        :notice="driftNotice"
        :resolving="resolveMode"
        @submitted="onSubmitted"
        @queued="onQueued"
        @reschema="onReschema"
        @discard="onDiscard"
    />
</template>

<style scoped>
.app-state {
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--mds-space-6);
    background-color: var(--mds-color-bg-canvas);
}
</style>
