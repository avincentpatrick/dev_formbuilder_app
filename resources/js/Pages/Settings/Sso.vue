<script setup lang="ts">
/**
 * Single sign-on settings (P1a — ADR-0016).
 *
 * ⚠️ THE DIRECTORY IS CAPITAL-`S` `Pages/Settings/`, MATCHING THE EXISTING ONE. `app.ts` resolves
 * `./Pages/${name}.vue` against a literal-path `import.meta.glob`, so a lowercase `Pages/settings/` would
 * merge with this directory on Windows and macOS and FORK it on Linux CI — green locally, 404 in the
 * pipeline. The controller renders `Settings/Sso` and the two must agree exactly.
 *
 * No layout import: `app.ts` attaches the persistent `AppLayout` to every page outside auth/invitations/admin.
 *
 * ── HOW THIS PAGE IS REACHED, AND WHY THERE IS NO NAV ITEM (§D6) ─────────────────────────────────────
 * `sso_saml` is Enterprise-only and Enterprise is seeded `is_active: false`, so a feature-gated sidebar row
 * would be invisible in every environment and every test — an unreachable registry row. The way in is the
 * signpost card on `/settings`, which renders on **entitled OR configured** so that both an entitled tenant
 * with nothing set up and a downgraded tenant with a live connection have a path.
 *
 * ── THE DOWNGRADE POSTURE, COPIED FROM `domains/Index.vue` ───────────────────────────────────────────
 * A statement of fact and never an upgrade CTA: ADR-0008 §D6 holds Enterprise from sale, so a button here
 * would point at a plan nobody can buy. `can.configure` hides the paid affordances; `can.manage` keeps
 * Disable and Remove, because a tenant must always be able to undo what a paid tier let them create.
 */
import { computed, ref } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { MdsButton, MdsCard, MdsEmptyState, MdsModal } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import SpDetailsCard from '@/components/sso/SpDetailsCard.vue';
import IdpMetadataCard from '@/components/sso/IdpMetadataCard.vue';
import SsoPolicyCard from '@/components/sso/SsoPolicyCard.vue';
import SsoStatusCard from '@/components/sso/SsoStatusCard.vue';
import SsoFailuresCard from '@/components/sso/SsoFailuresCard.vue';
import type { SsoPageProps } from '@/components/sso/types';

const props = defineProps<SsoPageProps>();

const busy = ref(false);
const removeOpen = ref(false);

const isConfigured = computed<boolean>(() => props.data !== null);

function toggle(enabled: boolean): void {
    busy.value = true;
    router.patch(
        '/settings/sso/status',
        { status: enabled ? 'active' : 'disabled' },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
}

function remove(): void {
    busy.value = true;
    router.delete('/settings/sso', {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            removeOpen.value = false;
        },
    });
}
</script>

<template>
    <div class="sso">
        <Head title="Single sign-on" />

        <PageHeader title="Single sign-on" icon="shield" />

        <!-- The downgraded-tenant case. A statement of fact, never an upgrade CTA: Enterprise is held from
             sale, so a button here would point at a plan that cannot be bought. -->
        <MdsCard v-if="!entitled && isConfigured" class="sso__notice">
            <p class="sso__notice-text">
                Single sign-on isn’t part of your current plan, so you can’t change how it’s configured. What
                you have set up keeps working, and you can switch it off or remove it here at any time.
            </p>
        </MdsCard>

        <div v-if="entitled || isConfigured" class="sso__stack">
            <SsoStatusCard
                v-if="data"
                :connection="data"
                :can-manage="can.manage"
                :can-configure="can.configure"
                :busy="busy"
                @toggle="toggle"
                @remove="removeOpen = true"
            />

            <SpDetailsCard :sp="sp" />

            <IdpMetadataCard :connection="data" :can-configure="can.configure" />

            <SsoPolicyCard
                v-if="data"
                :connection="data"
                :roles="roles"
                :name-id-formats="nameIdFormats"
                :attribute-keys="attributeKeys"
                :can-configure="can.configure"
            />

            <!-- P1c. LAST in the stack deliberately: it is a diagnostic an admin comes looking for, not
                 something to walk past on the way to configuring the connection. It is also the one card
                 that stays useful after a downgrade — `can.configure` hides the paid controls above, and
                 the reason a member cannot sign in is not a paid capability. -->
            <SsoFailuresCard v-if="data" :failures="failures" :serving="data.serves_protocol" />
        </div>

        <MdsEmptyState
            v-else
            illustration="default"
            headline="Single sign-on isn’t part of your plan"
            description="Members sign in with their email address and password. Single sign-on lets them use your organisation’s own identity provider instead."
        />

        <MdsModal :open="removeOpen" title="Remove single sign-on?" @close="removeOpen = false">
            <p class="sso__confirm">
                This deletes your identity provider’s details and this workspace’s trust in them. Everyone
                keeps their account and signs in with their email address and password.
            </p>
            <p class="sso__confirm">
                To set it up again you will need to import your provider’s metadata and add this workspace on
                their side once more.
            </p>
            <template #footer>
                <MdsButton variant="secondary" @click="removeOpen = false">Cancel</MdsButton>
                <MdsButton variant="destructive" :loading="busy" @click="remove">Remove</MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
.sso {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.sso__stack {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.sso__notice {
    max-width: 640px;
}

.sso__notice-text {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.sso__confirm {
    margin: 0 0 var(--mds-space-3);
    color: var(--mds-color-text-body);
}

.sso__confirm:last-of-type {
    margin-block-end: 0;
}
</style>
