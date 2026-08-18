<script setup lang="ts">
/**
 * The ⌘K command palette (Increment J1d, DSR §3.4.1).
 *
 * ── ⚠️ IT CONSUMES `MdsCombobox` AS OF J4c, AND IT USED TO HAND-ROLL THE WHOLE PATTERN ─────────────────
 * This file was the product's only ARIA 1.2 combobox and was a LOGGED DEVIATION for that reason:
 * generalising a palette whose options are heterogeneous — forms, submissions, members, destinations and a
 * synthetic "see all" row — into a primitive before there was a second consumer would have been inventing
 * an API from one example. The log said to wait for the increment that owns `MdsCombobox` and to DELETE
 * the entry rather than amend it when that landed. That has happened; the entry is gone.
 *
 * **What moved:** the input's role and its four ARIA attributes, option id generation, the grouped
 * rendering, the active-descendant model, the ↓↑/Home/End/Enter keyboard, and the polite live region.
 * **What stayed here, because none of it is combobox behaviour:** the ⌘K chord, the debounce, the
 * abort-plus-sequence fetch discipline, the synthetic see-all row, the single-string empty copy, and the
 * modal that wraps all of it.
 *
 * ⚠️ THE COMPONENT NOW GETS A STORYBOOK STORY AND THEREFORE AN axe SCAN, WHICH THIS FILE NEVER HAD. The
 * coverage gap this file used to record against itself is a general fact about the Storybook glob, and it
 * now lives in DSR §4.6 where every component can cite it.
 *
 * ── WHAT MdsModal SUPPLIES, SO IT IS NOT REBUILT HERE ────────────────────────────────────────────────────
 * The inert stack and its paint-order handling, the scroll lock, Escape, the Tab trap, return-focus, and
 * the ≤480px full-sheet treatment. ⚠️ Escape in particular: `MdsCombobox` deliberately does NOT bind it,
 * because the surface that owns it is this dialog. See that component's docblock before "fixing" it.
 */
import { MdsCombobox, MdsModal, firstFocusable, type ComboboxOption } from '@meridian/design-system';
import { router } from '@inertiajs/vue3';
import { computed, nextTick, ref, useId, watch } from 'vue';
import { useCommandPalette } from '@/composables/useCommandPalette';

interface SuggestItem {
    id: string;
    title: string;
    subtitle: string;
    url: string;
}

interface SuggestGroup {
    entity: string;
    label: string;
    items: SuggestItem[];
    has_more: boolean;
}

/** A row the user can activate: either a real result or the synthetic "see all" tail. */
interface PaletteOption extends ComboboxOption {
    url: string;
    subtitle: string;
}

const props = defineProps<{
    /**
     * Candidate controls to focus before opening, in preference order — the modal captures whichever one
     * is focused as its return-focus target.
     *
     * ⚠️ A LIST, NOT ONE SELECTOR, AND CI IS WHAT PROVED IT HAD TO BE. The first version took a single
     * selector (`#topnav-search`) and stranded focus at 375px: below the 480px breakpoint that field is
     * `display: none`, `.focus()` on a hidden element is a silent no-op, so MdsModal captured the body and
     * closing the palette left focus nowhere. Every unit test passed — happy-dom has no layout, so nothing
     * there can distinguish a hidden element from a visible one. Only the browser at a real viewport could.
     *
     * ⚠️ AND AS OF J6 THE SAME LIST IS ALSO PASSED AS `MdsModal`'s `returnFocus`, WHICH IS ONE LIST FOR ONE
     * QUESTION RATHER THAN TWO LISTS THAT WOULD DRIFT. Focusing a candidate before opening only *usually*
     * fixes return-focus — it fails whenever the captured element does not survive to close time, which the
     * palette makes routine: activating a result navigates, the shell re-renders, and an opener captured
     * inside a since-closed mobile drawer no longer exists. Handing the same preference order to the modal
     * lets it re-resolve against the document as it stands *then*, rather than trusting a handle it took
     * before the page changed underneath it.
     */
    openerSelectors?: string[];
}>();

