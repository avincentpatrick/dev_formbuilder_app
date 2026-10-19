<script setup lang="ts">
/**
 * "Add a question here" — M176 (`R-5354b4b9`, `D142` A). The testers (2026-10-11, comment 3): *"“+ Add question here”
 * button not working"*. It only selected the section and moved focus to the left pane's palette — easy to miss on a wide
 * screen, and refused while a save ran (its buttons are disabled then, so the focus silently failed). Nothing was added.
 *
 * Now the button opens the question types right where it is, as an inline list — not a pop-up: `MdsMenu` may not be
 * mounted inside a scroll container (DSR §3.4b), and the Structure canvas and the Preview both scroll. Choosing a type
 * says so (`add`), and the owner adds it at this spot. The list is the palette's own (`palette-groups.ts`), so it never
 * offers a type the palette does not.
 *
 * The types are NOT disabled while a save runs: the add waits its turn in the store's queue behind that save, and its
 * place is named here, never read from the selection — so there is no race left to guard against.
 *
 * A disclosure (`aria-expanded` / `aria-controls`): opening it puts focus on the first type; Escape or Cancel closes it
 * and gives focus back to the button.
 */
import { computed, nextTick, ref, useId } from 'vue';
import { MdsButton } from '@meridian/design-system';
import { pickableGroups } from './palette-groups';
import type { PaletteGroup } from './types';

const props = defineProps<{ palette: PaletteGroup[]; label: string }>();
const emit = defineEmits<{ add: [typeValue: string] }>();

const open = ref(false);
const listId = useId();
const root = ref<HTMLElement | null>(null);
const groups = computed(() => pickableGroups(props.palette));

async function show(): Promise<void> {
    open.value = true;
    await nextTick();
    root.value?.querySelector<HTMLButtonElement>('.add-here__type')?.focus();
}

async function close(): Promise<void> {
    open.value = false;
    await nextTick();
    root.value?.querySelector<HTMLButtonElement>('[data-add-here-toggle]')?.focus();
}

function choose(typeValue: string): void {
    open.value = false;
    emit('add', typeValue);
}
</script>

<template>
    <div ref="root" class="add-here" :class="{ 'add-here--open': open }" @keydown.esc.stop.prevent="open && close()">
        <MdsButton
            type="button"
            variant="tertiary"
            size="sm"
            icon-left="plus"
            data-add-here-toggle
            :aria-expanded="open ? 'true' : 'false'"
            :aria-controls="listId"
            @click="open ? close() : show()"
        >
            {{ label }}
        </MdsButton>
        <div v-if="open" :id="listId" class="add-here__list" role="group" aria-label="Choose a question type" data-add-here-list>
            <p class="add-here__lead">Choose the kind of question to add here.</p>
            <div v-for="group in groups" :key="group.category" class="add-here__group">
                <h4 class="add-here__group-title">{{ group.label }}</h4>
                <ul class="add-here__types">
                    <li v-for="type in group.types" :key="type.value">
                        <button type="button" class="add-here__type" :data-add-type="type.value" @click="choose(type.value)">
                            {{ type.label }}
                        </button>
                    </li>
                </ul>
            </div>
            <div>
                <MdsButton type="button" variant="tertiary" size="sm" data-add-here-cancel @click="close()">Cancel</MdsButton>
            </div>
        </div>
    </div>
</template>

<style scoped>
.add-here {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: var(--mds-space-2);
}

/* Open, it takes the whole row its host lays out — both hosts are wrapping flex rows, which would squeeze the list to one
   narrow column — and drops the host's italic note styling (measured in the real-browser look). */
.add-here--open {
    flex-basis: 100%;
    font-style: normal;
}

.add-here__list {
    align-self: stretch;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.add-here__lead {
    margin: 0;
    color: var(--mds-color-text-body);
    font-style: normal;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.add-here__group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.add-here__group-title {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
}

.add-here__types {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(8.5rem, 1fr));
    gap: var(--mds-space-1);
    margin: 0;
    padding: 0;
    list-style: none;
}

.add-here__type {
    width: 100%;
    min-height: 2.25rem;
    padding: var(--mds-space-1) var(--mds-space-2);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-sm);
    background-color: var(--mds-color-bg-surface);
    color: var(--mds-color-text-body);
    font: inherit;
    font-size: var(--mds-type-body-sm-font-size);
    text-align: start;
    cursor: pointer;
}

.add-here__type:hover {
    border-color: var(--mds-color-action-primary-bg);
}

.add-here__type:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 1px;
}
</style>
