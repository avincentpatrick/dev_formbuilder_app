<script setup lang="ts">
/**
 * The media capture control (Increment G6), shared by manual-encode + the public SPA via {@link FieldInput.vue}
 * (mounted lazily through `defineAsyncComponent`). Covers `file_upload` / `image_capture` / `audio_capture` /
 * `video_capture`; `signature` (a canvas control) is deferred.
 *
 * The canonical value is a `MediaAttachmentRef[]` — a list of references to already-staged `attachments` rows.
 * Upload happens ON SELECTION (UX §8.4), before the form is submitted: each picked file is validated
 * client-side (size + type, from the field config), uploaded to the channel's staging endpoint
 * (`field.upload.url`) with a determinate per-file progress bar + retry, and only then appended to the value.
 * The server is authoritative — required/min/max count are re-checked by `processMedia`, and existence/scan by
 * `AttachmentReferenceValidator` at submit; this control only PRODUCES the ref list.
 *
 * Capture: on mobile the native `<input type="file" accept capture>` opens the camera/microphone directly —
 * the standard, accessible, offline-safe baseline. (A desktop in-page getUserMedia/MediaRecorder live recorder
 * is a deferred fast-follow.) Accessibility (the merge-blocking axe requirement): a real `<fieldset>/<legend>`,
 * a labelled file input, keyboard-operable remove/retry buttons, and an `aria-live` status/error region.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { MdsButton } from '@meridian/design-system';

import type { AnswerValue, EncodeField, MediaAttachmentRef, RequiredMarker } from './FieldInput.vue';

const props = defineProps<{
    field: EncodeField;
    modelValue: AnswerValue;
    error?: string;
    requiredMarker?: RequiredMarker;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: AnswerValue] }>();

// ── Config + labels ─────────────────────────────────────────────────────────────────────────────
const marker = computed<RequiredMarker>(() => props.requiredMarker ?? (props.field.required ? 'required' : 'none'));
const showRequiredMarker = computed<boolean>(() => marker.value === 'required');
const showOptionalMarker = computed<boolean>(() => marker.value === 'optional');

const acceptedTypes = computed<string[]>(() => props.field.media?.acceptedTypes ?? []);
const maxCount = computed<number | null>(() => props.field.media?.maxCount ?? null);
const maxFileSizeBytes = computed<number | null>(() => props.field.media?.maxFileSizeBytes ?? null);
const isMulti = computed<boolean>(() => maxCount.value !== null && maxCount.value > 1);

/** The `accept` attribute — the author allowlist, or a sensible default per media type (any for file upload). */
const acceptAttr = computed<string | undefined>(() => {
    if (acceptedTypes.value.length > 0) {
        return acceptedTypes.value.join(',');
    }
    switch (props.field.field_type) {
        case 'image_capture':
            return 'image/*';
        case 'audio_capture':
            return 'audio/*';
        case 'video_capture':
            return 'video/*';
        default:
            return undefined; // file_upload — any type
    }
});

/** `capture` opens the device camera/mic inline on mobile when the author asked for camera capture. */
const captureAttr = computed<'environment' | undefined>(() =>
    props.field.media?.captureSource === 'camera' ? 'environment' : undefined,
);

const previewKind = computed<'image' | 'audio' | 'video' | 'file'>(() => {
    switch (props.field.field_type) {
        case 'image_capture':
            return 'image';
        case 'audio_capture':
            return 'audio';
        case 'video_capture':
            return 'video';
        default:
            return 'file';
    }
});

let uid = 0;
const inputId = `media-input-${(uid += 1)}`;

// ── Local item state (a controlled control needs a local mirror: an item may be uploading, failed-and-retryable,
//    or done; only `done` items contribute their ref to the emitted value). ───────────────────────────────────
type ItemStatus = 'uploading' | 'error' | 'done';

interface MediaItem {
    id: number; // stable local key
    name: string;
    status: ItemStatus;
    progress: number; // 0–100
    error: string | null;
    ref: MediaAttachmentRef | null; // set when done
    previewUrl: string | null; // object URL for a freshly-picked file (revoked on removal/unmount)
    file: File | null; // kept for retry
}

