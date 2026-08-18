<script setup lang="ts">
/**
 * Submission detail (Increment F7) — read-only answers plus the reviewer workflow. The answers are rendered
 * against the submission's own immutable version schema (grouped by section), each value resolved server-side
 * (choice labels, multi-select joins, yes/no) by SubmissionInboxPresenter. When the viewer can review, an
 * action bar drives the guarded status transitions (approve / return-with-reason / mark-under-review /
 * archive). Assembled from shared design-system components.
 */
import { computed, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsBreadcrumb,
    MdsButton,
    MdsCard,
    MdsFormField,
    MdsModal,
    MdsTextarea,
    statusVariant,
    type BreadcrumbItem,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';

type FieldRow = { key: string; label: string; value: string };
/**
 * `id` is a stable unique key for the block, not a section id — a repeatable section emits one block per
 * stored instance (Increment H6b) carrying `"{sectionId}#{n}"`, so `fields` can legitimately be empty for
 * a repeat the respondent added nothing to.
 */
type Block = { id: string | null; label: string | null; fields: FieldRow[] };

/** The submission's generated PDF record (Increment H17), or null if none has been made yet. */
type PdfArtifact = { id: string; generated_at: string | null; size_bytes: number };

type Submission = {
    id: string;
    // Increment J2e — the short handle, already grouped by the server (`7K4M-2QXB`).
    reference: string;
    form_id: string;
    form_title: string;
    version_number: number | null;
    status: string;
    status_label: string;
    source: string;
    source_label: string;
    respondent: string;
    locale: string | null;
    submitted_at: string | null;
    finalized_at: string | null;
    review: {
        validator: string | null;
        validated_at: string | null;
        returned_reason: string | null;
        remarks: string | null;
    };
};

const props = defineProps<{
    submission: Submission;
    blocks: Block[];
    // `update` is I9c's `SubmissionPolicy::update()` — a SEPARATE permission from `review`, held by
    // Owner/Admin (`submissions.edit.any`) and Form Editor (`.own`), and NOT by Reviewer. Folding it into
    // `can.review` would hand every Reviewer the power to rewrite the answers they are meant to be judging.
    can: { review: boolean; update: boolean };
    pdf: PdfArtifact | null;
    /**
     * The trail back, resolved SERVER-SIDE by `CrumbTrail` (Increment J2d).
     *
     * ══════════════════════════════════════════════════════════════════════════════════════════════════
     * ⚠️ THIS PAGE IS WHY J2d EXISTS. THE TWO MIDDLE CRUMBS WERE BOTH LIVE DEFECTS.
     * ══════════════════════════════════════════════════════════════════════════════════════════════════
     * J2c built them here as hard-coded `/forms/${form_id}` and `/forms/${form_id}/submissions`, on a route
     * gated ONLY by `can:view,submission`:
     *
     *  • `SubmissionPolicy::view()` admits a RESPONDENT (`respondent_user_id = me`), an arm
     *    `FormPolicy::viewOverview()` has no counterpart for. A keyer whose grant was revoked, or whose form
     *    was re-scoped, opened this page and got a **403 with no way back** from both crumbs.
     *  • A SOFT-DELETED form makes `form_title` render `—` and excludes the row from route-model binding,
     *    so an unguarded trail prints an em dash as a live hyperlink to a 404. ⚠️ FAIL-CLOSED RATHER THAN
     *    LIVE: nothing in the product soft-deletes a form today (no delete route; `FormService::archive()`
     *    sets `status`, never `deleted_at`), so this half is a guard for the feature that adds one. An
     *    archived form's hub resolves 200 and stays linked.
     *
     * Neither was visible to any gate: `MdsBreadcrumb` renders an href-less crumb as text, so broken and
     * correct look identical to vue-tsc, to axe and to every snapshot. Do not rebuild this client-side.
     */
    crumbs: BreadcrumbItem[];
}>();

/**
 * Queue a PDF (Increment H17).
 *
 * Request → redirect → toast, the H13b "redeliver" shape, which is this application's only
 * existing affordance for "you enqueued a job". It is NOT the H14/H15b `flash.testResult` modal
 * pattern: that works only because both testers run synchronously inline, and this genuinely does
 * not. There is no polling here and none anywhere in the app — the artifact arrives by email, and
 * a page reload surfaces it in the card below.
 */
const pdfQueuing = ref(false);

function generatePdf(): void {
    pdfQueuing.value = true;
    router.post(
        `/submissions/${props.submission.id}/pdf`,
        {},
        { preserveScroll: true, onFinish: () => (pdfQueuing.value = false) },
    );
}

function formatBytes(bytes: number): string {
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

// Which transitions are offered, from the current status (the service also guards server-side).
//
// `archive` is a POSITIVE list, mirroring `SubmissionReviewService::archive()`'s `$from` exactly. It used to
// be the negative `s !== 'archived' && s !== 'draft'`, which agreed with the server only by accident: the
// server refuses what it does not name, the client offered whatever it did not exclude, so every new
// SubmissionStatus silently became archivable in the UI and 500-adjacent on click. I9a's `screened_out` was
// the case that would have shipped that — and archiving it would have been worse than a dead button, because
// `archived` CONSUMES a capacity slot and `screened_out` deliberately does not, so the transition would have
// retroactively overfilled a paid cap. Keep this list positive; a future status must be added on purpose.
const actions = computed(() => {
    if (!props.can.review) return { review: false, approve: false, return: false, archive: false };
    const s = props.submission.status;
    return {
        review: s === 'submitted',
        approve: s === 'submitted' || s === 'under_review',
        return: s === 'submitted' || s === 'under_review',
        archive: s === 'submitted' || s === 'under_review' || s === 'approved' || s === 'returned',
    };
});

/**
 * "Edit answers" (Increment I9c) — a POSITIVE list for the same reason `archive` above is one, and gated on a
 * DIFFERENT permission (`can.update`, not `can.review`), so it is computed separately rather than folded into
 * the block above.
 *
 * The four states mirror `SubmissionAnswerEditService::EDITABLE` exactly. `SubmissionAnswerEditTest` drives `EDITABLE` against
 * `SubmissionStatus::cases()`, so the SERVER's set is pinned to the enum itself; `show.test.ts` pins this
 * client gate against a hand-transcribed copy of it. Two locks, one of them soft — NOT one assertion
 * spanning both, which an earlier draft of this sentence claimed. `draft` is excluded here even though the controller redirects it to the
 * resume page: this button belongs to a detail view the inbox only reaches for finalized rows, and offering
 * "Edit answers" as a synonym for "Resume draft" would be two names for one thing.
 */
const canEditAnswers = computed(() => {
    if (!props.can.update) return false;
    const s = props.submission.status;

    return s === 'submitted' || s === 'under_review' || s === 'approved' || s === 'returned';
});

const hasReviewInfo = computed(() => {
    const r = props.submission.review;
    return Boolean(r.validator || r.returned_reason || r.remarks);
});

const reviewUrl = `/submissions/${props.submission.id}/review`;

function oneClick(action: 'under_review' | 'approve'): void {
    router.patch(reviewUrl, { action }, { preserveScroll: true });
}

// Return (reason required) + archive confirm run through useForm so a missing reason surfaces inline.
const returnForm = useForm({ action: 'return', returned_reason: '', remarks: '' });
const archiveForm = useForm({ action: 'archive', remarks: '' });
const returnModalOpen = ref(false);
const archiveModalOpen = ref(false);

function openReturn(): void {
    returnForm.reset();
    returnForm.clearErrors();
    returnModalOpen.value = true;
}

function submitReturn(): void {
    returnForm.patch(reviewUrl, { preserveScroll: true, onSuccess: () => (returnModalOpen.value = false) });
}

function submitArchive(): void {
    archiveForm.patch(reviewUrl, { preserveScroll: true, onSuccess: () => (archiveModalOpen.value = false) });
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <div>
        <Head :title="`Submission · ${submission.form_title}`" />

        <PageHeader :title="submission.form_title" icon="submissions">
            <!--
                Increment J2c. This was a hand-rolled `← Back to submissions` link sitting OUTSIDE
                `PageHeader` — one of the four back-links DSR §3.4 names as owing this migration, and the
                only navigation off this page. It went to the global inbox, so a reviewer who arrived from a
                form had no way back to that form: the h1 above prints the form's TITLE, unlinked, which
                `FormHubController`'s docblock names as one of the three dead ends the hub was built to end.
                Four crumbs rather than three because this page genuinely is four deep, and the last is never
                a link — so ending at "Responses" would print the per-form list's name unreachable.
            -->
            <template #breadcrumbs>
                <MdsBreadcrumb :items="crumbs" :link-component="Link" />
            </template>
            <template #actions>
                <!-- I9c. Tertiary and FIRST, ahead of the review verbs: correcting a record is a different
                     kind of act from deciding its outcome, and the primary action on this page stays the
                     decision.
                     ⚠️ `<Link>` WRAPPING A BUTTON, not `MdsButton as="a"`. Both render an anchor, but the
                     bare `as="a"` form does a FULL BROWSER RELOAD — it remounts the persistent app shell and
                     loses client state — and every `as="a"` elsewhere in this tree points at something that
                     is deliberately not an Inertia page (a `mailto:`, an OAuth redirect, a file stream).
                     `forms/Index.vue` is the pattern for a button that navigates to another Inertia page.
                     Getting this wrong would have made navigation INTO the edit page hard while every route
                     out of it stayed soft. -->
                <Link v-if="canEditAnswers" :href="`/submissions/${submission.id}/edit`">
                    <MdsButton variant="tertiary" icon-left="edit">Edit answers</MdsButton>
                </Link>
                <MdsButton v-if="actions.review" variant="tertiary" @click="oneClick('under_review')">
                    Mark under review
                </MdsButton>
                <MdsButton v-if="actions.return" variant="secondary" icon-left="undo" @click="openReturn">Return</MdsButton>
                <MdsButton v-if="actions.approve" variant="primary" icon-left="check" @click="oneClick('approve')">
                    Approve
                </MdsButton>
                <MdsButton v-if="actions.archive" variant="tertiary" icon-left="trash" @click="archiveModalOpen = true">
                    Archive
                </MdsButton>
            </template>
        </PageHeader>

        <div class="detail__grid">
            <MdsCard>
                <dl class="detail__meta">
                    <!-- Increment J2e — FIRST, because it is what this page is ABOUT. Until now the detail
                         view showed no identifier at all: a respondent quoting a code had nothing on screen
                         to match it against. Not a link (this IS that page) and not truncated. -->
                    <div class="detail__meta-row">
                        <dt>Reference</dt>
                        <dd class="detail__reference">{{ submission.reference }}</dd>
                    </div>
                    <div class="detail__meta-row">
                        <dt>Status</dt>
                        <dd><MdsBadge v-bind="statusVariant(submission.status)" dot /></dd>
                    </div>
                    <div class="detail__meta-row">
                        <dt>Version</dt>
                        <dd>{{ submission.version_number !== null ? `v${submission.version_number}` : '—' }}</dd>
                    </div>
                    <div class="detail__meta-row"><dt>Source</dt><dd>{{ submission.source_label }}</dd></div>
                    <div class="detail__meta-row"><dt>Respondent</dt><dd>{{ submission.respondent }}</dd></div>
                    <div class="detail__meta-row"><dt>Submitted</dt><dd>{{ formatDate(submission.submitted_at) }}</dd></div>
                    <div v-if="submission.finalized_at" class="detail__meta-row">
                        <dt>Finalized</dt><dd>{{ formatDate(submission.finalized_at) }}</dd>
                    </div>
                    <div v-if="submission.locale" class="detail__meta-row"><dt>Locale</dt><dd>{{ submission.locale }}</dd></div>
                </dl>
            </MdsCard>

            <!--
                PDF record (Increment H17). Deliberately its own card rather than a fifth button in
                #actions: the header already carries up to four review-workflow buttons, and this is
                not a review action — it has state (generated when, how big) that a button cannot
                express, and its own explanation of why nothing appears immediately.
            -->
            <MdsCard>
                <template #header><h2 class="detail__card-title">PDF record</h2></template>

                <template v-if="pdf">
                    <dl class="detail__meta">
                        <div class="detail__meta-row">
                            <dt>Generated</dt><dd>{{ formatDate(pdf.generated_at) }}</dd>
                        </div>
                        <div class="detail__meta-row"><dt>Size</dt><dd>{{ formatBytes(pdf.size_bytes) }}</dd></div>
                    </dl>
                    <div class="detail__pdf-actions">
                        <MdsButton as="a" :href="`/attachments/${pdf.id}`" variant="primary" icon-left="download">
                            Download
                        </MdsButton>
                        <MdsButton variant="tertiary" :loading="pdfQueuing" @click="generatePdf">Regenerate</MdsButton>
                    </div>
                    <p class="detail__pdf-hint">
                        Regenerating replaces this file. Only the questions this respondent was shown are included.
                    </p>
                </template>

                <template v-else>
                    <p class="detail__pdf-hint">
                        A printable record of this submission, showing only the questions the respondent was
                        actually shown. We will email you a link when it is ready.
                    </p>
                    <div class="detail__pdf-actions">
                        <MdsButton variant="secondary" icon-left="download" :loading="pdfQueuing" @click="generatePdf">
                            Generate PDF
                        </MdsButton>
                    </div>
                </template>
            </MdsCard>

            <MdsCard v-if="hasReviewInfo">
                <template #header><h2 class="detail__card-title">Review</h2></template>
                <dl class="detail__meta">
                    <div v-if="submission.review.validator" class="detail__meta-row">
                        <dt>Reviewer</dt><dd>{{ submission.review.validator }}</dd>
                    </div>
                    <div v-if="submission.review.validated_at" class="detail__meta-row">
                        <dt>Reviewed</dt><dd>{{ formatDate(submission.review.validated_at) }}</dd>
                    </div>
                    <div v-if="submission.review.returned_reason" class="detail__meta-row">
                        <dt>Return reason</dt><dd>{{ submission.review.returned_reason }}</dd>
                    </div>
                    <div v-if="submission.review.remarks" class="detail__meta-row">
                        <dt>Remarks</dt><dd>{{ submission.review.remarks }}</dd>
                    </div>
                </dl>
            </MdsCard>
        </div>

        <MdsCard v-for="(block, i) in blocks" :key="block.id ?? `ungrouped-${i}`" class="detail__block">
            <template v-if="block.label" #header><h2 class="detail__card-title">{{ block.label }}</h2></template>
            <p v-if="block.fields.length === 0" class="detail__empty">No entries.</p>
            <dl v-else class="detail__answers">
                <div v-for="field in block.fields" :key="field.key" class="detail__answer">
                    <dt>{{ field.label }}</dt>
                    <dd>{{ field.value || '—' }}</dd>
                </div>
            </dl>
        </MdsCard>

        <!-- Return with reason -->
        <MdsModal v-model:open="returnModalOpen" title="Return submission" @close="returnModalOpen = false">
            <form class="detail__form" @submit.prevent="submitReturn">
                <MdsFormField label="Reason" required :error="returnForm.errors.returned_reason" v-slot="{ id, describedby, invalid }">
                    <MdsTextarea
                        :id="id"
                        v-model="returnForm.returned_reason"
                        :describedby="describedby"
                        :invalid="invalid"
                        placeholder="What needs to be corrected or completed?"
                    />
                </MdsFormField>
                <MdsFormField label="Internal remarks (optional)" :error="returnForm.errors.remarks" v-slot="{ id, describedby, invalid }">
                    <MdsTextarea :id="id" v-model="returnForm.remarks" :describedby="describedby" :invalid="invalid" />
                </MdsFormField>
            </form>
            <template #actions>
                <MdsButton variant="tertiary" @click="returnModalOpen = false">Cancel</MdsButton>
                <MdsButton
                    variant="primary"
                    icon-left="undo"
                    :loading="returnForm.processing"
                    :disabled="returnForm.returned_reason.trim() === ''"
                    @click="submitReturn"
                >
                    Return submission
                </MdsButton>
            </template>
        </MdsModal>

        <!-- Archive confirm -->
        <MdsModal :open="archiveModalOpen" title="Archive submission" @close="archiveModalOpen = false">
            <p class="detail__prose">
                Archive this submission? It moves to the terminal archived state and leaves the review queue. The
                response data is kept.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="archiveModalOpen = false">Cancel</MdsButton>
                <MdsButton variant="destructive" icon-left="trash" :loading="archiveForm.processing" @click="submitArchive">
                    Archive submission
                </MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
/* `.detail__back` was deleted with its markup in J2c — the hand-rolled back link became `MdsBreadcrumb`
   inside `PageHeader`'s slot, which brings its own styling. Left behind it would be dead rules that read as
   an element still on the page. */

.detail__grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(18rem, 1fr));
    gap: var(--mds-space-4);
    margin-bottom: var(--mds-space-5);
}

.detail__block {
    margin-bottom: var(--mds-space-4);
}

.detail__card-title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.detail__meta,
.detail__answers {
    margin: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

/* A repeatable section the respondent added nothing to (Increment H6b) — distinct from a section whose
   fields were left blank, which still renders its rows with an em-dash value. */
.detail__empty {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

/* PDF record card (Increment H17). `flex-wrap` is not decoration: this is the third instance of the
   standing shared-primitive lesson (H14 `.page-header__actions`, H15b `.mds-table__scroll`, H7
   `.palette`) — a non-wrapping row of buttons is a 375px horizontal-overflow failure waiting for the
   first viewport narrow enough, and that gate only runs in CI. */
.detail__pdf-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-3);
}

.detail__pdf-hint {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.detail__meta-row,
.detail__answer {
    display: grid;
    grid-template-columns: 10rem 1fr;
    gap: var(--mds-space-3);
    align-items: baseline;
}

.detail__meta-row dt,
.detail__answer dt {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-secondary);
}

.detail__meta-row dd,
.detail__answer dd {
    margin: 0;
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.detail__form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.detail__prose {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

@media (max-width: 480px) {
    .detail__meta-row,
    .detail__answer {
        grid-template-columns: 1fr;
        gap: var(--mds-space-1);
    }
}
</style>
