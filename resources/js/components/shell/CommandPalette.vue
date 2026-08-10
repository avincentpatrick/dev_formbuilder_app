<script setup lang="ts">
/**
 * The ⌘K command palette (Increment J1d, DSR §3.4.1).
 *
 * ⚠️ A HAND-ROLLED ARIA 1.2 COMBOBOX, AND THAT IS A LOGGED EXCEPTION rather than an oversight — see
 * `docs/ux/exceptions-log.md`. The increment that owns `MdsCombobox` runs after this one, and generalising
 * a palette whose options are heterogeneous (forms, submissions, members, destinations, and a synthetic
 * "see all" row) into a primitive before there is a second consumer would be inventing an API from one
 * example.
 *
 * ⚠️ ITS ONLY AUTOMATED a11y GATE IS `tests/e2e/command-palette.spec.ts`. Storybook globs
 * `packages/design-system/src/**` only, so an app-tree component gets no story and no `checkA11y` scan — a
 * green `design-system-a11y` job says nothing whatsoever about this file. Recorded so the gate is not
 * misread as coverage.
 *
 * ── WHAT MdsModal SUPPLIES, SO IT IS NOT REBUILT HERE ────────────────────────────────────────────────────
 * The inert stack and its paint-order handling, the scroll lock, Escape, the Tab trap, return-focus, and
 * the ≤480px full-sheet treatment. This component owns only the combobox: the input, the listbox, the
 * active-descendant model, and the fetch.
 */
import { MdsModal, MdsTextInput } from '@meridian/design-system';
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
interface Option {
    key: string;
    label: string;
    url: string;
}

const props = defineProps<{
    /** The control to return focus to, and to focus before opening so the modal never captures <body>. */
    openerSelector?: string;
}>();

const { open } = useCommandPalette(() =>
    props.openerSelector ? document.querySelector<HTMLElement>(props.openerSelector) : null
);

const listboxId = useId();
const optionIdPrefix = useId();

const query = ref('');
const groups = ref<SuggestGroup[]>([]);
const seeAllUrl = ref('/search');
const busy = ref(false);
const activeIndex = ref(0);

/**
 * The flattened, activatable list — what ↓/↑ walk and what Enter activates.
 *
 * The "see all" row is a REAL OPTION rather than a link below the list, which is what lets Enter mean
 * exactly one thing ("activate the highlighted option") instead of branching on whether anything is
 * highlighted. DSR §3.4.1 makes that the rule.
 */
const options = computed<Option[]>(() => {
    const flat: Option[] = [];

    for (const group of groups.value) {
        for (const item of group.items) {
            flat.push({ key: `${group.entity}:${item.id}`, label: item.title, url: item.url });
        }
    }

    if (query.value.trim() !== '') {
        flat.push({ key: 'see-all', label: `See all results for “${query.value.trim()}”`, url: seeAllUrl.value });
    }

    return flat;
});

const hasOptions = computed(() => options.value.length > 0);

/**
 * ⚠️ OMITTED, NEVER DANGLING. `aria-activedescendant` pointing at an id that is not in the DOM is worse
 * than absent — a screen reader announces nothing and the user has no way to tell. Same for
 * `aria-controls` while the listbox is not rendered. DSR §3.4.1 records that the axe gate will NOT catch
 * this, so it is asserted directly in the Vitest spec instead.
 */
const activeDescendantId = computed(() =>
    hasOptions.value && options.value[activeIndex.value] !== undefined
        ? `${optionIdPrefix}-${activeIndex.value}`
        : undefined
);

const controlsId = computed(() => (hasOptions.value ? listboxId : undefined));

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
    activeIndex.value = 0;
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
        // Auto-highlight the first row on every non-empty list: it keeps aria-activedescendant valid and
        // makes Enter useful. DSR §3.4.1 records the consequence — a screen-reader user hears the first
        // result on each debounce tick.
        activeIndex.value = 0;
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

function move(delta: number): void {
    if (!hasOptions.value) return;

    const count = options.value.length;
    activeIndex.value = (activeIndex.value + delta + count) % count;
}

function activate(): void {
    const option = options.value[activeIndex.value];

    if (option === undefined) {
        // No list at all — Enter still means "search for what I typed", which is the behaviour a user who
        // types faster than the debounce expects.
        if (query.value.trim() !== '') router.visit(`/search?q=${encodeURIComponent(query.value.trim())}`);

        return;
    }

    open.value = false;
    void nextTick(() => router.visit(option.url));
}

