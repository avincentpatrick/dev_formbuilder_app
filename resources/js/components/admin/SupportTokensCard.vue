<script setup lang="ts">
/**
 * Support tokens — the console card that issues the credential the dev session's feedback pull uses (M175,
 * `R-78f43f26`, `D139`, `D141`; ADR-0023).
 *
 * ── THE SECRET IS SHOWN ONCE, FROM THE FLASH, NEVER FROM THE PROPS ───────────────────────────────────────
 * `tokens` carries names and dates only; the server keeps a hash. The plaintext arrives once, in the one-shot
 * `flash.newSupportToken`, and this card reveals it in a dialog with a Copy button and a plain warning. Close
 * the dialog and it is gone — a lost token is revoked and replaced.
 *
 * ── AN EXPIRY IS NOT OPTIONAL ─────────────────────────────────────────────────────────────────────────────
 * The token carries no session, so the console's step-up never asks again; its lifetime is the only clock on
 * it. The choice is 30 or 90 days, and there is no "never".
 *
 * Revoking asks first, because a revoked token stops the pull until a new one is saved on the dev machine.
 */
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsCard,
    MdsFormField,
    MdsIcon,
    MdsModal,
    MdsSelect,
    MdsTextInput,
} from '@meridian/design-system';
import type { SupportTokenRow } from './types';

defineProps<{ tokens: SupportTokenRow[] }>();

const page = usePage();

const name = ref('');
const expiry = ref('90');
const expiryOptions = [
    { value: '30', label: '30 days' },
    { value: '90', label: '90 days' },
];
const busy = ref(false);

/** The one-shot plaintext, held only while its dialog is open. */
const revealed = ref<{ token: string; name: string } | null>(null);
const copied = ref(false);
const flashToken = computed(
    () => (page.props.flash as { newSupportToken?: { token: string; name: string } | null } | undefined)?.newSupportToken ?? null,
);
watch(
    flashToken,
    (value) => {
        if (value) {
            revealed.value = value;
            copied.value = false;
        }
    },
    { immediate: true },
);

const revoking = ref<SupportTokenRow | null>(null);

function create(): void {
    if (name.value.trim() === '') return;
    busy.value = true;
    router.post(
        '/admin/support-tokens',
        { name: name.value.trim(), expires_in_days: Number(expiry.value) },
        {
            preserveScroll: true,
            onSuccess: () => (name.value = ''),
            onFinish: () => (busy.value = false),
        },
    );
}

async function copy(): Promise<void> {
    if (revealed.value === null) return;
    try {
        await navigator.clipboard?.writeText(revealed.value.token);
        copied.value = true;
    } catch {
        copied.value = false;
    }
}

function revoke(): void {
    if (revoking.value === null) return;
    const id = revoking.value.id;
    busy.value = true;
    router.delete(`/admin/support-tokens/${id}`, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
            revoking.value = null;
        },
    });
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
</script>

<template>
    <MdsCard class="tokens">
        <template #header>
            <div class="tokens__head">
                <MdsIcon name="shield" size="sm" aria-hidden="true" />
                <h2 class="tokens__title">Support tokens</h2>
            </div>
        </template>

        <p class="tokens__lead">
            A support token lets the development team's feedback pull read every workspace's feedback and reply
            to it. It works until it expires or you revoke it, and it is shown only once.
        </p>

        <ul v-if="tokens.length > 0" class="tokens__list">
            <li v-for="token in tokens" :key="token.id" class="tokens__row">
                <div class="tokens__what">
                    <span class="tokens__name">{{ token.name }}</span>
                    <span class="tokens__meta">
                        {{ token.owner?.email ?? 'Former super-admin' }} · expires {{ formatDate(token.expires_at) }} ·
                        {{ token.last_used_at ? `last used ${formatDate(token.last_used_at)}` : 'Never used' }}
                    </span>
                </div>
                <MdsBadge v-if="token.state === 'revoked'" variant="neutral" label="Revoked" />
                <MdsBadge v-else-if="token.state === 'expired'" variant="warning" label="Expired" />
                <MdsButton v-else variant="tertiary" size="sm" @click="revoking = token">Revoke</MdsButton>
            </li>
        </ul>
        <p v-else class="tokens__empty">No support tokens yet.</p>

        <div class="tokens__create">
            <MdsFormField label="What is it for?" input-id="support-token-name">
                <MdsTextInput id="support-token-name" v-model="name" placeholder="Dev laptop — feedback pull" />
            </MdsFormField>
            <MdsFormField label="Expires after" input-id="support-token-expiry">
                <MdsSelect id="support-token-expiry" v-model="expiry" :options="expiryOptions" />
            </MdsFormField>
            <div class="tokens__actions">
                <MdsButton variant="secondary" :loading="busy" :disabled="name.trim() === ''" @click="create">
                    Create token
                </MdsButton>
            </div>
        </div>

        <MdsModal :open="revealed !== null" title="Support token created" @close="revealed = null">
            <div v-if="revealed" class="tokens__reveal">
                <p class="tokens__lead">
                    This is the token for <strong>{{ revealed.name }}</strong>. Copy it now — it won't be shown
                    again. Save it on the development machine as the feedback pull's token.
                </p>
                <MdsFormField label="Token" input-id="support-token-plain">
                    <MdsTextInput id="support-token-plain" :model-value="revealed.token" readonly />
                </MdsFormField>
            </div>
            <template #actions>
                <MdsButton variant="secondary" @click="copy">{{ copied ? 'Copied' : 'Copy token' }}</MdsButton>
                <MdsButton variant="primary" @click="revealed = null">Done</MdsButton>
            </template>
        </MdsModal>

        <MdsModal :open="revoking !== null" title="Revoke this support token?" @close="revoking = null">
            <p v-if="revoking" class="tokens__lead">
                <strong>{{ revoking.name }}</strong> stops working at once. Revoking it stops the feedback pull
                until a new token is created and saved on the development machine.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="revoking = null">Keep it</MdsButton>
                <MdsButton variant="destructive" :loading="busy" @click="revoke">Revoke token</MdsButton>
            </template>
        </MdsModal>
    </MdsCard>
</template>

<style scoped>
.tokens__head {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    color: var(--mds-color-text-secondary);
}

.tokens__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-sm-font-size);
    color: var(--mds-color-text-heading);
}

.tokens__lead {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.tokens__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0 0 var(--mds-space-4);
    padding: 0;
    list-style: none;
}

.tokens__row {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-3);
    padding: var(--mds-space-2) 0;
    border-bottom: 1px solid var(--mds-color-border-default);
}

.tokens__what {
    display: flex;
    flex-direction: column;
    min-width: 0;
}

.tokens__name {
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
    overflow-wrap: anywhere;
}

.tokens__meta {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
    overflow-wrap: anywhere;
}

.tokens__empty {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-secondary);
}

.tokens__create {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 14rem), 1fr));
    gap: var(--mds-space-3);
    align-items: end;
}

.tokens__actions {
    display: flex;
    align-items: flex-end;
}

.tokens__reveal :deep(input) {
    font-family: var(--mds-font-family-mono);
}
</style>
