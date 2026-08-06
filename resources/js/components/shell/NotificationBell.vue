<script setup lang="ts">
/**
 * The notification centre (Increment I4, PRD Feature #13b) — the bell in the top nav and the popover it
 * opens. POPOVER-ONLY: there is deliberately no /notifications page, because every row deep-links to the
 * thing itself and a full-page list would be a second surface with no second job.
 *
 * Feed, poll, refetch-on-navigate and the optimistic writes all live in `useNotificationFeed`; this file is
 * markup, accessibility and tokens. Dismissal is the shared `useDismissable`, as it was before I4.
 *
 * ── Every word on a row comes from the server ───────────────────────────────────────────────────────────
 * `title`, `description`, `action_label` and `url` are all resolved in `NotificationPresenter`, including
 * whether the destination is reachable at all — a target that was deleted, or that this reader may not
 * open, arrives with `url: null` and renders as plain text rather than as a link to a bare 403 page. This
 * file owns only the glyph and the colour band (`type-visual.ts`), which is the split
 * `NotificationType::label()`'s docblock asks for.
 */
import { computed, nextTick, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import { MdsButton, MdsEmptyState, MdsIcon, MdsIconButton, MdsSpinner } from '@meridian/design-system';
import { useDismissable } from '@/composables/useDismissable';
import { useNotificationFeed } from '@/composables/useNotificationFeed';
import { notificationVisual } from '@/components/notifications/type-visual';
import { absoluteTime, relativeTime } from '@/components/notifications/relative-time';

const { items, unreadCount, hasLoaded, stale, wake, markRead, markAllRead } = useNotificationFeed();

const open = ref(false);
const rootEl = ref<HTMLElement | null>(null);
const triggerEl = ref<HTMLButtonElement | null>(null);
const popoverEl = ref<HTMLElement | null>(null);
useDismissable({ isOpen: open, rootEl, triggerEl });

/**
 * CAPPED AT 9+, not 99+, and the cap is a layout decision as much as a semantic one — three glyphs force
 * the bubble into an ellipse wider than the 40px trigger, which under `[data-font-size="extra_large"]`
 * (§2.9, ~+25%) collides with the account menu one `--mds-space-1` to its right. A bell badge is a "there
 * is something" signal, not a metric: the exact number is in the accessible name, and the list is one
 * click away.
 */
const badgeText = computed(() => (unreadCount.value > 9 ? '9+' : String(unreadCount.value)));

/**
 * The count is IN THE NAME, not only in the bubble (WCAG 1.4.1) — a visual-only badge is the same defect
 * as a colour-only status. And it is the REAL number, never the capped "9+".
 */
const triggerLabel = computed(() =>
    unreadCount.value === 0 ? 'Notifications' : `Notifications, ${unreadCount.value} unread`,
);

async function toggle(): Promise<void> {
    open.value = !open.value;

    if (!open.value) return;

    // Opening the bell is a sign of a human: refetch, and give the idle poll another full window.
    wake();
    await nextTick();
    // Focus the DIALOG, not the first control — which is "Mark all as read". Focusing a bulk action on
    // open is a footgun; AccountMenu can focus its first item because that item is "Settings". The
    // dialog's accessible name is announced either way, and one Tab reaches the button.
    popoverEl.value?.focus();
}

/**
 * Marking a row read removes its button from the DOM, which browsers report as a `focusout` with a null
 * `relatedTarget` — i.e. the popover would close under the user mid-action. Suppress across that tick and
 * put focus back on the dialog.
 */
let suppressClose = false;

/**
 * `focusout` rather than AccountMenu's "Tab closes": that is ARIA `menu` behaviour, and a dialog holding
 * fifteen rows must be tabbable. Tabbing past the last control genuinely leaves the subtree, which closes
 * it and continues into the page.
 */
function onFocusOut(event: FocusEvent): void {
    if (suppressClose || !open.value) return;

    const next = event.relatedTarget as Node | null;

    if (next !== null && rootEl.value?.contains(next) === true) return;

    open.value = false;
}

/**
 * The per-row check button: mark read and STAY OPEN, so someone clearing several rows is not made to
 * reopen the popover between each one.
 */
async function onMarkRead(id: string): Promise<void> {
    suppressClose = true;
    markRead(id);
    await nextTick();
    popoverEl.value?.focus();
    suppressClose = false;
}

/**
 * Following a row's link: mark read and CLOSE. Leaving the popover open would park it over the very page
 * the user just asked to see. The read write is a fetch rather than an Inertia visit precisely so it
 * survives the navigation that starts in this same tick — Inertia's request stream is
 * `maxConcurrent: 1, interruptible: true`, and a visit here would be aborted by the `<Link>`'s own.
 */
function onFollow(id: string): void {
    markRead(id);
    open.value = false;
}
</script>

<template>
    <div ref="rootEl" class="bell" @focusout="onFocusOut">
        <button
            ref="triggerEl"
            type="button"
            class="bell__trigger"
            :aria-expanded="open"
            aria-haspopup="dialog"
            :aria-label="triggerLabel"
            @click="toggle"
        >
            <MdsIcon name="bell" size="md" aria-hidden="true" />
            <!-- aria-hidden: the count is already in the trigger's accessible name above, and announcing
                 it twice reads as two separate facts. -->
            <span v-if="unreadCount > 0" class="bell__count" aria-hidden="true">{{ badgeText }}</span>
        </button>

        <div
            v-if="open"
            ref="popoverEl"
            class="bell__popover"
            role="dialog"
            aria-labelledby="notification-popover-title"
            tabindex="-1"
        >
            <div class="bell__head">
                <!-- Labelled BY the visible heading rather than by an aria-label, so the accessible name
                     and the rendered words physically cannot disagree. The bell mounts once, so a constant
                     id can never duplicate. -->
                <h2 id="notification-popover-title" class="bell__heading">Notifications</h2>
                <MdsButton variant="tertiary" size="sm" :disabled="unreadCount === 0" @click="markAllRead">
                    Mark all as read
                </MdsButton>
            </div>

            <p v-if="!hasLoaded" class="bell__loading">
                <MdsSpinner size="sm" label="Loading notifications" />
            </p>

            <div v-else-if="items.length === 0" class="bell__empty">
                <MdsEmptyState
                    headline="You’re all caught up"
                    description="Updates about your forms and submissions will appear here."
                />
            </div>

            <!-- role="list" survives `list-style: none` (Safari/VoiceOver strips list semantics from it);
                 tabindex="0" is the answer to axe's `scrollable-region-focusable` (WCAG 2.1.1), because a
                 feed whose rows are all unlinkable has NO focusable descendant and the scroller would
                 otherwise be unreachable by keyboard. AppLayout solves the identical problem the identical
                 way. -->
            <ul v-else class="bell__list" role="list" tabindex="0" aria-label="Recent notifications">
                <!-- :key is load-bearing past tidiness: it keeps a focused row's DOM alive across a
                     sixty-second poll, so a refresh never yanks focus and slams the popover shut. -->
                <li
                    v-for="item in items"
                    :key="item.id"
                    class="bell__row"
                    :class="{ 'is-unread': item.read_at === null }"
                >
                    <!-- Always rendered so the grid columns cannot shift between read and unread rows;
                         painted only when unread. aria-hidden — the row's own text carries the meaning. -->
                    <span
                        class="bell__dot"
                        :class="{ 'bell__dot--on': item.read_at === null }"
                        aria-hidden="true"
                    />

                    <span
                        class="bell__chip"
                        :class="`bell__chip--${notificationVisual(item.type).variant}`"
                        aria-hidden="true"
                    >
                        <MdsIcon :name="notificationVisual(item.type).icon" size="sm" />
                    </span>

                    <div class="bell__body">
                        <Link v-if="item.url" :href="item.url" class="bell__link" @click="onFollow(item.id)">
                            <span v-if="item.read_at === null" class="bell__sr">Unread. </span>
                            <span class="bell__row-title">{{ item.title }}</span>
                            <span class="bell__action">
                                {{ item.action_label }}
                                <MdsIcon name="chevron-right" size="sm" aria-hidden="true" />
                            </span>
                        </Link>
                        <!-- `url` is legitimately null — an unresolvable, deleted or forbidden target. A
                             non-interactive row, exactly as audit/Index.vue degrades one, and with no
                             action label, because there is no action. -->
                        <p v-else class="bell__plain">
                            <span v-if="item.read_at === null" class="bell__sr">Unread. </span>
                            <span class="bell__row-title">{{ item.title }}</span>
                        </p>

                        <p class="bell__desc">{{ item.description }}</p>

                        <time
                            class="bell__time"
                            :datetime="item.created_at"
                            :title="absoluteTime(item.created_at)"
                            >{{ relativeTime(item.created_at) }}</time
                        >
                    </div>

                    <!-- A SIBLING of the link, never nested inside it (axe `nested-interactive`), and an
                         MdsIconButton because it is the only control here that already guarantees a ≥24px
                         box for WCAG 2.2 §2.5.8 — a rule the e2e axe run enforces and the Storybook runner
                         does not. -->
                    <MdsIconButton
                        v-if="item.read_at === null"
                        icon="check"
                        size="sm"
                        :label="`Mark as read: ${item.title}`"
                        @click="onMarkRead(item.id)"
                    />
                    <span v-else class="bell__spacer" aria-hidden="true" />
                </li>
            </ul>

            <p v-if="stale" class="bell__stale" role="status">
                Couldn’t refresh just now — showing the last update.
            </p>

            <div class="bell__foot">
                <Link href="/settings" class="bell__prefs" @click="open = false">
                    Notification preferences
                </Link>
            </div>
        </div>
    </div>
</template>

<style scoped>
.bell {
    position: relative;
}

.bell__trigger {
    position: relative; /* the count bubble anchors here */
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 40px;
    height: 40px;
    border: none;
    border-radius: var(--mds-radius-md);
    background-color: transparent;
    color: var(--mds-color-text-secondary);
    cursor: pointer;
}

.bell__trigger:hover {
    background-color: var(--mds-color-bg-sunken);
}

.bell__trigger:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

/*
 * The count bubble. `.account__avatar` next door is the existing --mds-radius-full circle with text on it;
 * this is that circle at 18px, overlaid on the trigger.
 *
 * --mds-color-action-primary-bg / --mds-color-text-on-primary is an accent pair the design system already
 * MEASURES (the avatar wears it, MdsCheckbox paints its check glyph with it). A hand-picked red would be a
 * new pairing nothing in the token set has a ratio for — and the DARK axe sweep is where an unmeasured
 * pairing gets found, never the light one (see the note at Pages/audit/Index.vue).
 */
.bell__count {
    position: absolute;
    top: var(--mds-space-0-5);
    right: var(--mds-space-0-5);
    display: inline-flex;
    align-items: center;
    justify-content: center;
    /*
     * MIN-block-size, not a fixed one. `--mds-type-caption-font-size` is 12px at `standard` and 15px
     * under `[data-font-size="extra_large"]` (§2.9 scales the WHOLE type scale ×1.25), and
     * theme-overrides.css records what a pinned box costs there: the original §2.9 wording produced
     * "17px body-sm text inside an 18px line box … clipping descenders and failing WCAG 1.4.12". A badge
     * that grows is a slightly larger badge; a badge that clips is a number the user cannot read.
     * `--mds-radius-full` keeps it a pill rather than a rectangle if it ever does grow.
     */
    min-inline-size: 18px;
    min-block-size: 18px;
    padding: 0 var(--mds-space-1);
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-action-primary-bg);
    color: var(--mds-color-text-on-primary);
    font-size: var(--mds-type-caption-font-size);
    font-weight: var(--mds-font-weight-semibold);
    line-height: 1;
}

