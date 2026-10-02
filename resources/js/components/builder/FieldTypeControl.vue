<script setup lang="ts">
/**
 * "Question type · Short text · [Change type]" — the first row of the Basics tab (Increment M123, `B5b`), and the
 * state machine behind `ConvertFieldDialog`.
 *
 * ⛔ IT TAKES THE STORE, UNLIKE THE TAB'S VALUE EDITORS, AND THAT IS THE POINT. The sub-editor contract (props in,
 * `update:` out, routed through `setField()` → `touch()`) exists for VALUES. A type change must never take that route:
 * `touch()` would record a second history entry, and `fieldPayload()` cannot send a type at all — the PATCH is the
 * autosave channel, and a dropped keystroke must never change what a question is. So this calls the store's own
 * queued actions, the pane-level precedent `BuilderCanvas`, `LogicRail` and `PreviewPane` already follow.
 *
 * ⚠️ AT RENDER IT READS ONLY `selectedField`, `palette` AND `enums`. `ConfigPanel.test.ts` mounts the panel with a
 * hand-built store double holding a fixed set of members; everything else here is touched only inside a handler.
 * `FieldTypeControl.test.ts` pins that contract against a double, so a render-time read of a member the double lacks
 * fails there rather than in the hub's own suite.
 *
 * ✅ AND IT IS WHERE `R-a367bf9e`'s VARIANT SWITCH LIVES (M125). The palette shows one "Text" and one "Number"; a field
 * of a variant group gets a segmented "format" control over the group's members, labelled with their own TRANSMITTED
 * type labels, and a number gets "Allow negative numbers". A switch reads the plans, applies a plan that needs no
 * review directly (one request, one undo entry — the store records it), and opens the dialog preselected when it does.
 */
import { computed, nextTick, reactive, ref, useId, watch } from 'vue';
import { MdsButton, MdsCheckbox, MdsSegmentedControl } from '@meridian/design-system';
import ConvertFieldDialog from './ConvertFieldDialog.vue';
import {
    consequenceView,
    NEGATIVE_FLOOR_RULE,
    needsReview,
    negativeFloor,
    staleMessage,
    targetGroups,
    variantOptions,
    withNegativesAllowed,
    type ConvertPhase,
} from './field-conversion';
import type { Uid } from './types';
import type { BuilderStore, ConversionPlan } from './useBuilderStore';

const props = defineProps<{ store: BuilderStore }>();

const field = props.store.selectedField;
const paletteByValue = new Map(props.store.palette.flatMap((group) => group.types.map((type) => [type.value, type] as const)));
const labelOf = (value: string): string => paletteByValue.get(value)?.label ?? value;

const labelId = useId();
const valueId = useId();
const negativeHintId = useId();

const dialog = reactive({
    open: false,
    phase: 'loading' as ConvertPhase,
    uid: null as Uid | null,
    plans: [] as ConversionPlan[],
    selected: '',
    message: null as string | null,
});

/** The polite line under the row. It speaks for the last conversion only while that conversion is still true. */
const announcement = ref('');
let announcedType: string | null = null;
let session = 0;
let preparing = false;

const currentLabel = computed(() => (field.value ? labelOf(field.value.field_type) : ''));
const questionLabel = computed(() => {
    const current = field.value;
    if (!current) return '';
    const label = current.label.trim();

    return label === '' ? current.key : label;
});
const selectedPlan = computed(() => dialog.plans.find((plan) => plan.to === dialog.selected) ?? null);
const groups = computed(() => targetGroups(dialog.plans, paletteByValue));
const targetLabel = computed(() => (dialog.selected === '' ? '' : labelOf(dialog.selected)));
const view = computed(() => {
    const plan = selectedPlan.value;
    if (!plan) return null;

    return consequenceView(plan, {
        enums: props.store.enums,
        fields: props.store.fields.value,
        sections: props.store.sections.value,
        ownKey: field.value?.key ?? '',
        labelOf,
    });
});

watch(
    () => field.value?.uid,
    () => {
        announcement.value = '';
        announcedType = null;
    },
);
// "Changed to Long text. Use Undo…" stops being true the moment the type moves again — an undo above all.
watch(
    () => field.value?.field_type,
    (type) => {
        if (announcedType !== null && type !== announcedType) {
            announcement.value = '';
            announcedType = null;
        }
    },
);