let seq = 0;
const items = ref<MediaItem[]>([]);
const announce = ref<string>('');
const fileInput = ref<HTMLInputElement | null>(null);

const doneCount = computed<number>(() => items.value.filter((i) => i.status === 'done').length);
const atCapacity = computed<boolean>(() => maxCount.value !== null && doneCount.value >= maxCount.value);

// ── Value ⇄ state ───────────────────────────────────────────────────────────────────────────────
function isRefArray(value: unknown): value is MediaAttachmentRef[] {
    return Array.isArray(value) && value.every((v) => v !== null && typeof v === 'object' && typeof (v as { id?: unknown }).id === 'string');
}

const lastEmitted = ref<string | null>(null);

/** Emit the confirmed ref list (empty → null, mirroring the "empty is unanswered" contract). */
function emitValue(): void {
    const refs = items.value.filter((i) => i.status === 'done' && i.ref !== null).map((i) => i.ref as MediaAttachmentRef);
    const value: MediaAttachmentRef[] | null = refs.length === 0 ? null : refs;
    lastEmitted.value = JSON.stringify(value);
    emit('update:modelValue', value);
}

/** Rebuild the item list from an externally-supplied value (mount / draft-restore / version-drift). */
function hydrate(value: AnswerValue): void {
    revokeAll();
    items.value = isRefArray(value)
        ? value.map((ref) => ({
              id: (seq += 1),
              name: ref.name ?? ref.id,
              status: 'done' as const,
              progress: 100,
              error: null,
              ref,
              previewUrl: null,
              file: null,
          }))
        : [];
}

watch(
    () => props.modelValue,
    (value) => {
        // Ignore the echo of our own emit; only hydrate a genuinely external change (draft restore / drift).
        if (JSON.stringify(isRefArray(value) ? value : null) !== lastEmitted.value) {
            hydrate(value);
        }
    },
    { immediate: true },
);

// ── Client-side validation ────────────────────────────────────────────────────────────────────
function mimeAllowed(type: string): boolean {
    if (acceptedTypes.value.length === 0) {
        return true;
    }
    return acceptedTypes.value.some((pattern) => pattern === type || (pattern.endsWith('/*') && type.startsWith(pattern.slice(0, -1))));
}

function rejectReason(file: File): string | null {
    if (!mimeAllowed(file.type)) {
        return `“${file.name}” is not an accepted file type.`;
    }
    if (maxFileSizeBytes.value !== null && file.size > maxFileSizeBytes.value) {
        return `“${file.name}” is larger than the ${formatBytes(maxFileSizeBytes.value)} limit.`;
    }
    return null;
}

function formatBytes(bytes: number): string {
    if (bytes >= 1_048_576) {
        return `${Math.round((bytes / 1_048_576) * 10) / 10} MB`;
    }
    if (bytes >= 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }
    return `${bytes} B`;
}

// ── Upload ──────────────────────────────────────────────────────────────────────────────────────
function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return match ? decodeURIComponent(match[1]) : null;
}