/**
 * ⚠️ THE PREDICATE MOVED INTO THE DESIGN SYSTEM IN J6, AND IT WAS INCOMPLETE HERE. This file used to carry
 * a private `isVisible()` asking `checkVisibility()`, which is the honest question about RENDERING and
 * knows nothing about `inert` — an inert element renders normally, so it answered `true` for a control
 * `.focus()` cannot move to. With the mobile nav drawer holding the page, every candidate below lives in
 * the inert top nav: the resolver handed one back, the focus call was a silent no-op, and `MdsModal`
 * captured whatever the drawer happened to have. **That is the same defect the LIST below was created to
 * fix**, blind to `inert` instead of blind to layout. `firstFocusable` now answers both, in one place, and
 * `MdsModal` reads the same definition on the way out.
 */
const { open } = useCommandPalette(() => firstFocusable(props.openerSelectors ?? []));

/**
 * The input's id, minted here and handed DOWN, so the modal's `initialFocus` selector can name the real
 * control rather than a wrapper.
 *
 * ⚠️ THIS REPLACED A `data-mds-initial-focus` ATTRIBUTE ON THE COMPONENT, AND THE SWAP IS A CORRECTNESS FIX
 * THAT THE UNIT SUITE CAUGHT. `MdsTextInput`'s root element IS the input under Vue's default
 * `inheritAttrs`, so that attribute used to land on something focusable. `MdsCombobox` has a real wrapper,
 * so the same attribute silently moved onto a DIV — and `.focus()` on a non-focusable element is a no-op,
 * which strands focus on the body with Escape and the Tab trap both unreachable. DSR §4.5 names that
 * outcome exactly: a keyboard trap (WCAG 2.1.2) reached through the very prop meant to prevent one.
 * `MdsModal` does verify focus landed and falls back, so this degraded rather than broke — but the
 * fallback is the close button, not the search field, which is not what a ⌘K user asked for.
 */
const inputId = `${useId()}-palette-input`;

const query = ref('');
const groups = ref<SuggestGroup[]>([]);
const seeAllUrl = ref('/search');
const busy = ref(false);

/**
 * The flattened, activatable list — what ↓/↑ walk and what Enter activates.
 *
 * The "see all" row is a REAL OPTION rather than a link below the list, which is what lets Enter mean
 * exactly one thing ("activate the highlighted option") instead of branching on whether anything is
 * highlighted. DSR §3.4.1 makes that the rule.
 *
 * ⚠️ It carries NO `group`, and that is deliberate rather than incidental: `MdsCombobox` renders an
 * ungrouped run as direct children of the listbox, so the synthetic row does not acquire a heading that is
 * nowhere on the screen.
 */
const options = computed<PaletteOption[]>(() => {
    const flat: PaletteOption[] = [];

    for (const group of groups.value) {
        for (const item of group.items) {
            flat.push({
                key: `${group.entity}:${item.id}`,
                label: item.title,
                subtitle: item.subtitle,
                url: item.url,
                group: group.label,
            });
        }
    }

    if (query.value.trim() !== '') {
        flat.push({
            key: 'see-all',
            label: `See all results for “${query.value.trim()}”`,
            subtitle: '',
            url: seeAllUrl.value,
        });
    }

    return flat;
});

/**
 * What the live region says. The CONSUMER owns this wording — `MdsCombobox` takes it as a prop and never
 * counts for itself, because only this file knows the last row is synthetic and should not be counted.
 */
const status = computed(() => {
    if (busy.value) return 'Searching…';

    return options.value.length > 0 ? `${options.value.length - 1} results` : '';
});

/**
 * The zero-result string, used for BOTH "nothing matched" and "everything that matched is invisible to
 * you". One string, one code path — DSR §3.4.1's binding disclosure rule. Never branch this.
 */
const emptyCopy = 'No results.';

let sequence = 0;
let controller: AbortController | null = null;
let debounce: ReturnType<typeof setTimeout> | null = null;

function reset(): void {
    groups.value = [];
    busy.value = false;
}

