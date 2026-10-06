<script setup lang="ts">
/**
 * The post-submit confirmation (UX §9): a full-screen thank-you with the submission's handle and an option to
 * submit another response. Focus moves to the heading on arrival so a screen reader is told the flow has
 * concluded (§10.2).
 *
 * ⚠️ TWO DIFFERENT CODES, AND THE DISTINCTION IS THE WHOLE POINT (Increment J2e).
 *
 *   · `reference` — the SERVER-issued handle, present when the response actually reached the server. This is
 *     the string a respondent should write down: the tenant can paste it into their inbox and find this row.
 *   · `queueTag` — a device-local label for a response still sitting in the outbox. There is no server row
 *     yet, so there is no reference yet; calling it one would hand the respondent a code that finds nothing.
 *
 * Exactly one is ever non-null. Before J2e both screens said "Reference:" and the offline one was derived
 * from the client uuid and stored nowhere — which is the defect this split removes.
 *
 * ── AFTER THE THANK-YOU (M130, `R-db169c29`, `D76`) ─────────────────────────────────────────────────────
 * When the author set a destination and the server ACCEPTED the response, the screen names it and offers
 * "Continue now"; while the count runs it also offers "Stay on this page", and after the form's delay it goes on
 * its own — WCAG 2.2.1 lets a timed move stand when the person can stop it. The form builder chooses the delay
 * (M138, `D91` amending `D76`): 20 s, WCAG's figure, by default, or 5, 10 or 30.
 *
 *   · The destination rides on the submit RESPONSE: a queued response never had one, so it cannot move, and
 *     App.vue drops it for a resolved conflict.
 *   · No count, only the link, when the page is framed (an embed must not navigate its host; the link opens
 *     `_top`) or the device still holds unsent responses (leaving would strand them until the next visit).
 *     That count refreshes a moment after an online submit settles, so a zero arriving late still starts it.
 *   · The count stops on `pagehide`, and a page restored from the back/forward cache keeps the link without
 *     counting again — otherwise Back would bounce the respondent forward.
 *   · Leaving emits `leave` first, and App.vue rotates the respondent session as Submit-another does.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { MdsButton } from '@meridian/design-system';
import { DEFAULT_REDIRECT_DELAY } from '../lib/api-client';
import type { SubmitRedirect } from '../lib/types';

const props = withDefaults(
    defineProps<{
        reference: string | null;
        queueTag: string | null;
        message: string;
        /** The server's destination, or null to stay. */
        redirect?: SubmitRedirect | null;
        /** Rows on this device still waiting to send; while any are, the link is offered and nothing counts. */
        unsent?: number;
        /** Whether this page is inside a frame — passed in, so a test can say so. */
        framed?: boolean;
        /** Goes to the destination: `window.location.assign`, unless a test passes its own. */
        navigate?: (url: string) => void;
    }>(),
    { redirect: null, unsent: 0, framed: false, navigate: (url: string) => window.location.assign(url) },
);
const emit = defineEmits<{ restart: []; leave: [] }>();


const heading = ref<HTMLElement | null>(null);
const continueLink = ref<{ $el?: HTMLElement } | null>(null);
// A queue tag means the server never answered, so there is nowhere to go.
const destination = computed<SubmitRedirect | null>(() => (props.reference !== null ? props.redirect : null));
/** The wait the form builder chose; the parser has already reduced anything unexpected to 20 (M138). */
const delaySeconds = computed(() => destination.value?.delaySeconds ?? DEFAULT_REDIRECT_DELAY);
const remaining = ref(DEFAULT_REDIRECT_DELAY);
const counting = ref(false);
const announcement = ref('');
// Once the respondent stays, leaves, starts again or comes Back to this page, the count never starts again.
let settled = false;
let timer: ReturnType<typeof setInterval> | null = null;

function stop(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
    counting.value = false;
}

function leave(): void {
    settled = true;
    stop();
    emit('leave');
}

function go(): void {
    if (destination.value === null) return;
    leave();
    props.navigate(destination.value.url);
}

function onContinue(event: MouseEvent): void {
    leave();
    // Framed, the link itself opens `_top`; otherwise the page goes through the injected navigation.
    if (!props.framed && destination.value !== null) {
        event.preventDefault();
        props.navigate(destination.value.url);
    }
}

function onStay(): void {
    settled = true;
    stop();
    announcement.value = 'You will stay on this page.';
    // The Stay button goes with the count, so focus moves to the link that remains.
    void nextTick(() => continueLink.value?.$el?.focus());
}

function onRestart(): void {
    settled = true;
    stop();
    emit('restart');
}

function startCount(): void {
    if (settled || counting.value || destination.value === null || props.framed || props.unsent > 0) return;
    counting.value = true;
    remaining.value = delaySeconds.value;
    announcement.value = `Next: ${destination.value.label}, in ${delaySeconds.value} seconds. Choose Stay on this page to remain here.`;
    timer = setInterval(() => {
        remaining.value -= 1;
        if (remaining.value <= 0) go();
    }, 1000);
}

function onPageHide(): void {
    stop();
}

