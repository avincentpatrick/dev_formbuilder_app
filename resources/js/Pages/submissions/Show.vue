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
import { MdsBadge, MdsButton, MdsCard, MdsFormField, MdsModal, MdsTextarea, statusVariant } from '@meridian/design-system';
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
    can: { review: boolean };
    pdf: PdfArtifact | null;
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
const actions = computed(() => {
    if (!props.can.review) return { review: false, approve: false, return: false, archive: false };
    const s = props.submission.status;
    return {
        review: s === 'submitted',
        approve: s === 'submitted' || s === 'under_review',
        return: s === 'submitted' || s === 'under_review',
        archive: s !== 'archived' && s !== 'draft',
    };
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

        <Link href="/submissions" class="detail__back">← Back to submissions</Link>

        <PageHeader :title="submission.form_title" icon="submissions">
            <template #actions>
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
                    <div class="detail__meta-row">
                        <dt>Status</dt>
                        <dd><MdsBadge v-bind="statusVariant(submission.status)" /></dd>
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
.detail__back {
    display: inline-block;
    margin-bottom: var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-action-primary-fg);
    text-decoration: none;
}

.detail__back:hover {
    text-decoration: underline;
}

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
