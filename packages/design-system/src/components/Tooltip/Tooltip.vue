<script setup lang="ts">
/**
 * MdsTooltip (DSR §3.4a, J4b) — the hover/focus label the collapsed sidebar rail has required since
 * §3.4 and §6 were written, and which J4a could not build.
 *
 * ⚠️ IT TELEPORTS, AND THAT IS A CORRECTNESS REQUIREMENT RATHER THAN A CONVENIENCE. Its required
 * consumer lives inside a container that sets `overflow-y: auto`, and per CSS Overflow 3 a box with
 * one axis `auto` and the other `visible` computes the visible axis to `auto` as well. So an in-flow
 * bubble in the 64px rail is clipped AND silently adds an internal scrollbar — one the document-level
 * overflow assertion in the e2e suite cannot see, because the shell clips its own horizontal axis.
 *
 * ⚠️ AND THE EXEMPTION ATTRIBUTE BELONGS ON THE DIRECT BODY CHILD, WITH NOTHING BETWEEN IT AND THE
 * TELEPORT. The inert stack walks ancestor siblings, so an exempt node nested inside a node it has
 * already marked inherits `inert` and the attribute does nothing — silently. Without it, a tooltip on
 * a control inside an open dialog fails three ways at once: dropped from the accessibility tree so
 * its description resolves to nothing, given `pointer-events: none` so the hoverable clause becomes
 * structurally impossible, and made invisible to the one user who is inside the dialog. Do not wrap
 * this in a Transition — besides re-nesting the attribute, the test utils stub a Transition as a real
 * element, so the tree under test would differ from the browser's by a level.
 *
 * ⚠️ ESCAPE IS BOUND IN THE CAPTURE PHASE, AND THE ORDERING IS THE FEATURE. MdsModal listens for
 * Escape on its panel; a bubble-phase listener here would run after it, so an Escape aimed at the
 * tooltip would close the dialog underneath it. Capture plus stopPropagation makes the tooltip
 * consume the first Escape and the dialog receive the second, which is the sequence 1.4.13 asks for.
 * Focus never moves — dismissing a description must not relocate the reader.
 *
 * The contract is DSR §3.4a's, decided before the component existed: dismissible, hoverable,
 * persistent, and `aria-describedby` — NEVER the accessible name.
 */
import { computed, nextTick, onBeforeUnmount, ref, useId, watch } from 'vue';
import {
    anchorOffscreen,
    placeTooltip,
    type Placed,
    type TooltipPlacement,
} from './position';

const props = withDefaults(
    defineProps<{
        /** The tooltip's text. A plain string — a tooltip is a description, never a container. */
        text: string;
        /** Preferred side. Flips to the opposite only when that side is genuinely better. */
        placement?: TooltipPlacement;
        /**
         * Teleport the bubble to `<body>` (default). `false` renders it in place, which is the seam
         * the axe stories need: the story runner scans the story root, and a teleported bubble is
         * outside it. Same seam and same reason as MdsModal and MdsToastHost.
         *
         * ⚠️ In the application this must stay true — see the clipping note in the file docblock.
         */
        teleport?: boolean;
        /** `block` makes the anchor fill its container (a full-width rail item) rather than shrink-wrap. */
        block?: boolean;
        /**
         * Seeds the visible state. The story and axe seam ONLY: a tooltip nothing is hovering is an
         * empty subtree, and scanning one is the vacuous green this repo has shipped before.
         */
        defaultVisible?: boolean;
        /**
         * Turn the tooltip off while keeping the trigger exactly where it is.
         *
         * ⚠️ THIS EXISTS BECAUSE A TOOLTIP IS OFTEN WANTED AT ONE BREAKPOINT AND NOISE AT THE OTHERS, AND
         * THE ALTERNATIVE IS WORSE. The consumer that needs it — the sidebar — shows full labels above
         * 1024px and inside its mobile drawer, and only hides them in the 64px rail between. Wrapping the
         * trigger conditionally would mean spelling the same link twice, so the wrapper stays and the
         * behaviour is what switches. A disabled tooltip also drops `aria-describedby`, so it never
         * describes an element with something the reader cannot summon.
         */
        disabled?: boolean;
    }>(),
    { placement: 'top', teleport: true, block: false, defaultVisible: false, disabled: false },
);

defineSlots<{
    /**
     * The trigger. Bind `trigger` onto the REAL focusable element:
     *
     *     <template #default="{ trigger }"><button v-bind="trigger">…</button></template>
     *
     * ⚠️ A SCOPED SLOT IS THE ONLY CONSTRUCTION THAT PUTS `aria-describedby` WHERE IT COUNTS. The
     * anchor wrapper is not focusable, so an attribute on it is never announced; Vue cannot add one
     * to slot content; and reaching into the child from JS breaks the moment the call site wraps it.
     * Making the binding explicit also puts the contract in front of whoever writes the call site.
     */
    default(props: { trigger: { 'aria-describedby': string | undefined } }): unknown;
}>();

/** Grace period on DISENGAGEMENT — not an auto-hide. See the guard on `scheduleHide`. */
const HIDE_GRACE_MS = 120;

const id = useId();
const anchorEl = ref<HTMLElement | null>(null);
const bubbleEl = ref<HTMLElement | null>(null);
const visible = ref(props.defaultVisible);
const placed = ref<Placed>({ left: 0, top: 0, placement: props.placement });

// Escape must not be undone by the pointer that is still resting on the trigger, or the dismissal is
// a no-op the user watches fail. Cleared when the pointer or focus actually leaves.
const dismissed = ref(false);

let hideTimer: ReturnType<typeof setTimeout> | null = null;

