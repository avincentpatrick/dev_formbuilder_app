<script setup lang="ts">
/**
 * Config sub-editor for a cascading-select field (Increment G4a). The author defines the ordered LEVELS
 * (e.g. Region → Province → City) and a flat OPTION pool, each option tagged with the level it belongs to
 * and — below the root — its PARENT option's value. The runtime filters each level's choices by the parent
 * selection; the publish gate verifies the hierarchy resolves (no dangling parent, every level populated).
 *
 * Emits a fresh array on every change so the store records one debounced history entry (same contract as
 * ChoicesEditor). Values are lenient here — completeness/integrity is enforced at publish, not per keystroke.
 */
import { computed } from 'vue';
import { MdsButton, MdsIconButton, MdsSelect, MdsTextInput } from '@meridian/design-system';

interface CascadeLevel {
    key: string;
    label: string;
}
interface CascadeOption {
    value: string;
    label: string;
    level: string;
    parent: string | null;
}

const props = defineProps<{ levels: CascadeLevel[]; options: CascadeOption[] }>();
const emit = defineEmits<{
    'update:levels': [value: CascadeLevel[]];
    'update:options': [value: CascadeOption[]];
}>();

const levelSelectOptions = computed(() => props.levels.map((l) => ({ value: l.key, label: l.label || l.key })));

function levelIndexOf(levelKey: string): number {
    return props.levels.findIndex((l) => l.key === levelKey);
}

/** The parent-value choices for an option: every option one level shallower. Root/unknown level → none. */
function parentChoicesFor(option: CascadeOption): { value: string; label: string }[] {
    const index = levelIndexOf(option.level);
    if (index <= 0) return [];
    const parentLevelKey = props.levels[index - 1]?.key;
    return props.options
        .filter((o) => o.level === parentLevelKey)
        .map((o) => ({ value: o.value, label: `${o.label || o.value} (${o.value})` }));
}

// ── Levels ──────────────────────────────────────────────────────────────────
function updateLevel(index: number, patch: Partial<CascadeLevel>): void {
    emit('update:levels', props.levels.map((l, i) => (i === index ? { ...l, ...patch } : l)));
}
function addLevel(): void {
    const existing = new Set(props.levels.map((l) => l.key));
    let n = props.levels.length + 1;
    while (existing.has(`level_${n}`)) n++;
    emit('update:levels', [...props.levels, { key: `level_${n}`, label: `Level ${n}` }]);
}
function removeLevel(index: number): void {
    emit('update:levels', props.levels.filter((_, i) => i !== index));
}

// ── Options ─────────────────────────────────────────────────────────────────
function updateOption(index: number, patch: Partial<CascadeOption>): void {
    emit('update:options', props.options.map((o, i) => (i === index ? { ...o, ...patch } : o)));
}
function addOption(): void {
    const existing = new Set(props.options.map((o) => o.value));
    let n = props.options.length + 1;
    while (existing.has(`option_${n}`)) n++;
    const level = props.levels[0]?.key ?? '';
    emit('update:options', [...props.options, { value: `option_${n}`, label: `Option ${n}`, level, parent: null }]);
}
function removeOption(index: number): void {
    emit('update:options', props.options.filter((_, i) => i !== index));
}
</script>

<template>
    <div class="cascading">
        <section class="cascading__section">
            <h3 class="cascading__heading">Levels</h3>
            <p class="cascading__hint">Ordered from broadest to most specific (e.g. Region, then Province).</p>
            <div class="cascading__levels">
                <div v-for="(level, i) in levels" :key="i" class="cascading__level-row">
                    <MdsTextInput
                        :model-value="level.key"
                        :aria-label="`Level ${i + 1} key`"
                        @update:model-value="updateLevel(i, { key: $event })"
                    />
                    <MdsTextInput
                        :model-value="level.label"
                        :aria-label="`Level ${i + 1} label`"
                        @update:model-value="updateLevel(i, { label: $event })"
                    />
                    <MdsIconButton
                        icon="trash"
                        :label="`Remove level ${i + 1}`"
                        variant="danger"
                        size="sm"
                        @click="removeLevel(i)"
                    />
                </div>
                <p v-if="levels.length === 0" class="cascading__empty">No levels yet.</p>
            </div>
            <div>
                <MdsButton variant="tertiary" size="sm" icon-left="plus" @click="addLevel">Add level</MdsButton>
            </div>
        </section>

        <section class="cascading__section">
            <h3 class="cascading__heading">Options</h3>
            <p class="cascading__hint">Tag each option with its level and (below the top level) its parent option.</p>
            <div class="cascading__options">
                <div v-for="(option, i) in options" :key="i" class="cascading__option">
                    <div class="cascading__option-grid">
                        <MdsTextInput
                            :model-value="option.value"
                            :aria-label="`Option ${i + 1} value`"
                            @update:model-value="updateOption(i, { value: $event })"
                        />
                        <MdsTextInput
                            :model-value="option.label"
                            :aria-label="`Option ${i + 1} label`"
                            @update:model-value="updateOption(i, { label: $event })"
                        />
                        <MdsSelect
                            :model-value="option.level"
                            :options="levelSelectOptions"
                            placeholder="Level"
                            :aria-label="`Option ${i + 1} level`"
                            @update:model-value="updateOption(i, { level: $event, parent: null })"
                        />
                        <MdsSelect
                            :model-value="option.parent ?? ''"
                            :options="parentChoicesFor(option)"
                            placeholder="No parent (top level)"
                            :disabled="parentChoicesFor(option).length === 0"
                            :aria-label="`Option ${i + 1} parent`"
                            @update:model-value="updateOption(i, { parent: $event || null })"
                        />
                    </div>
                    <MdsIconButton
                        icon="trash"
                        :label="`Remove option ${i + 1}`"
                        variant="danger"
                        size="sm"
                        @click="removeOption(i)"
                    />
                </div>
                <p v-if="options.length === 0" class="cascading__empty">No options yet.</p>
            </div>
            <div>
                <MdsButton variant="tertiary" size="sm" icon-left="plus" @click="addOption">Add option</MdsButton>
            </div>
        </section>
    </div>
</template>

<style scoped>
.cascading {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
    min-width: 0;
}

.cascading__section {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.cascading__heading {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-body);
}

.cascading__hint {
    margin: 0;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

.cascading__levels,
.cascading__options {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
}

.cascading__level-row {
    display: grid;
    grid-template-columns: 1fr 1fr 28px;
    gap: var(--mds-space-2);
    align-items: center;
}

.cascading__option {
    display: grid;
    grid-template-columns: 1fr 28px;
    gap: var(--mds-space-2);
    align-items: start;
    padding: var(--mds-space-2);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    min-width: 0;
}

.cascading__option-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--mds-space-2);
    min-width: 0;
}

.cascading__empty {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    font-style: italic;
}
</style>
