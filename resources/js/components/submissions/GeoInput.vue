<script setup lang="ts">
/**
 * The geospatial capture control (Increment G5b2), shared by manual-encode + the public SPA via
 * {@link FieldInput.vue} (mounted lazily through `defineAsyncComponent`, so geo-free forms never load Leaflet):
 *  - `geopoint`  — a single coordinate; value `{type:'Point', coordinates:[lon,lat(,alt)], accuracy?}`.
 *  - `geotrace`  — an ordered vertex list; value `{type:'LineString', coordinates:[[lon,lat],…]}` (≥2 to be valid).
 *  - `geoshape`  — a closed ring; value `{type:'Polygon', coordinates:[[[lon,lat],…,firstPoint]]}` (≥4 positions).
 *
 * The canonical value is the GeoJSON envelope the merged G5b1 engine already validates — coordinates in
 * `[lon, lat(, alt)]` order (GeoJSON/PostGIS), an empty answer is `null` (never `{}`). Server-authoritative:
 * this control only PRODUCES the envelope; range / closure / min-points are enforced by `processGeo`.
 *
 * Accessibility (WCAG 2.2 AA, the merge-blocking axe requirement): the labelled numeric Lat/Lon fields + the
 * keyboard-operable vertex list are the ALWAYS-present, offline-safe baseline. The Leaflet map is a strictly
 * additive progressive enhancement — it is lazy + guarded, so no network, no secure context, and no DOM
 * layout (Vitest/happy-dom) all degrade to the manual inputs with the map simply absent. Note the display
 * order is lat-first (matching SchemaValueFormatter) while the stored envelope is lon-first.
 */
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { MdsButton, MdsFormField, MdsIconButton, MdsNumberInput } from '@meridian/design-system';
import 'leaflet/dist/leaflet.css';
import markerIconUrl from 'leaflet/dist/images/marker-icon.png';
import markerIconRetinaUrl from 'leaflet/dist/images/marker-icon-2x.png';
import markerShadowUrl from 'leaflet/dist/images/marker-shadow.png';
import type { Layer as LLayer, Map as LMap, Marker as LMarker } from 'leaflet';

import type { AnswerValue, EncodeField, GeoEnvelope, RequiredMarker } from './FieldInput.vue';

const props = defineProps<{
    field: EncodeField;
    modelValue: AnswerValue;
    error?: string;
    requiredMarker?: RequiredMarker;
}>();
const emit = defineEmits<{ 'update:modelValue': [value: AnswerValue] }>();

// ── Config + labels ───────────────────────────────────────────────────────────────────────────
const kind = computed<'geopoint' | 'geotrace' | 'geoshape'>(() => {
    const t = props.field.field_type;
    return t === 'geotrace' ? 'geotrace' : t === 'geoshape' ? 'geoshape' : 'geopoint';
});
const isMulti = computed<boolean>(() => kind.value !== 'geopoint');
const captureAltitude = computed<boolean>(() => kind.value === 'geopoint' && (props.field.geo?.captureAltitude ?? false));

const marker = computed<RequiredMarker>(() => props.requiredMarker ?? (props.field.required ? 'required' : 'none'));
const showRequiredMarker = computed<boolean>(() => marker.value === 'required');
const showOptionalMarker = computed<boolean>(() => marker.value === 'optional');

const TILE_URL = 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';

// ── Local editing state (a controlled control needs a local mirror: a vertex may be half-entered, and the
//    list needs stable keys the derived envelope cannot provide). Coordinates are stored lat/lon for the UI;
//    the emit swaps to lon-first. ─────────────────────────────────────────────────────────────────────────
interface Vertex {
    id: number;
    lat: number | null;
    lon: number | null;
    alt: number | null;
}

let seq = 0;
const nextId = (): number => (seq += 1);

const vertices = ref<Vertex[]>([]);
const accuracy = ref<number | null>(null);
const announce = ref<string>('');
const locating = ref<boolean>(false);

