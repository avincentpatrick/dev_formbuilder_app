<script setup lang="ts">
// The data explorer's builder (M235, ADR-0025): pick a data source, say what to group and what to count,
// and watch the chart redraw. Rendered inside the persistent AppLayout (assigned in app.ts).
//
// ── One declaration, and the server draws it ────────────────────────────────────────────────────────────
// Everything on the left edits ONE object, `definition` — the same shape a saved chart stores. Every change
// to it is sent to `/explore/query` and the answer is drawn on the right. The page computes nothing: it does
// not aggregate, sort or fold, so what a reader sees here is what a tile on the board will show.
//
// ── A fetch, not a visit ────────────────────────────────────────────────────────────────────────────────
// `/analytics` re-fetches by Inertia partial reload because its whole declaration lives in the URL. A
// builder is twiddled dozens of times a minute and its declaration is a nested document, so the preview is
// a JSON fetch. Saving IS a visit, like every other write in the application.
//
// ── Stale answers are dropped ───────────────────────────────────────────────────────────────────────────
// Two changes in quick succession send two requests, and the first can arrive last. Each run takes a
// number and only the newest is allowed to write the result; without that the chart can show an answer to
// a question the controls no longer ask.
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsCard,
    MdsEmptyState,
    MdsFormField,
    MdsIconButton,
    MdsModal,
    MdsNumberInput,
    MdsSelect,
    MdsSwitch,
    MdsTextInput,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import ExploreFilters from '@/components/explore/ExploreFilters.vue';
import ExploreResult from '@/components/explore/ExploreResult.vue';
import ExploreSaveModal from '@/components/explore/ExploreSaveModal.vue';
import { fetchFields, runQuery } from '@/components/explore/exploreClient';
import {
    dropUnknownFields,
    fieldGroups,
    fieldsForAggregate,
    fitToChart,
    incompleteReason,
    isGroupable,
    isoDate,
    isTemporal,
    newDefinition,
    rangeEnding,
    sameDefinition,
    toPayload,
} from '@/components/explore/definition';
import type {
    Aggregate,
    Bucket,
    BuilderOptions,
    ChartType,
    Definition,
    Field,
    Filter,
    Option,
    Refusal,
    Result,
    SavedChart,
    Sort,
    Source,
} from '@/components/explore/types';

const props = defineProps<{
    chart: SavedChart | null;
    sources: Source[];
    forms: Option[];
    options: BuilderOptions;
}>();

const today = isoDate(new Date());

/** The reader's own zone when the server knows it, so a new chart groups by THEIR day. */
function ownTimezone(): string {
    const zone = Intl.DateTimeFormat().resolvedOptions().timeZone;

    return props.options.timezones.includes(zone) ? zone : 'UTC';
}

function initial(): Definition {
    if (props.chart !== null) return JSON.parse(JSON.stringify(props.chart.definition)) as Definition;

    return newDefinition(props.sources[0], ownTimezone(), today);
}

const definition = ref<Definition>(initial());
const formFields = ref<Field[] | null>(null);
const formNotice = ref<string | null>(null);

const result = ref<Result | null>(null);
const refusal = ref<Refusal | null>(null);
const prompt = ref<string | null>(null);
const loading = ref(false);
const showTable = ref(false);

const saveOpen = ref(false);
const saveMode = ref<'create' | 'copy' | 'details'>('create');
const deleteOpen = ref(false);
const saving = ref(false);
const saveError = ref<string | null>(null);

const source = computed(() => props.sources.find((s) => s.key === definition.value.source));
const fields = computed<Field[]>(() => {
    if (source.value === undefined) return [];

    return source.value.requires_form && formFields.value !== null ? formFields.value : source.value.fields;
});
const fieldByKey = computed(() => new Map(fields.value.map((field) => [field.key, field])));
const chartOption = computed(() => props.options.charts.find((c) => c.value === definition.value.chart) ?? props.options.charts[0]);

