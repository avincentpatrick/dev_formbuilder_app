<script setup lang="ts">
/**
 * MdsMenu (DSR §3.4b, J4b) — the anchored action menu, extracted from the account menu that had been
 * carrying this behaviour alone in the application tree.
 *
 * ⚠️ IT IS AN EXTRACTION, NOT AN INVENTION, AND THAT DISTINCTION IS WHY IT IS ALLOWED TO EXIST WITH ONE
 * ADOPTED CONSUMER. The exceptions log refuses to generalise the command palette from a single example,
 * and the rule is the same here — the difference is that this behaviour was already written, already
 * correct in its keyboard model, and already duplicated in spirit by two more surfaces. What the move
 * buys is not reuse but SCRUTINY: an app-tree component gets no story and therefore no accessibility
 * scan, which is precisely how the defect below survived.
 *
 * ⚠️ A MENU OWNS ONLY MENUITEMS, AND THE VERSION THIS REPLACED DID NOT. The account menu put a bare
 * name/email block INSIDE role="menu", whose children must all be menuitem/-radio/-checkbox or
 * group. So the header was not merely misplaced: a screen reader in application mode walks menuitems and
 * never announces a stray div, which means "who am I signed in as" was unreachable to the readers most
 * likely to need it. Here the panel is a plain box, the header is its first child, and role="menu" is a
 * NESTED element containing nothing else — with the header wired as the menu's description, so entering
 * the menu announces it. Nothing moves visually.
 *
 * ⚠️ IT DOES NOT TELEPORT, AND THE REASON IS MEASURED RATHER THAN ASSUMED. Walk the ancestors of the
 * panel in the shell: nothing sets `overflow` until the app shell's `overflow-x: clip`, and `clip` paired
 * with `visible` does NOT drag the other axis to `auto` — that coercion is specified for `hidden`,
 * `scroll` and `auto`. So there is no vertical clipping and no scroll container, unlike the sidebar rail
 * where MdsTooltip must teleport. But `overflow-x: clip` has a sting: an over-wide panel is CLIPPED
 * rather than caught, because the document-level overflow assertion the e2e suite relies on reads a
 * width the clip pins flat. The panel is therefore clamped in viewport units and its header wraps —
 * see the stylesheet, where a test holds both.
 *
 * ⚠️ SO THIS COMPONENT MUST NOT BE MOUNTED INSIDE A SCROLL CONTAINER — not a modal body, not the data
 * table's scroll region, not the collapsed rail. Those need the teleport-plus-exemption construction
 * MdsTooltip carries, and a consumer that needs one should add it there rather than making every menu
 * in the product float.
 */
import { nextTick, onBeforeUnmount, onMounted, ref, useId, type Component } from 'vue';
import MdsIcon from '../Icon/Icon.vue';
import type { IconName } from '../Icon/icons';

export interface MenuItem {
    /** Stable key, and what `select` emits for an item that is not a link. */
    id: string;
    label: string;
    icon?: IconName;
    /** Present ⇒ rendered through `linkComponent`. Absent ⇒ a real button element. */
    href?: string;
    /**
     * Renders `aria-disabled`, never the native attribute. A natively disabled control is not focusable
     * and fires no pointer events, so it drops out of the arrow-key ring and any explanation attached to
     * it becomes unreachable — the rule DSR §3.4a states in full.
     */
    disabled?: boolean;
    tone?: 'default' | 'danger';
}

const props = withDefaults(
    defineProps<{
        items: MenuItem[];
        /** The trigger's accessible name. It is icon- or avatar-only by construction, so this is required. */
        triggerLabel: string;
        /** The menu's own accessible name. */
        label: string;
        /** Which edge the panel aligns to. `end` is right-aligned — the top-nav case. */
        align?: 'start' | 'end';
        /**
         * What a LINK item renders as. Defaults to the string 'a' so a router-less Storybook renders.
         * ⚠️ An Inertia application passes its own Link, and the injected component must receive `href`
         * as a real PROP: bound as a fall-through attribute the anchor looks correct and silently stops
         * being a client visit, which is the lesson MdsBreadcrumb's suite pins.
         */
        linkComponent?: Component | string;
        /** Seeds the open state. The story and axe seam ONLY — a closed menu is an empty subtree to scan. */
        defaultOpen?: boolean;
    }>(),
    { align: 'end', linkComponent: 'a', defaultOpen: false },
);

const emit = defineEmits<{
    /** A non-link item was activated. The menu has already closed and returned focus. */
    select: [id: string];
    'update:open': [value: boolean];
}>();