function numOrNull(value: unknown): number | null {
    if (value === null || value === undefined || value === '') {
        return null;
    }
    const n = Number(value);
    return Number.isFinite(n) ? n : null;
}

function round6(n: number): number {
    return Math.round(n * 1e6) / 1e6;
}

function isGeoEnvelope(value: unknown): value is GeoEnvelope {
    return (
        value !== null &&
        typeof value === 'object' &&
        !Array.isArray(value) &&
        typeof (value as { type?: unknown }).type === 'string' &&
        'coordinates' in (value as object)
    );
}

// ── Envelope ⇄ state ──────────────────────────────────────────────────────────────────────────
/** The current canonical envelope from the editable state (empty → null; lon-first; ring closed for shape). */
function currentEnvelope(): GeoEnvelope | null {
    const positions: number[][] = [];
    for (const v of vertices.value) {
        if (v.lat === null || v.lon === null) {
            continue;
        }
        const position = [v.lon, v.lat];
        if (captureAltitude.value && v.alt !== null) {
            position.push(v.alt);
        }
        positions.push(position);
    }
    if (positions.length === 0) {
        return null;
    }

    if (kind.value === 'geopoint') {
        const env: GeoEnvelope = { type: 'Point', coordinates: positions[0] };
        if (accuracy.value !== null) {
            env.accuracy = accuracy.value;
        }
        return env;
    }
    if (kind.value === 'geotrace') {
        return { type: 'LineString', coordinates: positions };
    }
    // geoshape — close the ring (first == last) on emit; the UI edits the open ring.
    return { type: 'Polygon', coordinates: [[...positions, positions[0]]] };
}

const lastEmitted = ref<string | null>(null);

function emitValue(): void {
    const env = currentEnvelope();
    lastEmitted.value = env === null ? null : JSON.stringify(env);
    emit('update:modelValue', env);
    syncMap();
}

/** Rebuild the editable state from an externally-supplied envelope (mount / draft-restore / version-drift). */
function hydrate(value: AnswerValue): void {
    if (!isGeoEnvelope(value)) {
        vertices.value = [];
        accuracy.value = null;
        return;
    }
    const env = value;
    let positions: number[][] = [];
    if (Array.isArray(env.coordinates)) {
        if (env.type === 'Point') {
            positions = [env.coordinates as number[]];
        } else if (env.type === 'LineString') {
            positions = env.coordinates as number[][];
        } else if (env.type === 'Polygon') {
            const ring = ((env.coordinates as number[][][])[0] ?? []).slice();
            // Drop the closing duplicate so the list shows the open ring for editing.
            if (ring.length >= 2) {
                const first = ring[0];
                const last = ring[ring.length - 1];
                if (first[0] === last[0] && first[1] === last[1]) {
                    ring.pop();
                }
            }
            positions = ring;
        }
    }
    vertices.value = positions.map((p) => ({
        id: nextId(),
        lon: numOrNull(p?.[0]),
        lat: numOrNull(p?.[1]),
        alt: p?.[2] !== undefined ? numOrNull(p[2]) : null,
    }));
    accuracy.value = typeof env.accuracy === 'number' ? env.accuracy : null;
}

// Re-hydrate only on an EXTERNAL change (not our own echo): the parent stores exactly what we emit, so an
// incoming value whose JSON matches `lastEmitted` is our echo and is ignored (keeps in-progress edits stable).
watch(
    () => props.modelValue,
    (value) => {
        const incoming = value === null || value === undefined ? null : JSON.stringify(value);
        if (incoming !== lastEmitted.value) {
            hydrate(value);
            syncMap();
            fitMap();
        }
    },
);

// ── geopoint helpers ──────────────────────────────────────────────────────────────────────────
const pointLat = computed<number | null>(() => vertices.value[0]?.lat ?? null);
const pointLon = computed<number | null>(() => vertices.value[0]?.lon ?? null);
const pointAlt = computed<number | null>(() => vertices.value[0]?.alt ?? null);