const sourceOptions = computed(() => props.sources.map((s) => ({ value: s.key, label: s.label })));
const chartOptions = computed(() => props.options.charts.map((c) => ({ value: c.value, label: c.label })));
const timezoneOptions = computed(() => props.options.timezones.map((zone) => ({ value: zone, label: zone })));
const groupByGroups = computed(() => fieldGroups(fields.value, isGroupable));
const splitGroups = computed(() =>
    fieldGroups(fields.value, (field) => isGroupable(field) && field.key !== definition.value.dimensions[0]?.field),
);

const rangeFieldLabel = computed(() => fieldByKey.value.get(source.value?.range_field ?? '')?.label ?? 'Date');
const canSplit = computed(() => chartOption.value.max_dimensions >= 2 && definition.value.dimensions.length >= 1);
const maxMeasures = computed(() =>
    definition.value.dimensions.length === 2 ? chartOption.value.max_measures_split : chartOption.value.max_measures,
);
const firstIsTimeline = computed(() => definition.value.dimensions[0]?.bucket != null);

const title = computed(() => props.chart?.name ?? 'Untitled chart');
const isDirty = computed(() => props.chart !== null && !sameDefinition(definition.value, props.chart.definition));

// ── Editing the declaration ─────────────────────────────────────────────────────────────────────────────

function patch(next: Partial<Definition>): void {
    definition.value = { ...definition.value, ...next };
}

function setSource(key: string): void {
    const next = props.sources.find((s) => s.key === key);

    if (next === undefined) return;

    formFields.value = null;
    formNotice.value = null;
    definition.value = newDefinition(next, definition.value.timezone, today);
}

async function setForm(formId: string): Promise<void> {
    patch({ form_id: formId === '' ? null : formId });
    await loadFormFields();
}

/** A form's questions arrive by fetch; whatever the chart named that this form does not have is dropped. */
async function loadFormFields(): Promise<void> {
    const formId = definition.value.form_id;

    formFields.value = null;
    formNotice.value = null;

    if (source.value === undefined || !source.value.requires_form || formId === null) return;

    const { payload, message } = await fetchFields(source.value.key, formId);

    // The reader may have moved on to another form while this one was loading.
    if (definition.value.form_id !== formId) return;

    if (payload === null) {
        formNotice.value = message;

        return;
    }

    formFields.value = payload.fields;
    definition.value = dropUnknownFields(definition.value, payload.fields);
}

function setChart(type: string): void {
    const option = props.options.charts.find((c) => c.value === (type as ChartType));

    if (option !== undefined) definition.value = fitToChart(definition.value, option, fields.value);
}

/** A date needs a period to be grouped into; month is the one that reads at any range. */
function dimensionFor(fieldKey: string): { field: string; bucket: Bucket | null } {
    const field = fieldByKey.value.get(fieldKey);

    return { field: fieldKey, bucket: field !== undefined && isTemporal(field.type) ? 'month' : null };
}

function setGroupBy(fieldKey: string): void {
    const rest = definition.value.dimensions.slice(1).filter((d) => d.field !== fieldKey);

    patch({ dimensions: [dimensionFor(fieldKey), ...rest] });
}

function setSplit(fieldKey: string): void {
    const first = definition.value.dimensions.slice(0, 1);

    if (fieldKey === '') {
        patch({ dimensions: first });

        return;
    }

    // Split by a second field and the chart shows one value: the second field supplies the series.
    patch({ dimensions: [...first, dimensionFor(fieldKey)], measures: definition.value.measures.slice(0, Math.max(1, chartOption.value.max_measures_split)) });
}

function setBucket(index: number, bucket: string): void {
    patch({
        dimensions: definition.value.dimensions.map((d, i) => (i === index ? { ...d, bucket: bucket as Bucket } : d)),
    });
}

function bucketOptionsFor(fieldKey: string): { value: string; label: string }[] {
    const type = fieldByKey.value.get(fieldKey)?.type;

    // A calendar date an answer holds has no time of day.
    return props.options.buckets.filter((b) => type !== 'date' || b.value !== 'hour');
}