function onPageShow(event: PageTransitionEvent): void {
    if (event.persisted) {
        settled = true;
        stop();
    }
}

onMounted(() => {
    heading.value?.focus();
    window.addEventListener('pagehide', onPageHide);
    window.addEventListener('pageshow', onPageShow);

    if (destination.value === null) return;
    startCount();
    if (!counting.value) {
        announcement.value = `Next: ${destination.value.label}. Choose Continue now when you are ready.`;
    }
});

watch(
    () => props.unsent,
    (count) => (count > 0 ? stop() : startCount()),
);

onBeforeUnmount(() => {
    stop();
    window.removeEventListener('pagehide', onPageHide);
    window.removeEventListener('pageshow', onPageShow);
});
</script>

<template>
    <div class="confirmation">
        <div class="confirmation__card">
            <svg class="confirmation__icon" viewBox="0 0 48 48" aria-hidden="true" focusable="false">
                <circle cx="24" cy="24" r="21" fill="none" stroke="currentColor" stroke-width="2" />
                <path
                    d="M15 24l6 6 12-12"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2.5"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />
            </svg>
            <h1 ref="heading" tabindex="-1" class="confirmation__title">{{ message }}</h1>
            <p v-if="reference !== null" class="confirmation__ref">
                Reference: <strong>{{ reference }}</strong>
            </p>
            <template v-else-if="queueTag !== null">
                <p class="confirmation__ref">Queue tag: <strong>{{ queueTag }}</strong></p>
                <p class="confirmation__ref-note">
                    This is a temporary label for this device. Your reference is issued once this response is
                    sent.
                </p>
            </template>
            <div v-if="destination !== null" class="confirmation__next" data-redirect>
                <p class="confirmation__next-label">Next: <strong>{{ destination.label }}</strong></p>
                <p v-if="counting" class="confirmation__countdown">Continuing in {{ remaining }} {{ remaining === 1 ? 'second' : 'seconds' }}.</p>
                <div class="confirmation__next-actions">
                    <MdsButton
                        ref="continueLink"
                        as="a"
                        :href="destination.url"
                        :target="framed ? '_top' : undefined"
                        variant="primary"
                        @click="onContinue"
                    >
                        Continue now
                    </MdsButton>
                    <MdsButton v-if="counting" variant="tertiary" @click="onStay">Stay on this page</MdsButton>
                </div>
            </div>
            <p class="confirmation__sr" role="status">{{ announcement }}</p>
            <MdsButton variant="secondary" @click="onRestart">Submit another response</MdsButton>
        </div>
    </div>
</template>

<style scoped>
.confirmation__ref-note {
    /* J2e — the sentence that stops a queue tag being mistaken for a reference. Quieter than the code above
       it, but NOT `--mds-color-text-muted`: it is the only thing on screen saying the response has not been
       delivered yet, which is the truth that matters most on this screen. */
    margin: calc(-1 * var(--mds-space-2)) 0 var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.confirmation {
    /* I10d — `flex: 1`, not `min-height: 100vh`. The sync surface now sits ABOVE this in App.vue's
       flex column, so claiming the whole viewport here would make the document taller than the screen and
       put a scrollbar on every page. `assertClean` checks HORIZONTAL overflow only, so this would have
       shipped as a visible regression with a green gate. */
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--mds-space-6);
    background-color: var(--mds-color-bg-canvas);
}

.confirmation__card {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
    gap: var(--mds-space-4);
    max-width: 32rem;
    padding: var(--mds-space-8);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    /* JR2: the page-level card tier (DSR §2.6). The guest runtime is a separate SPA, but it reads the
       same token sheet, and this card IS the respondent's whole confirmation screen — the one surface
       a person outside the tenant ever sees. The declared fallback chain is kept: it is the guest
       bundle's habit of never assuming a token version, so it degrades to the previous tier rather
       than to a square corner. */
    border-radius: var(--mds-radius-xl, var(--mds-radius-lg));
    box-shadow: var(--mds-shadow-1);
    position: relative;
}

.confirmation__icon {
    width: 56px;
    height: 56px;
    color: var(--mds-color-action-primary-fg);
}

.confirmation__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-2-font-size);
    line-height: var(--mds-type-heading-2-line-height);
    font-weight: var(--mds-type-heading-2-font-weight);
    color: var(--mds-color-text-heading);
}

.confirmation__title:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 4px;
}

.confirmation__ref {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    color: var(--mds-color-text-secondary);
}

.confirmation__ref strong {
    font-family: var(--mds-font-family-mono, monospace);
    color: var(--mds-color-text-body);
}

/* M130 (`D76`) — the destination, set off from the thank-you by a rule so it reads as what comes next. */
.confirmation__next {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--mds-space-3);
    width: 100%;
    padding-top: var(--mds-space-4);
    border-top: 1px solid var(--mds-color-border-default);
}

.confirmation__next-label {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.confirmation__countdown {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.confirmation__next-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: var(--mds-space-2);
}

/* Read once by a screen reader; the visible countdown above is not a live region, so it never chatters. */
.confirmation__sr {
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
</style>
