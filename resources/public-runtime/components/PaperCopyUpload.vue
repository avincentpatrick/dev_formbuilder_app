<script setup lang="ts">
/**
 * "Filled in a paper copy? Upload it instead" (M165, `R-7173c02c`, `D106`) — above the form, for a respondent who
 * already answered on the printed form. They send photos or one PDF of its pages, and the page passes up the reference to
 * quote and the paper token the server answered with. Since M166 (`D116`) the page then waits for the reading and shows
 * the sender the form filled in as read, to correct and submit; `PaperReading` is that wait, and leaving the copy for
 * staff — `M165`'s whole behaviour — is always one button away.
 *
 * Shown only where the schema's `paper_upload` hint says the form takes one — a HINT: the service worker can serve
 * an older schema, so the server decides each upload and its refusals are shown here in its own words.
 *
 * ⚠️ COLLAPSED UNTIL ASKED FOR. Most respondents are here to fill in the form, so the upload is one quiet button
 * rather than a second form above the first.
 *
 * ⚠️ ONLINE ONLY, AND NEVER QUEUED. The outbox holds answers, not files, and a respondent must see the reference
 * before they leave — so offline the button says why it cannot send rather than pretending to.
 *
 * The file input is native and styled, the scans page's choice and for its reasons: the operating system's picker,
 * the keyboard and the phone camera. The list of chosen pages and the file check are the scans page's own
 * (`ChosenPages.vue`, `checkScanFiles`), so a page refused there is refused here in the same words.
 */
import { computed, onBeforeUnmount, ref, useId } from 'vue';
import { MdsButton } from '@meridian/design-system';
import ChosenPages from '@/components/ocr/ChosenPages.vue';
import { checkScanFiles } from '@/components/ocr/scan-upload';
import { ApiError } from '../lib/error-normalizer';
import type { ApiClient } from '../lib/api-client';
import type { PaperUploadLimits } from '../lib/types';

const props = defineProps<{
    limits: PaperUploadLimits;
    client: ApiClient;
    online: boolean;
}>();

const emit = defineEmits<{ received: [reference: string, paperToken: string] }>();

const panelId = useId();
const inputId = useId();
const hintId = useId();
const errorId = useId();

interface ChosenPage {
    id: number;
    file: File;
    previewUrl: string | null;
}

let seq = 0;
const open = ref(false);
const chosen = ref<ChosenPage[]>([]);
const error = ref<string | null>(null);
const sending = ref(false);
const announce = ref('');

const accept = computed(() => props.limits.accepted_types.join(','));
const perFileMegabytes = computed(() => Math.floor(props.limits.max_bytes_per_file / 1_000_000));
const chosenFiles = computed(() => chosen.value.map((page) => page.file));
const chosenView = computed(() =>
    chosen.value.map((page) => ({ id: page.id, name: page.file.name, size: page.file.size, previewUrl: page.previewUrl })),
);

function recheck(): void {
    error.value = chosen.value.length > 0 ? checkScanFiles(chosenFiles.value, props.limits) : null;
}

function onChoose(event: Event): void {
    const target = event.target as HTMLInputElement;
    for (const file of Array.from(target.files ?? [])) {
        seq += 1;
        chosen.value.push({ id: seq, file, previewUrl: file.type.startsWith('image/') ? URL.createObjectURL(file) : null });
    }
    // Emptied so the same file can be picked again after a removal; the list is what was chosen.
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

/** The server's refusal in words a respondent can act on. */
function refusalMessage(thrown: unknown): string {
    if (!(thrown instanceof ApiError)) {
        return 'The paper copy could not be sent. Check your connection and try again.';
    }

    const { kind, code, message, fieldErrors, retryAfterSeconds } = thrown.normalized;
    if (kind === 'rate_limited' && code !== 'paper_upload_limit_reached') {
        const minutes = Math.max(1, Math.ceil((retryAfterSeconds ?? 60) / 60));
        return `You have sent several copies just now. Please wait ${minutes} ${minutes === 1 ? 'minute' : 'minutes'} and try again.`;
    }
    const firstField = Object.values(fieldErrors)[0]?.[0];

    return firstField ?? message;
}

async function send(): Promise<void> {
    if (sending.value || !props.online) {
        return;
    }

    const refused = checkScanFiles(chosenFiles.value, props.limits);
    if (refused !== null) {
        error.value = refused;
        return;
    }

    sending.value = true;
    error.value = null;
    try {
        const receipt = await props.client.uploadPaperCopy(chosenFiles.value);
        emit('received', receipt.reference, receipt.paperToken);
    } catch (thrown) {
        error.value = refusalMessage(thrown);
    } finally {
        sending.value = false;
    }
}
</script>

<template>
    <section class="paper" data-paper-copy>
        <MdsButton
            variant="tertiary"
            icon-left="upload"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="panelId"
            @click="open = !open"
        >
            Filled in a paper copy? Upload it instead
        </MdsButton>

        <form v-show="open" :id="panelId" class="paper__panel" @submit.prevent="send">
            <p class="paper__intro">
                Send photos or a scan of the paper form you filled in. Our staff will check it and enter your answers
                for you, so there is no need to fill in the form below as well.
            </p>

            <label class="paper__label" :for="inputId">Pages of your paper form</label>
            <p :id="hintId" class="paper__hint">
                Up to {{ limits.max_pages }} photos, one per page, or one PDF. JPEG, PNG, WebP or PDF, up to
                {{ perFileMegabytes }} MB each.
            </p>
            <input
                :id="inputId"
                class="paper__file"
                type="file"
                multiple
                :accept="accept"
                :disabled="sending"
                :aria-describedby="hintId"
                :aria-invalid="error !== null ? 'true' : undefined"
                :aria-errormessage="error !== null ? errorId : undefined"
                @change="onChoose"
            />
            <p v-if="error !== null" :id="errorId" class="paper__error" role="alert">{{ error }}</p>
            <ChosenPages :pages="chosenView" :disabled="sending" @remove="remove" />
            <p class="paper__announce" aria-live="polite">{{ announce }}</p>

            <p v-if="!online" class="paper__offline" role="status">
                You are offline. Connect to the internet to send your paper copy.
            </p>

            <div class="paper__actions">
                <MdsButton type="submit" variant="primary" icon-left="upload" :loading="sending" :disabled="!online">
                    {{ sending ? 'Sending…' : 'Send paper copy' }}
                </MdsButton>
            </div>
        </form>
    </section>
</template>

<style scoped>
.paper {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--mds-space-2);
}

/* Positioned so the clipped announcement below resolves its containing block HERE (`clipped-node-containment`). */
.paper__panel {
    position: relative;
    align-self: stretch;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.paper__intro {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.paper__label {
    font-family: var(--mds-font-family-body);
    font-weight: var(--mds-type-label-font-weight);
    color: var(--mds-color-text-body);
}

.paper__hint,
.paper__offline {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

/* The native control, kept native — see the docblock. Only its box is themed, so it reads as a field. */
.paper__file {
    max-width: 100%;
    padding: var(--mds-space-2);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-bg-surface);
    color: var(--mds-color-text-body);
}

.paper__file:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.paper__error {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-danger-text);
}

/* What a screen reader hears after a pick or a removal, clipped to nothing on screen. */
.paper__announce {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}

.paper__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}
</style>