function setAggregate(index: number, aggregate: string): void {
    const next = aggregate as Aggregate;
    const candidates = fieldsForAggregate(next, fields.value);
    const current = definition.value.measures[index].field;
    const field = next === 'count' ? null : candidates.some((f) => f.key === current) ? current : (candidates[0]?.key ?? null);

    patch({ measures: definition.value.measures.map((m, i) => (i === index ? { aggregate: next, field } : m)) });
}

function setMeasureField(index: number, fieldKey: string): void {
    patch({ measures: definition.value.measures.map((m, i) => (i === index ? { ...m, field: fieldKey } : m)) });
}

function addMeasure(): void {
    const numeric = fields.value.find((f) => f.type === 'number');

    patch({
        measures: [
            ...definition.value.measures,
            numeric === undefined ? { aggregate: 'count', field: null } : { aggregate: 'avg', field: numeric.key },
        ],
    });
}

function removeMeasure(index: number): void {
    patch({ measures: definition.value.measures.filter((_, i) => i !== index) });
}

function aggregateOptions(): { value: string; label: string; disabled?: boolean }[] {
    return props.options.aggregates.map((a) => ({
        value: a.value,
        label: a.label,
        // A sum needs a number: on a source with none, the choice is shown and cannot be taken.
        disabled: a.needs_field && fieldsForAggregate(a.value, fields.value).length === 0,
    }));
}

function measureFieldGroups(aggregate: Aggregate): { label: string; options: { value: string; label: string }[] }[] {
    const allowed = new Set(fieldsForAggregate(aggregate, fields.value).map((f) => f.key));

    return fieldGroups(fields.value, (field) => allowed.has(field.key));
}

function setRange(days: number): void {
    patch(rangeEnding(today, days));
}

// ── Running it ──────────────────────────────────────────────────────────────────────────────────────────

let runNumber = 0;
let timer: ReturnType<typeof setTimeout> | undefined;

async function run(): Promise<void> {
    const mine = ++runNumber;

    // A saved chart over a source this reader is not offered is still sent: the server's refusal is the
    // sentence that explains it, and "choose a data source" would not be true.
    const waitingOn = source.value === undefined && props.chart !== null ? null : incompleteReason(definition.value, source.value, fields.value);

    if (waitingOn !== null) {
        prompt.value = waitingOn;
        result.value = null;
        refusal.value = null;
        loading.value = false;

        return;
    }

    prompt.value = null;
    loading.value = true;

    const payload = await runQuery(toPayload(definition.value));

    if (mine !== runNumber) return;

    result.value = payload.result;
    refusal.value = payload.refusal;
    loading.value = false;
}

function schedule(): void {
    clearTimeout(timer);
    timer = setTimeout(() => void run(), 250);
}

watch(definition, schedule, { deep: true });

onBeforeUnmount(() => clearTimeout(timer));

// A saved chart on a form opens with that form's questions, then draws.
void (async () => {
    if (definition.value.form_id !== null) await loadFormFields();

    await run();
})();

// ── Saving ──────────────────────────────────────────────────────────────────────────────────────────────

function openSave(mode: 'create' | 'copy' | 'details'): void {
    saveMode.value = mode;
    saveOpen.value = true;
}

function saveChanges(): void {
    if (props.chart === null) return;

    saveError.value = null;

    router.patch(
        `/explore/charts/${props.chart.id}`,
        { definition: toPayload(definition.value) },
        {
            preserveScroll: true,
            preserveState: true,
            onStart: () => {
                saving.value = true;
            },
            onError: (errors) => {
                saveError.value = errors.definition ?? errors.name ?? 'The chart could not be saved.';
            },
            onFinish: () => {
                saving.value = false;
            },
        },
    );
}

function destroy(): void {
    if (props.chart === null) return;

    router.delete(`/explore/charts/${props.chart.id}`, { onFinish: () => (deleteOpen.value = false) });
}
</script>

