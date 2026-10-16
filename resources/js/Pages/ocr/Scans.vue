<script setup lang="ts">
/**
 * The scans page (M129 — single-form OCR groundwork 2): send the photos or the PDF of one filled-in paper
 * form, and find a scan again once it has been read.
 *
 * The page renders even when the form does not accept scans, because the route's gates are who may scan and
 * whether the workspace may — the form's own opt-in is the upload's refusal to make. So the page says why, in
 * the server's words (`refusal`), instead of a reader meeting a 403 who may be the one able to fix it.
 *
 * ⚠️ A NATIVE FILE INPUT, STYLED, AND NOT A DESIGN-SYSTEM COMPONENT, because the design system has no file
 * input. It is the `MediaInput.vue` and `BrandingCard.vue` precedent: a real `<input type="file">` keeps the
 * operating system's picker, the keyboard and the phone camera, which a custom control would have to
 * re-create one by one.
 *
 * After a successful upload the reader goes to the scan's review page, which waits for the reading.
 *
 * M154 (`R-c84e4f12`): the chosen pages are LISTED before they are sent — a preview, the name, the size and a
 * Remove — because the native input says only "2 files" and a wrong photo used to be found on the review screen,
 * after it had been read. A pick ADDS to the list and the input is emptied, so a missed page is picked on its own
 * and a wrong one removed without starting again; the file check runs on every change, not only on send.
 *
 * M165 (`R-7173c02c`, `D106`): a filled paper copy a guest sent from the public link is listed here like any scan,
 * marked "From the public link" with the reference its sender was given, and the search above the list finds a
 * scan by that reference — what a guest who calls about their paper copy can quote. The server normalizes what is
 * typed (any case, with or without the hyphen) and finds nothing for text that cannot be a reference.
 */
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    MdsAlert,
    MdsBadge,
    MdsBreadcrumb,
    MdsButton,
    MdsCard,
    MdsEmptyState,
    MdsSearchField,
    type BreadcrumbItem,
} from '@meridian/design-system';
import type { BadgeVariant } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import ChosenPages from '@/components/ocr/ChosenPages.vue';
import { checkScanFiles, uploadScan, type ScanLimits } from '@/components/ocr/scan-upload';

interface ScanRow {
    id: string;
    /** `queued`, `reading`, `read`, `failed`, or `saved` once a reviewer has saved it. */
    status: string;
    /** The status in the reader's words, from the server. This page keeps no list of statuses. */
    status_label: string;
    pages: number;
    uploaded_by: string | null;
    /** M165: a guest sent it from the form's public link. */
    from_public_link: boolean;
    /** The code a guest was given (`7K4M-2QXB`); null for a staff upload. */
    reference: string | null;
    created_at: string;
    review_url: string | null;
    submission_url: string | null;
    error_message: string | null;
}

const props = defineProps<{
    form: { id: string; title: string };
    accepts_scans: boolean;
    /** Why a scan of this form would be refused right now, or null when it would be read. */
    refusal: string | null;
    upload: ScanLimits & { url: string };
    scans: ScanRow[];
    /** M165: the reference the list is narrowed to, as it was typed; null for the newest scans. */
    search: { reference: string | null };
    crumbs: BreadcrumbItem[];
}>();

const referenceQuery = ref(props.search.reference ?? '');

