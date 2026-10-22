<script setup lang="ts">
/**
 * One choice-list file chosen and uploaded (M184, taken out of `CascadingEditor` and `ChoiceListsPanel`, which carried the
 * same control twice): a CSV file or an Excel workbook (`R-3e7c81bb`), the server's own sentence when it refuses one, and
 * the codes-as-text sentence `R-1059e9bd` asked for beside every upload — in the field's help, so a screen reader hears it
 * with the control.
 */
import { computed, ref } from 'vue';
import { MdsFormField } from '@meridian/design-system';
import { CHOICE_LIST_ACCEPT, CODES_AS_TEXT, describeChoiceList, uploadChoiceList, type ChoiceListRow } from '@/components/forms/choice-lists';

const props = defineProps<{ formId: string; label: string; help: string }>();
const emit = defineEmits<{ uploaded: [list: ChoiceListRow] }>();

const uploading = ref(false);
const error = ref<string | null>(null);
const status = ref<string | null>(null);
const help = computed(() => `${props.help} ${CODES_AS_TEXT}`);

async function onChosen(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    input.value = '';
    if (file === undefined || uploading.value) return;

    uploading.value = true;
    error.value = null;
    status.value = null;
    try {
        const added = await uploadChoiceList(props.formId, file);
        status.value = `Uploaded ${added.name}: ${describeChoiceList(added)}.`;
        emit('uploaded', added);
    } catch (thrown) {
        error.value = thrown instanceof Error ? thrown.message : 'The file was not accepted.';
    } finally {
        uploading.value = false;
    }
}
</script>

<template>
    <div class="choice-list-upload" data-choice-list-upload>
        <MdsFormField v-slot="{ id, describedby, invalid }" :label="label" :help="help" :error="error ?? undefined">
            <input
                :id="id"
                class="choice-list-upload__file"
                type="file"
                :accept="CHOICE_LIST_ACCEPT"
                :disabled="uploading"
                :aria-describedby="describedby"
                :aria-invalid="invalid || undefined"
                @change="onChosen"
            />
        </MdsFormField>
        <p class="choice-list-upload__status" role="status">{{ uploading ? 'Reading the file…' : (status ?? '') }}</p>
    </div>
</template>

<style scoped>
.choice-list-upload {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
}

.choice-list-upload__file {
    max-width: 100%;
}

.choice-list-upload__status {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
