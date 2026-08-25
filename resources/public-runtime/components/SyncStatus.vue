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
 *     with it — and `docs/offline-first-sync-design.md:184` requires that action to be ALWAYS VISIBLE,
 *     because it is the documented fallback on platforms with weak Background Sync (notably iOS Safari),
 *     i.e. exactly the platforms where a row can be stuck with the queue looking idle. (The citation read
 *     `:103` until M15 and had drifted; `:103` is now an unrelated paragraph about `openapi.json`.)
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
 *
 * ── INCREMENT M15: "ON THIS DEVICE" NOW MEANS "ON THIS VISIT" ───────────────────────────────────────────
 * Everything above is about being visible from every screen, and all of it still holds. What it never
 * considered is that the page is UNAUTHENTICATED and the hardware is shared, so "visible from every screen"
 * silently meant "visible to everyone who walks up". The identified list is now this respondent's own; an
 * earlier visit's unsent rows collapse to a bare count, which is the shape `docs/ux/form-filling-ux-flow.md`
 * §7.3 specifies for this surface anyway ("1 submission couldn't be sent. Tap to review."). The bar, the
 * empty-state sentences and every selector are unchanged. Full reasoning:
 * `docs/adr/0021-respondent-scoped-device-outbox.md`.
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
// Increment M15 — this VISIT's counts drive everything the respondent reads; `pending`/`needsAttention`/
// `conflict` above stay device-wide and drive the boot drain and the quota estimate. `earlierUnsent` is the
// bare count of everybody else's unsent rows and is the only thing said about them.
const mine = computed(() => sync?.mine.value ?? { pending: 0, needsAttention: 0, conflict: 0 });
const earlierUnsent = computed(() => sync?.earlierUnsent.value ?? 0);
const syncing = computed(() => sync?.syncing.value ?? false);
const quotaWarning = computed(() => sync?.quotaWarning.value ?? null);
const rows = computed(() => sync?.rows.value ?? []);
const syncingUuids = computed(() => sync?.syncingUuids.value ?? new Set<string>());
const reviewingUuid = computed(() => sync?.reviewingUuid.value ?? null);
const announcement = computed(() => sync?.lastAnnouncement.value ?? '');

const unsent = computed(() => mine.value.pending + mine.value.needsAttention + mine.value.conflict);

/**
 * Conflicts the respondent can see the count of but cannot act on from here.
 *
 * ⚠️ INCREMENT M15 CHANGED THE MINUEND AND THE SENTENCE WOULD HAVE GONE WRONG QUIETLY IF IT HAD NOT. It was
 * `conflict - conflictHere`, i.e. device-wide minus this-form; with rows now belonging to visits, that
 * difference counts a STRANGER's conflict and reports it as "needs review on another form" — sending a
 * respondent looking for a row that is not theirs and that they will never find. The subtraction is between
 * two numbers about the same person: this visit's conflicts anywhere, minus this visit's conflicts here.
 */
const conflictElsewhere = computed(() => Math.max(0, mine.value.conflict - conflictHere.value));

function responses(n: number): string {
    return `${n} response${n === 1 ? '' : 's'}`;
}

