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
 * ⚠️ THE TAB SAYS WHAT RESPONDENTS SEE. Until M130 it said they saw none of this (the editor shipped a day before the
 * renderer); since `R-c9f50df2` they see these blocks IN PLACE of the note's label (`D69`), and the notice says that,
 * so an author knows the label is now theirs alone.
 *
 * ⚠️ THE IMAGE PICKER IS A NATIVE FILE INPUT, because the design system has no file input — the `BrandingCard.vue`
 * and `ocr/Scans.vue` precedent. A real `<input type="file">` keeps the operating system's picker, the keyboard and
 * the phone camera, and `MdsFormField` still gives it its label, help and error region.
 *
 * No `v-html` anywhere: every leaf renders as text.
 *
 * ── M245: LISTS, DETAILS AND GLOSSARY WORDS ──────────────────────────────────────────────────────────────
 * A list is one textarea, one item to a line, speaking the same syntax. A details block is a summary line over THIS
 * editor again, `nested`: one level down, without a Details button of its own (the shape allows one level), its
 * blocks counted against the note's one limit of `maxBlocks`. A glossary word is `[[word]]`, written by the "Glossary
 * word" button; a word whose term the form's glossary lacks is named under its block, but never stops a save — publish
 * is where it is refused, so an author may mark first and define later.
 */
import { computed, inject, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { MdsButton, MdsFormField, MdsIconButton, MdsSelect, MdsTextarea, MdsTextInput } from '@meridian/design-system';
import {
    CALLOUT_TONES,
    CONTENT_LIMITS,
    countBlocks,
    LIST_STYLES,
    uploadContentImage,
    type CalloutTone,
    type ContentBlock,
    type ContentBlockType,
    type ContentInnerBlock,
    type ContentListItem,
    type ContentListStyle,
    type ContentSpan,
} from './content-blocks';
import { linkLooksSafe, normalizeSpans, parseMarkup, serializeSpans } from './content-markup';
import { findEntry, type GlossaryEntry } from './glossary';
import { ContentGlossaryKey } from '@/components/submissions/note-content';

const props = withDefaults(
    defineProps<{
        blocks: ContentBlock[];
        formId: string;
        /** Inside a details block (M245): no notices, no Details button, and the parent's limit. */
        nested?: boolean;
        /** Prefixes every id and block number, so a nested editor's controls stay unique and say where they are. */
        idPrefix?: string;
        numberPrefix?: string;
        /** A nested editor's limit is the whole note's, which its parent counts. */
        atCapacity?: boolean;
    }>(),
    { nested: false, idPrefix: '', numberPrefix: '', atCapacity: false },
);

const emit = defineEmits<{ 'update:blocks': [blocks: ContentBlock[]] }>();

const TYPE_LABELS: Record<ContentBlockType, string> = {
    heading: 'Heading',
    paragraph: 'Paragraph',
    callout: 'Callout',
    divider: 'Divider',
    image: 'Image',
    list: 'List',
    details: 'Details',
};

const STYLE_LABELS: Record<ContentListStyle, string> = { bulleted: 'Bulleted list', numbered: 'Numbered list' };
const styleOptions = LIST_STYLES.map((style) => ({ value: style, label: STYLE_LABELS[style] }));

const TONE_LABELS: Record<CalloutTone, string> = { info: 'Information', success: 'Success', warning: 'Warning', danger: 'Danger' };

const toneOptions = CALLOUT_TONES.map((tone) => ({ value: tone, label: TONE_LABELS[tone] }));
const levelOptions = [
    { value: '1', label: 'Large heading' },
    { value: '2', label: 'Small heading' },
];

const LINK_NOTE = 'A link must start with https://, http://, mailto: or tel: — until it does, it is saved as plain text.';

const atLimit = computed(() => (props.nested ? props.atCapacity : countBlocks(props.blocks) >= CONTENT_LIMITS.maxBlocks));

const glossary = inject(
    ContentGlossaryKey,
    computed<readonly GlossaryEntry[]>(() => []),
);

/** The block's marked words whose term the form's glossary does not have yet, each once. */
function missingTerms(block: ContentBlock): string[] {
    const runs = block.type === 'paragraph' || block.type === 'callout' ? [block.spans] : block.type === 'list' ? block.items.map((item) => item.spans) : [];
    const missing: string[] = [];
    for (const span of runs.flat()) {
        if (typeof span.term === 'string' && findEntry(glossary.value, span.term) === null && !missing.includes(span.term)) {
            missing.push(span.term);
        }
    }
    return missing;
}

function copyBlocks(): ContentBlock[] {
    return props.blocks.map((block) => ({ ...block })) as ContentBlock[];
}

// ── Text: the per-block cache the header describes ─────────────────────────────────────────────────────
/** What each paragraph's, callout's or list's textarea holds, by block position. */
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

function sameSpans(a: unknown, b: unknown): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}