async function fetchSuggestions(term: string): Promise<void> {
    controller?.abort();
    controller = new AbortController();

    // ⚠️ A MONOTONIC SEQUENCE *AS WELL AS* THE ABORT, not instead of it. An aborted fetch can still resolve
    // in some runtimes, and abort alone makes ordering an accident of the transport rather than a property
    // of this code. The counter is what actually guarantees a stale response cannot overwrite a fresh one.
    const mine = ++sequence;
    busy.value = true;

    try {
        const response = await fetch(`/search/suggest?q=${encodeURIComponent(term)}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
            // ⚠️ NEVER send X-Inertia here. Laravel would answer with an Inertia response and this would
            // silently start parsing a page payload as a suggest payload.
        });

        if (!response.ok) return;

        const payload = (await response.json()) as { groups?: SuggestGroup[]; see_all_url?: string };

        if (mine !== sequence) return;

        groups.value = payload.groups ?? [];
        seeAllUrl.value = payload.see_all_url ?? '/search';
    } catch {
        // An abort or a network blip must leave the PREVIOUS list intact rather than blanking the panel
        // under the user mid-read.
    } finally {
        if (mine === sequence) busy.value = false;
    }
}

watch(query, (term) => {
    if (debounce !== null) clearTimeout(debounce);

    if (term.trim() === '') {
        // No request at all for an empty query — there is nothing to ask about, and it keeps the listbox
        // (and therefore aria-controls) correctly absent.
        controller?.abort();
        sequence++;
        reset();

        return;
    }

    debounce = setTimeout(() => void fetchSuggestions(term), 250);
});

watch(open, (isOpen) => {
    if (isOpen) return;

    if (debounce !== null) clearTimeout(debounce);
    controller?.abort();
    sequence++;
    query.value = '';
    reset();
});

function activate(option: ComboboxOption): void {
    open.value = false;
    void nextTick(() => router.visit((option as PaletteOption).url));
}

function submitRawQuery(term: string): void {
    // Enter with no list at all still means "search for what I typed", which is what a user who types
    // faster than the debounce expects.
    if (term.trim() !== '') router.visit(`/search?q=${encodeURIComponent(term.trim())}`);
}
</script>

<template>
    <MdsModal
        :open="open"
        title="Search"
        close-label="Close search"
        :initial-focus="`#${inputId}`"
        :return-focus="openerSelectors"
        @close="open = false"
        @update:open="open = $event"
    >
        <MdsCombobox
            v-model="query"
            :options="options"
            :input-id="inputId"
            label="Search this workspace"
            listbox-label="Search results"
            placeholder="Search forms, submissions, members and pages"
            :status="status"
            @select="activate"
            @submit="submitRawQuery"
        >
            <template #option="{ option }">
                <span class="palette__option-title">{{ option.label }}</span>
                <span v-if="(option as PaletteOption).subtitle" class="palette__option-sub">
                    {{ (option as PaletteOption).subtitle }}
                </span>
            </template>

            <template #empty>
                <p v-if="query.trim() !== '' && !busy" class="palette__empty">{{ emptyCopy }}</p>
            </template>
        </MdsCombobox>
    </MdsModal>
</template>

<style scoped>
/*
 * ⚠️ THE VISUALLY-HIDDEN CLIP IDIOM IS GONE FROM THIS FILE, AND THAT IS WHY THIS COMPONENT COULD LEAVE
 * `KNOWN_UNGUARDED` IN `clipped-node-containment.test.ts`. Both clipped nodes — the input's label and the
 * polite live region — moved into `MdsCombobox`, which positions its own root so their containing block
 * resolves inside the component that owns them. That list may only ever SHRINK, and this is the shrink.
 *
 * `.palette__list`, `.palette__option`, `.palette__group-heading` and `.palette__label` are deleted with
 * the markup they styled: dead rules for elements that are no longer on the page read to the next author
 * as a component still in use. What remains is only what the scoped SLOT still renders.
 */
.palette__option-title {
    font-weight: 500;
}

.palette__option-sub {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.palette__empty {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}
</style>