.bell__popover {
    position: absolute;
    top: calc(100% + var(--mds-space-1));
    right: 0;
    z-index: 30;
    display: flex;
    flex-direction: column;
    /* 360px, not the placeholder's 300px: a row carries a chip, two lines of text, a timestamp and a
       button. */
    width: 360px;
    max-width: calc(100vw - var(--mds-space-6));
    background-color: var(--mds-color-bg-surface-raised);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    box-shadow: var(--mds-shadow-3);
}

.bell__popover:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

/*
 * ⚠️ AT 375px AN ANCHORED PANEL FAILS SILENTLY AND NO GATE SEES IT.
 * The bell's right edge sits roughly 291px from the left (shell padding + the account trigger + the gap),
 * so a 360px panel anchored `right: 0` would start at about -69px. `.app-shell` is `overflow-x: clip`, so
 * that does NOT trip axe.ts::assertClean's scrollWidth assertion — it CLIPS the panel instead, which is
 * the worse failure because CI stays green over an unreadable popover. Below the mobile breakpoint the
 * panel therefore stops being anchored to the bell and becomes a sheet under the top nav, the same move
 * MdsModal makes at this width. The 64px is TopNav's fixed height; there is no token for it, and inventing
 * one would fail `token-references`.
 */
@media (max-width: 480px) {
    .bell__popover {
        position: fixed;
        top: 64px;
        inset-inline: var(--mds-space-2);
        width: auto;
        max-width: none;
        max-block-size: calc(100dvh - 64px - var(--mds-space-4));
    }

    .bell__list {
        max-block-size: none;
        flex: 1 1 auto;
    }
}

