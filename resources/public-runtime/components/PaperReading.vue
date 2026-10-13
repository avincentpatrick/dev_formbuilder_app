<script setup lang="ts">
/**
 * While a sent paper copy is read (M166, `R-309e4dfa`, `D116`): the wait between "Send paper copy" and the form filled in
 * with what was read. It asks the server where the scan stands every few seconds, and hands the page the answers once they
 * are ready; the respondent then checks and corrects them, and submits them as their own response.
 *
 * Never a dead end. At any moment the respondent can leave the paper copy for staff — exactly `M165`'s behaviour, the scan
 * already waits in the staff list — and if reading fails, or takes longer than {@link SLOW_AFTER_MS}, they can also fill the
 * form in themselves. A scan that was already submitted (a second tab, a reload) is treated as left for staff.
 *
 * The poll stops on every way out, and on unmount.
 */
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { MdsButton, MdsSpinner } from '@meridian/design-system';
import type { ApiClient } from '../lib/api-client';
import type { AnswerMap, PaperFieldMeta, PaperPage } from '../lib/types';

const props = withDefaults(
    defineProps<{
        client: ApiClient;
        paperToken: string;
        /** Milliseconds between status reads; a test shortens it. */
        intervalMs?: number;
    }>(),
    { intervalMs: 3000 },
);

const emit = defineEmits<{
    read: [result: { answers: AnswerMap; fields: Record<string, PaperFieldMeta>; pages: PaperPage[] }];
    fillByHand: [];
    leave: [];
}>();

/** How long the respondent waits before being offered the other ways forward. */
const SLOW_AFTER_MS = 120_000;

const state = ref<'reading' | 'slow' | 'failed'>('reading');
const heading = ref<HTMLElement | null>(null);
let timer: ReturnType<typeof setTimeout> | null = null;
let stopped = false;
const startedAt = Date.now();

function stop(): void {
    stopped = true;
    if (timer !== null) {
        clearTimeout(timer);
        timer = null;
    }
}

async function settle(next: 'slow' | 'failed'): Promise<void> {
    stop();
    state.value = next;
    await nextTick();
    heading.value?.focus();
}

async function poll(): Promise<void> {
    if (stopped) return;
    try {
        const status = await props.client.fetchPaper(props.paperToken);
        if (stopped) return;
        if (status.status === 'read') {
            stop();
            emit('read', { answers: status.answers, fields: status.fields, pages: status.pages });
            return;
        }
        if (status.status === 'submitted') {
            stop();
            emit('leave');
            return;
        }
        if (status.status === 'failed') {
            await settle('failed');
            return;
        }
    } catch {
        // A refused or expired paper token, or the network: either way this page can no longer show what was read.
        await settle('failed');
        return;
    }

    if (Date.now() - startedAt >= SLOW_AFTER_MS) {
        await settle('slow');
        return;
    }
    timer = setTimeout(() => void poll(), props.intervalMs);
}

function keepWaiting(): void {
    stopped = false;
    state.value = 'reading';
    void poll();
}

function fillByHand(): void {
    stop();
    emit('fillByHand');
}

function leave(): void {
    stop();
    emit('leave');
}

onMounted(() => void poll());
onBeforeUnmount(stop);
</script>

<template>
    <div class="paper-reading" data-paper-reading>
        <div class="paper-reading__card">
            <template v-if="state === 'reading'">
                <MdsSpinner size="lg" label="Reading your pages" />
                <h1 ref="heading" tabindex="-1" class="paper-reading__title">Reading your paper form…</h1>
                <p class="paper-reading__text">
                    This usually takes under a minute. Then you can check what we read, correct anything that is wrong,
                    and submit it.
                </p>
                <div class="paper-reading__actions">
                    <MdsButton variant="tertiary" @click="leave">Leave it for our staff instead</MdsButton>
                </div>
            </template>

            <template v-else>
                <h1 ref="heading" tabindex="-1" class="paper-reading__title">
                    {{ state === 'failed' ? 'We could not read your pages' : 'This is taking longer than usual' }}
                </h1>
                <p class="paper-reading__text">
                    You can fill in the form yourself, or leave your paper copy for our staff to enter. Either way, what
                    you sent is safe with us.
                </p>
                <div class="paper-reading__actions">
                    <MdsButton variant="primary" @click="fillByHand">Fill in the form myself</MdsButton>
                    <MdsButton variant="secondary" @click="leave">Leave it for our staff</MdsButton>
                    <MdsButton v-if="state === 'slow'" variant="tertiary" @click="keepWaiting">Keep waiting</MdsButton>
                </div>
            </template>

            <p class="paper-reading__sr" role="status">
                {{ state === 'reading' ? 'Reading your paper form.' : state === 'failed' ? 'We could not read your pages.' : 'This is taking longer than usual.' }}
            </p>
        </div>
    </div>
</template>

<style scoped>
.paper-reading {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--mds-space-6) var(--mds-space-4);
    background-color: var(--mds-color-bg-canvas);
}

/* Positioned so the clipped status line resolves its containing block HERE (`clipped-node-containment`). */
.paper-reading__card {
    position: relative;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--mds-space-4);
    width: 100%;
    max-width: 32rem;
    padding: var(--mds-space-6);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-lg);
    background-color: var(--mds-color-bg-surface);
    text-align: center;
}

.paper-reading__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-body);
}

.paper-reading__title:focus {
    outline: none;
}

.paper-reading__text {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-secondary);
}

.paper-reading__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: var(--mds-space-2);
}

.paper-reading__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}
</style>
