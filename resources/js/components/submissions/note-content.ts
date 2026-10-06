/**
 * A note's content blocks as a respondent sees them (M130, `R-c9f50df2`) — the pure half of `NoteContent.vue`.
 *
 * The SHAPE is `App\Rules\ContentBlocks` (M125), and the builder's editor writes it (M129). This module only
 * READS it, and reads it defensively, for two reasons that are both measured rather than assumed:
 *   · the builder preview renders a LENIENT draft — a heading with no text, a span list with nothing in it, a
 *     link with no address and an image with no description all pass a save and are refused only at publish;
 *   · `ConvertEmptyStringsToNull` turns an emptied span's text into `null` on the way in, so `null` is a span
 *     text the renderer must draw as nothing rather than as the word "null".
 *
 * ⛔ NO MARKUP SINK, ANYWHERE. Every leaf is text, rendered through Vue's own interpolation; a link is
 * re-checked here at render time by `linkLooksSafe()` — the TypeScript twin `ContentBlocks::linkIsSafe()` is
 * pinned to by `tests/fixtures/content-block-links.json` — and one that fails renders as plain text.
 */
import type { InjectionKey } from 'vue';
import type { ContentBlock, ContentSpan } from '@/components/builder/content-blocks';
import { linkLooksSafe } from '@/components/builder/content-markup';

export type { ContentBlock, ContentSpan };

/**
 * The level of the heading the note sits under. Content headings are stored RELATIVE (level 1 or 2), because
 * the guest page and the encode page title a section with an `h2` while the builder preview uses an `h3`.
 */
export const ContentHeadingBaseKey: InjectionKey<number> = Symbol('content-heading-base');

/**
 * Where a content image is read from. The default is the staff route (`GET /attachments/{id}`, which the encode
 * page, the OCR review and the builder preview may all use since M129); the guest runtime provides its own,
 * token-scoped one.
 */
export const ContentImageUrlKey: InjectionKey<(attachmentId: string) => string> = Symbol('content-image-url');

export function staffContentImageUrl(attachmentId: string): string {
    return `/attachments/${encodeURIComponent(attachmentId)}`;
}

/**
 * How often an image that failed to load is asked for again, and how many times (M137, `R-ddb4fc26`).
 *
 * ⛔ OPT-IN, AND ONLY THE BUILDER PREVIEW OPTS IN. An image just uploaded there answers 409 until its virus check
 * passes, so its first load fails by design and a minute later succeeds; without a retry the preview kept the failure
 * until the page was reloaded. The guest page and the encode page provide nothing and keep "the description stands
 * in": a respondent's page asking an image route again and again would spend requests on an image that, once a form
 * is published, has long been checked.
 */
export interface ContentImageRetry {
    delayMs: number;
    maxAttempts: number;
}

export const ContentImageRetryKey: InjectionKey<ContentImageRetry | null> = Symbol('content-image-retry');

/** The same address asked for afresh: the browser keeps a failed response per URL, so each try needs its own. */
export function withAttempt(url: string, attempt: number): string {
    return attempt === 0 ? url : `${url}${url.includes('?') ? '&' : '?'}attempt=${attempt}`;
}

const TONES = new Set(['info', 'success', 'warning', 'danger']);

/** The HTML heading tag for a block: the context's level plus the block's relative level, never past `h6`. */
export function headingTag(base: number, level: number): string {
    return `h${Math.min(6, Math.max(2, base + (level === 2 ? 2 : 1)))}`;
}

/** A span's text, with the emptied-span `null` drawn as nothing. */
export function spanText(span: ContentSpan): string {
    return typeof span.text === 'string' ? span.text : '';
}

/** The address a span may link to, re-checked now — or null, and the span renders as plain text. */
export function safeLink(span: ContentSpan): string | null {
    return typeof span.link === 'string' && span.link !== '' && linkLooksSafe(span.link) ? span.link : null;
}

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

function spansOf(raw: unknown): ContentSpan[] {
    return Array.isArray(raw) ? raw.filter(isRecord).map((span) => span as unknown as ContentSpan) : [];
}

/**
 * The blocks a renderer can draw, from a note's raw `config.content`. Anything outside the closed shape is
 * skipped rather than guessed at: a published snapshot is data, and a block this renderer does not know is one
 * it must not invent a rendering for.
 */
export function renderableBlocks(raw: unknown): ContentBlock[] {
    if (!Array.isArray(raw)) {
        return [];
    }

    const blocks: ContentBlock[] = [];

    for (const block of raw) {
        if (!isRecord(block)) {
            continue;
        }

        switch (block.type) {
            case 'heading':
                blocks.push({ type: 'heading', level: block.level === 2 ? 2 : 1, text: typeof block.text === 'string' ? block.text : null });
                break;
            case 'paragraph':
                blocks.push({ type: 'paragraph', spans: spansOf(block.spans) });
                break;
            case 'callout':
                blocks.push({
                    type: 'callout',
                    tone: (typeof block.tone === 'string' && TONES.has(block.tone) ? block.tone : 'info') as 'info',
                    spans: spansOf(block.spans),
                });
                break;
            case 'divider':
                blocks.push({ type: 'divider' });
                break;
            case 'image':
                if (typeof block.attachment_id === 'string' && block.attachment_id !== '') {
                    blocks.push({ type: 'image', attachment_id: block.attachment_id, alt: typeof block.alt === 'string' ? block.alt : null });
                }
                break;
        }
    }

    return blocks;
}

/**
 * What a screen reader is told when a question appears through relevance (`FieldRow`, `InstanceField`). A note
 * with content blocks is announced by what it says — its label is the author's alone (`D69`) — and a note whose
 * blocks say nothing is not announced at all; everything else, a label-only note included, as before.
 */
export function announcementFor(fieldType: string, content: unknown, label: string): string | null {
    if (fieldType === 'note') {
        const blocks = renderableBlocks(content);
        if (blocks.length > 0) {
            const summary = contentSummary(blocks);
            return summary === null ? null : `New information: ${summary}`;
        }
    }

    return `New question: ${label}`;
}

/**
 * A note's content in one line for a screen reader: its first text, else its first image's description, else
 * nothing at all — never the author-only label (`D69`), and never an empty "New question: ".
 */
export function contentSummary(blocks: ContentBlock[]): string | null {
    for (const block of blocks) {
        const text =
            block.type === 'heading'
                ? (block.text ?? '')
                : block.type === 'paragraph' || block.type === 'callout'
                  ? block.spans.map(spanText).join('')
                  : '';
        if (text.trim() !== '') {
            return text.trim();
        }
    }

    for (const block of blocks) {
        if (block.type === 'image' && (block.alt ?? '').trim() !== '') {
            return (block.alt ?? '').trim();
        }
    }

    return null;
}