.bell__head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3);
    border-bottom: 1px solid var(--mds-color-border-default);
}

.bell__heading {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.bell__list {
    margin: 0;
    padding: 0;
    list-style: none;
    max-block-size: min(60vh, 420px);
    overflow-y: auto;
    overscroll-behavior: contain;
}

.bell__list:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: -2px;
}

/* minmax(0, 1fr) is the Domains-page overflow lesson: without it a long form title blows the text track
   out and the panel grows past its own width. */
.bell__row {
    display: grid;
    grid-template-columns: 8px auto minmax(0, 1fr) auto;
    align-items: start;
    gap: var(--mds-space-1) var(--mds-space-3);
    padding: var(--mds-space-3);
    border-bottom: 1px solid var(--mds-color-border-default);
}

.bell__row:last-child {
    border-bottom: 0;
}

.bell__row:hover {
    background-color: var(--mds-color-bg-sunken);
}

/*
 * Unread carries THREE channels and not one of them is colour alone: the visually-hidden "Unread." text,
 * the heavier title, and this dot's PRESENCE — a shape difference, not a hue difference. The row ground is
 * deliberately NOT tinted: body text on an accent tint is not a pairing the token set measures, and
 * audit/Index.vue's 1.38:1 anchor is what an unmeasured pairing costs.
 */
