<script setup lang="ts">
/**
 * Manual encoding page (Increment F4b) — the first Submission Pipeline channel with a UI. Renders the
 * form's published version as a fillable form (sections → fields), collects answers, and POSTs them to the
 * pipeline in one submit. Server-authoritative: no client validation beyond required affordances — the
 * pipeline (structural → integrity → semantic → persist) is the sole authority, and its per-field 422s bind
 * back to each input via `form.errors['answers.<key>']`. Assembled from shared design-system components.
 */
import { Head, Link, useForm } from '@inertiajs/vue3';
import { MdsButton, MdsCard } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import FieldInput, { type AnswerValue, type EncodeField } from '@/components/submissions/FieldInput.vue';

interface Block {
    id: string | null;
    label: string | null;
    description: string | null;
    repeatable: boolean;
    fields: EncodeField[];
}

const props = defineProps<{
    form: { id: string; title: string; description: string | null };
    version: { id: string; version_number: number };
    blocks: Block[];
}>();

// Seed one answer slot per encodable field with its empty shape (list for multi-select, null for numbers,
// "" otherwise). Note/unsupported fields carry no answer and are omitted.
const initialAnswers: Record<string, AnswerValue> = {};
for (const block of props.blocks) {
    for (const field of block.fields) {
        if (!field.supported) continue;
        if (field.field_type === 'multi_select') initialAnswers[field.key] = [];
        else if (field.field_type === 'integer' || field.field_type === 'decimal') initialAnswers[field.key] = null;
        else initialAnswers[field.key] = '';
    }
}

// Named `encodeForm` (not `form`) so it doesn't shadow the `form` prop in the template.
const encodeForm = useForm<{ answers: Record<string, AnswerValue> }>({ answers: initialAnswers });

function submit(): void {
    encodeForm.post(`/forms/${props.form.id}/submissions`, {
        preserveScroll: true,
        // The controller redirects back to this same page; reset so the encoder starts a clean next entry.
        onSuccess: () => encodeForm.reset(),
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
                    <div v-if="block.label || block.description || block.repeatable" class="encode__block-head">
                        <h2 v-if="block.label" class="encode__block-title">{{ block.label }}</h2>
                        <p v-if="block.description" class="encode__block-desc">{{ block.description }}</p>
                        <p v-if="block.repeatable" class="encode__block-note">
                            Repeat groups aren't available for manual entry yet (Phase 2).
                        </p>
                    </div>

                    <div class="encode__fields">
                        <FieldInput
                            v-for="field in block.fields"
                            :key="field.key"
                            :field="field"
                            :model-value="encodeForm.answers[field.key]"
                            :error="encodeForm.errors[`answers.${field.key}`]"
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
    font-style: italic;
}

.encode__fields {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.encode__actions {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: var(--mds-space-4);
}
</style>