/** A list's textarea: one item to a line, blank lines skipped, so pressing Enter for the next item changes nothing yet. */
function itemsFrom(text: string): { items: ContentListItem[]; demoted: boolean } {
    let demoted = false;
    const items = text
        .split('\n')
        .filter((line) => line.trim() !== '')
        .map((line) => {
            const parsed = spansFrom(line);
            demoted = demoted || parsed.demoted;
            return { spans: parsed.spans };
        });
    return { items, demoted };
}

function serializeItems(items: ContentListItem[]): string {
    return items.map((item) => serializeSpans(item.spans)).join('\n');
}

watch(
    () => props.blocks,
    (blocks) => {
        const next: Record<number, string> = {};
        blocks.forEach((block, index) => {
            const typed = drafts.value[index];
            if (block.type === 'paragraph' || block.type === 'callout') {
                next[index] = typed !== undefined && sameSpans(spansFrom(typed).spans, block.spans) ? typed : serializeSpans(block.spans);
            } else if (block.type === 'list') {
                next[index] = typed !== undefined && sameSpans(itemsFrom(typed).items, block.items) ? typed : serializeItems(block.items);
            }
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
    if (block?.type === 'list') {
        drafts.value = { ...drafts.value, [index]: value };
        const { items, demoted } = itemsFrom(value);
        linkNotes.value = { ...linkNotes.value, [index]: demoted };
        replaceBlock(index, { ...block, items });
        return;
    }
    if (block === undefined || (block.type !== 'paragraph' && block.type !== 'callout')) {
        return;
    }
    drafts.value = { ...drafts.value, [index]: value };
    const { spans, demoted } = spansFrom(value);
    linkNotes.value = { ...linkNotes.value, [index]: demoted };
    replaceBlock(index, { ...block, spans });
}

function onStyle(index: number, value: string): void {
    const block = props.blocks[index];
    const style = LIST_STYLES.find((candidate) => candidate === value);
    if (block?.type === 'list' && style !== undefined) {
        replaceBlock(index, { ...block, style });
    }
}

function onSummary(index: number, value: string): void {
    const block = props.blocks[index];
    if (block?.type === 'details') {
        replaceBlock(index, { ...block, summary: value === '' ? null : value });
    }
}

/** A details block's own blocks changed, in the nested editor below it. */
function onInner(index: number, inner: ContentBlock[]): void {
    const block = props.blocks[index];
    if (block?.type === 'details') {
        replaceBlock(index, { ...block, blocks: inner.filter((child): child is ContentInnerBlock => child.type !== 'details') });
    }
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
    if (atLimit.value || (type === 'details' && props.nested)) {
        return;
    }
    const block: ContentBlock =
        type === 'heading'
            ? { type: 'heading', level: 1, text: null }
            : type === 'callout'
              ? { type: 'callout', tone: 'info', spans: [] }
              : type === 'divider'
                ? { type: 'divider' }
                : type === 'list'
                  ? { type: 'list', style: 'bulleted', items: [] }
                  : type === 'details'
                    ? { type: 'details', summary: null, blocks: [] }
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
async function format(index: number, kind: 'bold' | 'italic' | 'link' | 'term'): Promise<void> {
    const element = textareas.get(index);
    const current = drafts.value[index] ?? '';
    const start = element?.selectionStart ?? current.length;
    const end = element?.selectionEnd ?? current.length;
    const selected = current.slice(start, end) || (kind === 'link' ? 'link text' : kind === 'term' ? 'word' : 'text');

    const [before, after] =
        kind === 'bold' ? ['**', '**'] : kind === 'italic' ? ['__', '__'] : kind === 'term' ? ['[[', ']]'] : ['[', '](https://)'];
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

/** A block's number as an author reads it: "3", or "3.2" inside the third block's details. */
function blockNumber(index: number): string {
    return `${props.numberPrefix}${index + 1}`;
}

function blockLabel(index: number, block: ContentBlock): string {
    return `Block ${blockNumber(index)}: ${TYPE_LABELS[block.type]}`;
}
</script>

<template>
    <div class="cbe">
        <!-- Two facts an author needs before composing anything, so both are there from first paint. -->
        <template v-if="!nested">
            <p class="cbe__notice" data-content-notice="replaces-label">
                Respondents see this content in place of the note's label.
            </p>
            <p class="cbe__hint">Shown in the form's default language only.</p>
        </template>

        <p v-if="blocks.length === 0 && !nested" class="cbe__hint" data-content-empty>This note has no content yet. Add a block below.</p>
        <p v-else-if="blocks.length === 0" class="cbe__hint" data-content-empty>Nothing inside yet. Add a block below.</p>

        <ol v-else class="cbe__list">
            <li v-for="(block, index) in blocks" :key="index" class="cbe__block" :data-block-type="block.type">
                <div class="cbe__block-head">
                    <p :id="`${idPrefix}cbe-block-${index}`" class="cbe__block-label">{{ blockLabel(index, block) }}</p>
                    <div class="cbe__actions" role="group" :aria-labelledby="`${idPrefix}cbe-block-${index}`">
                        <MdsIconButton
                            icon="chevron-up"
                            :label="`Move block ${blockNumber(index)} up`"
                            size="sm"
                            :disabled="index === 0"
                            @click="move(index, -1)"
                        />
                        <MdsIconButton
                            icon="chevron-down"
                            :label="`Move block ${blockNumber(index)} down`"
                            size="sm"
                            :disabled="index === blocks.length - 1"
                            @click="move(index, 1)"
                        />
                        <MdsIconButton
                            icon="trash"
                            :label="`Remove block ${blockNumber(index)}`"
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

                <template v-else-if="block.type === 'paragraph' || block.type === 'callout' || block.type === 'list'">
                    <MdsFormField v-if="block.type === 'callout'" v-slot="{ id }" label="Kind of callout">
                        <MdsSelect :id="id" :model-value="block.tone" :options="toneOptions" @update:model-value="onTone(index, $event)" />
                    </MdsFormField>
                    <MdsFormField v-if="block.type === 'list'" v-slot="{ id }" label="Style">
                        <MdsSelect :id="id" :model-value="block.style" :options="styleOptions" @update:model-value="onStyle(index, $event)" />
                    </MdsFormField>
                    <div class="cbe__actions" role="group" :aria-label="`Format block ${blockNumber(index)}`">
                        <MdsButton size="sm" variant="secondary" @click="format(index, 'bold')">Bold</MdsButton>
                        <MdsButton size="sm" variant="secondary" @click="format(index, 'italic')">Italic</MdsButton>
                        <MdsButton size="sm" variant="secondary" @click="format(index, 'link')">Link</MdsButton>
                        <MdsButton size="sm" variant="secondary" @click="format(index, 'term')">Glossary word</MdsButton>
                    </div>
                    <MdsFormField
                        v-slot="{ id, describedby, invalid }"
                        :label="block.type === 'list' ? 'Items' : 'Text'"
                        :help="
                            block.type === 'list'
                                ? 'One item to a line. **bold**, __italic__, [link text](https://…) and [[glossary word]] work in each.'
                                : '**bold**, __italic__, [link text](https://…) and [[glossary word]] — or select text and use the buttons above.'
                        "
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
                    <p v-if="missingTerms(block).length > 0" class="cbe__hint" data-glossary-missing>
                        Not in this form's glossary yet: {{ missingTerms(block).join(', ') }}. Add
                        {{ missingTerms(block).length === 1 ? 'it' : 'them' }} under Form settings → Glossary before you
                        publish.
                    </p>
                </template>

                <template v-else-if="block.type === 'details'">
                    <MdsFormField
                        v-slot="{ id, describedby, invalid }"
                        label="Summary"
                        help="Always shown. Respondents open it to read what is inside; paper and PDF print it open."
                    >
                        <MdsTextInput
                            :id="id"
                            :model-value="block.summary ?? ''"
                            :maxlength="CONTENT_LIMITS.maxHeadingLength"
                            :describedby="describedby"
                            :invalid="invalid"
                            @update:model-value="onSummary(index, $event)"
                        />
                    </MdsFormField>
                    <ContentBlocksEditor
                        :blocks="block.blocks"
                        :form-id="formId"
                        nested
                        :id-prefix="`${idPrefix}d${index}-`"
                        :number-prefix="`${blockNumber(index)}.`"
                        :at-capacity="atLimit"
                        class="cbe__nested"
                        @update:blocks="onInner(index, $event)"
                    />
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

        <!-- A nested editor's group is "Add inside block N", never "Add a block": the outer group is found by that name. -->
        <div class="cbe__actions" role="group" :aria-label="nested ? `Add inside block ${numberPrefix.replace(/\.$/, '')}` : 'Add a block'">
            <MdsButton size="sm" variant="secondary" icon-left="plus" :disabled="atLimit" @click="add('heading')">Heading</MdsButton>
            <MdsButton size="sm" variant="secondary" icon-left="plus" :disabled="atLimit" @click="add('paragraph')">Paragraph</MdsButton>
            <MdsButton size="sm" variant="secondary" icon-left="plus" :disabled="atLimit" @click="add('callout')">Callout</MdsButton>
            <MdsButton size="sm" variant="secondary" icon-left="plus" :disabled="atLimit" @click="add('divider')">Divider</MdsButton>
            <MdsButton size="sm" variant="secondary" icon-left="list" :disabled="atLimit" @click="add('list')">List</MdsButton>
            <MdsButton v-if="!nested" size="sm" variant="secondary" icon-left="plus" :disabled="atLimit" @click="add('details')">Details</MdsButton>
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
        <p v-if="atLimit && !nested" class="cbe__hint">
            A note can hold at most {{ CONTENT_LIMITS.maxBlocks }} blocks, counting those inside a details block.
        </p>
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

/* A details block's own blocks, set in from the summary so their place reads at a glance. */
.cbe__nested {
    padding-inline-start: var(--mds-space-3);
    border-inline-start: 2px solid var(--mds-color-border-default);
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
