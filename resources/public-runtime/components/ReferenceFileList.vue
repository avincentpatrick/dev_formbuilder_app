<script setup lang="ts">
/**
 * The reference files a form shows its respondents (M132, `R-bf49e4c1`), under the form's description on every page:
 * a guide, a consent form, a map. Only files the version shows and the virus check has passed are ever sent here.
 *
 * ── THE PAGE FETCHES EACH FILE; IT NEVER NAVIGATES TO ONE (`D84`) ──────────────────────────────────────────
 * The service worker controls `/f/` only, so a link to `/api/...` opened in a new tab is a navigation it never sees
 * and could never serve offline. So an image opens in a dialog whose `<img>` the worker DOES see, and a PDF is
 * fetched by the page and saved from what came back. Either way the worker keeps a copy (`sw.ts`, CacheFirst), and a
 * file opened once online opens again offline. Nothing is downloaded before it is asked for.
 *
 * A PDF is saved rather than shown because the server never renders one in this origin (`InlineAttachmentResponse`
 * sends it as a download); the phone's own viewer opens the saved copy.
 */
import { computed, ref } from 'vue';
import { MdsButton, MdsIcon, MdsModal } from '@meridian/design-system';
import { describeReferenceFile } from '@/components/forms/reference-files';
import { useAnnouncer } from '../composables/context';
import { referenceFileDownloadName, referenceFileUrl } from '../lib/reference-files';
import type { GuestReferenceFile } from '../lib/types';

const props = defineProps<{
    files: GuestReferenceFile[];
    /** The CURRENT share token, read at the moment a file is opened, so a re-minted one is used. */
    shareToken: () => string;
}>();

const OFFLINE = 'Open this file once while you are online to keep it on this device.';
const UNAVAILABLE = 'This file is not available right now. Please try again later.';

const viewing = ref<GuestReferenceFile | null>(null);
const imageFailed = ref(false);
const saving = ref<string | null>(null);
const message = ref('');
// The SPA's one live region (RuntimeShell), so a result is announced without a second `role=status` on the page.
const announcer = useAnnouncer();

function say(text: string): void {
    message.value = text;
    announcer.announce(text);
}

const labelId = `reference-files-${Math.random().toString(36).slice(2, 10)}`;
const imageUrl = computed(() => (viewing.value === null ? '' : referenceFileUrl(props.shareToken(), viewing.value.id)));

function isImage(file: GuestReferenceFile): boolean {
    return file.mime_type.startsWith('image/');
}

function hint(file: GuestReferenceFile): string {
    return `${describeReferenceFile(file.mime_type, file.size_bytes)} · ${isImage(file) ? 'opens here' : 'saves a copy'}`;
}

function unavailable(): string {
    return typeof navigator !== 'undefined' && navigator.onLine === false ? OFFLINE : UNAVAILABLE;
}

function open(file: GuestReferenceFile): void {
    message.value = '';
    if (isImage(file)) {
        imageFailed.value = false;
        viewing.value = file;
        return;
    }
    void save(file);
}

async function save(file: GuestReferenceFile): Promise<void> {
    saving.value = file.id;
    try {
        const response = await fetch(referenceFileUrl(props.shareToken(), file.id), { credentials: 'same-origin' });
        if (!response.ok) {
            say(unavailable());
            return;
        }
        const href = URL.createObjectURL(await response.blob());
        const link = document.createElement('a');
        link.href = href;
        link.download = referenceFileDownloadName(file.label, file.mime_type);
        document.body.appendChild(link);
        link.click();
        link.remove();
        // Long enough for the browser to finish writing the file; a revoked URL would cut the save short.
        window.setTimeout(() => URL.revokeObjectURL(href), 60_000);
        say(`Saved ${link.download}.`);
    } catch {
        // Offline and never opened: the worker has no copy and the network is gone.
        say(unavailable());
    } finally {
        saving.value = null;
    }
}
</script>

<template>
    <div class="reference-files" data-reference-files>
        <p :id="labelId" class="reference-files__label">Reference files</p>
        <ul class="reference-files__list" :aria-labelledby="labelId">
            <li v-for="file in files" :key="file.id">
                <button type="button" class="reference-files__open" :disabled="saving === file.id" @click="open(file)">
                    <MdsIcon :name="isImage(file) ? 'image' : 'download'" size="sm" />
                    <span class="reference-files__name">{{ file.label }}</span>
                    <span class="reference-files__hint">{{ hint(file) }}</span>
                </button>
            </li>
        </ul>
        <p v-if="message !== ''" class="reference-files__message">{{ message }}</p>

        <MdsModal :open="viewing !== null" :title="viewing?.label ?? ''" @close="viewing = null">
            <img
                v-if="viewing !== null && !imageFailed"
                class="reference-files__image"
                :src="imageUrl"
                :alt="viewing.label"
                @error="imageFailed = true"
            />
            <p v-else class="reference-files__unavailable">{{ unavailable() }}</p>
            <template #actions>
                <MdsButton variant="tertiary" @click="viewing = null">Close</MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
.reference-files {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.reference-files__label {
    margin: 0;
    color: var(--mds-color-text-heading);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-semibold);
}

.reference-files__list {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

/* A chip a thumb can hit: the 44px minimum target, its name and its kind on one or two lines. */
.reference-files__open {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-1) var(--mds-space-2);
    max-width: 100%;
    min-height: 44px;
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-sm);
    background-color: var(--mds-color-bg-surface);
    color: var(--mds-color-action-primary-fg);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    text-align: left;
    cursor: pointer;
}

.reference-files__open:hover {
    background-color: var(--mds-color-bg-sunken);
}

.reference-files__open:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}

.reference-files__open:disabled {
    cursor: progress;
}

.reference-files__name {
    font-weight: var(--mds-font-weight-medium);
    overflow-wrap: anywhere;
}

.reference-files__hint {
    color: var(--mds-color-text-secondary);
}

.reference-files__message,
.reference-files__unavailable {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.reference-files__image {
    display: block;
    max-width: 100%;
    height: auto;
    margin: 0 auto;
}
</style>
