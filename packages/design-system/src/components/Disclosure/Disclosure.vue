<script setup lang="ts">
/**
 * A summary line that opens and closes the content below it (M245, `R-93e22765`) — built for a form note's "details"
 * block ("More about this indicator", "Research behind this"), and the shared form of DSR §5's disclosure widget.
 *
 * ── THE PATTERN, AS DSR §5 SPECIFIES IT ─────────────────────────────────────────────────────────────────────
 * Collapsed by default; a real `<button>` carries `aria-expanded` and `aria-controls`, so Enter and Space work with no
 * key handling of ours; a chevron (▸ closed, ▾ open) is the non-text channel, and turns at `--mds-duration-moderate`
 * unless the reader asked for reduced motion. The panel stays in the document while closed (`v-show`), so the id
 * `aria-controls` names always resolves — axe reports a dangling `aria-controls` as incomplete, never as a violation,
 * which is why the unit suite asserts it.
 *
 * ── WHERE IT DIFFERS FROM THE "ADVANCED" TOGGLE ───────────────────────────────────────────────────────────────
 * §5's "Advanced" entry point is deliberately muted (`body-sm`, secondary text). This summary is an author's own words
 * in the body of a form, so it is set at body size in the body colour: a respondent must notice there is more to read.
 *
 * ⛔ PRINTED OPEN. A browser's print of the page shows the panel whatever its state, because paper cannot be opened.
 * The server-side paper form and PDF print a details block open on their own (`PrintedContent`).
 */
import { useId } from 'vue';
import Icon from '../Icon/Icon.vue';

defineProps<{
    /** The always-visible line that names what opening it shows. */
    summary: string;
}>();

const open = defineModel<boolean>('open', { default: false });

const panelId = useId();
</script>

<template>
    <div class="mds-disclosure" :class="{ 'mds-disclosure--open': open }">
        <button
            type="button"
            class="mds-disclosure__toggle"
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="panelId"
            @click="open = !open"
        >
            <Icon name="chevron-right" size="sm" class="mds-disclosure__chevron" aria-hidden="true" />
            <span class="mds-disclosure__summary">{{ summary }}</span>
        </button>
        <div v-show="open" :id="panelId" class="mds-disclosure__panel">
            <slot />
        </div>
    </div>
</template>

<style scoped>
.mds-disclosure {
    border-top: 1px solid var(--mds-color-border-default);
    border-bottom: 1px solid var(--mds-color-border-default);
}

.mds-disclosure__toggle {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-2);
    width: 100%;
    /* A respondent taps this on a phone: a full-width row at least 44px tall. */
    min-height: 2.75rem;
    padding: var(--mds-space-2) 0;
    border: none;
    background: none;
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    font-weight: 600;
    text-align: start;
    cursor: pointer;
}

.mds-disclosure__toggle:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-disclosure__chevron {
    flex-shrink: 0;
    /* Aligned to the first line of the summary. */
    margin-top: 0.2em;
    color: var(--mds-color-text-secondary);
    transition: transform var(--mds-duration-moderate) var(--mds-ease-standard);
}

.mds-disclosure--open .mds-disclosure__chevron {
    transform: rotate(90deg);
}

.mds-disclosure__summary {
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
}

.mds-disclosure__panel {
    padding: 0 0 var(--mds-space-3) calc(1rem + var(--mds-space-2));
}

@media (prefers-reduced-motion: reduce) {
    .mds-disclosure__chevron {
        transition: none;
    }
}

@media print {
    .mds-disclosure__panel {
        display: block !important;
    }

    .mds-disclosure__chevron {
        display: none;
    }
}
</style>