const summary = computed(() => {
    if (unsent.value === 0) {
        // ⚠️ INCREMENT M15 — THE "FROM THIS DEVICE" WORDING BECAME A CONTRADICTION AND ONLY A LOOK AT THE
        // RUNNING PAGE SHOWED IT. With the list scoped to a visit, a second respondent with nothing of
        // their own read "Nothing is waiting to be sent from this device." directly above "One response from
        // an earlier session on this device is still waiting to send." Both sentences were individually
        // defensible and together they were nonsense — the first is about THEM and the second about the
        // DEVICE, and nothing on screen said so. No unit test could see it and no e2e assertion covers this
        // string; it took rendering the hand-over at :8081.
        //
        // The original two sentences are kept verbatim for the case they were written for — a device with
        // nothing on it at all — because there they are exactly true.
        if (earlierUnsent.value > 0) {
            return rows.value.length === 0 ? 'Nothing of yours is waiting to be sent.' : 'Everything of yours has been sent.';
        }

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

/**
 * Everything a second respondent is told about the first (Increment M15): a number, and no more.
 *
 * ⚠️ IT SAYS LESS THAN IT COULD, AND THAT IS THE DESIGN RATHER THAN AN OMISSION. Not the queue tag, not the
 * server reference, not which form, not when — every one of which the list above discloses for this visit's
 * own rows and every one of which identifies a stranger on shared hardware. `docs/ux/form-filling-ux-flow.md`
 * §7.3 specifies exactly this shape for the persistent app-level surface ("1 submission couldn't be sent.
 * Tap to review."); §7.1's identified list is scoped to "the respondent", singular.
 *
 * It is not silent, because silence would be its own defect: "Sync now" is always visible and would have
 * nothing to explain, an operator checking a device at the end of a field day needs to know something is
 * still queued, and a stuck device must not look idle.
 */
const earlierNote = computed(() =>
    earlierUnsent.value === 0
        ? null
        : earlierUnsent.value === 1
          ? 'One response from an earlier session on this device is still waiting to send.'
          : `${earlierUnsent.value} responses from earlier sessions on this device are still waiting to send.`,
);

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
            <MdsBadge v-if="mine.pending > 0" variant="neutral" :label="`${mine.pending} queued`" />
            <MdsBadge v-if="mine.needsAttention > 0" variant="warning" :label="`${mine.needsAttention} failed`" />
            <MdsBadge
                v-if="mine.conflict > 0"
                variant="info"
                :label="`${mine.conflict} ${mine.conflict === 1 ? 'needs' : 'need'} review`"
            />

            <MdsButton size="sm" variant="secondary" :loading="syncing" @click="sync.syncNow()">
                {{ syncing ? 'Syncing…' : 'Sync now' }}
            </MdsButton>
            <!--
                Increment M15 — `needsAttention`, NOT `mine.needsAttention`, and that is the one control on
                this bar that is deliberately device-wide. "Retry all" SENDS what is queued: it discloses
                nothing and destroys nothing, and an earlier respondent's failed row draining from whoever
                picks the device up next is the outcome they wanted. Scoping it would strand their response
                and buy no privacy at all. `lib/outbox.ts`'s `retryAll` carries the same note.
            -->
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
        <p v-if="earlierNote" class="sync-status__earlier">{{ earlierNote }}</p>
        <p v-if="quotaWarning" class="sync-status__quota">{{ quotaWarning }}</p>

        <!--
            The LIST is conditional; the BAR above is not. `offline-first-sync-design.md:103` requires the
            manual "Sync now" to be always visible — it says nothing about the list, and rendering "Nothing
            waiting" above every screen of a first online visit is noise the respondent has to read past
            before reaching the form. The summary line already states the empty case in one sentence.
        -->
        <!--
            `rows` is this VISIT's rows after M15, so the list itself needed no change: it simply receives
            fewer. That is why every selector the e2e locates — `.outbox__ref`, `.outbox__detail`, the
            per-row actions — is untouched by this increment.
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
/*
 * Increment M15 — `position: relative` IS A FIX, NOT A LINT APPEASEMENT, and it is the other half of a
 * paired change (Standing Rule 7(b-bis)).
 *
 * `.sync-status__sr` below is `position: absolute` + `clip: rect(0 0 0 0)`, and this file positioned nothing
 * else — so that live region resolved its containing block outside the component entirely, against whatever
 * ancestor happened to be positioned or, here, against the initial containing block. A clipped node with no
 * containing block inside its own component contributes to the DOCUMENT's scrollable box rather than its
 * own, which is how a 1px announcer comes to add real page scroll. Four increments of this repository's
 * history are in `packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts`, which listed
 * this file in `KNOWN_UNGUARDED` and asserts that list EXACTLY — so the entry is deleted in the same PR as
 * this declaration, which is what that gate was built to force.
 *
 * The verification that list asks for was done rather than assumed: `relative` also makes this element the
 * containing block for any absolutely-positioned descendant and establishes a stacking context. The only
 * absolutely-positioned descendant is the sr-only region itself, which is the node that wanted one; the
 * children are `MdsBadge`, `MdsButton` and `SubmissionOutbox`, none of which positions anything.
 */
.sync-status {
    position: relative;
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
.sync-status__earlier,
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
