<script setup lang="ts">
/**
 * Config sub-editor for the media field types (Increment G6) — the "Media" tab. Media answers + their
 * validation are frozen by the G6 engine; this editor only lets the author tune what may be uploaded and how
 * many. Controlled + stateless (the {@link GeoEditor} shape): props come from `field.config`, every change
 * emits a fresh value the parent writes back through `setConfig` (one debounced history entry).
 *
 * The config keys written here — `accepted_types` / `max_file_size_bytes` / `max_count` / `min_count` /
 * `capture_source` — are the exact snake_case keys read by `EncodeFormPresenter::media()` (PHP) +
 * `buildMedia()` (TS runtime) + `mediaCountBounds` (both engines). The lenient PATCH validation lives in
 * `UpdateFieldRequest::configRules()`; only `min_count ≤ max_count` is enforced at publish (every value is
 * optional — a media field is fully usable with no author config).
 *
 * M187 (`R-af2d278b`): a Signature has none of these. Its pad always saves one PNG, and the server takes a signature's
 * file types from its own default whatever the question says, so the tab says how people sign instead.
 */
import { computed } from 'vue';
import { MdsCheckbox, MdsFormField, MdsNumberInput, MdsTextInput } from '@meridian/design-system';

const props = defineProps<{
    fieldType: string;
    acceptedTypes: string[];
    maxFileSizeBytes: number | null;
    maxCount: number | null;
    minCount: number | null;
    captureSource: string | null;
}>();
const emit = defineEmits<{
    'update:acceptedTypes': [value: string[]];
    'update:maxFileSizeBytes': [value: number | null];
    'update:maxCount': [value: number | null];
    'update:minCount': [value: number | null];
    'update:captureSource': [value: string | null];
}>();

const BYTES_PER_MB = 1_048_576;

const isSignature = computed<boolean>(() => props.fieldType === 'signature');

// Camera/mic capture only makes sense for the capture types — a plain file upload has no device to prefer.
const isCaptureType = computed(
    () => props.fieldType === 'image_capture' || props.fieldType === 'audio_capture' || props.fieldType === 'video_capture',
);

const acceptedTypesText = computed<string>(() => props.acceptedTypes.join(', '));
const maxFileSizeMb = computed<number | null>(() =>
    props.maxFileSizeBytes === null ? null : Math.round((props.maxFileSizeBytes / BYTES_PER_MB) * 100) / 100,
);
const preferCamera = computed<boolean>(() => props.captureSource === 'camera');

// A comma/space-separated list of MIME types or globs (e.g. `image/*, application/pdf`) → the string[] config.
function setAcceptedTypes(text: string): void {
    const types = text
        .split(',')
        .map((t) => t.trim())
        .filter((t) => t !== '');
    emit('update:acceptedTypes', types);
}

function setMaxFileSize(mb: number | null): void {
    emit('update:maxFileSizeBytes', mb === null ? null : Math.round(mb * BYTES_PER_MB));
}
</script>

<template>
    <div v-if="isSignature" class="media-editor">
        <section class="media-editor__group">
            <h3 class="media-editor__heading">How it is signed</h3>
            <p class="media-editor__note">
                People sign with a finger, a pen or the mouse, on the web form and in manual entry. The signature is saved as a
                picture, shown on the response and in its PDF. The paper form prints a line to sign on.
            </p>
        </section>
    </div>
    <div v-else class="media-editor">
        <section class="media-editor__group">
            <h3 class="media-editor__heading">What can be uploaded</h3>
            <MdsFormField
                label="Accepted file types"
                help="The kinds of file to take, separated by commas — for example image/* for any picture, or application/pdf. Leave it empty to take any file."
                v-slot="{ id, describedby }"
            >
                <MdsTextInput
                    :id="id"
                    :describedby="describedby"
                    :model-value="acceptedTypesText"
                    placeholder="e.g. image/*"
                    @update:model-value="setAcceptedTypes($event)"
                />
            </MdsFormField>
            <MdsFormField
                label="Maximum file size (MB)"
                help="The largest one file may be. Leave it empty for the standard limit."
                v-slot="{ id, describedby }"
            >
                <MdsNumberInput
                    :id="id"
                    :describedby="describedby"
                    :model-value="maxFileSizeMb"
                    :min="1"
                    :step="1"
                    placeholder="e.g. 10"
                    @update:model-value="setMaxFileSize($event)"
                />
            </MdsFormField>
        </section>

        <section class="media-editor__group">
            <h3 class="media-editor__heading">How many</h3>
            <div class="media-editor__counts">
                <MdsFormField label="Minimum" help="Fewest files required." v-slot="{ id, describedby }">
                    <MdsNumberInput
                        :id="id"
                        :describedby="describedby"
                        :model-value="minCount"
                        :min="0"
                        :step="1"
                        placeholder="0"
                        @update:model-value="emit('update:minCount', $event)"
                    />
                </MdsFormField>
                <MdsFormField label="Maximum" help="Most files allowed (blank = one)." v-slot="{ id, describedby }">
                    <MdsNumberInput
                        :id="id"
                        :describedby="describedby"
                        :model-value="maxCount"
                        :min="1"
                        :step="1"
                        placeholder="1"
                        @update:model-value="emit('update:maxCount', $event)"
                    />
                </MdsFormField>
            </div>
        </section>

        <section v-if="isCaptureType" class="media-editor__group">
            <h3 class="media-editor__heading">Capture</h3>
            <MdsCheckbox
                :model-value="preferCamera"
                label="Open the device camera / microphone by default"
                @update:model-value="emit('update:captureSource', $event ? 'camera' : null)"
            />
        </section>
    </div>
</template>

<style scoped>
.media-editor {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
    min-width: 0;
}

.media-editor__group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
}

.media-editor__heading {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-body);
}

.media-editor__note {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.media-editor__counts {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--mds-space-3);
    min-width: 0;
}
</style>
