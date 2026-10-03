<script setup lang="ts">
/**
 * The Content tab of a note (M129 — `R-6dedc3a9`'s editor half, with `R-f0c5b682`'s images): headings,
 * paragraphs, callouts, dividers and images, in the order an author arranges them.
 *
 * ── CONTROLLED AND STATELESS, LIKE EVERY OTHER CONFIG EDITOR ──────────────────────────────────────────
 * It takes no store and emits a FRESH block list on every change (`ConfigPanel.vue`'s house contract); the panel
 * writes that list to `config.content`. The shape is `App\Rules\ContentBlocks`'s, and nothing here can emit a key
 * that rule refuses. A refused save is reported by the panel's own alert, above every tab, so it is not repeated
 * here.
 *
 * ── TEXT IS EDITED AS A SMALL SYNTAX, AND STORED AS SPANS ────────────────────────────────────────────
 * A paragraph or callout is a textarea speaking `content-markup.ts`'s syntax — **bold**, __italic__, `code`,
 * [text](link) — with a toolbar that writes it for the author. What is SAVED is the parsed span list, never the
 * syntax. Each textarea keeps the author's own text while they type, so the cursor never jumps; it is re-derived
 * from the spans only when the blocks change from outside it — undo, a reload, or a block moving.
 *
 * ⚠️ A LINK THE SERVER WOULD REFUSE IS NEVER EMITTED. The builder saves a field's whole config in one PATCH, so one
 * unsafe link would refuse the label and every other edit with it. Such a link is kept as plain text and the block
 * says why, asked through `linkLooksSafe()`, which agrees with `ContentBlocks::linkIsSafe()` on a shared fixture.
 *
 * ⚠️ RESPONDENTS DO NOT SEE THIS CONTENT YET, AND THE TAB SAYS SO. The approved Oct 12 plan builds this editor
 * before the renderer (`R-c9f50df2`), the reverse of the order the row's step (f) chose. Until the renderer ships,
 * a respondent sees the note's label; the renderer row removes the notice.
 *
 * ⚠️ THE IMAGE PICKER IS A NATIVE FILE INPUT, because the design system has no file input — the `BrandingCard.vue`
 * and `ocr/Scans.vue` precedent. A real `<input type="file">` keeps the operating system's picker, the keyboard and
 * the phone camera, and `MdsFormField` still gives it its label, help and error region.
 *
 * No `v-html` anywhere: every leaf renders as text.
 */
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { MdsButton, MdsFormField, MdsIconButton, MdsSelect, MdsTextarea, MdsTextInput } from '@meridian/design-system';
import {
    CALLOUT_TONES,
    CONTENT_LIMITS,
    uploadContentImage,
    type CalloutTone,
    type ContentBlock,
    type ContentBlockType,
    type ContentSpan,
} from './content-blocks';
import { linkLooksSafe, normalizeSpans, parseMarkup, serializeSpans } from './content-markup';

const props = defineProps<{
    blocks: ContentBlock[];
    formId: string;
}>();

const emit = defineEmits<{ 'update:blocks': [blocks: ContentBlock[]] }>();

const TYPE_LABELS: Record<ContentBlockType, string> = {
    heading: 'Heading',
    paragraph: 'Paragraph',
    callout: 'Callout',
    divider: 'Divider',
    image: 'Image',
};

const TONE_LABELS: Record<CalloutTone, string> = { info: 'Information', success: 'Success', warning: 'Warning', danger: 'Danger' };

const toneOptions = CALLOUT_TONES.map((tone) => ({ value: tone, label: TONE_LABELS[tone] }));
const levelOptions = [
    { value: '1', label: 'Large heading' },
    { value: '2', label: 'Small heading' },
];

const LINK_NOTE = 'A link must start with https://, http://, mailto: or tel: — until it does, it is saved as plain text.';

const atLimit = computed(() => props.blocks.length >= CONTENT_LIMITS.maxBlocks);

function copyBlocks(): ContentBlock[] {
    return props.blocks.map((block) => ({ ...block })) as ContentBlock[];
}

// ── Text: the per-block cache the header describes ─────────────────────────────────────────────────────
/** What each paragraph's or callout's textarea holds, by block position. */
const drafts = ref<Record<number, string>>({});
/** Blocks whose typed text held a link that was kept as plain text, by block position. */
const linkNotes = ref<Record<number, boolean>>({});

/** Parse what the author typed, demoting every link the server would refuse to plain text. */
function spansFrom(text: string): { spans: ContentSpan[]; demoted: boolean } {
    let demoted = false;
    const spans = parseMarkup(text).map((span) => {
        if (span.link === undefined || linkLooksSafe(span.link)) {
            return span;
        }
        demoted = true;
        const { link: _refused, ...rest } = span;
        return rest;
    });

    return { spans: normalizeSpans(spans), demoted };
}

