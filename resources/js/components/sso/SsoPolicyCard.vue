<script setup lang="ts">
/**
 * What happens when someone signs in through the identity provider (P1a — ADR-0016 §D7).
 *
 * ⚠️ THE ROLE PICKER IS BUILT FROM THE `roles` PROP AND NEVER FROM A LITERAL. That list comes from
 * `App\Support\Authorization\AssignableRoles`, which is the same expression the
 * `sso_connections_default_role_check` CHECK is compiled from — so a hard-coded option here would not fail
 * at validation, it would fail as a `SQLSTATE 23514` five hundred. `owner` is absent by construction: RBAC
 * §5 establishes it only by ownership transfer, and an IdP attribute must never be a path to it.
 *
 * ⚠️ THE NameID CONTROL GOES READ-ONLY WHEN THE STORED VALUE IS NOT IN THE PICKER. An import can legitimately
 * write a urn the four-option list does not carry, and silently re-selecting the nearest one would rewrite
 * the tenant's contract with their IdP on their next unrelated save.
 */
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsButton, MdsCard, MdsFormField, MdsIcon, MdsSelect, MdsSwitch, MdsTextInput } from '@meridian/design-system';
import type { SsoConnectionRow, SsoOption } from './types';

const props = defineProps<{
    connection: SsoConnectionRow;
    roles: SsoOption[];
    nameIdFormats: SsoOption[];
    attributeKeys: string[];
    /** Owner/Admin AND the plan — these are the paid controls. */
    canConfigure: boolean;
}>();

/** Human labels for the four attributes an IdP might name differently. */
const ATTRIBUTE_LABELS: Record<string, string> = {
    email: 'Email address',
    name: 'Full name',
    first_name: 'First name',
    last_name: 'Last name',
};

function initialAttributeMap(): Record<string, string> {
    const map: Record<string, string> = {};

    for (const key of props.attributeKeys) {
        map[key] = props.connection.attribute_map[key] ?? '';
    }

    return map;
}

const form = useForm({
    jit_provisioning_enabled: props.connection.jit_provisioning_enabled,
    default_role_name: props.connection.default_role_name,
    name_id_format: props.connection.name_id_format,
    attribute_map: initialAttributeMap(),
});

// A successful import replaces the whole IdP half, including name_id_format — so re-seed rather than let
// the form keep a value the server has already moved on from.
watch(
    () => props.connection,
    (connection): void => {
        form.defaults({
            jit_provisioning_enabled: connection.jit_provisioning_enabled,
            default_role_name: connection.default_role_name,
            name_id_format: connection.name_id_format,
            attribute_map: initialAttributeMap(),
        });
        form.reset();
    },
);

const formatOptions = computed<SsoOption[]>(() =>
    props.connection.name_id_format_known
        ? props.nameIdFormats
        : [...props.nameIdFormats, { value: props.connection.name_id_format, label: props.connection.name_id_format }],
);

function submit(): void {
    form.patch('/settings/sso', { preserveScroll: true });
}
</script>

<template>
    <MdsCard class="settings-card">
        <template #header>
            <div class="settings-card__head">
                <MdsIcon name="users" size="sm" aria-hidden="true" />
                <h2 class="settings-card__title">People and provisioning</h2>
            </div>
        </template>

        <p
            v-if="!connection.name_id_format_is_email"
            class="sso-policy__warning"
            role="status"
        >
            Your provider is set to send a
            <strong>{{ connection.name_id_format.split(':').pop() }}</strong>
            identifier. This workspace matches people by email address, so sign-in will not find an account
            until your provider sends one.
        </p>

        <form class="sso-policy__form" @submit.prevent="submit">
            <fieldset class="sso-policy__fieldset" :disabled="!canConfigure">
                <legend class="sso-policy__legend">Provisioning</legend>

                <div class="settings-row">
                    <div class="settings-row__text">
                        <p class="settings-row__label">Create accounts on first sign-in</p>
                        <p class="settings-row__hint">
                            Someone who signs in through your provider and has no account here gets one
                            automatically. Switch this off to let only people you have already invited in.
                        </p>
                    </div>
                    <MdsSwitch v-model="form.jit_provisioning_enabled" label="Create accounts on first sign-in" />
                </div>

                <MdsFormField
                    label="Role for new accounts"
                    help="What someone can do the first time they sign in. You can change any individual member's role afterwards. Owner is never granted this way."
                    :error="form.errors.default_role_name"
                    v-slot="{ id, describedby, invalid }"
                >
                    <MdsSelect
                        :id="id"
                        v-model="form.default_role_name"
                        name="default_role_name"
                        :options="roles"
                        :describedby="describedby"
                        :invalid="invalid"
                    />
                </MdsFormField>

                <MdsFormField
                    label="Name ID format"
                    help="How your provider identifies people. This workspace resolves accounts by email address."
                    :error="form.errors.name_id_format"
                    v-slot="{ id, describedby, invalid }"
                >
                    <MdsSelect
                        :id="id"
                        v-model="form.name_id_format"
                        name="name_id_format"
                        :options="formatOptions"
                        :disabled="!connection.name_id_format_known"
                        :describedby="describedby"
                        :invalid="invalid"
                    />
                </MdsFormField>

                <legend class="sso-policy__legend sso-policy__legend--sub">Attribute names</legend>
                <p class="sso-policy__hint">
                    Only fill these in if your provider sends attributes under non-standard names. Leave a box
                    empty to use the usual one.
                </p>

                <MdsFormField
                    v-for="key in attributeKeys"
                    :key="key"
                    :label="ATTRIBUTE_LABELS[key] ?? key"
                    :error="form.errors[`attribute_map.${key}` as keyof typeof form.errors] as string | undefined"
                    v-slot="{ id, describedby, invalid }"
                >
                    <MdsTextInput
                        :id="id"
                        v-model="form.attribute_map[key]"
                        :name="`attribute_map[${key}]`"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="key"
                        :describedby="describedby"
                        :invalid="invalid"
                    />
                </MdsFormField>
            </fieldset>

            <div v-if="canConfigure" class="settings-form__foot">
                <MdsButton variant="primary" type="submit" :loading="form.processing">Save</MdsButton>
                <span v-if="form.recentlySuccessful" class="settings-form__saved" role="status">Saved</span>
            </div>
            <p v-else class="sso-policy__hint">
                These settings aren’t part of your current plan, so they can’t be changed. What is configured
                keeps working.
            </p>
        </form>
    </MdsCard>
</template>

<style scoped>
/* Re-declared, not inherited — scoped CSS reaches a child SFC's root node only (the ModulesCard note). */
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
    margin-block-end: var(--mds-space-4);
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

/* A fieldset is what carries `disabled` to every control at once — one attribute rather than a `:disabled`
   on each, which is how one gets missed. It keeps no border of its own. */
.sso-policy__fieldset {
    margin: 0;
    padding: 0;
    border: 0;
    min-inline-size: 0;
}

.sso-policy__legend {
    padding: 0;
    margin-block-end: var(--mds-space-3);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.sso-policy__legend--sub {
    margin-block-start: var(--mds-space-5);
}

.sso-policy__hint {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

/* `-fg`, never `-bg`, for a coloured indicator — the J2a WCAG 1.4.11 finding. */
.sso-policy__warning {
    margin: 0 0 var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-status-warning-fg);
}
</style>
