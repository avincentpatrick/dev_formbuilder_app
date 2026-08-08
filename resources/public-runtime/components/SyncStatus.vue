<script setup lang="ts">
/**
 * The offline sync surface (Increment G8b, rebuilt in I10d). Mounted at APP level — not inside a fill
 * session — so it is visible from every screen of the installed PWA, which is what `docs/ux/
 * form-filling-ux-flow.md` §7.1 asks for ("visible from the form's own completion/home view") and §7.3
 * reinforces with its persistent, non-modal banner. The confirmation screen is exactly where a respondent
 * looks after submitting, and it is exactly where a session-scoped surface could not appear.
 *
 * Three things changed in I10d, each because the old shape stated or implied something untrue:
 *
 *  1. **It always renders.** The old `v-if` hid the whole surface on an empty queue, which took "Sync now"
 *     with it — and `docs/offline-first-sync-design.md:103` requires that action to be ALWAYS VISIBLE,
 *     because it is the documented fallback on platforms with weak Background Sync (notably iOS Safari),
 *     i.e. exactly the platforms where a row can be stuck with the queue looking idle.
 *  2. **The needs-attention row is no longer `role="alert"`.** Assertive was tolerable inside one form
 *     session; on a surface that now mounts on every screen it would interrupt the respondent on every page
 *     load and every phase transition for as long as one failed row exists. The scoped polite region below
 *     replaces it.
 *  3. **The Review CTA is driven by `conflictHere`, not `conflict`.** This fixes a live bug rather than a
 *     new one: the count was cross-form while the resolver is slug-scoped, so a conflict belonging to
 *     ANOTHER form produced "1 response needs review" above a Review button that silently did nothing.
 *
 * Built from `Mds*` primitives (Standing Rule 2). That is not cosmetics here: the hand-rolled action button
 * this replaces was ~24px tall with no focus style, and `MdsButton` guarantees a ≥44×44px target and a
 * never-removed focus ring — so the conversion is a measurable accessibility fix.
 */
import { computed, inject } from 'vue';
import { MdsBadge, MdsButton } from '@meridian/design-system';
import { ConflictReviewKey, SyncOutboxKey } from '../composables/context';
import SubmissionOutbox from './SubmissionOutbox.vue';

const sync = inject(SyncOutboxKey, null);
const reviewConflicts = inject(ConflictReviewKey, null);

const pending = computed(() => sync?.pending.value ?? 0);
const needsAttention = computed(() => sync?.needsAttention.value ?? 0);
const conflict = computed(() => sync?.conflict.value ?? 0);
const conflictHere = computed(() => sync?.conflictHere.value ?? 0);
const syncing = computed(() => sync?.syncing.value ?? false);
const quotaWarning = computed(() => sync?.quotaWarning.value ?? null);
const rows = computed(() => sync?.rows.value ?? []);
const syncingUuids = computed(() => sync?.syncingUuids.value ?? new Set<string>());
const reviewingUuid = computed(() => sync?.reviewingUuid.value ?? null);
const announcement = computed(() => sync?.lastAnnouncement.value ?? '');

const unsent = computed(() => pending.value + needsAttention.value + conflict.value);

/** Conflicts the respondent can see the count of but cannot act on from here. */
const conflictElsewhere = computed(() => Math.max(0, conflict.value - conflictHere.value));

function responses(n: number): string {
    return `${n} response${n === 1 ? '' : 's'}`;
}

const summary = computed(() => {
    if (unsent.value === 0) {
        // "Everything has been sent" is FALSE for a first-time visitor who has sent nothing — and this surface
        // now renders on their very first screen. Distinguish the two empty cases.
        return rows.value.length === 0
            ? 'Nothing is waiting to be sent from this device.'
            : 'Everything on this device has been sent.';
    }

    const base = `${responses(unsent.value)} on this device ${unsent.value === 1 ? 'has' : 'have'} not been sent yet.`;

    // Say so rather than leaving the respondent looking for an action that is not here: a count they cannot
    // act on with no explanation is the shape of the bug this increment fixed.
    return conflictElsewhere.value > 0
        ? `${base} ${conflictElsewhere.value} need${conflictElsewhere.value === 1 ? 's' : ''} review on another form.`
        : base;
});

function onRetry(uuid: string): void {
    void sync?.retryOne(uuid);
}

function onDiscard(uuid: string): void {
    void sync?.discardSubmission(uuid);
}
</script>

<template>
    <section v-if="sync" class="sync-status" aria-labelledby="sync-status-title">
        <!--
            A paragraph, NOT a heading. This surface sits above the page's only <h1> (the form title, or the
            confirmation heading), and an <h2> before an <h1> is a heading-order inversion. `aria-labelledby`
            gives the region its name without inventing that structure.
        -->
        <p id="sync-status-title" class="sync-status__title">My submissions on this device</p>

        <div class="sync-status__bar">
            <MdsBadge v-if="pending > 0" variant="neutral" :label="`${pending} queued`" />
            <MdsBadge v-if="needsAttention > 0" variant="warning" :label="`${needsAttention} failed`" />
            <MdsBadge v-if="conflict > 0" variant="info" :label="`${conflict} ${conflict === 1 ? 'needs' : 'need'} review`" />

            <MdsButton size="sm" variant="secondary" :loading="syncing" @click="sync.syncNow()">
                {{ syncing ? 'Syncing…' : 'Sync now' }}
            </MdsButton>
            <MdsButton
                v-if="needsAttention > 0"
                size="sm"
                variant="tertiary"
                :disabled="syncing"
                @click="sync.retryNeedsAttention()"
            >
                Retry all
            </MdsButton>
            <MdsButton
                v-if="reviewConflicts && conflictHere > 0"
                data-testid="review-conflicts"
                size="sm"
                variant="tertiary"
                @click="reviewConflicts()"
            >
                Review
            </MdsButton>
        </div>

        <p class="sync-status__summary">{{ summary }}</p>
        <p v-if="quotaWarning" class="sync-status__quota">{{ quotaWarning }}</p>

        <!--
            The LIST is conditional; the BAR above is not. `offline-first-sync-design.md:103` requires the
            manual "Sync now" to be always visible — it says nothing about the list, and rendering "Nothing
            waiting" above every screen of a first online visit is noise the respondent has to read past
            before reaching the form. The summary line already states the empty case in one sentence.
        -->
        <SubmissionOutbox
            v-if="rows.length > 0"
            :rows="rows"
            :syncing-uuids="syncingUuids"
            :slug="sync.slug"
            :reviewing-uuid="reviewingUuid"
            @retry="onRetry"
            @review="(uuid: string) => reviewConflicts?.(uuid)"
            @discard="onDiscard"
        />

        <!--
            ONE scoped polite region, and deliberately not RuntimeShell's. That announcer is provided by
            RuntimeSession (useAnnouncer THROWS when absent) and is unmounted in exactly the confirmation /
            error / unavailable phases where this surface must still speak. Polite, never assertive: a sync
            completing must not interrupt someone mid-question.
        -->
        <p class="sync-status__sr" role="status" aria-live="polite">{{ announcement }}</p>
    </section>
</template>

<style scoped>
.sync-status {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.sync-status__title {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size, var(--mds-type-caption-font-size));
    font-weight: 600;
    color: var(--mds-color-text-heading);
}

.sync-status__bar {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    flex-wrap: wrap;
}

.sync-status__summary,
.sync-status__quota {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

.sync-status__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0 0 0 0);
    clip-path: inset(50%);
    white-space: nowrap;
    border: 0;
}
</style>