/** POST one file to the staging endpoint via XHR (for upload progress); resolve the returned AttachmentRef. */
function uploadFile(file: File, onProgress: (pct: number) => void): Promise<MediaAttachmentRef> {
    return new Promise((resolve, reject) => {
        const url = props.field.upload?.url;
        if (url === undefined) {
            reject(new Error('Uploads are not configured for this field.'));
            return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', url);
        xhr.responseType = 'json';
        xhr.withCredentials = true;
        xhr.setRequestHeader('Accept', 'application/json');
        // The tenant (web) endpoint is CSRF-guarded; the guest (api) endpoint is not (no XSRF cookie there).
        const xsrf = readCookie('XSRF-TOKEN');
        if (xsrf !== null) {
            xhr.setRequestHeader('X-XSRF-TOKEN', xsrf);
        }

        xhr.upload.onprogress = (event: ProgressEvent): void => {
            if (event.lengthComputable) {
                onProgress(Math.round((event.loaded / event.total) * 100));
            }
        };
        xhr.onload = (): void => {
            const body = xhr.response as { data?: MediaAttachmentRef; error?: { code?: string; message?: string } } | null;
            if (xhr.status >= 200 && xhr.status < 300 && body?.data !== undefined && typeof body.data.id === 'string') {
                resolve(body.data);
            } else {
                reject(new Error(body?.error?.message ?? 'The upload was rejected. Please try a different file.'));
            }
        };
        xhr.onerror = (): void => reject(new Error('Upload failed. Check your connection and retry.'));

        const form = new FormData();
        form.append('file', file);
        form.append('field_key', props.field.key);
        xhr.send(form);
    });
}

function startUpload(item: MediaItem): void {
    if (item.file === null) {
        return;
    }
    item.status = 'uploading';
    item.progress = 0;
    item.error = null;

    uploadFile(item.file, (pct) => {
        item.progress = pct;
    })
        .then((ref) => {
            item.ref = ref;
            item.status = 'done';
            item.progress = 100;
            announce.value = `${item.name} uploaded.`;
            emitValue();
        })
        .catch((error: unknown) => {
            item.status = 'error';
            item.error = error instanceof Error ? error.message : 'Upload failed.';
            announce.value = `${item.name} failed to upload.`;
        });
}

// ── Handlers ────────────────────────────────────────────────────────────────────────────────────
function onSelect(event: Event): void {
    const input = event.target as HTMLInputElement;
    const picked = Array.from(input.files ?? []);
    input.value = ''; // allow re-picking the same file after a removal

    for (const file of picked) {
        if (maxCount.value !== null && doneCount.value + countPending() >= maxCount.value) {
            announce.value = `You can attach at most ${maxCount.value} file${maxCount.value === 1 ? '' : 's'}.`;
            break;
        }

        const reason = rejectReason(file);
        if (reason !== null) {
            items.value.push({ id: (seq += 1), name: file.name, status: 'error', progress: 0, error: reason, ref: null, previewUrl: null, file });
            announce.value = reason;
            continue;
        }

        const item: MediaItem = {
            id: (seq += 1),
            name: file.name,
            status: 'uploading',
            progress: 0,
            error: null,
            ref: null,
            previewUrl: previewKind.value === 'file' ? null : URL.createObjectURL(file),
            file,
        };
        items.value.push(item);
        startUpload(item);
    }
}

function countPending(): number {
    return items.value.filter((i) => i.status === 'uploading').length;
}

function retry(item: MediaItem): void {
    startUpload(item);
}

function remove(item: MediaItem): void {
    if (item.previewUrl !== null) {
        URL.revokeObjectURL(item.previewUrl);
    }
    items.value = items.value.filter((i) => i.id !== item.id);
    announce.value = `${item.name} removed.`;
    emitValue();
}

function revokeAll(): void {
    for (const item of items.value) {
        if (item.previewUrl !== null) {
            URL.revokeObjectURL(item.previewUrl);
        }
    }
}

onBeforeUnmount(revokeAll);
</script>

<template>
    <fieldset class="encode-field media" :aria-describedby="error ? `${inputId}-error` : undefined">
        <legend class="encode-field__legend">
            {{ field.label }}
            <span v-if="showRequiredMarker" class="encode-field__req" aria-hidden="true">*</span>
            <span v-if="showRequiredMarker" class="mds-visually-hidden">(required)</span>
            <span v-if="showOptionalMarker" class="encode-field__opt">(optional)</span>
        </legend>

        <p v-if="field.hint" class="media__hint">{{ field.hint }}</p>

        <label :for="inputId" class="media__picker" :class="{ 'media__picker--disabled': atCapacity }">
            <span class="media__picker-text">{{ isMulti ? 'Choose files' : 'Choose a file' }}</span>
        </label>
        <input
            :id="inputId"
            ref="fileInput"
            class="media__input"
            type="file"
            :accept="acceptAttr"
            :capture="captureAttr"
            :multiple="isMulti"
            :disabled="atCapacity"
            @change="onSelect"
        />

        <ul v-if="items.length > 0" class="media__list">
            <li v-for="item in items" :key="item.id" class="media__item" :class="`media__item--${item.status}`">
                <div v-if="item.previewUrl" class="media__preview">
                    <img v-if="previewKind === 'image'" :src="item.previewUrl" :alt="item.name" class="media__thumb" />
                    <audio v-else-if="previewKind === 'audio'" :src="item.previewUrl" controls class="media__player" />
                    <video v-else-if="previewKind === 'video'" :src="item.previewUrl" controls class="media__player" />
                </div>

                <div class="media__meta">
                    <span class="media__name">{{ item.name }}</span>

                    <progress
                        v-if="item.status === 'uploading'"
                        class="media__progress"
                        :value="item.progress"
                        max="100"
                    >{{ item.progress }}%</progress>

                    <span v-else-if="item.status === 'done'" class="media__status media__status--done">Uploaded</span>
                    <span v-else class="media__status media__status--error">{{ item.error }}</span>
                </div>

                <div class="media__actions">
                    <MdsButton v-if="item.status === 'error' && item.file" variant="tertiary" size="sm" @click="retry(item)">Retry</MdsButton>
                    <button type="button" class="media__remove" @click="remove(item)">
                        <span aria-hidden="true">✕</span>
                        <span class="mds-visually-hidden">Remove {{ item.name }}</span>
                    </button>
                </div>
            </li>
        </ul>

        <p v-if="error" :id="`${inputId}-error`" class="media__error" role="alert">{{ error }}</p>
        <p class="mds-visually-hidden" aria-live="polite">{{ announce }}</p>
    </fieldset>
</template>

<style scoped>
.encode-field.media {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.encode-field__legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-font-size-body);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-strong);
}