function sameSpans(a: ContentSpan[], b: ContentSpan[]): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}

watch(
    () => props.blocks,
    (blocks) => {
        const next: Record<number, string> = {};
        blocks.forEach((block, index) => {
            if (block.type !== 'paragraph' && block.type !== 'callout') {
                return;
            }
            const typed = drafts.value[index];
            next[index] = typed !== undefined && sameSpans(spansFrom(typed).spans, block.spans) ? typed : serializeSpans(block.spans);
        });
        drafts.value = next;
    },
    { immediate: true, deep: true },
);

/** Forget every textarea's typed text, so each is re-derived from its block: positions are about to change. */
function forgetDrafts(): void {
    drafts.value = {};
    linkNotes.value = {};
}

// ── Emitting ───────────────────────────────────────────────────────────────────────────────────────────
function replaceBlock(index: number, block: ContentBlock): void {
    const next = copyBlocks();
    next[index] = block;
    emit('update:blocks', next);
}

function onText(index: number, value: string): void {
    const block = props.blocks[index];
    if (block === undefined || (block.type !== 'paragraph' && block.type !== 'callout')) {
        return;
    }
    drafts.value = { ...drafts.value, [index]: value };
    const { spans, demoted } = spansFrom(value);
    linkNotes.value = { ...linkNotes.value, [index]: demoted };
    replaceBlock(index, { ...block, spans });
}

function onHeadingText(index: number, value: string): void {
    const block = props.blocks[index];
    if (block?.type === 'heading') {
        replaceBlock(index, { ...block, text: value === '' ? null : value });
    }
}

function onLevel(index: number, value: string): void {
    const block = props.blocks[index];
    if (block?.type === 'heading') {
        replaceBlock(index, { ...block, level: value === '1' ? 1 : 2 });
    }
}

function onTone(index: number, value: string): void {
    const block = props.blocks[index];
    const tone = CALLOUT_TONES.find((candidate) => candidate === value);
    if (block?.type === 'callout' && tone !== undefined) {
        replaceBlock(index, { ...block, tone });
    }
}

function onAlt(index: number, value: string): void {
    const block = props.blocks[index];
    if (block?.type === 'image') {
        replaceBlock(index, { ...block, alt: value === '' ? null : value });
    }
}

function add(type: Exclude<ContentBlockType, 'image'>): void {
    if (atLimit.value) {
        return;
    }
    const block: ContentBlock =
        type === 'heading'
            ? { type: 'heading', level: 1, text: null }
            : type === 'callout'
              ? { type: 'callout', tone: 'info', spans: [] }
              : type === 'divider'
                ? { type: 'divider' }
                : { type: 'paragraph', spans: [] };
    emit('update:blocks', [...copyBlocks(), block]);
}

function move(index: number, offset: -1 | 1): void {
    const target = index + offset;
    if (target < 0 || target >= props.blocks.length) {
        return;
    }
    const next = copyBlocks();
    [next[index], next[target]] = [next[target], next[index]];
    forgetDrafts();
    emit('update:blocks', next);
}

function remove(index: number): void {
    forgetDrafts();
    emit('update:blocks', copyBlocks().filter((_, i) => i !== index));
}

// ── The formatting toolbar ─────────────────────────────────────────────────────────────────────────────
// `MdsTextarea` renders the native element as its root, so a component ref's `$el` is the textarea itself.
const textareas = new Map<number, HTMLTextAreaElement>();

function bindTextarea(index: number, component: unknown): void {
    const element = (component as { $el?: unknown } | null)?.$el;
    if (element instanceof HTMLTextAreaElement) {
        textareas.set(index, element);
    } else {
        textareas.delete(index);
    }
}

/** Wrap the selection in the syntax for `kind`, then select what the author most likely types next. */
async function format(index: number, kind: 'bold' | 'italic' | 'link'): Promise<void> {
    const element = textareas.get(index);
    const current = drafts.value[index] ?? '';
    const start = element?.selectionStart ?? current.length;
    const end = element?.selectionEnd ?? current.length;
    const selected = current.slice(start, end) || (kind === 'link' ? 'link text' : 'text');

    const [before, after] = kind === 'bold' ? ['**', '**'] : kind === 'italic' ? ['__', '__'] : ['[', '](https://)'];
    onText(index, current.slice(0, start) + before + selected + after + current.slice(end));

    await nextTick();
    if (element === undefined) {
        return;
    }
    element.focus();
    if (kind === 'link') {
        // The address, so typing replaces the placeholder `https://`.
        const address = start + before.length + selected.length + 2;
        element.setSelectionRange(address, address + 'https://'.length);
    } else {
        element.setSelectionRange(start + before.length, start + before.length + selected.length);
    }
}