function setPoint(axis: 'lat' | 'lon' | 'alt', value: number | null): void {
    if (vertices.value.length === 0) {
        vertices.value = [{ id: nextId(), lat: null, lon: null, alt: null }];
    }
    vertices.value[0][axis] = value;
    const v = vertices.value[0];
    if (v.lat === null && v.lon === null && v.alt === null) {
        vertices.value = [];
        accuracy.value = null;
    } else if (axis !== 'alt') {
        // A manual coordinate edit invalidates a previously-captured GPS accuracy.
        accuracy.value = null;
    }
    emitValue();
}

// ── geotrace / geoshape helpers ─────────────────────────────────────────────────────────────────
function setVertex(index: number, axis: 'lat' | 'lon', value: number | null): void {
    const v = vertices.value[index];
    if (v === undefined) {
        return;
    }
    v[axis] = value;
    emitValue();
}

function addVertex(): void {
    vertices.value.push({ id: nextId(), lat: null, lon: null, alt: null });
    announce.value = `Point ${vertices.value.length} added.`;
    emitValue();
}

function removeVertex(index: number): void {
    vertices.value.splice(index, 1);
    announce.value = `Point ${index + 1} removed.`;
    emitValue();
}

function moveVertex(index: number, direction: -1 | 1): void {
    const target = index + direction;
    if (target < 0 || target >= vertices.value.length) {
        return;
    }
    const list = vertices.value;
    [list[index], list[target]] = [list[target], list[index]];
    announce.value = `Point ${index + 1} moved ${direction < 0 ? 'up' : 'down'}.`;
    emitValue();
}

// ── GPS ────────────────────────────────────────────────────────────────────────────────────────
function useMyLocation(): void {
    if (typeof navigator === 'undefined' || navigator.geolocation === undefined) {
        announce.value = 'Location is not available on this device.';
        return;
    }
    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        (position) => {
            locating.value = false;
            const lat = round6(position.coords.latitude);
            const lon = round6(position.coords.longitude);
            if (kind.value === 'geopoint') {
                const alt =
                    captureAltitude.value && position.coords.altitude !== null
                        ? round6(position.coords.altitude)
                        : null;
                vertices.value = [{ id: nextId(), lat, lon, alt }];
                accuracy.value =
                    position.coords.accuracy !== null ? Math.round(position.coords.accuracy) : null;
            } else {
                vertices.value.push({ id: nextId(), lat, lon, alt: null });
            }
            announce.value = `Location captured at ${lat}, ${lon}.`;
            emitValue();
            fitMap();
        },
        () => {
            locating.value = false;
            announce.value = 'Could not get your location. Enter the coordinates manually.';
        },
        { enableHighAccuracy: true, timeout: 10_000 },
    );
}

// ── Accuracy soft-warning (informational only; the engine does not enforce it) ──────────────────
const accuracyThreshold = computed<number | null>(() => props.field.geo?.accuracyThreshold ?? null);
const accuracyWarning = computed<boolean>(
    () => accuracy.value !== null && accuracyThreshold.value !== null && accuracy.value > accuracyThreshold.value,
);

// ── Leaflet map (progressive enhancement; lazy + guarded) ───────────────────────────────────────
const mapEl = ref<HTMLElement | null>(null);
const mapAriaLabel = computed<string>(
    () =>
        `Interactive map for ${props.field.label}. The coordinate fields above are the primary, ` +
        `keyboard-accessible way to set this location.`,
);

let leaflet: typeof import('leaflet') | null = null;
let map: LMap | null = null;
let markerLayers: { id: number; marker: LMarker }[] = [];
let shapeLayer: LLayer | null = null;
let destroyed = false;

onMounted(() => {
    hydrate(props.modelValue);
    lastEmitted.value = props.modelValue === null || props.modelValue === undefined ? null : JSON.stringify(props.modelValue);
    void initMap();
});

