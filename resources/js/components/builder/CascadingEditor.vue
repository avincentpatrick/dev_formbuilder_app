<script setup lang="ts">
/**
 * Config sub-editor for a cascading-select field (Increment G4a). The author defines the ordered LEVELS
 * (e.g. Region → Province → City) and a flat OPTION pool, each option tagged with the level it belongs to
 * and — below the root — its PARENT option's value. The runtime filters each level's choices by the parent
 * selection; the publish gate verifies the hierarchy resolves (no dangling parent, every level populated).
 *
 * Emits a fresh array on every change so the store records one debounced history entry (same contract as
 * ChoicesEditor). Values are lenient here — completeness/integrity is enforced at publish, not per keystroke.
 *
 * ── CHOICES FROM CSV FILES (M141, `R-f69aab42`, `D95`) ──────────────────────────────────────────────────────────
 * A level can take its choices from a CSV file instead, Kobo's `select_one_from_file`: one file per level, `name` and
 * `label` columns, and below the first level a column named after the level above. The level stores the list's NAME
 * (`list`), never its rows, so the draft — and every PATCH of it — stays small however long the list is; publishing
 * turns the lists into the options. When every level names a list, the option editor gives way to their sizes. The
 * lists are read and uploaded here by form id (`ContentBlocksEditor`'s precedent), and managed in the form's settings.
 *
 * ── THE PHILIPPINE PLACES (M186, `R-70c7673a`, `D131`) ───────────────────────────────────────────────────────────
 * One button sets the four levels to the built-in places (`psgc:region` … `psgc:barangay`, `PsgcLists`), clears any
 * typed options and turns on filling in a level that has only one choice — a city outside any province is its own one
 * choice at the city level. Nothing is uploaded: publishing reads the server's newest quarter. Before a deploy has copied
 * the places in, the button says they are not on this server yet.
 */
import { computed, onMounted, ref } from 'vue';
import { MdsButton, MdsCheckbox, MdsFormField, MdsIconButton, MdsSelect, MdsTextInput } from '@meridian/design-system';
import ChoiceListUpload from '@/components/forms/ChoiceListUpload.vue';
import { describeChoiceList, listChoiceLists, type ChoiceListRow } from '@/components/forms/choice-lists';
import ChoiceFilterEditor from './ChoiceFilterEditor.vue';
import type { ChoiceFilterValue, LinkableSource, PsgcSummary } from './types';

interface CascadeLevel {
    key: string;
    label: string;
    list?: string;
    /** M186 (`R-ed7f3127`): what narrows this level's choices by what another form recorded. */
    filter?: ChoiceFilterValue | null;
}
interface CascadeOption {
    value: string;
    label: string;
    level: string;
    parent: string | null;
}

const props = defineProps<{
    levels: CascadeLevel[];
    options: CascadeOption[];
    formId?: string | null;
    /** M186: the built-in Philippine places on this server, or null before a deploy copied them in. */
    psgc?: PsgcSummary | null;
    /** M186: `config.skip_single_choice_levels`. */
    skipSingle?: boolean;
    /** M186 (`R-ed7f3127`): the forms whose shared answers may narrow a level — every level offers Show only when any fits. */
    sources?: LinkableSource[];
}>();
const emit = defineEmits<{
    'update:levels': [value: CascadeLevel[]];
    'update:options': [value: CascadeOption[]];
    /** M186: several config keys in one save — the preset sets the levels, the options and the fill-in switch together. */
    patch: [value: Record<string, unknown>];
}>();

// ── The Philippine places (M186) ────────────────────────────────────────────
type PsgcTier = keyof PsgcSummary['counts'];
const PSGC_PREFIX = 'psgc:';
const PSGC_LEVELS: { tier: PsgcTier; label: string }[] = [
    { tier: 'region', label: 'Region' },
    { tier: 'province', label: 'Province or district' },
    { tier: 'city', label: 'City or municipality' },
    { tier: 'barangay', label: 'Barangay' },
];
const psgcTierOf = (level: CascadeLevel): PsgcTier | null =>
    typeof level.list === 'string' && level.list.startsWith(PSGC_PREFIX) ? (level.list.slice(PSGC_PREFIX.length) as PsgcTier) : null;