function onKeydown(event: KeyboardEvent): void {
    // preventDefault on the arrows, or the caret jumps to the ends of the input while the list moves.
    switch (event.key) {
        case 'ArrowDown':
            event.preventDefault();
            move(1);
            break;
        case 'ArrowUp':
            event.preventDefault();
            move(-1);
            break;
        case 'Home':
            event.preventDefault();
            activeIndex.value = 0;
            break;
        case 'End':
            event.preventDefault();
            activeIndex.value = Math.max(0, options.value.length - 1);
            break;
        case 'Enter':
            event.preventDefault();
            activate();
            break;
        default:
            break;
    }
}

/** Flat index of an item, so each rendered row can find its own option id. */
function indexOf(groupIndex: number, itemIndex: number): number {
    let flat = 0;

    for (let g = 0; g < groupIndex; g++) {
        flat += groups.value[g]?.items.length ?? 0;
    }

    return flat + itemIndex;
}
</script>

<template>
    <MdsModal
        :open="open"
        title="Search"
        close-label="Close search"
        initial-focus="[data-mds-initial-focus]"
        @close="open = false"
        @update:open="open = $event"
    >
        <div class="palette">
            <label class="palette__label" :for="`${listboxId}-input`">Search this workspace</label>
            <MdsTextInput
                :id="`${listboxId}-input`"
                v-model="query"
                type="search"
                role="combobox"
                autocomplete="off"
                placeholder="Search forms, submissions, members and pages"
                aria-autocomplete="list"
                :aria-expanded="hasOptions"
                :aria-controls="controlsId"
                :aria-activedescendant="activeDescendantId"
                data-mds-initial-focus
                @keydown="onKeydown"
            />

            <div v-if="hasOptions" :id="listboxId" class="palette__list" role="listbox" aria-label="Search results">
                <div
                    v-for="(group, groupIndex) in groups"
                    :key="group.entity"
                    class="palette__group"
                    role="group"
                    :aria-label="group.label"
                >
                    <p class="palette__group-heading" aria-hidden="true">{{ group.label }}</p>
                    <!--
                        role="option" on a div, never a button: a button inside a listbox trips axe's
                        nested-interactive AND breaks aria-activedescendant. Rows are not tabbable by
                        design — DOM focus never leaves the input.
                    -->
                    <div
                        v-for="(item, itemIndex) in group.items"
                        :id="`${optionIdPrefix}-${indexOf(groupIndex, itemIndex)}`"
                        :key="item.id"
                        class="palette__option"
                        :class="{ 'is-active': activeIndex === indexOf(groupIndex, itemIndex) }"
                        role="option"
                        :aria-selected="activeIndex === indexOf(groupIndex, itemIndex)"
                        @click="activeIndex = indexOf(groupIndex, itemIndex); activate()"
                    >
                        <span class="palette__option-title">{{ item.title }}</span>
                        <span class="palette__option-sub">{{ item.subtitle }}</span>
                    </div>
                </div>

                <div
                    :id="`${optionIdPrefix}-${options.length - 1}`"
                    class="palette__option palette__option--all"
                    :class="{ 'is-active': activeIndex === options.length - 1 }"
                    role="option"
                    :aria-selected="activeIndex === options.length - 1"
                    @click="activeIndex = options.length - 1; activate()"
                >
                    {{ options[options.length - 1]?.label }}
                </div>
            </div>

            <p v-else-if="query.trim() !== '' && !busy" class="palette__empty">{{ emptyCopy }}</p>

            <!--
                ⚠️ INSIDE the dialog. `inert` removes everything outside an open modal from the
                accessibility tree, so a live region in the shell would stop announcing entirely and
                nothing replays on close.
            -->
            <p class="palette__status" role="status">
                <template v-if="busy">Searching…</template>
                <template v-else-if="hasOptions">{{ options.length - 1 }} results</template>
            </p>
        </div>
    </MdsModal>
</template>

<style scoped>
.palette {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.palette__label {
    position: absolute;
    width: 1px;
    height: 1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    clip-path: inset(50%);
    white-space: nowrap;
}

.palette__list {
    max-height: 22rem;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.palette__group-heading {
    margin: 0;
    padding: var(--mds-space-1) var(--mds-space-2);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.palette__option {
    display: flex;
    flex-direction: column;
    gap: 2px;
    padding: var(--mds-space-2) var(--mds-space-3);
    border-radius: var(--mds-radius-sm);
    cursor: pointer;
}

.palette__option.is-active {
    /*
     * ⚠️ The same token Sidebar.vue uses for its current-section row, so "the highlighted thing" looks the
     * same in both places. The first draft invented `--mds-color-surface-active`, which does not exist —
     * a missing token resolves to nothing and the highlight silently disappears, which is J1b's
     * `--mds-space-9` all over again. `token-references.test.ts` is the gate; check the token list first.
     */
    background-color: var(--mds-color-action-primary-tint);
    color: var(--mds-color-text-heading);
}

.palette__option-sub {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.palette__option--all {
    font-weight: 600;
}

.palette__empty,
.palette__status {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}
</style>
