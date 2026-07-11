<script setup lang="ts">
/**
 * Manual encoding page (Increment F4b; repeat groups added in G2) — the first Submission Pipeline channel with
 * a UI. Renders the form's published version as a fillable form (sections → fields), collects answers, and
 * POSTs them to the pipeline in one submit. Server-authoritative: no client validation beyond required
 * affordances — the pipeline (structural → integrity → semantic → persist) is the sole authority, and its
 * per-field 422s bind back to each input via `form.errors['answers.<path>']`. Assembled from shared
 * design-system components.
 *
 * A repeatable section (G2) renders an add/remove-instance loop: its answers live under the SECTION key as a
 * list of per-instance field-key→value maps (the exact nested shape the G1 pipeline persists), so an instance
 * field binds to `answers[sectionKey][i][fieldKey]` and its 422 keys `answers.<sectionKey>[i].<fieldKey>`; a
 * min/max count failure keys the bare `answers.<sectionKey>`.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MdsButton, MdsCard } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import FieldInput, { type AnswerValue, type EncodeField } from '@/components/submissions/FieldInput.vue';

interface Block {
    id: string | null;
    key: string | null;
    label: string | null;
    description: string | null;
    repeatable: boolean;
    min_instances: number | null;
    max_instances: number | null;
    fields: EncodeField[];
}

type EncodeInstance = Record<string, AnswerValue>;
type EncodeAnswer = AnswerValue | EncodeInstance[];

const props = defineProps<{
    form: { id: string; title: string; description: string | null };
    version: { id: string; version_number: number };
    blocks: Block[];
}>();

// A stable client id per repeat instance (decoupled from array index) so removing a middle row never
// re-keys another row's inputs. A monotonic counter — NOT crypto.randomUUID, which throws outside a secure
// context (the tenant app is plain http on localhost in dev).
let uidSeq = 0;
const nextUid = (): string => `inst-${uidSeq++}`;
const instanceUids: Record<string, string[]> = {};

function emptyFieldValue(field: EncodeField): AnswerValue {
    if (!field.supported) {
        return null;
    }
    // A multi-select and a cascading select (Increment G4a) both hold a list of chosen values.
    if (field.field_type === 'multi_select' || field.field_type === 'cascading_select') {
        return [];
    }
    if (field.field_type === 'integer' || field.field_type === 'decimal') {
        return null;
    }
    return '';
}

function emptyInstance(block: Block): EncodeInstance {
    const instance: EncodeInstance = {};
    for (const field of block.fields) {
        if (field.supported) {
            instance[field.key] = emptyFieldValue(field);
        }
    }
    return instance;
}

// Seed one answer slot per encodable field; a repeatable block seeds its `min_instances` starter rows (so the
// required rows are present to fill). Note/unsupported fields carry no answer and are omitted.
function buildInitialAnswers(): Record<string, EncodeAnswer> {
    const answers: Record<string, EncodeAnswer> = {};
    for (const block of props.blocks) {
        if (block.repeatable && block.key !== null) {
            const starter = Math.max(block.min_instances ?? 0, 0);
            const rows = Array.from({ length: starter }, () => emptyInstance(block));
            answers[block.key] = rows;
            instanceUids[block.key] = rows.map(() => nextUid());
            continue;
        }
        for (const field of block.fields) {
            if (field.supported) {
                answers[field.key] = emptyFieldValue(field);
            }
        }
    }
    return answers;
}

// Named `encodeForm` (not `form`) so it doesn't shadow the `form` prop in the template.
const encodeForm = useForm<{ answers: Record<string, EncodeAnswer> }>({ answers: buildInitialAnswers() });

function instancesOf(block: Block): EncodeInstance[] {
    const value = block.key !== null ? encodeForm.answers[block.key] : undefined;
    // A repeatable block's slot only ever holds an instance array (seeded that way, never a flat scalar).
    return Array.isArray(value) ? (value as EncodeInstance[]) : [];
}

function uidsOf(block: Block): string[] {
    return block.key !== null ? (instanceUids[block.key] ?? []) : [];
}

function canAdd(block: Block): boolean {
    return block.max_instances === null || instancesOf(block).length < block.max_instances;
}

function addInstance(block: Block): void {
    if (block.key === null || !canAdd(block)) {
        return;
    }
    instancesOf(block).push(emptyInstance(block));
    (instanceUids[block.key] ??= []).push(nextUid());
}

function removeInstance(block: Block, index: number): void {
    if (block.key === null) {
        return;
    }
    instancesOf(block).splice(index, 1);
    instanceUids[block.key]?.splice(index, 1);
}

function boundsHint(block: Block): string | null {
    const lo = block.min_instances ?? 0;
    const hi = block.max_instances;
    if (lo > 0 && hi !== null) {
        return `Add ${lo} to ${hi}.`;
    }
    if (lo > 0) {
        return `Add at least ${lo}.`;
    }
    if (hi !== null) {
        return `Add up to ${hi}.`;
    }
    return null;
}

function instanceLegend(block: Block, index: number): string {
    return `${block.label ?? 'Entry'} ${index + 1}`;
}

// A flat field slot only ever holds a scalar AnswerValue (never an instance array); the cast narrows the
// union answer-map value type for the template binding.
function flatValue(fieldKey: string): AnswerValue {
    return encodeForm.answers[fieldKey] as AnswerValue;
}

function fieldError(fieldKey: string): string | undefined {
    return encodeForm.errors[`answers.${fieldKey}`];
}

function instanceError(block: Block, index: number, fieldKey: string): string | undefined {
    return encodeForm.errors[`answers.${block.key}[${index}].${fieldKey}`];
}

function countError(block: Block): string | undefined {
    return block.key !== null ? encodeForm.errors[`answers.${block.key}`] : undefined;
}

function submit(): void {
    encodeForm.post(`/forms/${props.form.id}/submissions`, {
        preserveScroll: true,
        // The controller redirects back to this same page; reset so the encoder starts a clean next entry.
        onSuccess: () => {
            encodeForm.defaults({ answers: buildInitialAnswers() });
            encodeForm.reset();
        },
    });
}
</script>

<template>
    <div class="encode">
        <Head :title="`Encode — ${form.title}`" />

        <PageHeader title="New submission" icon="submissions">
            <template #breadcrumbs>
                <Link href="/forms" class="encode__crumb">← Forms</Link>
            </template>
            <template #actions>
                <Link href="/forms" class="encode__cancel">Cancel</Link>
            </template>
        </PageHeader>

        <p class="encode__intro">
            Encoding a response for <strong>{{ form.title }}</strong> (v{{ version.version_number }}).
        </p>

        <form class="encode__form" @submit.prevent="submit">
            <MdsCard v-for="(block, bi) in blocks" :key="block.id ?? `ungrouped-${bi}`">
                <div class="encode__block">
                    <div v-if="block.label || block.description || boundsHint(block)" class="encode__block-head">
                        <h2 v-if="block.label" class="encode__block-title">{{ block.label }}</h2>
                        <p v-if="block.description" class="encode__block-desc">{{ block.description }}</p>
                        <p v-if="boundsHint(block)" class="encode__block-note">{{ boundsHint(block) }}</p>
                    </div>

                    <!-- Repeatable section: an add/remove-instance loop over the member fields (G2). -->
                    <template v-if="block.repeatable && block.key !== null">
                        <p v-if="instancesOf(block).length === 0" class="encode__empty">Nothing added yet.</p>

                        <ol v-else class="encode__instances">
                            <li v-for="(uid, index) in uidsOf(block)" :key="uid">
                                <fieldset class="encode__instance">
                                    <legend class="encode__instance-legend">{{ instanceLegend(block, index) }}</legend>
                                    <div class="encode__fields">
                                        <FieldInput
                                            v-for="field in block.fields"
                                            :key="field.key"
                                            :field="field"
                                            :model-value="instancesOf(block)[index][field.key]"
                                            :error="instanceError(block, index, field.key)"
                                            @update:model-value="instancesOf(block)[index][field.key] = $event"
                                        />
                                    </div>
                                    <div class="encode__instance-actions">
                                        <MdsButton
                                            type="button"
                                            variant="tertiary"
                                            size="sm"
                                            icon-left="trash"
                                            :aria-label="`Remove ${instanceLegend(block, index)}`"
                                            @click="removeInstance(block, index)"
                                        >
                                            Remove
                                        </MdsButton>
                                    </div>
                                </fieldset>
                            </li>
                        </ol>

                        <p v-if="countError(block)" class="encode__count-error" role="alert">
                            {{ countError(block) }}
                        </p>

                        <div class="encode__add">
                            <MdsButton
                                type="button"
                                variant="secondary"
                                icon-left="plus"
                                :disabled="!canAdd(block)"
                                @click="addInstance(block)"
                            >
                                Add {{ block.label ?? 'entry' }}
                            </MdsButton>
                            <span v-if="!canAdd(block)" class="encode__max-note">
                                Maximum of {{ block.max_instances }} reached.
                            </span>
                        </div>
                    </template>

                    <!-- Flat section (or the lead section-less block): fields render directly. -->
                    <div v-else class="encode__fields">
                        <FieldInput
                            v-for="field in block.fields"
                            :key="field.key"
                            :field="field"
                            :model-value="flatValue(field.key)"
                            :error="fieldError(field.key)"
                            @update:model-value="encodeForm.answers[field.key] = $event"
                        />
                    </div>
                </div>
            </MdsCard>

            <div class="encode__actions">
                <Link href="/forms" class="encode__cancel">Cancel</Link>
                <MdsButton type="submit" variant="primary" icon-left="check" :loading="encodeForm.processing">
                    Submit response
                </MdsButton>
            </div>
        </form>
    </div>
</template>

<style scoped>
.encode {
    max-width: 720px;
}

.encode__crumb,
.encode__cancel {
    color: var(--mds-color-action-primary-fg);
    font-size: var(--mds-type-body-sm-font-size);
    text-decoration: none;
}

.encode__crumb:hover,
.encode__cancel:hover {
    text-decoration: underline;
}

.encode__crumb:focus-visible,
.encode__cancel:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.encode__intro {
    margin: 0 0 var(--mds-space-6);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-secondary);
}

.encode__form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.encode__block {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.encode__block-head {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.encode__block-title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.encode__block-desc {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.encode__block-note {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.encode__fields {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.encode__empty {
    margin: 0;
    padding: var(--mds-space-3) var(--mds-space-4);
    border: 1px dashed var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.encode__instances {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.encode__instance {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    margin: 0;
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
    min-width: 0;
}

.encode__instance-legend {
    padding: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.encode__instance-actions {
    display: flex;
    justify-content: flex-end;
}

.encode__count-error {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-danger-text);
}

.encode__add {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    flex-wrap: wrap;
}

.encode__max-note {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.encode__actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--mds-space-4);
}
</style>
