<script setup lang="ts">
/**
 * A note's content blocks, rendered for whoever fills the form (M130, `R-c9f50df2`): the guest page, the encode
 * page in all its modes, and the builder preview — `FieldInput.vue`'s note branch is the one place it mounts.
 *
 * ⛔ ZERO RAW-HTML SINKS, AND THAT IS THE WHOLE SECURITY ARGUMENT. `PublicRuntimeSecurityHeaders` deliberately
 * sets no `script-src`, so output encoding is the only control on this surface
 * (`docs/piping-output-encoding-design.md` §5). Every leaf below is a text node — interpolated in the
 * template, or passed as a string child to `h()` — and the raw-HTML census under `resources/js/__tests__/`
 * fails the build if any file in the tree grows a sink. A link is re-checked at render, never trusted from
 * storage, and opens in a new tab so neither a respondent mid-form nor the builder preview navigates away.
 *
 * Content is NOT piped: it is not on the template-bearing list (§6), so a `${key}` in a paragraph is text.
 */
import { MdsCallout } from '@meridian/design-system';
import { h, inject, onBeforeUnmount, reactive, type FunctionalComponent, type VNodeChild } from 'vue';
import {
    ContentHeadingBaseKey,
    ContentImageRetryKey,
    ContentImageUrlKey,
    headingTag,
    safeLink,
    spanText,
    staffContentImageUrl,
    withAttempt,
    type ContentBlock,
    type ContentSpan,
} from './note-content';

defineProps<{ blocks: ContentBlock[] }>();

const headingBase = inject(ContentHeadingBaseKey, 2);
const imageUrl = inject(ContentImageUrlKey, staffContentImageUrl);
const retry = inject(ContentImageRetryKey, null);

// An image the server will not serve yet (still being checked, or refused) cannot be known from the payload,
// whose checksum is pinned; the browser's own load error is the signal, and the description stands in for it.
const failedImages = reactive(new Set<string>());

// M137 (`R-ddb4fc26`): where the page asks for it (the builder preview), a failed image is asked for again, each
// time under a fresh address, until it loads or the tries run out. Meanwhile it says it is being checked.
const attempts = reactive(new Map<string, number>());
const waiting = reactive(new Set<string>());
const timers = new Set<number>();

function srcFor(attachmentId: string): string {
    return withAttempt(imageUrl(attachmentId), attempts.get(attachmentId) ?? 0);
}

function onImageError(attachmentId: string): void {
    const tried = attempts.get(attachmentId) ?? 0;
    if (retry === null || tried >= retry.maxAttempts) {
        failedImages.add(attachmentId);
        return;
    }

    waiting.add(attachmentId);
    const timer = window.setTimeout(() => {
        timers.delete(timer);
        attempts.set(attachmentId, tried + 1);
        waiting.delete(attachmentId);
    }, retry.delayMs);
    timers.add(timer);
}

onBeforeUnmount(() => {
    timers.forEach((timer) => window.clearTimeout(timer));
    timers.clear();
});

/** One run of text, wrapped innermost-first: code, then italic, then bold, then the link around all of it. */
const SpanRun: FunctionalComponent<{ span: ContentSpan }> = (props) => {
    let node: VNodeChild = spanText(props.span);
    if (props.span.code === true) node = h('code', { class: 'note-content__code' }, node);
    if (props.span.italic === true) node = h('em', node);
    if (props.span.bold === true) node = h('strong', node);

    const href = safeLink(props.span);
    if (href !== null) {
        node = h('a', { href, target: '_blank', rel: 'noopener noreferrer', class: 'note-content__link' }, [
            node,
            h('span', { class: 'note-content__sr' }, ' (opens in a new tab)'),
        ]);
    }

    return node;
};
SpanRun.props = ['span'];
</script>

<template>
    <div class="note-content" data-note-content>
        <template v-for="(block, index) in blocks" :key="index">
            <!-- A heading with no text (a lenient draft) draws nothing: an empty heading element is an axe failure. -->
            <component
                :is="headingTag(headingBase, block.level)"
                v-if="block.type === 'heading' && (block.text ?? '').trim() !== ''"
                class="note-content__heading"
                :class="block.level === 2 ? 'note-content__heading--minor' : 'note-content__heading--major'"
            >
                {{ block.text ?? '' }}
            </component>
            <p v-else-if="block.type === 'paragraph'" class="note-content__paragraph">
                <SpanRun v-for="(span, s) in block.spans" :key="s" :span="span" />
            </p>
            <MdsCallout v-else-if="block.type === 'callout'" :tone="block.tone" class="note-content__callout">
                <p class="note-content__paragraph">
                    <SpanRun v-for="(span, s) in block.spans" :key="s" :span="span" />
                </p>
            </MdsCallout>
            <hr v-else-if="block.type === 'divider'" class="note-content__divider" />
            <figure v-else-if="block.type === 'image'" class="note-content__figure">
                <p v-if="waiting.has(block.attachment_id)" class="note-content__alt" data-image-checking>
                    This image is still being checked. It appears here once it passes.
                </p>
                <img
                    v-else-if="!failedImages.has(block.attachment_id)"
                    :key="srcFor(block.attachment_id)"
                    class="note-content__image"
                    :src="srcFor(block.attachment_id)"
                    :alt="block.alt ?? ''"
                    loading="lazy"
                    @error="onImageError(block.attachment_id)"
                />
                <p v-else-if="(block.alt ?? '') !== ''" class="note-content__alt">{{ block.alt }}</p>
                <p v-else-if="retry !== null" class="note-content__alt" data-image-gave-up>
                    This image is not ready yet. Reload the page to try again.
                </p>
            </figure>
        </template>
    </div>
</template>

<style scoped>
/* `position: relative` so the visually hidden "(opens in a new tab)" resolves its containing block here. */
.note-content {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.note-content__heading {
    margin: 0;
    font-family: var(--mds-font-family-display);
    color: var(--mds-color-text-heading);
}

.note-content__heading--major {
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
}

.note-content__heading--minor {
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
}

.note-content__paragraph {
    margin: 0;
}

.note-content :deep(.note-content__code) {
    padding: 0 var(--mds-space-1);
    border-radius: var(--mds-radius-sm);
    background-color: var(--mds-color-bg-sunken);
    font-family: var(--mds-font-family-mono);
}

/* Inside a callout the link takes the tone's own foreground, which is paired against its background; the
   underline is the link's non-colour signifier either way.
   ⚠️ `:deep()` on everything `SpanRun` draws: elements made by `h()` in a functional component carry no scope id,
   so a plain scoped selector matched nothing and "(opens in a new tab)" showed (the M130 probe saw it). */
.note-content :deep(.note-content__link) {
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
}

.note-content__callout :deep(.note-content__link) {
    color: inherit;
}

.note-content :deep(.note-content__link:focus-visible) {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.note-content :deep(.note-content__sr) {
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

.note-content__divider {
    width: 100%;
    margin: 0;
    border: 0;
    border-top: 1px solid var(--mds-color-border-default);
}

.note-content__figure {
    margin: 0;
}

.note-content__image {
    display: block;
    max-width: 100%;
    height: auto;
    border-radius: var(--mds-radius-md);
}

.note-content__alt {
    margin: 0;
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px dashed var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    color: var(--mds-color-text-secondary);
}
</style>
