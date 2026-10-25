<script setup lang="ts">
/**
 * M187 (`R-af2d278b`) — the Signature control, shared by manual entry and the guest runtime through
 * {@link FieldInput.vue} (lazily, like {@link MediaInput.vue}). Before it, both channels said "Not available for manual
 * entry yet", so a Signature question could be placed on a form that nobody could answer.
 *
 * The person signs with a finger, a pen or the mouse on a 3:1 pad. Once the pen has been up for {@link SETTLE_MS}, the
 * drawing is saved as a PNG on white and uploaded through {@link uploadMediaFile} — the endpoint, CSRF header and guest
 * offline stash a photo uses — and the answer becomes the one reference it returns, as a single photo's would. A stroke
 * begun while a save is in flight supersedes it: only the newest drawing's reference is ever emitted, so the saved
 * signature is the one on the pad. Clear empties the pad and the answer.
 *
 * An answer the pad did not draw itself (a restored draft, a resumed response) shows as "Signed" with Sign again rather
 * than the picture, because a guest has no route to fetch a staged file back. In the builder's Preview there is no
 * upload endpoint, so the pad draws and says the signature is not saved there.
 *
 * Accessibility: a real fieldset and legend, the pad named for what it is, keyboard-operable buttons and a polite status
 * region. Drawing is path-dependent input, which WCAG 2.1.1 exempts; a typed alternative is filed (M187).
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { MdsButton } from '@meridian/design-system';

import type { AnswerValue, EncodeField, MediaAttachmentRef, RequiredMarker } from './FieldInput.vue';
import { uploadMediaFile } from './media-upload';
import { SETTLE_MS, drawStrokes, exportPng, hasInk, toPadPoint, type PadStroke } from './signature-canvas';

const props = defineProps<{
    field: EncodeField;
    modelValue: AnswerValue;
    error?: string;
    requiredMarker?: RequiredMarker;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: AnswerValue] }>();

const marker = computed<RequiredMarker>(() => props.requiredMarker ?? (props.field.required ? 'required' : 'none'));
const showRequiredMarker = computed<boolean>(() => marker.value === 'required');
const showOptionalMarker = computed<boolean>(() => marker.value === 'optional');

// Field keys are unique within a form, and a media question cannot sit in a repeatable section, so the key names the pad.
const padId = `signature-pad-${props.field.key}`;
const canSave = computed<boolean>(() => props.field.upload !== null && props.field.upload !== undefined);

// ── State ───────────────────────────────────────────────────────────────────────────────────────
// `signed`: an answer the pad did not draw (shown as text). The rest describe the drawing on the pad.
type Phase = 'empty' | 'drawing' | 'saving' | 'saved' | 'error' | 'preview' | 'signed';

const canvas = ref<HTMLCanvasElement | null>(null);
const phase = ref<Phase>('empty');
const saveError = ref<string | null>(null);
const announce = ref<string>('');

// Strokes are plain data, redrawn on demand; nothing in the template reads them, so they need not be reactive.
let strokes: PadStroke[] = [];
let activeStroke: PadStroke | null = null;
let activePointer: number | null = null;
let settleTimer: ReturnType<typeof setTimeout> | null = null;
// Bumped by every new stroke and every save, so a save that finishes after the drawing changed is dropped.
let generation = 0;
let observer: ResizeObserver | null = null;

const isBlank = computed<boolean>(() => phase.value === 'empty');

const statusText = computed<string>(() => {
    switch (phase.value) {
        case 'saving':
            return 'Saving…';
        case 'saved':
            return 'Saved';
        case 'error':
            return saveError.value ?? 'Your signature could not be saved.';
        case 'preview':
            return 'Not saved in the Preview';
        default:
            return '';
    }
});

// ── Value ⇄ state ───────────────────────────────────────────────────────────────────────────────
function isRefArray(value: unknown): value is MediaAttachmentRef[] {
    return Array.isArray(value) && value.length > 0 && value.every((v) => v !== null && typeof v === 'object' && typeof (v as { id?: unknown }).id === 'string');
}

const lastEmitted = ref<string | null>(null);

function emitValue(value: MediaAttachmentRef[] | null): void {
    lastEmitted.value = JSON.stringify(value);
    emit('update:modelValue', value);
}

watch(
    () => props.modelValue,
    (value) => {
        const incoming = isRefArray(value) ? value : null;
        // Ignore the echo of our own emit; only a genuinely external change (a restore) resets the pad.
        if (JSON.stringify(incoming) === lastEmitted.value) {
            return;
        }
        lastEmitted.value = JSON.stringify(incoming);
        cancelSettle();
        generation += 1;
        strokes = [];
        phase.value = incoming === null ? 'empty' : 'signed';
        void nextTick(resize);
    },
    { immediate: true },
);

// ── Drawing ─────────────────────────────────────────────────────────────────────────────────────
function redraw(): void {
    const el = canvas.value;
    const ctx = el?.getContext('2d') ?? null;
    if (el === null || ctx === null) {
        return;
    }
    drawStrokes(ctx, strokes, el.width, el.height);
}

/** Match the backing store to the pad's drawn size (sharp on a high-density screen), then redraw the strokes. */
function resize(): void {
    const el = canvas.value;
    if (el === null) {
        return;
    }
    const ratio = typeof window !== 'undefined' && window.devicePixelRatio > 0 ? window.devicePixelRatio : 1;
    el.width = Math.max(1, Math.round(el.clientWidth * ratio));
    el.height = Math.max(1, Math.round(el.clientHeight * ratio));
    redraw();
}