.encode-field__req {
    color: var(--mds-color-text-danger);
}

.encode-field__opt {
    margin-inline-start: var(--mds-space-1);
    font-weight: var(--mds-font-weight-regular);
    color: var(--mds-color-text-muted);
}

.media__hint {
    margin: 0;
    font-size: var(--mds-font-size-caption);
    color: var(--mds-color-text-muted);
}

.media__picker {
    display: inline-flex;
    align-items: center;
    align-self: flex-start;
    gap: var(--mds-space-2);
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-border-strong);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-surface-raised);
    color: var(--mds-color-text-strong);
    font-size: var(--mds-font-size-body);
    cursor: pointer;
}

.media__picker:hover {
    background: var(--mds-color-surface-sunken);
}

.media__picker--disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* The native input drives the (label-triggered) picker but stays keyboard-focusable + screen-reader-labelled. */
.media__input {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0, 0, 0, 0);
    white-space: nowrap;
    border: 0;
}

.media__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.media__item {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    padding: var(--mds-space-2);
    border: 1px solid var(--mds-color-border-subtle);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-surface-raised);
}

.media__item--error {
    border-color: var(--mds-color-border-danger);
}

.media__preview {
    flex: 0 0 auto;
}

.media__thumb {
    display: block;
    width: 56px;
    height: 56px;
    object-fit: cover;
    border-radius: var(--mds-radius-sm);
}

.media__player {
    max-width: 220px;
}

.media__meta {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    gap: var(--mds-space-1);
    min-width: 0;
}

.media__name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: var(--mds-font-size-body);
    color: var(--mds-color-text-strong);
}

.media__progress {
    width: 100%;
    height: 6px;
}

.media__status {
    font-size: var(--mds-font-size-caption);
}

.media__status--done {
    color: var(--mds-color-text-success);
}

.media__status--error {
    color: var(--mds-color-text-danger);
}

.media__actions {
    display: flex;
    flex: 0 0 auto;
    align-items: center;
    gap: var(--mds-space-1);
}

.media__remove {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    padding: 0;
    border: 0;
    border-radius: var(--mds-radius-sm);
    background: transparent;
    color: var(--mds-color-text-muted);
    cursor: pointer;
}

.media__remove:hover {
    background: var(--mds-color-surface-sunken);
    color: var(--mds-color-text-danger);
}

.media__error {
    margin: 0;
    font-size: var(--mds-font-size-caption);
    color: var(--mds-color-text-danger);
}
</style>
