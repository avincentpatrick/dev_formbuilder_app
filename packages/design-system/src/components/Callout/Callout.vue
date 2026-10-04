<script setup lang="ts">
/**
 * A static, toned note inside a page's content (built M130 for a form's content blocks) — `MdsAlert`'s sibling,
 * and deliberately not `MdsAlert` itself.
 *
 * ── WHY NOT `MdsAlert` ──────────────────────────────────────────────────────────────────────────────────
 * `MdsAlert` is a live region (`role="status"`, or `alert` when assertive) because its subject is an EVENT:
 * something just happened and a screen reader should hear about it. A callout an author placed in a form is
 * none of that — it is part of the page, read when the reader reaches it in order, and a live region would
 * have it announced the moment a question's relevance revealed it, over whatever the respondent was doing.
 * So this carries `role="note"` and no live semantics, closing the gap in the shared system (DSR §7) rather
 * than logging a local exception for a static box styled from the alert's tokens.
 *
 * Same four tones and the same `--mds-color-status-*` pairs as `MdsAlert`, so a tone means one thing across
 * the product; the icon is the non-colour channel (WCAG 1.4.1), and the default glyphs match the alert's.
 * The content is a slot, rendered as given — this component adds no markup sink of its own.
 */
import { computed } from 'vue';
import Icon from '../Icon/Icon.vue';
import type { IconName } from '../Icon/icons';

export type CalloutTone = 'info' | 'success' | 'warning' | 'danger';

const props = withDefaults(
    defineProps<{
        tone?: CalloutTone;
        /** Overrides the tone's glyph. */
        icon?: IconName;
    }>(),
    { tone: 'info' },
);

const TONE_ICONS: Record<CalloutTone, IconName> = {
    info: 'info',
    success: 'check',
    warning: 'alert',
    danger: 'alert',
};

const resolvedIcon = computed<IconName>(() => props.icon ?? TONE_ICONS[props.tone]);
</script>

<template>
    <div class="mds-callout" :class="`mds-callout--${tone}`" role="note">
        <Icon :name="resolvedIcon" size="sm" class="mds-callout__icon" aria-hidden="true" />
        <div class="mds-callout__body"><slot /></div>
    </div>
</template>

<style scoped>
.mds-callout {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.mds-callout__icon {
    /* Aligned to the first line of text, as `MdsAlert` does. */
    margin-top: 0.15em;
    flex-shrink: 0;
}

.mds-callout__body {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
}

.mds-callout--info {
    background-color: var(--mds-color-status-info-bg);
    color: var(--mds-color-status-info-fg);
}

.mds-callout--success {
    background-color: var(--mds-color-status-success-bg);
    color: var(--mds-color-status-success-fg);
}

.mds-callout--warning {
    background-color: var(--mds-color-status-warning-bg);
    color: var(--mds-color-status-warning-fg);
}

.mds-callout--danger {
    background-color: var(--mds-color-status-danger-bg);
    color: var(--mds-color-status-danger-fg);
}
</style>