function pointFor(event: PointerEvent): PadStroke[number] {
    const el = canvas.value as HTMLCanvasElement;

    return toPadPoint(event.clientX, event.clientY, el.getBoundingClientRect());
}

function cancelSettle(): void {
    if (settleTimer !== null) {
        clearTimeout(settleTimer);
        settleTimer = null;
    }
}

function onPointerDown(event: PointerEvent): void {
    if (event.pointerType === 'mouse' && event.button !== 0) {
        return;
    }
    event.preventDefault();
    cancelSettle();
    generation += 1; // a save already in flight now describes an older drawing
    canvas.value?.setPointerCapture?.(event.pointerId);
    activePointer = event.pointerId;
    activeStroke = [pointFor(event)];
    strokes.push(activeStroke);
    phase.value = 'drawing';
    redraw();
}

function onPointerMove(event: PointerEvent): void {
    if (activeStroke === null || event.pointerId !== activePointer) {
        return;
    }
    activeStroke.push(pointFor(event));
    redraw();
}

function onPointerUp(event: PointerEvent): void {
    if (activeStroke === null || event.pointerId !== activePointer) {
        return;
    }
    activeStroke = null;
    activePointer = null;
    if (canvas.value?.hasPointerCapture?.(event.pointerId) === true) {
        canvas.value.releasePointerCapture(event.pointerId);
    }
    scheduleSave();
}

/** Save once the pen has rested — never per stroke, which would upload every letter of a name. */
function scheduleSave(): void {
    cancelSettle();
    if (!hasInk(strokes)) {
        return;
    }
    settleTimer = setTimeout(() => {
        settleTimer = null;
        void save();
    }, SETTLE_MS);
}

async function save(): Promise<void> {
    if (!hasInk(strokes)) {
        return;
    }
    if (!canSave.value) {
        phase.value = 'preview';
        announce.value = 'In the Preview, a signature is not saved.';
        return;
    }

    generation += 1;
    const mine = generation;
    phase.value = 'saving';
    saveError.value = null;
    announce.value = 'Saving your signature…';

    try {
        const blob = await exportPng(strokes);
        const file = new File([blob], 'signature.png', { type: 'image/png' });
        const saved = await uploadMediaFile(props.field.upload, props.field.key, file, () => undefined);
        if (mine !== generation) {
            return;
        }
        phase.value = 'saved';
        announce.value = 'Signature saved.';
        emitValue([saved]);
    } catch (error: unknown) {
        if (mine !== generation) {
            return;
        }
        phase.value = 'error';
        saveError.value = error instanceof Error ? error.message : 'Your signature could not be saved.';
        announce.value = 'Your signature could not be saved.';
    }
}

function retry(): void {
    void save();
}

function clear(): void {
    cancelSettle();
    generation += 1;
    strokes = [];
    activeStroke = null;
    activePointer = null;
    phase.value = 'empty';
    saveError.value = null;
    announce.value = 'Signature cleared.';
    redraw();
    if (lastEmitted.value !== JSON.stringify(null)) {
        emitValue(null);
    }
}

function signAgain(): void {
    phase.value = 'empty';
    announce.value = 'Sign on the pad.';
    emitValue(null);
    void nextTick(resize);
}