.bell__dot {
    inline-size: 8px;
    block-size: 8px;
    margin-block-start: var(--mds-space-1);
    border-radius: var(--mds-radius-full);
    background-color: transparent;
}

.bell__dot--on {
    background-color: var(--mds-color-action-primary-bg);
}

.bell__row.is-unread .bell__row-title {
    font-weight: var(--mds-font-weight-semibold);
}

.bell__chip {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    inline-size: 28px;
    block-size: 28px;
    border-radius: var(--mds-radius-full);
}

.bell__chip--success {
    background-color: var(--mds-color-status-success-bg);
    color: var(--mds-color-status-success-fg);
}

.bell__chip--warning {
    background-color: var(--mds-color-status-warning-bg);
    color: var(--mds-color-status-warning-fg);
}

.bell__chip--danger {
    background-color: var(--mds-color-status-danger-bg);
    color: var(--mds-color-status-danger-fg);
}

.bell__chip--info {
    background-color: var(--mds-color-status-info-bg);
    color: var(--mds-color-status-info-fg);
}

.bell__chip--neutral {
    background-color: var(--mds-color-status-neutral-bg);
    color: var(--mds-color-status-neutral-fg);
}

.bell__body {
    min-inline-size: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

/*
 * ⚠️ A LINK WITHOUT A COLOUR TOKEN IS NOT AN UNSTYLED LINK — IT IS A DARK-MODE CONTRAST FAILURE.
 * See the note at Pages/audit/Index.vue: omitting a colour leaves the anchor at the user agent's default
 * #0000EE, which measures 1.38:1 against the dark surface and reddened CI at all three viewports while
 * passing in light. These rows are exactly that kind of anchor — outside a design-system component — and
 * there are up to fifteen of them.
 *
 * The ROW TITLE takes --mds-color-text-heading rather than the link colour: fifteen accent-coloured
 * headlines is a wall of blue, and the clickability is carried non-chromatically by the hover underline and
 * by the explicit action affordance below, which DOES take the link token.
 */
.bell__link,
.bell__plain {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    margin: 0;
    text-decoration: none;
}

.bell__row-title {
    color: var(--mds-color-text-heading);
    font-size: var(--mds-type-body-md-font-size);
    overflow-wrap: anywhere;
}

/* Scoped to the LINK, not the row: an unresolvable row's title is a `<p>`, and underlining it on hover
   would advertise a click that does nothing. */
.bell__link:hover .bell__row-title {
    text-decoration: underline;
}

.bell__link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.bell__action {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    color: var(--mds-color-action-primary-fg);
    font-size: var(--mds-type-body-sm-font-size);
}

.bell__desc {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    overflow-wrap: anywhere;
}

.bell__time {
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
}

/* Holds the mark-read column open on a read row, so the text track does not widen row by row. */
.bell__spacer {
    display: block;
    inline-size: 28px;
}

.bell__empty {
    padding: var(--mds-space-2);
}

.bell__stale {
    margin: 0;
    padding: var(--mds-space-2) var(--mds-space-3);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.bell__foot {
    padding: var(--mds-space-3);
    border-top: 1px solid var(--mds-color-border-default);
}

.bell__prefs {
    color: var(--mds-color-action-primary-fg);
    font-size: var(--mds-type-body-sm-font-size);
    text-decoration: none;
}

.bell__prefs:hover {
    text-decoration: underline;
}

.bell__prefs:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.bell__loading {
    display: flex;
    justify-content: center;
    margin: 0;
    padding: var(--mds-space-6);
}

/* The clip-rect visually-hidden idiom already used in Sidebar.vue and MdsSpinner — there is no global
   utility in resources/css/app.css, so every component declares its own. */
.bell__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
}
</style>
