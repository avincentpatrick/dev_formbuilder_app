<script setup lang="ts">
/**
 * Create / edit a webhook endpoint (Increment H14). One modal serves both flows: `endpoint` null = create
 * (POST /webhooks), non-null = edit (PATCH /webhooks/{id}). Uses Inertia `useForm` so the request's per-field
 * 422s (name, the PublicHttpUrl SSRF check, the event-type subset, form scope) surface inline via
 * `MdsFormField :error`; a successful save flashes the controller's toast and closes. Mirrors the builder's
 * ScheduleModal flow. The signing secret is never a field here — it is minted server-side and revealed once
 * through the separate SecretRevealModal after create.
 */
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsCheckbox, MdsFormField, MdsModal, MdsSelect, MdsTextInput } from '@meridian/design-system';

type Option = { value: string; label: string };
type Editable = { id: string; name: string; url: string; event_types: string[]; form_id: string | null };

const props = defineProps<{
    open: boolean;
    forms: Option[];
    eventTypes: Option[];
    endpoint?: Editable | null;
}>();

const emit = defineEmits<{ 'update:open': [value: boolean] }>();

const form = useForm<{ name: string; url: string; event_types: string[]; form_id: string }>({
    name: '',
    url: '',
    event_types: [],
    form_id: '',
});

// The tenant-wide (null form_id) choice leads the form scope select; a real form scopes the endpoint to it.
const formScopeOptions: Option[] = [{ value: '', label: 'All forms (tenant-wide)' }, ...props.forms];

// (Re)seed on open from the endpoint being edited, or blank for a create — Inertia's back() redirect refreshes
// the page props after each save, so this always reflects the latest persisted state.
watch(
    () => props.open,
    (open) => {
        if (!open) return;
        form.name = props.endpoint?.name ?? '';
        form.url = props.endpoint?.url ?? '';
        form.event_types = [...(props.endpoint?.event_types ?? [])];
        form.form_id = props.endpoint?.form_id ?? '';
        form.clearErrors();
    },
    { immediate: true },
);

function toggleEvent(value: string, checked: boolean): void {
    form.event_types = checked
        ? [...form.event_types, value]
        : form.event_types.filter((v) => v !== value);
}

function close(): void {
    emit('update:open', false);
}

function submit(): void {
    const shaped = form.transform((data) => ({
        ...data,
        form_id: data.form_id === '' ? null : data.form_id,
    }));

    const options = { preserveScroll: true, onSuccess: () => close() };

    if (props.endpoint) {
        shaped.patch(`/webhooks/${props.endpoint.id}`, options);
    } else {
        shaped.post('/webhooks', options);
    }
}
</script>

<template>
    <MdsModal :open="open" :title="endpoint ? 'Edit webhook endpoint' : 'Add webhook endpoint'" @close="close">
        <form class="webhook-form" @submit.prevent="submit">
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Name" :error="form.errors.name">
                <MdsTextInput
                    :id="id"
                    v-model="form.name"
                    placeholder="e.g. Zapier"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>

            <MdsFormField
                v-slot="{ id, describedby, invalid }"
                label="Payload URL"
                help="An HTTPS endpoint we POST each event to. Private and internal addresses are rejected."
                :error="form.errors.url"
            >
                <MdsTextInput
                    :id="id"
                    v-model="form.url"
                    type="url"
                    placeholder="https://hooks.example.com/webhooks/…"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>

            <!-- A checkbox GROUP → fieldset/legend (not FormField, which owns a single label→control). -->
            <fieldset class="webhook-form__group">
                <legend class="webhook-form__legend">Events</legend>
                <p class="webhook-form__help">Which events this endpoint receives.</p>
                <div class="webhook-form__events">
                    <MdsCheckbox
                        v-for="opt in eventTypes"
                        :key="opt.value"
                        :model-value="form.event_types.includes(opt.value)"
                        :label="opt.label"
                        @update:model-value="toggleEvent(opt.value, $event)"
                    />
                </div>
                <p v-if="form.errors.event_types" class="webhook-form__group-error" role="alert">
                    {{ form.errors.event_types }}
                </p>
            </fieldset>

            <MdsFormField
                v-slot="{ id, describedby, invalid }"
                label="Scope"
                help="Deliver events from every form, or just one."
                :error="form.errors.form_id"
            >
                <MdsSelect
                    :id="id"
                    v-model="form.form_id"
                    :options="formScopeOptions"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>
        </form>
        <template #actions>
            <MdsButton variant="tertiary" :disabled="form.processing" @click="close">Cancel</MdsButton>
            <MdsButton
                variant="primary"
                icon-left="check"
                :loading="form.processing"
                @click="submit"
            >
                {{ endpoint ? 'Save changes' : 'Create endpoint' }}
            </MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.webhook-form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.webhook-form__group {
    margin: 0;
    padding: 0;
    border: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.webhook-form__legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.webhook-form__help {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

.webhook-form__events {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.webhook-form__group-error {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-danger-text);
}
</style>
