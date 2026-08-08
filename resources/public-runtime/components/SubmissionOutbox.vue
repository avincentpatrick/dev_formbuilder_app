<script setup lang="ts">
/**
 * "My submissions on this device" (Increment I10d) — the per-submission list `docs/PRD.md:223` requires and
 * `docs/ux/form-filling-ux-flow.md` §7.1 specifies.
 *
 * Until now every piece of this existed EXCEPT the rendering: the outbox has carried a per-row status,
 * attempt count, error and conflict code since G8b, and the respondent could see three aggregate numbers.
 * "2 responses waiting to sync" does not tell someone whether THEIRS was one of them.
 *
 * ── THE DISCARD CONFIRM IS INLINE, NOT A MODAL, AND NOT `window.confirm` ────────────────────────────────
 * §7.3 requires a confirmation step before a respondent can drop a submission. `window.confirm` — which
 * `App.vue` still used for the conflict path before this increment — is unacceptable in new code here for
 * reasons specific to this surface rather than stylistic: it blocks the main thread, it renders as unstyled
 * OS chrome inside an installed PWA whose whole brand shell is cached for offline use, it cannot be asserted
 * in Playwright without a dialog handler (so the gate could not prove the confirmation exists, and §7.3
 * makes it a requirement), and its copy cannot carry the reference number — leaving the respondent to
 * confirm destroying "this response" with no way to tell WHICH, in a list that may hold several.
 *
 * An inline two-step keeps the reference on screen, needs no portal, and moves focus to the SAFE control.
 */
import { computed, nextTick, ref } from 'vue';
import { MdsBadge, MdsButton, MdsEmptyState, MdsSpinner } from '@meridian/design-system';
import { describeRow, type OutboxItemView } from '../lib/outbox-status';
import type { OutboxRow } from '../lib/db';

const props = defineProps<{
    rows: OutboxRow[];
    syncingUuids: ReadonlySet<string>;
    /** The form currently open, or undefined at app level — decides whether a conflict is resolvable here. */
    slug?: string;
    /** Excluded from the list while its review flow is on screen, so it is not offered twice. */
    reviewingUuid: string | null;
}>();

const emit = defineEmits<{
    retry: [uuid: string];
    review: [uuid: string];
    discard: [uuid: string];
}>();

const confirming = ref<string | null>(null);
const keepButton = ref<HTMLElement | null>(null);

const items = computed<OutboxItemView[]>(() =>
    props.rows
        .filter((row) => row.client_submission_uuid !== props.reviewingUuid)
        .map((row) =>
            describeRow(
                row,
                props.syncingUuids.has(row.client_submission_uuid),
                // A conflict belonging to a DIFFERENT form cannot be resolved from here: the resolver reuses
                // the App-level share-token client, which is minted for one slug.
                props.slug !== undefined && row.slug === props.slug,
            ),
        ),
);

async function askDiscard(uuid: string): Promise<void> {
    confirming.value = uuid;
    // Focus the SAFE control, never the destructive one — an accidental Enter must not destroy anything.
    await nextTick();
    keepButton.value?.focus();
}

function cancelDiscard(): void {
    confirming.value = null;
}

function confirmDiscard(uuid: string): void {
    confirming.value = null;
    emit('discard', uuid);
}
</script>

<template>
    <div class="outbox">
        <MdsEmptyState
            v-if="items.length === 0"
            headline="Nothing waiting"
            description="Responses you finish while offline appear here until they are sent."
        />

        <ul v-else class="outbox__list">
            <li v-for="item in items" :key="item.uuid" class="outbox__item">
                <div class="outbox__head">
                    <MdsBadge :variant="item.variant" :label="item.label" />
                    <!-- aria-hidden because MdsSpinner carries its own role="status"; one live region per
                         row would be N competing announcers. The scoped region in SyncStatus speaks instead. -->
                    <span v-if="item.isSyncing" aria-hidden="true" class="outbox__spinner">
                        <MdsSpinner size="sm" />
                    </span>
                    <span class="outbox__ref">{{ item.reference }}</span>
                </div>

                <p class="outbox__detail">{{ item.detail }}</p>

                <div v-if="confirming === item.uuid" class="outbox__actions">
                    <span class="outbox__confirm">Discard this response? It cannot be recovered.</span>
                    <MdsButton ref="keepButton" size="sm" variant="secondary" @click="cancelDiscard">
                        Keep it
                    </MdsButton>
                    <MdsButton size="sm" variant="destructive" @click="confirmDiscard(item.uuid)">
                        Yes, discard
                    </MdsButton>
                </div>

                <div v-else class="outbox__actions">
                    <MdsButton
                        v-if="item.canRetry"
                        size="sm"
                        variant="secondary"
                        :disabled="item.isSyncing"
                        @click="emit('retry', item.uuid)"
                    >
                        Retry now
                    </MdsButton>
                    <MdsButton v-if="item.canReview" size="sm" variant="secondary" @click="emit('review', item.uuid)">
                        Review
                    </MdsButton>
                    <!-- A NAVIGATION, so a link and not a button: MdsButton's own docblock records that a
                         <button> announces the wrong role and drops middle-click / open-in-new-tab. Opening
                         that form re-boots the SPA under its slug, where the conflict IS resolvable. -->
                    <MdsButton
                        v-if="item.variant === 'info' && !item.canReview && item.label === 'Needs review'"
                        as="a"
                        size="sm"
                        variant="tertiary"
                        :href="`/f/${item.slug}`"
                    >
                        Open this form
                    </MdsButton>
                    <MdsButton v-if="item.canDiscard" size="sm" variant="tertiary" @click="askDiscard(item.uuid)">
                        Discard
                    </MdsButton>
                </div>
            </li>
        </ul>
    </div>
</template>

<style scoped>
.outbox__list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.outbox__item {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.outbox__head {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    flex-wrap: wrap;
}

.outbox__spinner {
    display: inline-flex;
}

.outbox__ref {
    margin-inline-start: auto;
    font-family: var(--mds-font-family-mono, monospace);
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

.outbox__detail {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size, var(--mds-type-caption-font-size));
    line-height: var(--mds-type-body-sm-line-height, var(--mds-type-caption-line-height));
    color: var(--mds-color-text-body);
}

.outbox__actions {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    flex-wrap: wrap;
}

.outbox__confirm {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-body);
}
</style>