function cancelHide(): void {
    if (hideTimer !== null) {
        clearTimeout(hideTimer);
        hideTimer = null;
    }
}

function reposition(): void {
    const anchor = anchorEl.value;
    const bubble = bubbleEl.value;
    if (anchor === null || bubble === null) return;

    const rect = anchor.getBoundingClientRect();
    const viewport = { width: window.innerWidth, height: window.innerHeight };

    if (anchorOffscreen(rect, viewport)) {
        hideNow();
        return;
    }

    const box = bubble.getBoundingClientRect();
    placed.value = placeTooltip(rect, { width: box.width, height: box.height }, viewport, props.placement);
}

function show(): void {
    if (dismissed.value || props.disabled) return;
    cancelHide();
    visible.value = true;
    // The bubble must exist and have been measured before it can be placed.
    void nextTick(reposition);
}

function hideNow(): void {
    cancelHide();
    visible.value = false;
}

function scheduleHide(): void {
    cancelHide();
    hideTimer = setTimeout(hideNow, HIDE_GRACE_MS);
}

function onPointerEnter(event: PointerEvent): void {
    // ⚠️ TOUCH HAS NO HOVER, so a pointerenter there is the first half of a tap. Showing a bubble that
    // a navigation immediately tears down is worse than showing nothing, and DSR §3.4a forbids a
    // tooltip being the sole carrier on touch precisely because this event is not a hover.
    if (event.pointerType === 'touch') return;
    show();
}

function onPointerLeave(): void {
    dismissed.value = false;
    scheduleHide();
}

function onFocusOut(event: FocusEvent): void {
    const next = event.relatedTarget as Node | null;
    if (next !== null && anchorEl.value?.contains(next) === true) return;
    dismissed.value = false;
    hideNow();
}

function onDocumentKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Escape' || !visible.value) return;
    event.stopPropagation();
    dismissed.value = true;
    hideNow();
}

function onViewportChange(): void {
    reposition();
}

// Listeners exist only while something is on screen to move.
//
// ⚠️ `scroll` IS CAPTURED, AND IT HAS TO BE: scroll does not bubble from an element, and both the
// sidebar and the shell's content region are their own scroll containers. A bubble-phase document
// listener would miss every scroll that actually moves this tooltip's anchor.
watch(() => props.disabled, (isDisabled) => {
    if (isDisabled) hideNow();
});

watch(visible, (isVisible) => {
    if (isVisible) {
        document.addEventListener('keydown', onDocumentKeydown, true);
        document.addEventListener('scroll', onViewportChange, { capture: true, passive: true });
        window.addEventListener('resize', onViewportChange);
    } else {
        document.removeEventListener('keydown', onDocumentKeydown, true);
        document.removeEventListener('scroll', onViewportChange, true);
        window.removeEventListener('resize', onViewportChange);
    }
}, { immediate: true });

onBeforeUnmount(() => {
    cancelHide();
    document.removeEventListener('keydown', onDocumentKeydown, true);
    document.removeEventListener('scroll', onViewportChange, true);
    window.removeEventListener('resize', onViewportChange);
});

const describedBy = computed(() => (visible.value ? id : undefined));
</script>

<template>
    <span
        ref="anchorEl"
        class="mds-tooltip-anchor"
        :class="{ 'mds-tooltip-anchor--block': block }"
        @pointerenter="onPointerEnter"
        @pointerleave="onPointerLeave"
        @focusin="show"
        @focusout="onFocusOut"
    >
        <slot :trigger="{ 'aria-describedby': describedBy }" />
    </span>

    <Teleport to="body" :disabled="!teleport">
        <div
            v-if="visible"
            :id="id"
            ref="bubbleEl"
            class="mds-tooltip"
            role="tooltip"
            :data-placement="placed.placement"
            :style="{ left: `${placed.left}px`, top: `${placed.top}px` }"
            data-mds-inert-exempt
            @pointerenter="cancelHide"
            @pointerleave="scheduleHide"
        >{{ text }}</div>
    </Teleport>
</template>

<style scoped>
.mds-tooltip-anchor {
    display: inline-flex;
}

.mds-tooltip-anchor--block {
    display: block;
}

.mds-tooltip {
    position: fixed;
    /* ⚠️ THE FALLBACK IS LOAD-BEARING AND THERE IS NO GATE BEHIND IT. The token ships from a build
       step whose output is gitignored, so a developer running the dev server without having built
       tokens gets an invalid declaration, `z-index` computes to `auto`, and the bubble paints UNDER
       an open dialog. CI always builds tokens first, which is exactly why CI could never catch it. */
    z-index: var(--mds-z-index-tooltip, 1050);
    max-width: 240px;
    padding: var(--mds-space-1) var(--mds-space-2);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface-raised);
    color: var(--mds-color-text-body);
    box-shadow: var(--mds-shadow-3);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    overflow-wrap: anywhere;
}

/* THE BRIDGE. A transparent extension of the bubble's hit area across the gap to the trigger, so a
   pointer travelling between them never leaves both — WCAG 1.4.13's hoverable clause. Its width is
   the offset constant in position.ts, and a test holds the two equal. */
.mds-tooltip::before {
    content: '';
    position: absolute;
}

.mds-tooltip[data-placement='right']::before {
    left: -8px;
    top: 0;
    bottom: 0;
    width: 8px;
}

.mds-tooltip[data-placement='left']::before {
    right: -8px;
    top: 0;
    bottom: 0;
    width: 8px;
}

.mds-tooltip[data-placement='top']::before {
    bottom: -8px;
    left: 0;
    right: 0;
    height: 8px;
}

.mds-tooltip[data-placement='bottom']::before {
    top: -8px;
    left: 0;
    right: 0;
    height: 8px;
}
</style>