async function initMap(): Promise<void> {
    try {
        if (mapEl.value === null) {
            return;
        }
        const mod = await import('leaflet');
        if (destroyed || mapEl.value === null) {
            return;
        }
        leaflet = (mod as { default?: typeof import('leaflet') }).default ?? mod;

        // The default marker icon URLs break under bundlers — point them at the locally-bundled assets.
        delete (leaflet.Icon.Default.prototype as unknown as { _getIconUrl?: unknown })._getIconUrl;
        leaflet.Icon.Default.mergeOptions({
            iconUrl: markerIconUrl,
            iconRetinaUrl: markerIconRetinaUrl,
            shadowUrl: markerShadowUrl,
        });

        const center = props.field.geo?.defaultCenter ?? null;
        const zoom = props.field.geo?.defaultZoom ?? null;
        // Disable Leaflet's built-in attribution control — its links fail WCAG (no underline + low contrast).
        // The OSM credit is rendered as accessible, AA-contrast, underlined text below the map instead.
        map = leaflet.map(mapEl.value, { attributionControl: false }).setView([center?.lat ?? 0, center?.lon ?? 0], zoom ?? 2);
        leaflet.tileLayer(TILE_URL, { maxZoom: 19 }).addTo(map);
        map.on('click', (event) => onMapClick(event.latlng.lat, event.latlng.lng));
        syncMap();
        fitMap();
    } catch {
        // The map is a progressive enhancement — the numeric/list inputs remain fully functional without it.
        map = null;
    }
}

onBeforeUnmount(() => {
    destroyed = true;
    if (map !== null) {
        map.remove();
        map = null;
    }
});

function onMapClick(lat: number, lng: number): void {
    const rlat = round6(lat);
    const rlon = round6(lng);
    if (kind.value === 'geopoint') {
        vertices.value = [{ id: nextId(), lat: rlat, lon: rlon, alt: null }];
        accuracy.value = null;
    } else {
        vertices.value.push({ id: nextId(), lat: rlat, lon: rlon, alt: null });
    }
    announce.value = `Point added at ${rlat}, ${rlon}.`;
    emitValue();
}

/** Rebuild every marker + the connecting shape from the current vertices. */
function syncMap(): void {
    if (map === null || leaflet === null) {
        return;
    }
    for (const entry of markerLayers) {
        map.removeLayer(entry.marker);
    }
    markerLayers = [];

    const latlngs: [number, number][] = [];
    for (const v of vertices.value) {
        if (v.lat === null || v.lon === null) {
            continue;
        }
        const vid = v.id;
        const point: [number, number] = [v.lat, v.lon];
        const m = leaflet.marker(point, { draggable: true });
        m.on('dragend', () => {
            const ll = m.getLatLng();
            const found = vertices.value.find((x) => x.id === vid);
            if (found === undefined) {
                return;
            }
            found.lat = round6(ll.lat);
            found.lon = round6(ll.lng);
            announce.value = `Point moved to ${found.lat}, ${found.lon}.`;
            // Update the value + reshape WITHOUT a full marker rebuild (which would drop the dragged marker).
            const env = currentEnvelope();
            lastEmitted.value = env === null ? null : JSON.stringify(env);
            emit('update:modelValue', env);
            drawShape();
        });
        m.addTo(map);
        markerLayers.push({ id: vid, marker: m });
        latlngs.push(point);
    }
    drawShape(latlngs);
}

function drawShape(latlngs?: [number, number][]): void {
    if (map === null || leaflet === null) {
        return;
    }
    if (shapeLayer !== null) {
        map.removeLayer(shapeLayer);
        shapeLayer = null;
    }
    const points =
        latlngs ??
        vertices.value.filter((v) => v.lat !== null && v.lon !== null).map((v) => [v.lat as number, v.lon as number] as [number, number]);
    if (kind.value === 'geotrace' && points.length >= 2) {
        shapeLayer = leaflet.polyline(points).addTo(map);
    } else if (kind.value === 'geoshape' && points.length >= 3) {
        shapeLayer = leaflet.polygon(points).addTo(map);
    }
}

