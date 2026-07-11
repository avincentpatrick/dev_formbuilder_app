<script setup lang="ts">
/**
 * Root of the public-runtime SPA (Increment F6b). A small state machine — loading → ready → confirmation, with
 * an error terminal — around one long-lived, stateful `ApiClient`. It fetches the schema once, hosts the fill
 * session (re-keyed on a version-drift `reschema` so the store rebuilds cleanly), and shows the confirmation.
 */
import { onMounted, ref, shallowRef } from 'vue';
import { MdsEmptyState, MdsSpinner } from '@meridian/design-system';
import ConfirmationScreen from './components/ConfirmationScreen.vue';
import RuntimeSession from './components/RuntimeSession.vue';
import { createApiClient } from './lib/api-client';
import { ApiError } from './lib/error-normalizer';
import { deriveReference } from './lib/reference-number';
import type { AnswerMap, Bootstrap, SchemaResponse } from './lib/types';

const props = defineProps<{ bootstrap: Bootstrap }>();

type Phase = 'loading' | 'ready' | 'confirmation' | 'error';

const CONFIRM_MESSAGE = 'Thanks — your response has been recorded.';

const phase = ref<Phase>('loading');
const schema = shallowRef<SchemaResponse | null>(null);
const errorMessage = ref('');
const reference = ref('');
const sessionKey = ref(0);
const retainedAnswers = shallowRef<AnswerMap | undefined>(undefined);
const driftNotice = ref<string | null>(null);

const client = createApiClient({ token: props.bootstrap.shareToken, slug: props.bootstrap.slug });

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
        :message="CONFIRM_MESSAGE"
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
