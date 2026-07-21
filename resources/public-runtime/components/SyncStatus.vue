<script setup lang="ts">
/**
 * The offline sync-status surface (Increment G8b), rendered in RuntimeShell's notice slot beside the G8a
 * OfflineIndicator. It reads the App-level replay driver's live outbox counts and offers the always-available
 * "Sync now" action + a persistent "needs attention" banner (a rejected payload or 5 exhausted retries) with
 * Retry. Conflict rows (a form republished under a queued submission) are surfaced here and resolved by the
 * G8c UX. Nothing renders when the queue is empty.
 */
import { computed, inject } from 'vue';
import { ConflictReviewKey, SyncOutboxKey } from '../composables/context';

const sync = inject(SyncOutboxKey, null);
const reviewConflicts = inject(ConflictReviewKey, null);

const pending = computed(() => sync?.pending.value ?? 0);
const needsAttention = computed(() => sync?.needsAttention.value ?? 0);
const conflict = computed(() => sync?.conflict.value ?? 0);
const syncing = computed(() => sync?.syncing.value ?? false);
const quotaWarning = computed(() => sync?.quotaWarning.value ?? null);

function responses(n: number): string {
    return `${n} response${n === 1 ? '' : 's'}`;
}
</script>

<template>
    <div v-if="sync && (pending > 0 || needsAttention > 0 || conflict > 0 || quotaWarning)" class="sync-status">
        <div v-if="needsAttention > 0" class="sync-status__row sync-status__row--attention" role="alert">
            <span>{{ responses(needsAttention) }} couldn’t be sent and need your attention.</span>
            <button type="button" class="sync-status__action" :disabled="syncing" @click="sync.retryNeedsAttention()">
                Retry
            </button>
        </div>

        <div v-if="conflict > 0" class="sync-status__row sync-status__row--conflict" role="status">
            <span>{{ responses(conflict) }} need review — the form changed after they were saved.</span>
            <button
                v-if="reviewConflicts"
                type="button"
                class="sync-status__action"
                @click="reviewConflicts()"
            >
                Review
            </button>
        </div>

        <div v-if="pending > 0" class="sync-status__row" role="status">
            <span class="sync-status__dot" aria-hidden="true" />
            <span>{{ responses(pending) }} waiting to sync.</span>
            <button type="button" class="sync-status__action" :disabled="syncing" @click="sync.syncNow()">
                {{ syncing ? 'Syncing…' : 'Sync now' }}
            </button>
        </div>

        <p v-if="quotaWarning" class="sync-status__quota" role="status">{{ quotaWarning }}</p>
    </div>
</template>

<style scoped>
.sync-status {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.sync-status__row {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    flex-wrap: wrap;
    padding: var(--mds-space-2) var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    border: 1px solid var(--mds-color-border-default);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size, var(--mds-type-caption-font-size));
    line-height: var(--mds-type-body-sm-line-height, var(--mds-type-caption-line-height));
    color: var(--mds-color-text-body);
    background-color: var(--mds-color-bg-surface);
}

.sync-status__row--attention {
    border-left: 4px solid var(--mds-color-action-danger-bg, var(--mds-color-border-strong));
}

.sync-status__row--conflict {
    border-left: 4px solid var(--mds-color-border-strong);
}

.sync-status__dot {
    width: 8px;
    height: 8px;
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-border-strong);
}

.sync-status__action {
    margin-inline-start: auto;
    padding: var(--mds-space-1) var(--mds-space-3);
    border-radius: var(--mds-radius-sm);
    border: 1px solid var(--mds-color-border-strong);
    background-color: transparent;
    color: var(--mds-color-action-primary-bg, var(--mds-color-text-body));
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    cursor: pointer;
}

.sync-status__action:disabled {
    cursor: default;
    opacity: 0.6;
}

.sync-status__quota {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}
</style>