const usesPsgc = computed(() => props.levels.some((level) => psgcTierOf(level) !== null));

function usePsgc(): void {
    emit('patch', {
        levels: PSGC_LEVELS.map(({ tier, label }) => ({ key: tier, label, list: `${PSGC_PREFIX}${tier}` })),
        options: [],
        skip_single_choice_levels: true,
    });
}
function stopPsgc(): void {
    emit('update:levels', props.levels.map(({ list: _list, ...level }) => level));
}
/** "Philippine places — Barangay, 42,010 choices (3Q 2026)" — what a level on the built-in places holds. */
function psgcLine(level: CascadeLevel): string {
    const tier = psgcTierOf(level);
    const named = PSGC_LEVELS.find((candidate) => candidate.tier === tier)?.label ?? tier;
    const count = tier && props.psgc ? `, ${props.psgc.counts[tier].toLocaleString()} choices (${props.psgc.label})` : '';
    return `Philippine places — ${named}${count}`;
}

const levelSelectOptions = computed(() => props.levels.map((l) => ({ value: l.key, label: l.label || l.key })));

// ── CSV choice lists (M141; Excel workbooks too since M184) ─────────────────
const lists = ref<ChoiceListRow[]>([]);

async function loadLists(): Promise<void> {
    if (!props.formId) return;
    try {
        lists.value = await listChoiceLists(props.formId);
    } catch {
        // The picker shows what the level already names; the file section says why an upload failed.
    }
}
onMounted(loadLists);

const listChoices = computed(() => [
    { value: '', label: 'Typed in below' },
    ...lists.value.map((list) => ({ value: list.name, label: `${list.name} — ${describeChoiceList(list)}` })),
]);
/** " — 1,234 choices" for an uploaded list, or nothing. */
function listSize(name: string | undefined): string {
    const list = lists.value.find((candidate) => candidate.name === name);
    return list ? ` — ${describeChoiceList(list)}` : '';
}
const namesAList = (level: CascadeLevel): boolean => typeof level.list === 'string' && level.list !== '';
const allFromLists = computed(() => props.levels.length > 0 && props.levels.every(namesAList));
const someFromLists = computed(() => props.levels.some(namesAList));

/** Why a level's list will not publish, said where it is chosen — or null. */
function listProblem(index: number): string | null {
    const level = props.levels[index];
    if (!level || !namesAList(level) || !props.formId || psgcTierOf(level) !== null) return null;
    const list = lists.value.find((candidate) => candidate.name === level.list);
    if (!list) return `${level.list} is not uploaded to this form.`;
    const above = props.levels[index - 1];
    if (above && !list.columns.includes(above.key.toLowerCase())) {
        return `${list.name} has no “${above.key}” column. Name the level above after the file’s column, or add the column.`;
    }
    return null;
}

// ── Show only, per level (M186, `R-ed7f3127`) ───────────────────────────────────────────────
// Offered on a level once a form shares something that could narrow it — or when it is already narrowed — so a builder
// with nothing to narrow by shows nothing more.
function narrowable(level: CascadeLevel): boolean {
    if (level.filter != null) return true;
    const psgc = typeof level.list === 'string' && level.list.startsWith(PSGC_PREFIX) ? level.list : null;
    return (props.sources ?? []).some((source) =>
        source.questions.some((question) =>
            question.field_type === 'cascading_select'
                ? (question.levels ?? []).some((other) => (psgc !== null ? other.list === psgc : other.key !== '' && other.key === level.key))
                : psgc === null,
        ),
    );
}
function setLevelFilter(index: number, filter: ChoiceFilterValue | null): void {
    emit('update:levels', props.levels.map((l, i) => {
        if (i !== index) return l;
        const { filter: _old, ...rest } = l;
        return filter === null ? rest : { ...rest, filter };
    }));
}