defineSlots<{
    /** The trigger's CONTENT. The button element and all of its ARIA belong to this component. */
    trigger(): unknown;
    /** Non-menuitem content — a name/email header. Rendered OUTSIDE role="menu". */
    header?(): unknown;
}>();

const headerId = useId();
const open = ref(props.defaultOpen);
const rootEl = ref<HTMLElement | null>(null);
const triggerEl = ref<HTMLButtonElement | null>(null);
const menuEl = ref<HTMLElement | null>(null);

function itemEls(): HTMLElement[] {
    return menuEl.value === null
        ? []
        : Array.from(menuEl.value.querySelectorAll<HTMLElement>('[role="menuitem"]'));
}

function setOpen(next: boolean): void {
    open.value = next;
    emit('update:open', next);
}

async function toggle(): Promise<void> {
    setOpen(!open.value);
    if (open.value) {
        await nextTick();
        itemEls()[0]?.focus();
    }
}

function close(returnFocus: boolean): void {
    if (!open.value) return;
    setOpen(false);
    if (returnFocus) triggerEl.value?.focus();
}

/**
 * ⚠️ A DISABLED ITEM IS NEVER RENDERED AS A LINK, AND `preventDefault` IS NOT ENOUGH ON ITS OWN.
 * The injected link component brings its own click handler, and Vue merges a fall-through handler
 * AFTER the component's own — so an Inertia visit has already been issued by the time this
 * component's guard runs, and the row navigates while announcing itself unavailable. Rendering a
 * plain button instead removes the navigation rather than trying to out-run it.
 */
function isLink(item: MenuItem): boolean {
    return item.href !== undefined && item.disabled !== true;
}

function onActivate(item: MenuItem, event: Event): void {
    if (item.disabled === true) {
        // aria-disabled is advisory to the browser, so the click still arrives and must be refused here.
        event.preventDefault();
        return;
    }
    close(true);
    if (item.href === undefined) emit('select', item.id);
}

function onMenuKeydown(event: KeyboardEvent): void {
    const list = itemEls();
    if (list.length === 0) return;
    const i = list.indexOf(document.activeElement as HTMLElement);

    if (event.key === 'ArrowDown') {
        event.preventDefault();
        list[(i + 1) % list.length]?.focus();
    } else if (event.key === 'ArrowUp') {
        event.preventDefault();
        list[(i - 1 + list.length) % list.length]?.focus();
    } else if (event.key === 'Home') {
        event.preventDefault();
        list[0]?.focus();
    } else if (event.key === 'End') {
        event.preventDefault();
        list[list.length - 1]?.focus();
    } else if (event.key === 'Tab') {
        // No preventDefault: menu semantics say the tab sequence continues into the page behind it.
        close(false);
    }
}

// Dismissal is reimplemented here rather than imported. The application's own composable does exactly
// this, but `packages/` imports nothing from the application tree, and MdsTooltip's Escape has different
// semantics again (capture phase, no focus return) — so a shared abstraction would have to be
// parameterised on both axes from two examples. Extract one when a third consumer exists.

/**
 * ⚠️ ESCAPE IS BOUND ON THIS COMPONENT'S OWN ROOT, NOT ON `document`, AND THE DIFFERENCE IS THE WHOLE
 * BEHAVIOUR. The application's composable — and the account menu this replaced — used a document-level
 * bubble-phase listener, which is the LAST thing to see a keydown. MdsModal listens on its own panel,
 * an ancestor of any menu opened inside a dialog, so it would consume the Escape first: the user loses
 * the entire dialog when they meant to close a two-item popup, and the `stopPropagation` written to
 * prevent exactly that never runs. Binding on the root puts this handler first, because the event
 * starts at the focused menu item inside it.
 *
 * This is safe only because focus is always inside the menu while it is open — opening moves focus to
 * the first item, Tab closes it, and an outside pointer closes it. MdsTooltip cannot make that
 * assumption (it never takes focus at all), which is why it uses a document CAPTURE listener instead:
 * capture descends from the document, so it is first rather than last. Same goal, opposite mechanism,
 * and picking the wrong one of the two is invisible until something wraps a dialog around it.
 */
function onRootKeydown(event: KeyboardEvent): void {
    if (event.key !== 'Escape' || !open.value) return;
    event.stopPropagation();
    close(true);
}

