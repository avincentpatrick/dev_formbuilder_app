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
import { MdsBadge, MdsButton, MdsSpinner } from '@meridian/design-system';
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
const root = ref<HTMLElement | null>(null);

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

/**
 * Move focus to the SAFE control, never the destructive one — an accidental Enter must not destroy anything.
 *
 * ⚠️ QUERIED FROM THE DOM, NOT VIA A TEMPLATE REF, AND THE FIRST VERSION OF THIS WAS DEAD CODE. A
 * `ref="keepButton"` on a component INSIDE a `v-for` compiles with `ref_for: true`, so Vue assigns an ARRAY
 * rather than the element — and even unwrapped it would be an `MdsButton` component instance, which exposes
 * no `focus()`. `keepButton.value?.focus()` was therefore either a no-op or a TypeError, and the docblock
 * claiming focus moved was false. The adversarial review caught it; nothing in the suite could, because the
 * assertion was on the emitted event rather than on `document.activeElement`.
 */
async function focusSafeControl(): Promise<void> {
    await nextTick();
    root.value?.querySelector<HTMLElement>('[data-outbox-keep]')?.focus();
}

async function askDiscard(uuid: string): Promise<void> {
    confirming.value = uuid;
    await focusSafeControl();
}

function cancelDiscard(): void {
    confirming.value = null;
}

function confirmDiscard(uuid: string): void {
    confirming.value = null;
    emit('discard', uuid);
}

/**
 * Escape cancels the confirm rather than leaving it armed — the same affordance a dialog would give, which
 * is part of why an inline two-step is an acceptable substitute for one here.
 */
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape' && confirming.value !== null) {
        event.stopPropagation();
        cancelDiscard();
    }
}
</script>

<template>
    <div ref="root" class="outbox" @keydown="onKeydown">
        <!-- No empty state here. SyncStatus only mounts this when there ARE rows, and `items` additionally
             filters out the row under review — so an empty `items` means "your only submission is the one you
             are reviewing", where "Nothing waiting" would be both wrong and an <h3> above the page's <h1>. -->
        <ul v-if="items.length > 0" class="outbox__list">
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
                    <MdsButton data-outbox-keep size="sm" variant="secondary" @click="cancelDiscard">
                        Keep it
                    </MdsButton>
                    <MdsButton size="sm" variant="destructive" @click="confirmDiscard(item.uuid)">
                        Yes, discard
                    </MdsButton>
                </div>

                <div v-else class="outbox__actions">
                    <!-- No `:disabled="item.isSyncing"`: a syncing row is projected as the Syncing state,
                         which sets canRetry false, so the guard was unreachable. -->
                    <MdsButton
                        v-if="item.canRetry"
                        size="sm"
                        variant="secondary"
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