<template>
    <div class="explore-builder">
        <Head :title="chart === null ? 'New chart' : chart.name" />

        <PageHeader :title="title" icon="chart-pie">
            <template #actions>
                <Link href="/explore">
                    <MdsButton variant="tertiary" icon-left="chevron-left">All charts</MdsButton>
                </Link>

                <template v-if="chart === null">
                    <MdsButton variant="primary" :disabled="result === null" @click="openSave('create')">Save chart</MdsButton>
                </template>

                <template v-else-if="chart.can.update">
                    <MdsButton variant="tertiary" icon-left="edit" @click="openSave('details')">Name and sharing</MdsButton>
                    <MdsButton v-if="chart.can.delete" variant="tertiary" icon-left="trash" @click="deleteOpen = true">
                        Delete
                    </MdsButton>
                    <MdsButton variant="primary" :disabled="!isDirty || result === null" :loading="saving" @click="saveChanges">
                        Save changes
                    </MdsButton>
                </template>

                <MdsButton v-else variant="primary" icon-left="copy" :disabled="result === null" @click="openSave('copy')">
                    Save a copy
                </MdsButton>
            </template>
        </PageHeader>

        <p v-if="chart !== null && !chart.is_mine" class="builder__owner">
            <MdsBadge variant="neutral" :label="`Shared by ${chart.owner ?? 'a colleague'}`" />
            You can change this chart to look at the data another way. Your changes are not saved to it; save
            a copy to keep them.
        </p>
        <p v-if="saveError !== null" class="builder__error" role="alert">{{ saveError }}</p>

        <div class="builder">
            <div class="builder__controls">
                <MdsCard>
                    <template #header><h2 class="builder__heading">Data</h2></template>

                    <div class="builder__stack">
                        <MdsFormField label="Data source" input-id="explore-source" :help="source?.description">
                            <MdsSelect
                                id="explore-source"
                                :model-value="source === undefined ? '' : definition.source"
                                :options="sourceOptions"
                                placeholder="Choose a data source"
                                @update:model-value="setSource"
                            />
                        </MdsFormField>

                        <MdsFormField
                            v-if="source?.requires_form"
                            label="Form"
                            input-id="explore-form"
                            :error="formNotice ?? undefined"
                            :help="forms.length === 0 ? 'No published form you can open has answers to chart yet.' : undefined"
                        >
                            <MdsSelect
                                id="explore-form"
                                :model-value="definition.form_id ?? ''"
                                :options="forms"
                                placeholder="Choose a form"
                                :disabled="forms.length === 0"
                                @update:model-value="setForm"
                            />
                        </MdsFormField>
                    </div>
                </MdsCard>

                <MdsCard>
                    <template #header><h2 class="builder__heading">Chart</h2></template>

                    <div class="builder__stack">
                        <MdsFormField label="Chart type" input-id="explore-chart">
                            <MdsSelect id="explore-chart" :model-value="definition.chart" :options="chartOptions" @update:model-value="setChart" />
                        </MdsFormField>

                        <template v-if="chartOption.max_dimensions >= 1">
                            <MdsFormField label="Group by" input-id="explore-group">
                                <MdsSelect
                                    id="explore-group"
                                    :model-value="definition.dimensions[0]?.field ?? ''"
                                    :groups="groupByGroups"
                                    placeholder="Choose a field"
                                    @update:model-value="setGroupBy"
                                />
                            </MdsFormField>

                            <MdsFormField v-if="definition.dimensions[0]?.bucket != null" label="Group dates by" input-id="explore-bucket">
                                <MdsSelect
                                    id="explore-bucket"
                                    :model-value="definition.dimensions[0].bucket"
                                    :options="bucketOptionsFor(definition.dimensions[0].field)"
                                    @update:model-value="(v: string) => setBucket(0, v)"
                                />
                            </MdsFormField>

                            <template v-if="canSplit">
                                <MdsFormField
                                    label="Split by"
                                    input-id="explore-split"
                                    help="A second field. Each of its five largest groups becomes its own series."
                                >
                                    <MdsSelect
                                        id="explore-split"
                                        :model-value="definition.dimensions[1]?.field ?? ''"
                                        :options="[{ value: '', label: 'Nothing' }]"
                                        :groups="splitGroups"
                                        @update:model-value="setSplit"
                                    />
                                </MdsFormField>

                                <MdsFormField v-if="definition.dimensions[1]?.bucket != null" label="Split dates by" input-id="explore-split-bucket">
                                    <MdsSelect
                                        id="explore-split-bucket"
                                        :model-value="definition.dimensions[1].bucket"
                                        :options="bucketOptionsFor(definition.dimensions[1].field)"
                                        @update:model-value="(v: string) => setBucket(1, v)"
                                    />
                                </MdsFormField>
                            </template>
                        </template>

                        <fieldset v-for="(measure, index) in definition.measures" :key="index" class="builder__measure">
                            <legend class="builder__legend">{{ definition.measures.length > 1 ? `Value ${index + 1}` : 'Value' }}</legend>

                            <div class="builder__measure-row" :class="{ 'builder__measure-row--solo': definition.measures.length === 1 }">
                                <MdsSelect
                                    :model-value="measure.aggregate"
                                    :options="aggregateOptions()"
                                    :aria-label="`Value ${index + 1}: what to compute`"
                                    @update:model-value="(v: string) => setAggregate(index, v)"
                                />
                                <MdsIconButton
                                    v-if="definition.measures.length > 1"
                                    icon="trash"
                                    :label="`Remove value ${index + 1}`"
                                    size="sm"
                                    @click="removeMeasure(index)"
                                />
                            </div>

                            <MdsSelect
                                v-if="measure.aggregate !== 'count'"
                                :model-value="measure.field ?? ''"
                                :groups="measureFieldGroups(measure.aggregate)"
                                placeholder="Choose a field"
                                :aria-label="`Value ${index + 1}: of which field`"
                                @update:model-value="(v: string) => setMeasureField(index, v)"
                            />
                        </fieldset>

                        <MdsButton
                            v-if="definition.measures.length < maxMeasures"
                            class="builder__add"
                            variant="tertiary"
                            size="sm"
                            icon-left="plus"
                            @click="addMeasure"
                        >
                            Add a value
                        </MdsButton>

                        <div v-if="definition.dimensions.length > 0 && !firstIsTimeline" class="builder__pair">
                            <MdsFormField label="Order" input-id="explore-sort">
                                <MdsSelect
                                    id="explore-sort"
                                    :model-value="definition.sort"
                                    :options="options.sorts"
                                    @update:model-value="(v: string) => patch({ sort: v as Sort })"
                                />
                            </MdsFormField>

                            <MdsFormField label="Groups shown" input-id="explore-limit" help="The rest fold into “Other”.">
                                <MdsNumberInput
                                    id="explore-limit"
                                    :model-value="definition.limit"
                                    :min="1"
                                    :max="definition.chart === 'donut' ? 5 : options.limits.max_limit"
                                    @update:model-value="(v: number | null) => patch({ limit: Math.min(Math.max(v ?? 1, 1), definition.chart === 'donut' ? 5 : options.limits.max_limit) })"
                                />
                            </MdsFormField>
                        </div>
                    </div>
                </MdsCard>

                <MdsCard>
                    <template #header><h2 class="builder__heading">Filters</h2></template>

                    <ExploreFilters
                        :filters="definition.filters"
                        :fields="fields"
                        :operators="options.operators"
                        :max="options.limits.max_filters"
                        @change="(filters: Filter[]) => patch({ filters })"
                    />
                </MdsCard>

                <MdsCard>
                    <template #header><h2 class="builder__heading">Date range</h2></template>

                    <div class="builder__stack">
                        <p class="builder__hint">Counts what was {{ rangeFieldLabel.toLowerCase() }} between these two days.</p>

                        <div class="builder__pair">
                            <MdsFormField label="From" input-id="explore-from">
                                <MdsTextInput id="explore-from" type="date" :model-value="definition.from" @update:model-value="(v: string) => patch({ from: v })" />
                            </MdsFormField>
                            <MdsFormField label="To" input-id="explore-to">
                                <MdsTextInput id="explore-to" type="date" :model-value="definition.to" @update:model-value="(v: string) => patch({ to: v })" />
                            </MdsFormField>
                        </div>

                        <div class="builder__presets" role="group" aria-label="Quick ranges">
                            <MdsButton variant="tertiary" size="sm" @click="setRange(30)">Last 30 days</MdsButton>
                            <MdsButton variant="tertiary" size="sm" @click="setRange(90)">Last 90 days</MdsButton>
                            <MdsButton variant="tertiary" size="sm" @click="setRange(365)">Last year</MdsButton>
                        </div>

                        <MdsFormField label="Time zone" input-id="explore-timezone" help="Days, weeks and months are cut in this zone.">
                            <MdsSelect
                                id="explore-timezone"
                                :model-value="definition.timezone"
                                :options="timezoneOptions"
                                @update:model-value="(v: string) => patch({ timezone: v })"
                            />
                        </MdsFormField>
                    </div>
                </MdsCard>
            </div>

            <MdsCard class="builder__preview">
                <template #header>
                    <div class="builder__preview-header">
                        <h2 class="builder__heading">Preview</h2>
                        <MdsSwitch
                            v-if="result !== null && result.chart !== 'table' && result.chart !== 'number'"
                            v-model="showTable"
                            label="Show the numbers"
                        />
                    </div>
                </template>

                <MdsEmptyState v-if="prompt !== null" illustration="search" headline="Almost there" :description="prompt" />

                <ExploreResult v-else :result="result" :refusal="refusal" :loading="loading" :title="title" :show-table="showTable" />
            </MdsCard>
        </div>

        <ExploreSaveModal v-model:open="saveOpen" :mode="saveMode" :chart="chart" :definition="definition" />

        <MdsModal :open="deleteOpen" title="Delete this chart" @close="deleteOpen = false" @update:open="(v: boolean) => (deleteOpen = v)">
            <p class="builder__prose">
                “{{ chart?.name }}” will be removed from your board<span v-if="chart?.is_shared"> and from your colleagues’</span>.
                The data it charts is not touched.
            </p>

            <template #actions>
                <MdsButton variant="tertiary" @click="deleteOpen = false">Cancel</MdsButton>
                <MdsButton variant="destructive" icon-left="trash" @click="destroy">Delete chart</MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