function onDocumentPointerDown(event: Event): void {
    if (!open.value) return;
    const target = event.target as Node | null;
    // The trigger lives inside root and toggles itself; anything else outside closes WITHOUT stealing
    // focus, so the click lands where the user aimed it.
    if (target !== null && rootEl.value?.contains(target) === true) return;
    close(false);
}

onMounted(() => {
    // Capture phase, so a page that stops pointer propagation cannot strand the menu open.
    document.addEventListener('pointerdown', onDocumentPointerDown, true);
});

onBeforeUnmount(() => {
    document.removeEventListener('pointerdown', onDocumentPointerDown, true);
});
</script>

<template>
    <div ref="rootEl" class="mds-menu" @keydown="onRootKeydown">
        <button
            ref="triggerEl"
            type="button"
            class="mds-menu__trigger"
            :aria-label="triggerLabel"
            aria-haspopup="menu"
            :aria-expanded="open"
            @click="toggle"
        >
            <slot name="trigger" />
        </button>

        <div v-if="open" class="mds-menu__panel" :class="`mds-menu__panel--${align}`">
            <!-- OUTSIDE role="menu" — a menu's children must all be menuitems. See the docblock. -->
            <div v-if="$slots.header" :id="headerId" class="mds-menu__header">
                <slot name="header" />
            </div>

            <div
                ref="menuEl"
                class="mds-menu__list"
                role="menu"
                :aria-label="label"
                :aria-describedby="$slots.header ? headerId : undefined"
                @keydown="onMenuKeydown"
            >
                <component
                    :is="isLink(item) ? linkComponent : 'button'"
                    v-for="item in items"
                    :key="item.id"
                    :href="isLink(item) ? item.href : undefined"
                    :type="isLink(item) ? undefined : 'button'"
                    role="menuitem"
                    tabindex="-1"
                    class="mds-menu__item"
                    :class="{ 'mds-menu__item--danger': item.tone === 'danger' }"
                    :aria-disabled="item.disabled === true ? 'true' : undefined"
                    @click="onActivate(item, $event)"
                >
                    <MdsIcon v-if="item.icon" :name="item.icon" size="sm" />
                    <span>{{ item.label }}</span>
                </component>
            </div>
        </div>
    </div>
</template>

<style scoped>
.mds-menu {
    position: relative;
}

.mds-menu__trigger {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    min-height: 40px;
    padding: 0 var(--mds-space-2);
    border: none;
    border-radius: var(--mds-radius-md);
    background-color: transparent;
    color: var(--mds-color-text-secondary);
    cursor: pointer;
}

.mds-menu__trigger:hover {
    background-color: var(--mds-color-bg-sunken);
}

.mds-menu__trigger:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-menu__panel {
    position: absolute;
    top: calc(100% + var(--mds-space-1));
    z-index: var(--mds-z-index-menu, 30);
    min-width: 220px;
    /* ⚠️ THE CLAMP IS THE ONLY THING STANDING BETWEEN A LONG EMAIL AND A SILENTLY UNREACHABLE PANEL. The
       app shell sets `overflow-x: clip`, which pins the document width flat — so a panel widened past the
       viewport by content is clipped rather than caught by the e2e overflow assertion. Content can widen
       it: the header carries a user's email, and the text-size axis scales that by up to a quarter. */
    max-width: calc(100vw - var(--mds-space-6));
    padding: var(--mds-space-1);
    background-color: var(--mds-color-bg-surface-raised);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    box-shadow: var(--mds-shadow-3);
}

.mds-menu__panel--end {
    right: 0;
}

.mds-menu__panel--start {
    left: 0;
}

.mds-menu__header {
    padding: var(--mds-space-2) var(--mds-space-3);
    border-bottom: 1px solid var(--mds-color-border-default);
    margin-bottom: var(--mds-space-1);
    overflow-wrap: anywhere;
}

.mds-menu__item {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    width: 100%;
    min-height: 40px;
    padding: 0 var(--mds-space-3);
    border: none;
    border-radius: var(--mds-radius-sm);
    background-color: transparent;
    color: var(--mds-color-text-body);
    font: inherit;
    font-size: var(--mds-type-body-md-font-size);
    text-align: left;
    text-decoration: none;
    cursor: pointer;
}

.mds-menu__item--danger {
    color: var(--mds-color-status-danger-fg);
}

.mds-menu__item:hover,
.mds-menu__item:focus-visible {
    background-color: var(--mds-color-bg-sunken);
}

.mds-menu__item:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: -2px;
}

.mds-menu__item[aria-disabled='true'] {
    color: var(--mds-color-text-secondary);
    cursor: default;
}
</style>
