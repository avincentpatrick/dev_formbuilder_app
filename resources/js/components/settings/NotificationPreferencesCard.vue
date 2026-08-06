<script setup lang="ts">
/**
 * Per-type notification preferences — the /settings card (Increment I4, PRD Feature #13b,
 * data-dictionary §23).
 *
 * Autosave-on-change, like the Appearance panel and unlike Profile/Drafts: seven types × two channels
 * behind one Save button is a form nobody finishes, and a control that stays unsaved until you find a
 * button reads as broken.
 *
 * I5 swapped all fourteen controls from `MdsCheckbox` to `MdsSwitch` — these are per-channel ON/OFF states,
 * not a selection out of a set, and I5's App Settings panels below turned "we have no switch" from a
 * defensible gap into an inconsistency. The swap was mechanical BY DESIGN: `MdsSwitch` keeps `MdsCheckbox`'s
 * exact prop and event contract over a real `<input type="checkbox">`, so the locked treatment below and
 * every `input[type="checkbox"]` locator in this card's spec survived it untouched.
 *
 * ⚠️ BOTH BOOLEANS GO IN EVERY WRITE, and that is not belt-and-braces. The columns default `true`/`true`,
 * which DISAGREES with `NotificationType::SubmissionReceived`'s email default of `false` — so a partial
 * write leaning on the column default would silently opt a user INTO the one type the PRD says to keep
 * quiet. `UpdateNotificationPreferenceRequest` makes both `required` and
 * `NotificationPreferenceResolver::set()` spells the same rule again on the write side.
 *
 * ⚠️ A LOCKED EMAIL CONTROL IS ON AND DISABLED, NEVER A LIVE CONTROL.
 * `NotificationType::honorsEmailPreference()` is false for `export_ready` and `webhook_failed`: their
 * purpose-built branded emails predate this table and are sent by the emitting job regardless of anything
 * stored. A toggle wired to nothing that appears to turn something off is worse than no toggle.
 *
 * ⚠️ THE `.settings-*` RULES ARE RE-DECLARED AT THE BOTTOM OF THIS FILE RATHER THAN INHERITED.
 * Vue applies a parent's scope id to a child component's ROOT node only, so `Settings/Index.vue`'s
 * `<style scoped>` reaches `.settings-card` and nothing inside it. Every card component on this page now
 * carries the same block; `BrandingCard.vue` was the one that did not, which is why its head was unstyled
 * until I5 fixed it.
 */
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsCard, MdsIcon, MdsSwitch } from '@meridian/design-system';
import type { NotificationPreferenceRow } from '@/components/notifications/types';

const props = defineProps<{ preferences: NotificationPreferenceRow[] }>();

/** Optimistic local copy — the useTheme shape: paint now, PATCH, re-sync from props on the redirect. */
const local = ref<NotificationPreferenceRow[]>(props.preferences.map((row) => ({ ...row })));

watch(
    () => props.preferences,
    (next) => {
        local.value = next.map((row) => ({ ...row }));
    },
    { deep: true },
);

/**
 * ONE useForm reused by all seven rows, rather than raw `router.patch` (useTheme) or seven forms: it buys
 * `recentlySuccessful` — the exact "Saved" affordance every other card on this page uses — and
 * `processing`, for free, from an idiom already in the tree.
 */
const form = useForm({ notification_type: '', in_app_enabled: true, email_enabled: true });

function persist(row: NotificationPreferenceRow): void {
    form.notification_type = row.type;
    form.in_app_enabled = row.in_app;
    form.email_enabled = row.email;
    form.patch('/settings/notifications', {
        preserveScroll: true,
        preserveState: true,
        // A rejected write must not leave a checkbox showing a state the server does not hold.
        onError: () => {
            local.value = props.preferences.map((r) => ({ ...r }));
        },
    });
}

function setInApp(row: NotificationPreferenceRow, value: boolean): void {
    row.in_app = value;
    persist(row);
}

function setEmail(row: NotificationPreferenceRow, value: boolean): void {
    // Defence in depth: the control is already disabled, so this cannot normally be reached — but the
    // server would force the value back anyway, and a PATCH that changes nothing is still a PATCH.
    if (row.email_locked) return;

    row.email = value;
    persist(row);
}

const hintId = (type: string): string => `notification-pref-${type}-locked`;
</script>

<template>
    <MdsCard class="settings-card">
        <template #header>
            <div class="settings-card__head">
                <MdsIcon name="bell" size="sm" aria-hidden="true" />
                <h2 class="settings-card__title">Notifications</h2>
            </div>
        </template>

        <p class="prefs__lede">
            Choose what you’re told about, and where. “In app” shows in the bell at the top of every page;
            “Email” sends a message to your address. These choices are yours alone, and apply only to this
            workspace.
        </p>

        <!-- A real <fieldset>/<legend> per type, so each control has a GROUP name: without it a screen
             reader announces "Email switch" seven times with nothing to tell them apart. Stacked rather
             than label-left/controls-right so nothing can overflow at 375px under the §2.9 extra-large
             text scale. -->
        <fieldset v-for="row in local" :key="row.type" class="prefs__row">
            <legend class="settings-row__label">{{ row.label }}</legend>

            <p v-if="row.email_locked" :id="hintId(row.type)" class="settings-row__hint">
                We always email this one — it reports something you asked for, or something that has
                stopped working.
            </p>

            <div class="prefs__channels">
                <MdsSwitch
                    :model-value="row.in_app"
                    label="In app"
                    @update:model-value="(value: boolean) => setInApp(row, value)"
                />
                <MdsSwitch
                    v-if="row.email_locked"
                    :model-value="true"
                    label="Email"
                    disabled
                    :describedby="hintId(row.type)"
                />
                <MdsSwitch
                    v-else
                    :model-value="row.email"
                    label="Email"
                    @update:model-value="(value: boolean) => setEmail(row, value)"
                />
            </div>
        </fieldset>

        <div class="settings-form__foot">
            <span v-if="form.recentlySuccessful" class="settings-form__saved" role="status">Saved</span>
        </div>
    </MdsCard>
</template>

<style scoped>
.prefs__lede {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

/* Reset to a block so <legend> lays out predictably — it is not a flex item in Chrome. */
.prefs__row {
    display: block;
    min-inline-size: 0;
    margin: 0;
    padding: 0;
    border: 0;
}

/* The Appearance card's row separator, so each type reads as its own decision rather than as one dense
   block of fourteen switches. */
.prefs__row + .prefs__row {
    margin-block-start: var(--mds-space-4);
    padding-block-start: var(--mds-space-4);
    border-block-start: 1px solid var(--mds-color-border-default);
}

.prefs__channels {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-1) var(--mds-space-5);
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

.settings-row__label {
    margin: 0 0 var(--mds-space-1);
    padding: 0;
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.settings-row__hint {
    margin: 0 0 var(--mds-space-1);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
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