/* The controls keep a readable width and the preview takes the rest. `minmax(0, …)` on both tracks, so a
   visible data table with unbreakable cells scrolls inside its own wrapper and cannot widen the page. */
.builder {
    display: grid;
    grid-template-columns: minmax(0, 22rem) minmax(0, 1fr);
    gap: var(--mds-space-4);
    align-items: start;
}

.builder__controls {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    min-width: 0;
}

.builder__preview {
    min-width: 0;
}

.builder__preview-header {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2) var(--mds-space-4);
}

.builder__heading {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.builder__stack {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.builder__pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--mds-space-3);
    align-items: start;
}

.builder__measure {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}

.builder__legend {
    padding: 0;
    margin-bottom: var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: 600;
    color: var(--mds-color-text-heading);
}

.builder__measure-row {
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    align-items: center;
    gap: var(--mds-space-2);
}

/* With one value there is no remove button, and an empty second track would still cost its gap. */
.builder__measure-row--solo {
    grid-template-columns: minmax(0, 1fr);
}

.builder__add {
    align-self: flex-start;
}

.builder__presets {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}

.builder__hint,
.builder__owner {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.builder__owner {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    margin-bottom: var(--mds-space-4);
}

.builder__error {
    margin: 0 0 var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-danger-text);
}

.builder__prose {
    margin: 0;
}

@media (max-width: 1023px) {
    .builder {
        grid-template-columns: minmax(0, 1fr);
    }

    /* On a narrow screen the chart comes first: it is what every control below is changing. */
    .builder__preview {
        order: -1;
    }
}
</style>