// ── Images ─────────────────────────────────────────────────────────────────────────────────────────────
// An image whose virus check has not finished answers 409, so it is shown as a sentence and asked for again a few
// times, rather than as a broken image. After the last try it says what to do instead.
const RETRY_MS = 3000;
const MAX_RETRIES = 10;

const uploading = ref(false);
const uploadError = ref<string | null>(null);
const retries = ref<Record<string, number>>({});
const waiting = ref<Set<string>>(new Set());
const timers = new Set<number>();

function imageSrc(id: string): string {
    const attempt = retries.value[id] ?? 0;
    return attempt === 0 ? `/attachments/${id}` : `/attachments/${id}?attempt=${attempt}`;
}

function wait(id: string): void {
    waiting.value = new Set(waiting.value).add(id);
    const attempt = retries.value[id] ?? 0;
    if (attempt >= MAX_RETRIES) {
        return;
    }
    const timer = window.setTimeout(() => {
        timers.delete(timer);
        retries.value = { ...retries.value, [id]: attempt + 1 };
        const next = new Set(waiting.value);
        next.delete(id);
        waiting.value = next;
    }, RETRY_MS);
    timers.add(timer);
}

function gaveUp(id: string): boolean {
    return (retries.value[id] ?? 0) >= MAX_RETRIES;
}

onBeforeUnmount(() => {
    timers.forEach((timer) => window.clearTimeout(timer));
    timers.clear();
});

async function onImageChosen(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file === undefined || uploading.value || atLimit.value) {
        return;
    }

    uploading.value = true;
    uploadError.value = null;
    try {
        const image = await uploadContentImage(props.formId, file);
        if (!image.servable) {
            wait(image.id);
        }
        emit('update:blocks', [...copyBlocks(), { type: 'image', attachment_id: image.id, alt: null }]);
    } catch (thrown) {
        uploadError.value = thrown instanceof Error ? thrown.message : 'The image was not accepted. Please try another file.';
    } finally {
        uploading.value = false;
    }
}

function blockLabel(index: number, block: ContentBlock): string {
    return `Block ${index + 1}: ${TYPE_LABELS[block.type]}`;
}
</script>