function fitMap(): void {
    if (map === null || leaflet === null) {
        return;
    }
    const points = vertices.value
        .filter((v) => v.lat !== null && v.lon !== null)
        .map((v) => [v.lat as number, v.lon as number] as [number, number]);
    if (points.length === 1) {
        map.setView(points[0], Math.max(map.getZoom(), 13));
    } else if (points.length > 1) {
        map.fitBounds(points, { padding: [24, 24] });
    }
}
</script>

<template>
    <fieldset class="geo">
        <legend class="geo__legend">
            {{ field.label
            }}<span v-if="showRequiredMarker" class="geo__required"> (required)</span
            ><span v-else-if="showOptionalMarker" class="geo__required"> (optional)</span>
        </legend>
        <p v-if="field.hint" class="geo__help">{{ field.hint }}</p>

        <!-- geopoint: a single coordinate -->
        <template v-if="kind === 'geopoint'">
            <div class="geo__coords">
                <MdsFormField label="Latitude" v-slot="{ id, describedby, invalid }">
                    <MdsNumberInput
                        :id="id"
                        :model-value="pointLat"
                        :min="-90"
                        :max="90"
                        :step="0.000001"
                        placeholder="e.g. 14.5995"
                        :describedby="describedby"
                        :invalid="invalid || Boolean(error)"
                        @update:model-value="setPoint('lat', $event)"
                    />
                </MdsFormField>
                <MdsFormField label="Longitude" v-slot="{ id, describedby, invalid }">
                    <MdsNumberInput
                        :id="id"
                        :model-value="pointLon"
                        :min="-180"
                        :max="180"
                        :step="0.000001"
                        placeholder="e.g. 120.9842"
                        :describedby="describedby"
                        :invalid="invalid || Boolean(error)"
                        @update:model-value="setPoint('lon', $event)"
                    />
                </MdsFormField>
                <MdsFormField v-if="captureAltitude" label="Altitude (m)" v-slot="{ id, describedby }">
                    <MdsNumberInput
                        :id="id"
                        :model-value="pointAlt"
                        :step="0.1"
                        :describedby="describedby"
                        @update:model-value="setPoint('alt', $event)"
                    />
                </MdsFormField>
            </div>
            <p v-if="accuracy !== null" class="geo__accuracy" :class="{ 'geo__accuracy--warn': accuracyWarning }">
                Accuracy: ±{{ accuracy }} m<template v-if="accuracyWarning">
                    — greater than the {{ accuracyThreshold }} m target; try capturing again.</template
                >
            </p>
        </template>

        <!-- geotrace / geoshape: an ordered, keyboard-operable vertex list -->
        <template v-else>
            <p v-if="vertices.length === 0" class="geo__empty">No points added yet.</p>
            <ol v-else class="geo__vertices">
                <li v-for="(v, i) in vertices" :key="v.id">
                    <fieldset class="geo__vertex">
                        <legend class="geo__vertex-legend">Point {{ i + 1 }}</legend>
                        <div class="geo__coords">
                            <MdsFormField label="Latitude" v-slot="{ id, describedby }">
                                <MdsNumberInput
                                    :id="id"
                                    :model-value="v.lat"
                                    :min="-90"
                                    :max="90"
                                    :step="0.000001"
                                    :describedby="describedby"
                                    @update:model-value="setVertex(i, 'lat', $event)"
                                />
                            </MdsFormField>
                            <MdsFormField label="Longitude" v-slot="{ id, describedby }">
                                <MdsNumberInput
                                    :id="id"
                                    :model-value="v.lon"
                                    :min="-180"
                                    :max="180"
                                    :step="0.000001"
                                    :describedby="describedby"
                                    @update:model-value="setVertex(i, 'lon', $event)"
                                />
                            </MdsFormField>
                        </div>
                        <div class="geo__vertex-actions">
                            <MdsIconButton
                                icon="chevron-up"
                                :label="`Move point ${i + 1} up`"
                                :disabled="i === 0"
                                @click="moveVertex(i, -1)"
                            />
                            <MdsIconButton
                                icon="chevron-down"
                                :label="`Move point ${i + 1} down`"
                                :disabled="i === vertices.length - 1"
                                @click="moveVertex(i, 1)"
                            />
                            <MdsIconButton icon="trash" :label="`Remove point ${i + 1}`" @click="removeVertex(i)" />
                        </div>
                    </fieldset>
                </li>
            </ol>
            <div class="geo__list-actions">
                <MdsButton type="button" variant="secondary" size="sm" icon-left="plus" @click="addVertex">
                    Add point
                </MdsButton>
            </div>
        </template>

        <div class="geo__capture">
            <MdsButton
                type="button"
                variant="tertiary"
                size="sm"
                icon-left="map-pin"
                :loading="locating"
                @click="useMyLocation"
            >
                Use my location
            </MdsButton>
        </div>

        <!-- Progressive-enhancement map. role=application + a name declares an interactive widget that manages
             its own keys; the labelled inputs above are the accessible, always-present alternative. -->
        <div ref="mapEl" class="geo__map" role="application" :aria-label="mapAriaLabel"></div>

        <!-- OSM attribution (Leaflet's own control is disabled — its links fail WCAG). Underlined + AA contrast. -->
        <p class="geo__attribution">
            Map data ©
            <a
                class="geo__attribution-link"
                href="https://www.openstreetmap.org/copyright"
                target="_blank"
                rel="noopener noreferrer"
                >OpenStreetMap</a
            >
            contributors
        </p>

        <p class="geo__live" aria-live="polite">{{ announce }}</p>

        <div class="geo__error" aria-live="polite">
            <template v-if="error">
                <svg class="geo__error-icon" viewBox="0 0 16 16" aria-hidden="true" focusable="false">
                    <path
                        fill="currentColor"
                        d="M8 1a7 7 0 100 14A7 7 0 008 1zm-.9 3.6h1.8l-.2 5h-1.4l-.2-5zM8 12.4a1 1 0 110-2 1 1 0 010 2z"
                    />
                </svg>
                <span>{{ error }}</span>
            </template>
        </div>
    </fieldset>
</template>

<style scoped>
.geo {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    margin: 0;
    padding: 0;
    border: 0;
    min-width: 0;
}

.geo__legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.geo__required {
    font-weight: var(--mds-font-weight-regular);
    color: var(--mds-color-text-secondary);
}

.geo__help {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

/* Lat/Lon(/Alt) inputs wrap at narrow viewports so the control never forces horizontal page scroll. */
.geo__coords {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-3);
    min-width: 0;
}

.geo__coords > * {
    flex: 1 1 10rem;
    min-width: 0;
}

.geo__accuracy {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

.geo__accuracy--warn {
    color: var(--mds-color-danger-text);
}

.geo__empty {
    margin: 0;
    padding: var(--mds-space-3) var(--mds-space-4);
    border: 1px dashed var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.geo__vertices {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.geo__vertex {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
    min-width: 0;
}

.geo__vertex-legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.geo__vertex-actions {
    display: flex;
    gap: var(--mds-space-1);
    justify-content: flex-end;
}

.geo__list-actions,
.geo__capture {
    display: flex;
    gap: var(--mds-space-3);
    flex-wrap: wrap;
}

.geo__map {
    height: 18rem;
    max-width: 100%;
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
    z-index: 0;
}

.geo__attribution {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-secondary);
}

.geo__attribution-link {
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
}

.geo__attribution-link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

/* Empty until an action is announced; a screen-reader-only live region (kept out of the visual flow). */
.geo__live {
    position: absolute;
    width: 1px;
    height: 1px;
    margin: -1px;
    padding: 0;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}

.geo__error {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-1);
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-danger-text);
}
.geo__error:empty {
    display: none;
}

.geo__error-icon {
    flex-shrink: 0;
    width: 1em;
    height: 1em;
    margin-top: 0.15em;
}
</style>