async function openDialog(preselect = ''): Promise<void> {
    const target = field.value;
    if (!target || preparing || dialog.open) return;
    preparing = true;
    announcement.value = '';
    try {
        // Let a pending edit land first. If THAT write 409s, the conflict dialog opens over this button and has
        // captured it as its opener — stacking a second modal over it would strand focus when either closes.
        await props.store.whenIdle();
    } finally {
        preparing = false;
    }
    if (props.store.conflict.value !== null || field.value?.uid !== target.uid) return;

    session++;
    Object.assign(dialog, { open: true, phase: 'loading', uid: target.uid, plans: [], selected: preselect, message: null });
    await load(session);
}

async function load(mine: number): Promise<void> {
    const uid = dialog.uid;
    if (uid === null) return;
    const outcome = await props.store.loadConversionPlans(uid);
    if (mine !== session) return; // closed (or reopened) while this was in flight
    if (outcome.status === 'failed') {
        dialog.phase = 'load-failed';
        dialog.message = outcome.message;
        return;
    }
    dialog.plans = outcome.plans;
    if (!outcome.plans.some((plan) => plan.to === dialog.selected)) dialog.selected = '';
    dialog.phase = 'choosing';
}

async function confirm(): Promise<void> {
    const plan = selectedPlan.value;
    const uid = dialog.uid;
    if (!plan || uid === null || dialog.phase === 'applying') return;
    const mine = session;
    dialog.phase = 'applying';
    dialog.message = null;

    const outcome = await props.store.convertField(uid, plan);
    if (mine !== session) return;

    if (outcome.status === 'converted') {
        shut();
        // After the modal has released the page, so the line is announced from a region a reader can reach.
        await nextTick();
        announcedType = plan.to;
        announcement.value = `Changed to ${labelOf(plan.to)}. Use Undo to change it back.`;
        return;
    }
    if (outcome.status === 'failed') {
        dialog.phase = 'apply-failed';
        dialog.message = outcome.message;
        return;
    }

    // Stale: the store has already adopted the server's row. Read the plans again and say what moved.
    const reread = await props.store.loadConversionPlans(uid);
    if (mine !== session) return;
    if (reread.status === 'failed') {
        dialog.phase = 'load-failed';
        dialog.message = reread.message;
        return;
    }
    dialog.plans = reread.plans;
    const stillOffered = reread.plans.some((candidate) => candidate.to === dialog.selected);
    if (!stillOffered) dialog.selected = '';
    dialog.message = staleMessage(reread.from !== plan.from ? labelOf(reread.from) : null, stillOffered);
    dialog.phase = 'stale';
}

function retry(): void {
    if (dialog.phase !== 'load-failed') return;
    dialog.phase = 'loading';
    dialog.message = null;
    void load(session);
}

function requestClose(): void {
    if (dialog.phase !== 'applying') shut();
}

function shut(): void {
    session++; // a late answer to this session can no longer touch the dialog
    dialog.open = false;
    void nextTick(resyncControls); // a cancelled switch must not leave its radio claiming a format the field lacks
}

/*
|--------------------------------------------------------------------------
| Increment M125 — the variant switch and "Allow negative numbers".
|--------------------------------------------------------------------------
| ⛔ A NATIVE CONTROL CHECKS ITSELF ON THE CLICK, BEFORE ANYTHING IS DECIDED. When the switch is then refused, cancelled
| in the dialog or ignored while another is in flight, the bound value never changes, so Vue has no reason to re-patch
| `checked` and the radio keeps claiming a format the field does not have. `resyncControls()` re-sets the inputs from
| the field IN PLACE — never by remounting them, which would drop the focus a keyboard user is standing on, and would
| hand the dialog a dead opener to return focus to.
*/
const formatRoot = ref<HTMLElement | null>(null);
const negativeRoot = ref<HTMLElement | null>(null);
let switching = false;

const variant = computed(() => (field.value ? (paletteByValue.get(field.value.field_type)?.variant ?? null) : null));
const formatOptions = computed(() => (variant.value ? variantOptions(paletteByValue.values(), variant.value.group) : []));
/** Offered only where the server offers a minimum for this type's shape — a toggle must not build a rule publish refuses. */
const offersNegativeToggle = computed(() => {
    const type = field.value ? paletteByValue.get(field.value.field_type) : undefined;
    if (!type || !variant.value) return false;

    return props.store.enums.validation_rule_types.some((rule) => rule.value === NEGATIVE_FLOOR_RULE && rule.shapes.includes(type.value_shape));
});
const floor = computed(() => negativeFloor(field.value?.validations ?? []));

function resyncControls(): void {
    const type = field.value?.field_type;
    formatRoot.value?.querySelectorAll<HTMLInputElement>('input[type="radio"]').forEach((input) => {
        input.checked = input.value === type;
    });
    const box = negativeRoot.value?.querySelector<HTMLInputElement>('input[type="checkbox"]');
    if (box) box.checked = floor.value.allowed;
}

