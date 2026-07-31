<script setup lang="ts">
/**
 * Config sub-editor for `hidden` fields (Increment H7) — the "Prefill" tab. A hidden field is the one type
 * nobody fills in, so where its value comes from is the only thing there is to configure about it.
 *
 * Controlled + stateless (the {@link GeoEditor} shape): props come from `field.config`, every change emits a
 * fresh value the parent writes back through `setConfig`/`setField` (one debounced history entry). The keys
 * written here — `prefill_source` / `url_param` — are the exact snake_case keys read by
 * `App\Enums\PrefillSource` (PHP) and `lib/prefill.ts` (TS runtime); the fixed literal reuses the existing
 * `form_fields.default_value` column rather than minting a second one.
 *
 * Lenient PATCH validation lives in `UpdateFieldRequest::configRules()`. The rules that actually matter —
 * a usable parameter name, and that a hidden field can never be required or carry a validation rule — are
 * enforced at PUBLISH by `StructuralValidationGate`, so a mid-edit blur never 422s.
 */
import { computed } from 'vue';
import { MdsFormField, MdsSelect, MdsTextInput } from '@meridian/design-system';

const props = defineProps<{
    fieldKey: string;
    source: string | null;
    urlParam: string | null;
    defaultValue: string | null;
}>();
const emit = defineEmits<{
    'update:source': [value: string | null];
    'update:urlParam': [value: string | null];
    'update:defaultValue': [value: string | null];
}>();

const sourceOptions = [
    { value: '', label: 'Nothing (leave empty)' },
    { value: 'fixed', label: 'A fixed value' },
    { value: 'url', label: 'A value from the link' },
];

const isFixed = computed(() => props.source === 'fixed');
const isUrl = computed(() => props.source === 'url');

// The parameter name falls back to the field's own key when blank — mirrors PrefillSource::urlParam() and
// prefillParamOf(), so the example link below is always the real one.
const effectiveParam = computed(() => (props.urlParam?.trim() || props.fieldKey) || 'parameter');
</script>

<template>
    <div class="prefill-editor">
        <section class="prefill-editor__group">
            <MdsFormField
                label="Where the value comes from"
                help="A hidden field is never shown to the person filling in the form, so its value has to come from somewhere else."
                v-slot="{ id, describedby }"
            >
                <MdsSelect
                    :id="id"
                    :describedby="describedby"
                    :model-value="source ?? ''"
                    :options="sourceOptions"
                    @update:model-value="emit('update:source', $event || null)"
                />
            </MdsFormField>

            <MdsFormField
                v-if="isFixed"
                label="Fixed value"
                help="Recorded on every response. Set by the server, so it cannot be changed by whoever opens the form."
                v-slot="{ id, describedby }"
            >
                <MdsTextInput
                    :id="id"
                    :describedby="describedby"
                    :model-value="defaultValue ?? ''"
                    @update:model-value="emit('update:defaultValue', $event || null)"
                />
            </MdsFormField>

            <template v-if="isUrl">
                <MdsFormField
                    label="Link parameter name"
                    help="Letters, numbers, underscores and hyphens. Leave blank to use the field's own key."
                    v-slot="{ id, describedby }"
                >
                    <MdsTextInput
                        :id="id"
                        :describedby="describedby"
                        :model-value="urlParam ?? ''"
                        :placeholder="fieldKey"
                        @update:model-value="emit('update:urlParam', $event || null)"
                    />
                </MdsFormField>
                <p class="prefill-editor__hint">
                    Add <code class="prefill-editor__code">?{{ effectiveParam }}=some-value</code> to your form’s
                    link and that value is recorded with the response. Anyone with the link can change it, so
                    don’t use it for anything that has to be trusted.
                </p>
            </template>
        </section>
    </div>
</template>

<style scoped>
.prefill-editor {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
    min-width: 0;
}

.prefill-editor__group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
}

.prefill-editor__hint {
    margin: 0;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

/* An author-chosen key can be long; wrap rather than pushing the config panel into horizontal overflow at
   375px (the standing responsive-axe lesson). */
.prefill-editor__code {
    font-family: var(--mds-font-family-mono);
    overflow-wrap: anywhere;
}
</style>
