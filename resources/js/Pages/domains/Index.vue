<script setup lang="ts">
/**
 * Custom domains (Increment H22b / ADR-0012) — the Owner/Admin surface over the H22a lifecycle: claim a
 * hostname, read back the DNS record to publish, watch it verify, and choose which live host respondent
 * links are built on.
 *
 * ── WHAT THIS PAGE DELIBERATELY CANNOT DO ──────────────────────────────────────────────────────────
 * It cannot put a domain into service. `php artisan domains:activate`, run by whoever installed the TLS
 * certificate, is the only code path that sets `activated_at` (ADR-0012 §D6), because per-domain issuance
 * is structurally Track B and Track B is deferred. The page therefore states the wait in words instead of
 * offering a disabled button — see DomainCard.vue.
 *
 * ── WHY THE PAGE ITSELF IS NOT PLAN-GATED ──────────────────────────────────────────────────────────
 * ADR-0012 §D9 leaves the API's reads and deletes ungated, and the web routes mirror that exactly: a
 * tenant downgraded off Business keeps a LIVE, resolving hostname and must still be able to see it and
 * take it down. So `entitled` hides the claim/verify/primary affordances and NOTHING else, and it is not
 * paired with an upgrade CTA — ADR-0008 §D6 seeds Business `is_active = false`, so a call to action would
 * point at a plan nobody can buy.
 *
 * Assembled entirely from shared design-system components. Every mutation is an Inertia visit that
 * redirects with a flash; there is no JSON sidecar, because a refusal on a web route is a 302 even to an
 * XHR (the integrationsClient trap).
 */
import { computed, ref } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { MdsAlert, MdsButton, MdsEmptyState, MdsFormField, MdsModal, MdsTextInput } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import DomainCard from '@/components/domains/DomainCard.vue';
import DomainSetupGuide from '@/components/domains/DomainSetupGuide.vue';
import type { DomainRow } from '@/components/domains/types';

const props = defineProps<{
    data: DomainRow[];
    appHost: string;
    publicHost: string;
    can: { create: boolean; manage: boolean };
    entitled: boolean;
}>();

const addOpen = ref(false);
const removeTarget = ref<DomainRow | null>(null);
/** The hostname of the row whose Inertia visit is in flight, so only that card shows a spinner. */
const busyHost = ref<string | null>(null);

const addForm = useForm({ domain: '' });

const hasDomains = computed(() => props.data.length > 0);

/**
 * A hostname is a path segment here, and it is a segment the ROUTER constrains to `[A-Za-z0-9.-]+` — so a
 * value that would need escaping cannot match the route in the first place. Encoded anyway: relying on a
 * route constraint to make a URL safe means the safety lives in a different file from the construction.
 */
function domainUrl(domain: string, suffix = ''): string {
    return `/domains/${encodeURIComponent(domain)}${suffix}`;
}

function submitAdd(): void {
    addForm.post('/domains', {
        preserveScroll: true,
        onSuccess: () => {
            addOpen.value = false;
            addForm.reset();
        },
    });
}

function verify(domain: DomainRow): void {
    busyHost.value = domain.domain;
    router.post(domainUrl(domain.domain, '/verify'), {}, {
        preserveScroll: true,
        onFinish: () => (busyHost.value = null),
    });
}

function makePrimary(domain: DomainRow): void {
    busyHost.value = domain.domain;
    router.post(domainUrl(domain.domain, '/primary'), {}, {
        preserveScroll: true,
        onFinish: () => (busyHost.value = null),
    });
}

function confirmRemove(): void {
    const target = removeTarget.value;
    if (target === null) return;

    router.delete(domainUrl(target.domain), {
        onFinish: () => (removeTarget.value = null),
    });
}

function openAdd(): void {
    addForm.clearErrors();
    addForm.reset();
    addOpen.value = true;
}
</script>

<template>
    <div class="domains">
        <Head title="Domains" />

        <PageHeader title="Domains" icon="globe">
            <template #actions>
                <MdsButton v-if="can.create" variant="primary" icon-left="plus" @click="openAdd">
                    Add domain
                </MdsButton>
            </template>
        </PageHeader>

        <DomainSetupGuide v-if="entitled || !hasDomains" :app-host="appHost" class="domains__guide" />

        <!-- The downgraded-tenant case (ADR-0012 §D9). A statement of fact, never an upgrade CTA: Business
             is held from sale, so a button here would point at a plan that cannot be bought. -->
        <!-- J4a: an `MdsCard` with no `role` becomes an info `MdsAlert` — the twin of the SSO plan-gate
             notice, and worded to state a fact rather than to upsell. -->
        <MdsAlert
            v-if="!entitled && hasDomains"
            class="domains__notice"
            tone="info"
            message="Custom domains aren’t part of your current plan, so you can’t add or re-check domains. Any domain already in service keeps working, and you can remove it here at any time."
        />

        <div v-if="hasDomains" class="domains__list">
            <DomainCard
                v-for="domain in data"
                :key="domain.domain"
                :domain="domain"
                :can-write="can.create"
                :can-manage="can.manage"
                :busy="busyHost === domain.domain"
                @verify="verify(domain)"
                @primary="makePrimary(domain)"
                @remove="removeTarget = domain"
            />
        </div>

        <MdsEmptyState
            v-else
            illustration="default"
            headline="No custom domains"
            :description="`Your public forms are served at ${appHost}. Add a domain you own to serve them on your own hostname instead.`"
        >
            <template v-if="can.create" #action>
                <MdsButton variant="primary" icon-left="plus" @click="openAdd">Add domain</MdsButton>
            </template>
        </MdsEmptyState>

        <MdsModal :open="addOpen" title="Add a custom domain" @close="addOpen = false">
            <form class="domains__form" @submit.prevent="submitAdd">
                <MdsFormField
                    label="Domain"
                    required
                    help="The hostname your respondents will visit, e.g. forms.example.com. Don’t include https:// or a path."
                    :error="addForm.errors.domain"
                    v-slot="{ id, describedby, invalid }"
                >
                    <MdsTextInput
                        :id="id"
                        v-model="addForm.domain"
                        name="domain"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="forms.example.com"
                        :describedby="describedby"
                        :invalid="invalid"
                    />
                </MdsFormField>
                <p class="domains__form-note">
                    We’ll give you a TXT record to publish. Nothing changes for your respondents until the
                    domain is in service.
                </p>
            </form>
            <template #actions>
                <MdsButton variant="tertiary" @click="addOpen = false">Cancel</MdsButton>
                <MdsButton variant="primary" :loading="addForm.processing" @click="submitAdd">
                    Add domain
                </MdsButton>
            </template>
        </MdsModal>

        <MdsModal :open="removeTarget !== null" title="Remove this domain?" @close="removeTarget = null">
            <p class="domains__confirm">
                <strong>{{ removeTarget?.domain }}</strong> will stop serving your forms immediately and the
                hostname becomes available for anyone to claim.
                <template v-if="removeTarget?.is_public_host">
                    Respondent links will go back to <strong>{{ appHost }}</strong>, so links already sent on
                    this domain will stop working.
                </template>
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="removeTarget = null">Cancel</MdsButton>
                <MdsButton variant="destructive" icon-left="trash" @click="confirmRemove">Remove</MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
.domains {
    display: flex;
    flex-direction: column;
}

.domains__guide,
.domains__notice {
    margin-bottom: var(--mds-space-5);
}


.domains__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.domains__form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.domains__form-note,
.domains__confirm {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.domains__confirm {
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}
</style>
