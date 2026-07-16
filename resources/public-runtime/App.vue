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
import { DbKey, OfflineMediaKey, SyncOutboxKey, UploadUrlKey } from './composables/context';
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

const phase = ref<Phase>('loading');
const schema = shallowRef<SchemaResponse | null>(null);
const errorMessage = ref('');
const reference = ref('');
const confirmationMessage = ref(CONFIRM_MESSAGE);
const sessionKey = ref(0);
const retainedAnswers = shallowRef<AnswerMap | undefined>(undefined);
const driftNotice = ref<string | null>(null);

const client = createApiClient({ token: props.bootstrap.shareToken, slug: props.bootstrap.slug });

// Increment G8b — the offline database + replay driver are created once here and shared with every session
// (and, via the same DB name, with the service worker's Background-Sync replay).
const db = openDb();
const syncOutbox = createSyncOutbox(db);
provide(DbKey, db);
provide(SyncOutboxKey, syncOutbox);

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
    reference.value = deriveReference(id);
    confirmationMessage.value = CONFIRM_MESSAGE;
    phase.value = 'confirmation';
}

// Increment G8b — an offline (or dropped-mid-submit) finalize: the answers are safely queued, so show a
// "saved on this device" confirmation with a local reference derived from the client submission id.
function onQueued(clientUuid: string): void {
    reference.value = deriveReference(clientUuid);
    confirmationMessage.value = QUEUED_MESSAGE;
    phase.value = 'confirmation';
}

function onReschema(payload: { schema: SchemaResponse; answers: AnswerMap }): void {
    schema.value = payload.schema;
    retainedAnswers.value = payload.answers;
    driftNotice.value = 'This form was updated. Your answers were kept where possible — please review and resubmit.';
    sessionKey.value += 1;
    phase.value = 'ready';
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
        @submitted="onSubmitted"
        @queued="onQueued"
        @reschema="onReschema"
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
