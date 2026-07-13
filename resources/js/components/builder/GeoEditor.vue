<script setup lang="ts">
/**
 * Config sub-editor for the geospatial field types (Increment G5b2b) — the "Map" tab. Geo answers and their
 * validation are frozen by G5b1; this editor only lets the author tune capture + the initial map view. It is
 * deliberately map-free (numeric default-centre inputs, so the builder page needs no Leaflet chunk and no
 * tile CSP): the real interactive map lives in the respondent's `GeoInput.vue`.
 *
 * Controlled + stateless (the {@link MatrixEditor} shape): props come from `field.config`, every change emits
 * a fresh value that the parent writes back through `setConfig` (one debounced history entry). The config
 * keys written here — `capture_altitude` / `accuracy_threshold` / `default_center {lat,lon}` / `default_zoom`
 * — are the exact snake_case keys read by `EncodeFormPresenter::geo()` (PHP) and `buildGeo()` (TS runtime).
 * The lenient PATCH validation lives in `UpdateFieldRequest::configRules()`; completeness is not required
 * (every geo config value is optional — a geo field is fully usable with no author config at all).
 */
import { computed } from 'vue';
import { MdsCheckbox, MdsFormField, MdsNumberInput } from '@meridian/design-system';

const props = defineProps<{
    fieldType: string;
    captureAltitude: boolean;
    accuracyThreshold: number | null;
    defaultCenter: { lat: number; lon: number } | null;
    defaultZoom: number | null;
}>();
const emit = defineEmits<{
    'update:captureAltitude': [value: boolean];
    'update:accuracyThreshold': [value: number | null];
    'update:defaultCenter': [value: { lat: number | null; lon: number | null } | null];
    'update:defaultZoom': [value: number | null];
}>();

// Altitude capture is only meaningful for a single point — the trace/shape renderers capture no per-vertex
// altitude (mirrors the `kind === 'geopoint'` gate in GeoInput.vue).
const isPoint = computed(() => props.fieldType === 'geopoint');

const lat = computed<number | null>(() => props.defaultCenter?.lat ?? null);
const lon = computed<number | null>(() => props.defaultCenter?.lon ?? null);

// The two ordinates are separate inputs but one config key: emit the whole object (null when both are
// cleared — the runtime + presenter already drop a centre unless BOTH ordinates are numeric).
function setCenter(next: { lat: number | null; lon: number | null }): void {
    emit('update:defaultCenter', next.lat === null && next.lon === null ? null : next);
}
</script>

<template>
    <div class="geo-editor">
        <section class="geo-editor__group">
            <h3 class="geo-editor__heading">Capture options</h3>
            <MdsCheckbox
                v-if="isPoint"
                :model-value="captureAltitude"
                label="Capture altitude"
                @update:model-value="emit('update:captureAltitude', $event)"
            />
            <MdsFormField
                label="Accuracy target (m)"
                help="A soft reminder shown when a captured point's GPS accuracy is worse than this. Not enforced."
                v-slot="{ id, describedby }"
            >
                <MdsNumberInput
                    :id="id"
                    :describedby="describedby"
                    :model-value="accuracyThreshold"
                    :min="0"
                    :step="1"
                    placeholder="e.g. 20"
                    @update:model-value="emit('update:accuracyThreshold', $event)"
                />
            </MdsFormField>
        </section>

        <section class="geo-editor__group">
            <h3 class="geo-editor__heading">Default map view</h3>
            <p class="geo-editor__hint">Where the capture map first centres and how far it zooms in.</p>
            <div class="geo-editor__center">
                <MdsFormField label="Latitude" v-slot="{ id }">
                    <MdsNumberInput
                        :id="id"
                        :model-value="lat"
                        :min="-90"
                        :max="90"
                        :step="0.0001"
                        @update:model-value="setCenter({ lat: $event, lon })"
                    />
                </MdsFormField>
                <MdsFormField label="Longitude" v-slot="{ id }">
                    <MdsNumberInput
                        :id="id"
                        :model-value="lon"
                        :min="-180"
                        :max="180"
                        :step="0.0001"
                        @update:model-value="setCenter({ lat, lon: $event })"
                    />
                </MdsFormField>
            </div>
            <MdsFormField label="Zoom level" help="0 (world) to 22 (building)." v-slot="{ id, describedby }">
                <MdsNumberInput
                    :id="id"
                    :describedby="describedby"
                    :model-value="defaultZoom"
                    :min="0"
                    :max="22"
                    :step="1"
                    placeholder="e.g. 11"
                    @update:model-value="emit('update:defaultZoom', $event)"
                />
            </MdsFormField>
        </section>
    </div>
</template>

<style scoped>
.geo-editor {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
    min-width: 0;
}

.geo-editor__group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
}

.geo-editor__heading {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-body);
}

.geo-editor__hint {
    margin: 0;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

.geo-editor__center {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--mds-space-3);
    min-width: 0;
}
</style>
