<script setup lang="ts">
/**
 * The in-flow contextual message (DSR §3.7a, Increment J4a) — `MdsBanner`'s SIBLING, not its replacement.
 *
 * ── THE BOUNDARY, AS FOUR QUESTIONS, EACH ANSWERED BY A PROP THAT EXISTS OR DOES NOT ────────────────────
 * Banner states a CONDITION the page is currently in. Alert carries a MESSAGE about something that
 * happened. The split is enforced by the two APIs rather than by prose, which is why a reader can pick
 * correctly without having read this paragraph:
 *
 *   Can it be said in one line?   Banner's whole API is `message: string`. Alert has a default slot.
 *   Already true, or just now?    Banner is fixed-polite. Alert may opt into `assertive`.
 *   May the user dismiss it?      Banner cannot — hiding a live condition hides a fact. Alert can.
 *   Is it good news?              Banner has no success tone; a "condition" of success is a contradiction.
 *
 * ⚠️ POSITION IS NOT PART OF THE BOUNDARY, and assuming it is would be the easy mistake. `MdsBanner` is
 * already mounted INSIDE a card by `SsoStatusCard`, correctly — it is a standing condition that happens to
 * live in a panel. What separates the two is the nature of the message, never where it sits.
 *
 * ⚠️ AND NOTHING HERE WEAKENS BANNER'S `role="status"` ARGUMENT. That component is deliberately unable to
 * be assertive, because an impersonation notice announced before every page heading forever is hostile.
 * Alert can be assertive precisely because its subject is an event, which is over.
 *
 * ── THE TITLE IS A PARAGRAPH, NEVER A HEADING ───────────────────────────────────────────────────────────
 * An alert dropped into an arbitrary page cannot know its own heading level, and a wrong one breaks §4.1's
 * heading-order rule that `MdsFilterBar` already carries a contract about. It is also unnecessary: both
 * `alert` and `status` imply `aria-atomic="true"`, so the title and body are announced as a single unit
 * and a heading inside a live region buys nothing.
 *
 * ── THE COMPONENT NEVER HIDES ITSELF ────────────────────────────────────────────────────────────────────
 * `dismissible` renders the control and `dismiss` is emitted; the `v-if` stays at the call site. Whether a
 * dismissal should survive a reload, a navigation, or the rest of the session is a page decision every
 * time — `MdsToast` draws the same line.
 *
 * ── WARNING AND DANGER SHARE THE `alert` GLYPH, WHICH NARROWS SOMETHING AND SAYS SO ─────────────────────
 * `MdsBanner` makes `icon` required precisely so the author chooses. Here it is defaulted, because eleven
 * call sites passing the same value is noise. The consequence, stated rather than buried: on those two
 * tones the non-colour channel is the WORDS, which is what §3.8/§4.1 actually require — but a page
 * rendering a warning and a danger alert side by side should pass distinct icons. Minting a fifth glyph
 * was rejected: `icons.ts` refuses additions by rule.
 */
import { computed } from 'vue';
import Icon from '../Icon/Icon.vue';
import type { IconName } from '../Icon/icons';

export type AlertTone = 'info' | 'success' | 'warning' | 'danger';

const props = withDefaults(
    defineProps<{
        tone?: AlertTone;
        /** A bold first line. Omit for a single-paragraph alert. */
        title?: string;
        /** A one-line body. Rich bodies use the default slot instead; both may be present. */
        message?: string;
        /** Overrides the tone's glyph. Pass one when two alerts of different tones share a screen. */
        icon?: IconName;
        /**
         * `role="alert"` instead of `role="status"`. ONLY for something that just happened — an assertive
         * region interrupts whatever the user is reading, which is wrong for anything already true when
         * the page loaded.
         */
        assertive?: boolean;
        /** Renders a dismiss control. Never put one on a condition that is still true. */
        dismissible?: boolean;
        dismissLabel?: string;
    }>(),
    { tone: 'info', assertive: false, dismissible: false, dismissLabel: 'Dismiss' },
);

defineEmits<{ dismiss: [] }>();

const TONE_ICONS: Record<AlertTone, IconName> = {
    info: 'info',
    success: 'check',
    warning: 'alert',
    danger: 'alert',
};

const resolvedIcon = computed<IconName>(() => props.icon ?? TONE_ICONS[props.tone]);
</script>

<template>
    <div class="mds-alert" :class="`mds-alert--${tone}`" :role="assertive ? 'alert' : 'status'">
        <Icon :name="resolvedIcon" size="sm" class="mds-alert__icon" aria-hidden="true" />
        <div class="mds-alert__body">
            <p v-if="title" class="mds-alert__title">{{ title }}</p>
            <p v-if="message" class="mds-alert__message">{{ message }}</p>
            <slot />
            <div v-if="$slots.actions" class="mds-alert__actions"><slot name="actions" /></div>
        </div>
        <button
            v-if="dismissible"
            type="button"
            class="mds-alert__dismiss"
            :aria-label="dismissLabel"
            @click="$emit('dismiss')"
        >
            <Icon name="close" size="sm" />
        </button>
    </div>
</template>

<style scoped>
.mds-alert {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.mds-alert__icon {
    /* Optically aligned to the first line of text rather than to the box, so a three-line alert does not
       centre its glyph halfway down the paragraph. */
    margin-top: 0.1em;
    flex-shrink: 0;
}

.mds-alert__body {
    flex: 1;
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.mds-alert__title {
    font-weight: var(--mds-font-weight-semibold);
}

.mds-alert__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-1);
}

.mds-alert--info {
    background-color: var(--mds-color-status-info-bg);
    color: var(--mds-color-status-info-fg);
}

.mds-alert--success {
    background-color: var(--mds-color-status-success-bg);
    color: var(--mds-color-status-success-fg);
}

.mds-alert--warning {
    background-color: var(--mds-color-status-warning-bg);
    color: var(--mds-color-status-warning-fg);
}

.mds-alert--danger {
    background-color: var(--mds-color-status-danger-bg);
    color: var(--mds-color-status-danger-fg);
}

.mds-alert__dismiss {
    position: relative;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 0;
    border-radius: var(--mds-radius-sm);
    background: transparent;
    /* Inherits the tone's foreground: a neutral X on a tinted field is the one element that would not
       re-colour with the alert it belongs to. */
    color: inherit;
    cursor: pointer;
}

/* WCAG 2.5.8 — the visual box is 24px, so the hit area is expanded rather than the glyph inflated
   (§4.4). `MdsToast`'s dismiss uses the identical construction. */
.mds-alert__dismiss::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    min-width: 44px;
    min-height: 44px;
    width: 100%;
    height: 100%;
    transform: translate(-50%, -50%);
}

.mds-alert__dismiss:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}
</style>