/** Narrow the list to one reference, or back to the newest scans when the box is emptied. */
function findByReference(): void {
    const reference = referenceQuery.value.trim();
    router.get(`/forms/${props.form.id}/ocr/scans`, reference === '' ? {} : { reference }, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function showAll(): void {
    referenceQuery.value = '';
    findByReference();
}

/** A chosen file, with the preview this page made for it and must revoke. */
interface ChosenPage {
    id: number;
    file: File;
    previewUrl: string | null;
}

let seq = 0;
const chosen = ref<ChosenPage[]>([]);
const error = ref<string | null>(null);
const sending = ref(false);
/** What a screen reader hears after a pick or a removal — the list itself changes silently. */
const announce = ref('');

const accept = computed(() => props.upload.accepted_types.join(','));
const perFileMegabytes = computed(() => Math.floor(props.upload.max_bytes_per_file / 1_000_000));
const chosenFiles = computed(() => chosen.value.map((page) => page.file));
const chosenView = computed(() =>
    chosen.value.map((page) => ({ id: page.id, name: page.file.name, size: page.file.size, previewUrl: page.previewUrl })),
);

/** The check on the list as it now stands. An empty list is not refused here — only a send of nothing is. */
function recheck(): void {
    error.value = chosen.value.length > 0 ? checkScanFiles(chosenFiles.value, props.upload) : null;
}

function onChoose(event: Event): void {
    const target = event.target as HTMLInputElement;
    const picked = Array.from(target.files ?? []);
    for (const file of picked) {
        seq += 1;
        chosen.value.push({ id: seq, file, previewUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : null });
    }
    // Emptied so the same file can be picked again after a removal; the list above is what was chosen.
    target.value = '';
    recheck();
    const count = chosen.value.length;
    announce.value = `${count} ${count === 1 ? 'page' : 'pages'} chosen.`;
}

function remove(id: number): void {
    const page = chosen.value.find((candidate) => candidate.id === id);
    if (page === undefined) {
        return;
    }
    if (page.previewUrl !== null) {
        URL.revokeObjectURL(page.previewUrl);
    }
    chosen.value = chosen.value.filter((candidate) => candidate.id !== id);
    recheck();
    announce.value = `${page.file.name} removed.`;
}

onBeforeUnmount(() => {
    for (const page of chosen.value) {
        if (page.previewUrl !== null) {
            URL.revokeObjectURL(page.previewUrl);
        }
    }
});

async function send(): Promise<void> {
    if (sending.value) {
        return;
    }

    const refused = checkScanFiles(chosenFiles.value, props.upload);
    if (refused !== null) {
        error.value = refused;
        return;
    }

    sending.value = true;
    error.value = null;
    try {
        const scan = await uploadScan(props.upload.url, chosenFiles.value);
        router.visit(`/forms/${props.form.id}/ocr/scans/${scan.id}/review`);
    } catch (thrown) {
        error.value = thrown instanceof Error ? thrown.message : 'The scan was not accepted. Please try again.';
        sending.value = false;
    }
}

/** The status pill. The label is the server's; only the colour is decided here, by the state it names. */
function badgeVariant(status: string): BadgeVariant {
    switch (status) {
        case 'saved':
            return 'success';
        case 'read':
            return 'info';
        case 'failed':
            return 'danger';
        default:
            return 'neutral';
    }
}

function uploadedAt(iso: string): string {
    const date = new Date(iso);
    return Number.isNaN(date.getTime()) ? iso : date.toLocaleString();
}
</script>

<template>
    <div class="scans">
        <Head :title="`Scanned forms — ${form.title}`" />

        <PageHeader title="Scanned forms" icon="upload">
            <template #breadcrumbs>
                <MdsBreadcrumb :items="crumbs" :link-component="Link" />
            </template>
        </PageHeader>

        <p class="scans__intro">
            Send the photos or the scan of one filled-in paper copy of <strong>{{ form.title }}</strong>. It is
            read automatically, and you check every answer before it is saved as a response.
        </p>

        <div class="scans__layout">
            <MdsAlert v-if="refusal !== null" class="scans__upload-card" tone="info" :message="refusal" />

            <MdsCard v-else class="scans__upload-card">
                <form class="scans__upload" @submit.prevent="send">
                    <label class="scans__label" for="scan-pages">Pages of one form</label>
                    <p id="scan-pages-hint" class="scans__hint">
                        Up to {{ upload.max_pages }} photos, one per page, or one PDF. JPEG, PNG, WebP or PDF, up to
                        {{ perFileMegabytes }} MB each.
                    </p>
                    <input
                        id="scan-pages"
                        class="scans__file"
                        type="file"
                        multiple
                        :accept="accept"
                        aria-describedby="scan-pages-hint"
                        :aria-invalid="error !== null ? 'true' : undefined"
                        :aria-errormessage="error !== null ? 'scan-pages-error' : undefined"
                        @change="onChoose"
                    />
                    <p v-if="error !== null" id="scan-pages-error" class="scans__error" role="alert">{{ error }}</p>
                    <ChosenPages :pages="chosenView" :disabled="sending" @remove="remove" />
                    <p class="scans__announce" aria-live="polite">{{ announce }}</p>
                    <div class="scans__actions">
                        <MdsButton type="submit" variant="primary" icon-left="upload" :loading="sending">
                            Read this scan
                        </MdsButton>
                    </div>
                </form>
            </MdsCard>

            <section class="scans__recent" aria-labelledby="scans-recent-title">
                <h2 id="scans-recent-title" class="scans__title">Recent scans</h2>

                <div class="scans__search">
                    <MdsSearchField
                        v-model="referenceQuery"
                        :applied="search.reference ?? ''"
                        label="Find a scan by its reference"
                        placeholder="For example 7K4M-2QXB"
                        @submit="findByReference"
                    />
                    <MdsButton v-if="search.reference !== null" variant="tertiary" size="sm" @click="showAll">
                        Show all scans
                    </MdsButton>
                </div>

                <MdsEmptyState
                    v-if="scans.length === 0 && search.reference !== null"
                    headline="No scan has that reference"
                    description="Check the reference against what the sender was given. A reference is eight letters and digits, like 7K4M-2QXB."
                />

                <MdsEmptyState
                    v-else-if="scans.length === 0"
                    headline="No scans yet"
                    description="Scans of this form appear here, newest first, with where each one stands."
                />

                <ol v-else class="scans__list">
                    <li v-for="scan in scans" :key="scan.id" class="scans__row">
                        <div class="scans__row-main">
                            <MdsBadge :variant="badgeVariant(scan.status)" :label="scan.status_label" dot />
                            <MdsBadge v-if="scan.from_public_link" variant="neutral" label="From the public link" />
                            <span v-if="scan.reference" class="scans__reference">Reference {{ scan.reference }}</span>
                            <span class="scans__meta">
                                {{ uploadedAt(scan.created_at) }} ·
                                {{ scan.pages }} {{ scan.pages === 1 ? 'page' : 'pages' }}<template v-if="scan.uploaded_by">
                                    · {{ scan.uploaded_by }}</template>
                            </span>
                        </div>
                        <p v-if="scan.error_message" class="scans__row-error">{{ scan.error_message }}</p>
                        <div class="scans__row-action">
                            <Link v-if="scan.submission_url" :href="scan.submission_url" class="scans__link">
                                View the response
                            </Link>
                            <Link v-else-if="scan.review_url" :href="scan.review_url" class="scans__link">
                                {{ scan.status === 'read' ? 'Review' : 'Open' }}
                            </Link>
                        </div>
                    </li>
                </ol>
            </section>
        </div>
    </div>
</template>

<style scoped>
/* M173 (`R-8ee7d2dc`, `D120`, `D121`) — the page fills the width; it was a 720px column. The upload form keeps a
   measure because it is a form; the recent scans become a grid. The page is the container for its own split, and
   the split is on an inner wrapper, because an element cannot match its own container query (the `M129` note in
   `FormSettingsSections.vue`). */
.scans {
    container-type: inline-size;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.scans__layout {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.scans__upload-card {
    max-inline-size: 36rem;
}

/* From 75rem of page the upload card sits beside the recent scans instead of above them. */
@container (min-width: 75rem) {
    .scans__layout {
        display: grid;
        grid-template-columns: minmax(0, 36rem) minmax(0, 1fr);
        align-items: start;
        gap: var(--mds-space-6);
    }
}

.scans__intro {
    margin: 0;
    color: var(--mds-color-text-secondary);
}

/* Positioned so the clipped announcement below resolves its containing block HERE rather than far up the page
   (`clipped-node-containment.test.ts`). */
.scans__upload {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

/* M154: what a screen reader hears after a pick or a removal, clipped to nothing on screen. Its own rule because
   this repository has no shared utility for it — the `mds-visually-hidden` class it first copied is defined nowhere,
   which a real-browser look at this page found when the announcement showed as visible text (`MediaInput.vue`: M155). */
.scans__announce {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

.scans__label {
    font-weight: var(--mds-type-label-font-weight);
}

.scans__hint {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

/* The native control, kept native — see the docblock. Only its box is themed, so it reads as a field. */
.scans__file {
    max-width: 100%;
    padding: var(--mds-space-2);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-bg-surface);
    color: var(--mds-color-text-body);
}

.scans__file:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.scans__error {
    margin: 0;
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-sm-font-size);
}

.scans__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}

.scans__recent {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.scans__search {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-end;
    gap: var(--mds-space-2);
}

.scans__reference {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}

.scans__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
}

/* A grid of the same bordered rows, so a wide screen shows more scans across rather than one row's status at the
   left edge and its link at the right. `auto-fill` keeps a lone scan row-sized. */
.scans__list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 28rem), 1fr));
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.scans__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-bg-surface);
}

.scans__row-main {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    min-width: 0;
}

.scans__meta {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    overflow-wrap: anywhere;
}

.scans__row-error {
    flex-basis: 100%;
    margin: 0;
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-sm-font-size);
}

.scans__link {
    color: var(--mds-color-action-primary-fg);
    font-weight: var(--mds-type-label-font-weight);
}
</style>