async function switchFormat(to: string): Promise<void> {
    const target = field.value;
    if (!target || to === target.field_type) return;
    if (switching || preparing || dialog.open) {
        resyncControls();
        return;
    }
    switching = true;
    announcement.value = '';
    try {
        const loaded = await props.store.loadConversionPlans(target.uid);
        if (field.value?.uid !== target.uid) return;
        if (loaded.status === 'failed') {
            announcement.value = loaded.message;
            return;
        }
        const plan = loaded.plans.find((candidate) => candidate.to === to);
        if (!plan) {
            announcement.value = `This question can no longer change to ${labelOf(to)}.`;
            return;
        }
        if (needsReview(plan)) {
            // The dialog says what the switch would change, this format already chosen; the author decides there.
            switching = false;
            await openDialog(to);
            return;
        }
        const outcome = await props.store.convertField(target.uid, plan);
        if (outcome.status === 'converted') {
            await nextTick(); // after the type watcher, which would otherwise clear the line it is about to say
            announcedType = to;
            announcement.value = `Changed to ${labelOf(to)}. Use Undo to change it back.`;
        } else if (outcome.status === 'failed') {
            announcement.value = outcome.message;
        } else {
            announcement.value = 'This question changed elsewhere, so its format was not switched. Check it and try again.';
        }
    } finally {
        switching = false;
        await nextTick();
        resyncControls();
    }
}

function setNegativesAllowed(allowed: boolean): void {
    const target = field.value;
    if (!target) return;
    const next = withNegativesAllowed(target.validations, allowed);
    if (next === target.validations) {
        resyncControls();
        return;
    }
    target.validations = next;
    props.store.touch(target.uid, 'field');
}

defineExpose({ openDialog });
</script>

<template>
    <div v-if="field" class="field-type" data-field-type-control>
        <div class="field-type__row">
            <span :id="labelId" class="field-type__label">Question type</span>
            <span :id="valueId" class="field-type__value">{{ currentLabel }}</span>
            <MdsButton
                variant="secondary"
                size="sm"
                data-convert-opener
                :aria-describedby="`${labelId} ${valueId}`"
                @click="openDialog()"
            >
                Change type
            </MdsButton>
        </div>
        <div v-if="variant && formatOptions.length > 1" ref="formatRoot" class="field-type__group" data-variant-switch>
            <span class="field-type__label">{{ variant.label }} format</span>
            <MdsSegmentedControl
                :model-value="field.field_type"
                :options="formatOptions"
                :ariaLabel="`${variant.label} format`"
                @update:model-value="switchFormat"
            />
        </div>
        <div v-if="offersNegativeToggle" ref="negativeRoot" class="field-type__group" data-negative-toggle>
            <MdsCheckbox
                :model-value="floor.allowed"
                label="Allow negative numbers"
                :disabled="floor.state === 'custom'"
                :describedby="floor.state === 'custom' ? negativeHintId : undefined"
                @update:model-value="setNegativesAllowed"
            />
            <p v-if="floor.state === 'custom'" :id="negativeHintId" class="field-type__hint">
                A minimum is set on the Validation tab — change it there.
            </p>
        </div>
        <p class="field-type__status" role="status">{{ announcement }}</p>

        <ConvertFieldDialog
            :open="dialog.open"
            :phase="dialog.phase"
            :question-label="questionLabel"
            :current-label="currentLabel"
            :groups="groups"
            :selected="dialog.selected"
            :target-label="targetLabel"
            :view="view"
            :message="dialog.message"
            @update:selected="dialog.selected = $event"
            @confirm="confirm"
            @retry="retry"
            @close="requestClose"
        />
    </div>
</template>

<style scoped>
.field-type {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    min-width: 0;
}

/* Wraps rather than spills: the config column is `overflow-y: auto`, so a row that refuses to shrink becomes a
   horizontal scrollbar at 375px and at the largest text size — the D28 lesson, applied at this host. */
.field-type__row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    min-width: 0;
}

.field-type__label {
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.field-type__value {
    min-width: 0;
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.field-type__group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    min-width: 0;
}

/* The D28 affordance `ConfigPanel` applies to its own segmented control: wrap the segments' flex line rather than let
   "Whole number · Decimal number" refuse to shrink at 375px and the largest text size. */
.field-type__group .mds-segmented {
    flex-wrap: wrap;
}

.field-type__hint {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

/* Empty at rest and so zero-height; never `display: none`, which a screen reader would not announce from. */
.field-type__status {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}
</style>