onMounted(() => {
    resize();
    if (typeof ResizeObserver !== 'undefined' && canvas.value !== null) {
        observer = new ResizeObserver(() => resize());
        observer.observe(canvas.value);
    }
});

onBeforeUnmount(() => {
    cancelSettle();
    observer?.disconnect();
});
</script>

<template>
    <fieldset class="encode-field signature" :aria-describedby="error ? `${padId}-error` : undefined">
        <legend class="encode-field__legend">
            {{ field.label }}
            <span v-if="showRequiredMarker" class="encode-field__req" aria-hidden="true">*</span>
            <span v-if="showRequiredMarker" class="signature__sr">(required)</span>
            <span v-if="showOptionalMarker" class="encode-field__opt">(optional)</span>
        </legend>

        <p v-if="field.hint" class="signature__hint">{{ field.hint }}</p>

        <div v-if="phase === 'signed'" class="signature__signed">
            <span class="signature__status signature__status--done">Signed</span>
            <MdsButton variant="secondary" size="sm" @click="signAgain">Sign again</MdsButton>
        </div>

        <template v-else>
            <div class="signature__surface">
                <canvas
                    :id="padId"
                    ref="canvas"
                    class="signature__pad"
                    role="img"
                    :aria-label="`Signature pad for ${field.label}. Sign with a finger, a pen or the mouse.`"
                    @pointerdown="onPointerDown"
                    @pointermove="onPointerMove"
                    @pointerup="onPointerUp"
                    @pointercancel="onPointerUp"
                />
                <span class="signature__line" aria-hidden="true" />
                <span v-if="isBlank" class="signature__placeholder" aria-hidden="true">Sign here</span>
            </div>

            <div class="signature__actions">
                <MdsButton variant="tertiary" size="sm" :disabled="isBlank" @click="clear">Clear</MdsButton>
                <MdsButton v-if="phase === 'error'" variant="tertiary" size="sm" @click="retry">Retry</MdsButton>
                <span
                    v-if="statusText !== ''"
                    class="signature__status"
                    :class="{ 'signature__status--done': phase === 'saved', 'signature__status--error': phase === 'error' }"
                >
                    {{ statusText }}
                </span>
            </div>
        </template>

        <p v-if="error" :id="`${padId}-error`" class="signature__error" role="alert">{{ error }}</p>
        <p class="signature__sr" aria-live="polite">{{ announce }}</p>
    </fieldset>
</template>

<style scoped>
/* Positioned so the clipped nodes below resolve their containing block HERE (`clipped-node-containment.test.ts`). */
.encode-field.signature {
    position: relative;
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
    font-size: var(--mds-type-body-md-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-heading);
}

.encode-field__req {
    color: var(--mds-color-danger-text);
}

.encode-field__opt {
    margin-inline-start: var(--mds-space-1);
    font-weight: var(--mds-font-weight-regular);
    color: var(--mds-color-text-secondary);
}

.signature__hint {
    margin: 0;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

/* The pad is paper: the strokes are drawn on white whatever the theme, so the saved picture matches what was signed. */
.signature__surface {
    position: relative;
    width: 100%;
    max-width: 36rem;
}

.signature__pad {
    display: block;
    width: 100%;
    aspect-ratio: 3 / 1;
    border: 1px solid var(--mds-color-border-strong);
    border-radius: var(--mds-radius-md);
    cursor: crosshair;
    /* The browser must not scroll or zoom the page while a finger signs. */
    touch-action: none;
}

/* A guide to sign on, like the printed form's line. Drawn over the pad, never into the saved picture. */
.signature__line {
    position: absolute;
    inset-inline: 6%;
    bottom: 25%;
    border-top: 1px dashed var(--mds-color-border-default);
    pointer-events: none;
}

.signature__placeholder {
    position: absolute;
    inset-inline-start: 6%;
    bottom: calc(25% + var(--mds-space-1));
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
    pointer-events: none;
}

.signature__actions,
.signature__signed {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

.signature__status {
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

.signature__status--done {
    color: var(--mds-color-status-success-fg);
}

.signature__status--error {
    color: var(--mds-color-danger-text);
}

.signature__error {
    margin: 0;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-danger-text);
}

/* What a screen reader hears and no one sees: the "(required)" beside the question and the status announcements. */
.signature__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}
</style>
