<script setup lang="ts">
/**
 * Settings → Modules (Increment I5, PRD Feature #10's "per-module toggles").
 *
 * ⚠️ TWO STATES, NOT ONE, AND THE CARD MUST SAY WHICH. A module can be missing because the plan does not
 * include it, or because this workspace switched it off — and those need different answers ("upgrade" vs
 * "switch it back on"). The server sends `plan_granted` and `enabled` separately for exactly that reason;
 * do not collapse them into one boolean, and do not read the shared `entitlements` prop here (it reports
 * the COMPOSED result, which is right for gating a nav item and wrong for explaining an absence).
 *
 * A tenant can only ever switch a module OFF: `EntitlementService::feature()` ANDs this toggle with the
 * plan flag, so a plan-denied row is rendered as a disabled switch rather than as a live one that would
 * appear to grant something. That is why the disabled control still shows its real (off) position.
 *
 * Autosave-on-change, one module per request — see UpdateModuleSettingsRequest for why the write is not a
 * whole map.
 *
 * ⚠️ THE `.settings-*` RULES ARE RE-DECLARED AT THE BOTTOM — scoped CSS reaches a child SFC's root node only.
 */
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsCard, MdsIcon, MdsSwitch } from '@meridian/design-system';

type ModuleRow = {
    key: string;
    label: string;
    hint: string;
    plan_granted: boolean;
    enabled: boolean;
};

const props = defineProps<{ modules: ModuleRow[] }>();

/** Optimistic local copy — the NotificationPreferencesCard shape: paint now, PATCH, re-sync from props. */
const local = ref<ModuleRow[]>(props.modules.map((row) => ({ ...row })));

watch(
    () => props.modules,
    (next) => {
        local.value = next.map((row) => ({ ...row }));
    },
    { deep: true },
);

const form = useForm({ module: '', enabled: true });

function setEnabled(row: ModuleRow, value: boolean): void {
    // Defence in depth: the control is already disabled for a plan-denied module, and the server would
    // ignore the value anyway (the plan flag wins the AND), so a write here would be a no-op audit row.
    if (!row.plan_granted) return;

    row.enabled = value;
    form.module = row.key;
    form.enabled = value;
    form.patch('/settings/modules', {
        preserveScroll: true,
        preserveState: true,
        onError: () => {
            local.value = props.modules.map((r) => ({ ...r }));
        },
    });
}

const hintId = (key: string): string => `module-${key}-unavailable`;
</script>

<template>
    <MdsCard class="settings-card">
        <template #header>
            <div class="settings-card__head">
                <MdsIcon name="sliders" size="sm" aria-hidden="true" />
                <h2 class="settings-card__title">Modules</h2>
            </div>
        </template>

        <p class="modules__lede">
            Switch off capabilities your team doesn’t use. Turning one off hides it everywhere and stops it
            working — for everyone in this workspace, not just for you. Anything your plan doesn’t include is
            shown here too, so you can see what an upgrade would add.
        </p>

        <div v-for="row in local" :key="row.key" class="settings-row">
            <div class="settings-row__text">
                <p class="settings-row__label">{{ row.label }}</p>
                <p v-if="!row.plan_granted" :id="hintId(row.key)" class="settings-row__hint">
                    Not included in your current plan.
                </p>
                <p v-else class="settings-row__hint">{{ row.hint }}</p>
            </div>
            <MdsSwitch
                v-if="row.plan_granted"
                :model-value="row.enabled"
                :label="row.label"
                @update:model-value="(value: boolean) => setEnabled(row, value)"
            />
            <MdsSwitch
                v-else
                :model-value="false"
                :label="row.label"
                disabled
                :describedby="hintId(row.key)"
            />
        </div>

        <div class="settings-form__foot">
            <span v-if="form.recentlySuccessful" class="settings-form__saved" role="status">Saved</span>
        </div>
    </MdsCard>
</template>

<style scoped>
.modules__lede {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

/* Re-declared, not inherited — see the header note about scoped CSS and child SFCs. */
.settings-card {
    max-width: 640px;
}

.settings-card__head {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    color: var(--mds-color-text-secondary);
}

.settings-card__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.settings-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2) var(--mds-space-4);
}

/* Eleven rows in one card — separate them so each reads as its own decision, the Appearance/Notifications
   device. */
.settings-row + .settings-row {
    margin-block-start: var(--mds-space-3);
    padding-block-start: var(--mds-space-3);
    border-block-start: 1px solid var(--mds-color-border-default);
}

.settings-row__text {
    min-width: 12rem;
    flex: 1;
}

.settings-row__label {
    margin: 0 0 var(--mds-space-1);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.settings-row__hint {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.settings-form__foot {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
    margin-block-start: var(--mds-space-4);
    min-block-size: 1.5rem;
}

.settings-form__saved {
    color: var(--mds-color-status-success-fg);
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
