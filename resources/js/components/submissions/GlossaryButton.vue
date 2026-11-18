<script setup lang="ts">
/**
 * The Glossary button (M245, `R-d0bbb7bb`): every term of the form and its meaning, one tap away on every page of a
 * form that has a glossary — the guest page's header, the encode page's actions and the builder preview's bar.
 *
 * It is what makes a term in a QUESTION's wording reachable. A question's label is drawn inside its `<label for>`
 * (`MdsFormField`), which may hold no button, so a label's words are never marked; a note's marked word opens its own
 * definition in place (`NoteContent.vue`). The user chose this split in planning (2026-11-17).
 *
 * Renders nothing when the form has no glossary. Reads `ContentGlossaryKey`, which each host provides from the version
 * it shows. Every leaf is interpolated text.
 */
import { MdsButton, MdsModal } from '@meridian/design-system';
import { computed, inject, ref } from 'vue';
import type { GlossaryEntry } from '@/components/builder/glossary';
import { ContentGlossaryKey } from './note-content';

const glossary = inject(
    ContentGlossaryKey,
    computed<readonly GlossaryEntry[]>(() => []),
);

const open = ref(false);
</script>

<template>
    <template v-if="glossary.length > 0">
        <MdsButton variant="secondary" size="sm" icon-left="help" data-glossary-button @click="open = true">Glossary</MdsButton>
        <MdsModal :open="open" title="Glossary" size="lg" data-glossary-list @close="open = false">
            <dl class="glossary-list">
                <div v-for="entry in glossary" :key="entry.term" class="glossary-list__entry">
                    <dt class="glossary-list__term">{{ entry.term }}</dt>
                    <dd class="glossary-list__definition">{{ entry.definition }}</dd>
                </div>
            </dl>
        </MdsModal>
    </template>
</template>

<style scoped>
.glossary-list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.glossary-list__term {
    font-weight: 600;
    color: var(--mds-color-text-heading);
}

.glossary-list__definition {
    margin: var(--mds-space-1) 0 0;
}
</style>