function onListUploaded(added: ChoiceListRow): void {
    lists.value = [...lists.value.filter((list) => list.name !== added.name), added].sort((a, b) => a.name.localeCompare(b.name));
}

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
            <div class="cascading__preset">
                <MdsButton v-if="!usesPsgc" variant="secondary" size="sm" :disabled="!psgc" @click="usePsgc">Philippine places (PSGC)</MdsButton>
                <MdsButton v-else variant="tertiary" size="sm" @click="stopPsgc">Stop using the Philippine places</MdsButton>
                <p class="cascading__hint">
                    {{
                        psgc
                            ? `Region, province or district, city or municipality, and barangay, from the Philippine Statistics Authority’s ${psgc.label} list.`
                            : 'The Philippine places are not on this server yet.'
                    }}
                </p>
            </div>
            <div class="cascading__levels">
                <div v-for="(level, i) in levels" :key="i" class="cascading__level">
                    <div class="cascading__level-row">
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
                    <p v-if="psgcTierOf(level)" class="cascading__psgc">{{ psgcLine(level) }}</p>
                    <MdsSelect
                        v-else-if="formId && (lists.length > 0 || level.list)"
                        :model-value="level.list ?? ''"
                        :options="listChoices"
                        :aria-label="`Level ${i + 1} choices from`"
                        @update:model-value="updateLevel(i, { list: $event || undefined })"
                    />
                    <p v-if="listProblem(i)" class="cascading__problem">{{ listProblem(i) }}</p>
                    <ChoiceFilterEditor
                        v-if="narrowable(level)"
                        :filter="level.filter ?? null"
                        :sources="sources ?? []"
                        :target="{ kind: 'level', key: level.key, list: level.list }"
                        :name="level.label || level.key"
                        @update:filter="setLevelFilter(i, $event)"
                    />
                </div>
                <p v-if="levels.length === 0" class="cascading__empty">No levels yet.</p>
            </div>
            <div>
                <MdsButton variant="tertiary" size="sm" icon-left="plus" @click="addLevel">Add level</MdsButton>
            </div>
            <MdsCheckbox
                :model-value="skipSingle === true"
                label="Fill in a level that has only one choice"
                @update:model-value="emit('patch', { skip_single_choice_levels: $event })"
            />
            <ChoiceListUpload
                v-if="formId && !usesPsgc"
                :form-id="formId"
                label="Choices from a CSV or Excel file"
                help="One file per level: “name” and “label” columns, and below the first level a column named after the level above. The file’s name is the list’s name; uploading it again replaces the list."
                @uploaded="onListUploaded"
            />
        </section>

        <section v-if="allFromLists" class="cascading__section">
            <h3 class="cascading__heading">Options</h3>
            <p v-if="usesPsgc" class="cascading__hint">
                Every level takes its choices from the Philippine places. Publishing writes the newest list on this server
                into the form, so the published form keeps it; publish again to pick up a later one.
            </p>
            <p v-else class="cascading__hint">
                Every level takes its choices from a CSV or Excel file. The live form loads them, and publishing checks that
                every choice’s parent is in the file above.
            </p>
            <ul class="cascading__list-sizes">
                <li v-for="level in levels" :key="level.key">
                    {{ level.label || level.key }}: {{ psgcTierOf(level) ? psgcLine(level) : `${level.list}${listSize(level.list)}` }}
                </li>
            </ul>
        </section>

        <section v-else class="cascading__section">
            <h3 class="cascading__heading">Options</h3>
            <p v-if="someFromLists" class="cascading__problem">
                Every level must take its choices from a CSV or Excel file, or none may.
            </p>
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

.cascading__level {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    min-width: 0;
}

.cascading__level-row {
    display: grid;
    grid-template-columns: 1fr 1fr 28px;
    gap: var(--mds-space-2);
    align-items: center;
}

.cascading__preset {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

.cascading__psgc {
    margin: 0;
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-sm-font-size);
}

.cascading__problem {
    margin: 0;
    color: var(--mds-color-status-danger-fg);
    font-size: var(--mds-type-body-sm-font-size);
}

.cascading__list-sizes {
    margin: 0;
    padding-left: var(--mds-space-5);
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-sm-font-size);
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