<template>
    <div class="cbe">
        <!-- Two facts an author needs before composing anything, so both are there from first paint. -->
        <p class="cbe__notice" data-content-notice="not-shown-yet">
            Respondents do not see this content yet — until they do, they see the note's label.
        </p>
        <p class="cbe__hint">Shown in the form's default language only.</p>

        <p v-if="blocks.length === 0" class="cbe__hint" data-content-empty>This note has no content yet. Add a block below.</p>

        <ol v-else class="cbe__list">
            <li v-for="(block, index) in blocks" :key="index" class="cbe__block" :data-block-type="block.type">
                <div class="cbe__block-head">
                    <p :id="`cbe-block-${index}`" class="cbe__block-label">{{ blockLabel(index, block) }}</p>
                    <div class="cbe__actions" role="group" :aria-labelledby="`cbe-block-${index}`">
                        <MdsIconButton
                            icon="chevron-up"
                            :label="`Move block ${index + 1} up`"
                            size="sm"
                            :disabled="index === 0"
                            @click="move(index, -1)"
                        />
                        <MdsIconButton
                            icon="chevron-down"
                            :label="`Move block ${index + 1} down`"
                            size="sm"
                            :disabled="index === blocks.length - 1"
                            @click="move(index, 1)"
                        />
                        <MdsIconButton
                            icon="trash"
                            :label="`Remove block ${index + 1}`"
                            variant="danger"
                            size="sm"
                            @click="remove(index)"
                        />
                    </div>
                </div>

                <template v-if="block.type === 'heading'">
                    <MdsFormField v-slot="{ id }" label="Size">
                        <MdsSelect :id="id" :model-value="String(block.level)" :options="levelOptions" @update:model-value="onLevel(index, $event)" />
                    </MdsFormField>
                    <MdsFormField v-slot="{ id, describedby, invalid }" label="Heading text">
                        <MdsTextInput
                            :id="id"
                            :model-value="block.text ?? ''"
                            :maxlength="CONTENT_LIMITS.maxHeadingLength"
                            :describedby="describedby"
                            :invalid="invalid"
                            @update:model-value="onHeadingText(index, $event)"
                        />
                    </MdsFormField>
                </template>

                <template v-else-if="block.type === 'paragraph' || block.type === 'callout'">
                    <MdsFormField v-if="block.type === 'callout'" v-slot="{ id }" label="Kind of callout">
                        <MdsSelect :id="id" :model-value="block.tone" :options="toneOptions" @update:model-value="onTone(index, $event)" />
                    </MdsFormField>
                    <div class="cbe__actions" role="group" :aria-label="`Format block ${index + 1}`">
                        <MdsButton size="sm" variant="secondary" @click="format(index, 'bold')">Bold</MdsButton>
                        <MdsButton size="sm" variant="secondary" @click="format(index, 'italic')">Italic</MdsButton>
                        <MdsButton size="sm" variant="secondary" @click="format(index, 'link')">Link</MdsButton>
                    </div>
                    <MdsFormField
                        v-slot="{ id, describedby, invalid }"
                        label="Text"
                        help="**bold**, __italic__ and [link text](https://…) — or select text and use the buttons above."
                        :error="linkNotes[index] ? LINK_NOTE : undefined"
                    >
                        <MdsTextarea
                            :id="id"
                            :ref="(component: unknown) => bindTextarea(index, component)"
                            :model-value="drafts[index] ?? ''"
                            :rows="4"
                            :describedby="describedby"
                            :invalid="invalid"
                            @update:model-value="onText(index, $event)"
                        />
                    </MdsFormField>
                </template>

                <p v-else-if="block.type === 'divider'" class="cbe__hint">A line across the page.</p>

                <template v-else-if="block.type === 'image'">
                    <p v-if="waiting.has(block.attachment_id) && gaveUp(block.attachment_id)" class="cbe__hint">
                        This image cannot be shown yet. Remove it and upload it again if it does not appear after a reload.
                    </p>
                    <p v-else-if="waiting.has(block.attachment_id)" class="cbe__hint" role="status">
                        Checking this file — it will appear here shortly.
                    </p>
                    <img
                        v-else
                        class="cbe__image"
                        :src="imageSrc(block.attachment_id)"
                        :alt="block.alt ?? ''"
                        @error="wait(block.attachment_id)"
                    />
                    <MdsFormField
                        v-slot="{ id, describedby, invalid }"
                        label="Description"
                        help="What the image shows, for people who cannot see it. The form cannot be published without one."
                    >
                        <MdsTextInput
                            :id="id"
                            :model-value="block.alt ?? ''"
                            :maxlength="CONTENT_LIMITS.maxAltLength"
                            :describedby="describedby"
                            :invalid="invalid"
                            @update:model-value="onAlt(index, $event)"
                        />
                    </MdsFormField>
                </template>
            </li>
        </ol>

        <div class="cbe__actions" role="group" aria-label="Add a block">
            <MdsButton size="sm" variant="secondary" icon-left="plus" :disabled="atLimit" @click="add('heading')">Heading</MdsButton>
            <MdsButton size="sm" variant="secondary" icon-left="plus" :disabled="atLimit" @click="add('paragraph')">Paragraph</MdsButton>
            <MdsButton size="sm" variant="secondary" icon-left="plus" :disabled="atLimit" @click="add('callout')">Callout</MdsButton>
            <MdsButton size="sm" variant="secondary" icon-left="plus" :disabled="atLimit" @click="add('divider')">Divider</MdsButton>
        </div>

        <MdsFormField
            v-slot="{ id, describedby, invalid }"
            label="Add an image"
            help="PNG, JPEG or WebP, up to 2 MB. It is added at the end."
            :error="uploadError ?? undefined"
        >
            <input
                :id="id"
                class="cbe__file"
                type="file"
                accept="image/png,image/jpeg,image/webp"
                :disabled="atLimit || uploading"
                :aria-describedby="describedby"
                :aria-invalid="invalid || undefined"
                @change="onImageChosen"
            />
        </MdsFormField>
        <p v-if="uploading" class="cbe__hint" role="status">Uploading the image…</p>
        <p v-if="atLimit" class="cbe__hint">A note can hold at most {{ CONTENT_LIMITS.maxBlocks }} blocks.</p>
    </div>
</template>

<style scoped>
.cbe {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.cbe__notice {
    margin: 0;
    padding: var(--mds-space-2) var(--mds-space-3);
    border-left: 4px solid var(--mds-color-status-info-fg);
    background-color: var(--mds-color-bg-surface);
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-sm-font-size);
}

.cbe__hint {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.cbe__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    margin: 0;
    padding: 0;
    list-style: none;
}

.cbe__block {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.cbe__block-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2);
}

.cbe__block-label {
    margin: 0;
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-type-label-font-weight);
}

.cbe__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

.cbe__image {
    display: block;
    max-width: 100%;
    max-height: 240px;
    object-fit: contain;
    border-radius: var(--mds-radius-md);
}

/* The native control, kept native — see the docblock. Only its box is themed, so it reads as a field. */
.cbe__file {
    max-width: 100%;
    padding: var(--mds-space-2);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-bg-surface);
    color: var(--mds-color-text-body);
}

.cbe__file:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}
</style>
