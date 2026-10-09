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
 */
import { computed, onBeforeUnmount, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { MdsAlert, MdsBadge, MdsBreadcrumb, MdsButton, MdsCard, MdsEmptyState, type BreadcrumbItem } from '@meridian/design-system';
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
    crumbs: BreadcrumbItem[];
}>();

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

        <MdsAlert v-if="refusal !== null" tone="info" :message="refusal" />

        <MdsCard v-else>
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
                <p class="mds-visually-hidden" aria-live="polite">{{ announce }}</p>
                <div class="scans__actions">
                    <MdsButton type="submit" variant="primary" icon-left="upload" :loading="sending">
                        Read this scan
                    </MdsButton>
                </div>
            </form>
        </MdsCard>

        <section class="scans__recent" aria-labelledby="scans-recent-title">
            <h2 id="scans-recent-title" class="scans__title">Recent scans</h2>

            <MdsEmptyState
                v-if="scans.length === 0"
                headline="No scans yet"
                description="Scans of this form appear here, newest first, with where each one stands."
            />

            <ol v-else class="scans__list">
                <li v-for="scan in scans" :key="scan.id" class="scans__row">
                    <div class="scans__row-main">
                        <MdsBadge :variant="badgeVariant(scan.status)" :label="scan.status_label" dot />
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
</template>

<style scoped>
.scans {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    max-width: 720px;
}

.scans__intro {
    margin: 0;
    color: var(--mds-color-text-secondary);
}

.scans__upload {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
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
    color: var(--mds-color-danger-text);
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

.scans__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
}

.scans__list {
    display: flex;
    flex-direction: column;
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
    color: var(--mds-color-danger-text);
    font-size: var(--mds-type-body-sm-font-size);
}

.scans__link {
    color: var(--mds-color-action-primary-fg);
    font-weight: var(--mds-type-label-font-weight);
}
</style>
