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
 * ⚠️ AT RENDER IT READS ONLY `selectedField` AND `palette`. `ConfigPanel.test.ts` mounts the panel with a hand-built
 * store double holding a fixed set of members; everything else here is touched only inside a handler, after plans
 * have loaded. `FieldTypeControl.test.ts` pins that contract against a double, so a render-time read of a member
 * the double lacks fails there rather than in the hub's own suite.
 *
 * ⚠️ IT IS ALSO WHERE `R-a367bf9e`'s VARIANT SWITCH WILL LIVE ("Single line / Paragraph"), once the palette transmits
 * which types are variants of one another: `openDialog(preselect)` is its entry point for a plan that needs review.
 */
import { computed, nextTick, reactive, ref, useId, watch } from 'vue';
import { MdsButton } from '@meridian/design-system';
import ConvertFieldDialog from './ConvertFieldDialog.vue';
import { consequenceView, staleMessage, targetGroups, type ConvertPhase } from './field-conversion';
import type { Uid } from './types';
import type { BuilderStore, ConversionPlan } from './useBuilderStore';

const props = defineProps<{ store: BuilderStore }>();

const field = props.store.selectedField;
const paletteByValue = new Map(props.store.palette.flatMap((group) => group.types.map((type) => [type.value, type] as const)));
const labelOf = (value: string): string => paletteByValue.get(value)?.label ?? value;

const labelId = useId();
const valueId = useId();

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

/* Empty at rest and so zero-height; never `display: none`, which a screen reader would not announce from. */
.field-type__status {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}
</style>
