<script setup lang="ts">
/**
 * Styled two-factor-authentication enrolment (PRD Feature #14). Drives Fortify's own global 2FA
 * endpoints — this owns only the presentation. Three states, from the `enabled`/`confirmed` props the
 * host page passes (read from the user each load):
 *   - off              → "Enable" (POST /user/two-factor-authentication)
 *   - enabled, unconfirmed → show the QR + recovery codes (fetched from Fortify's JSON endpoints), then
 *                            confirm with a TOTP code (POST /user/confirmed-two-factor-authentication)
 *   - confirmed        → on; regenerate recovery codes / turn off
 * Fortify's `confirmPassword` feature may interpose a password-confirmation step; that is Fortify's own
 * redirect flow (the ConfirmPassword page), not re-implemented here.
 */
import { ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { MdsButton, MdsFormField, MdsTextInput } from '@meridian/design-system';

const props = defineProps<{ enabled: boolean; confirmed: boolean }>();

const qrSvg = ref<string>('');
const recoveryCodes = ref<string[]>([]);
const loading = ref(false);

const confirmForm = useForm({ code: '' });
const busy = ref(false);

async function loadSetup(): Promise<void> {
    loading.value = true;
    try {
        const [qrRes, rcRes] = await Promise.all([
            fetch('/user/two-factor-qr-code', { headers: { Accept: 'application/json' } }),
            fetch('/user/two-factor-recovery-codes', { headers: { Accept: 'application/json' } }),
        ]);
        qrSvg.value = ((await qrRes.json()) as { svg: string }).svg;
        recoveryCodes.value = (await rcRes.json()) as string[];
    } finally {
        loading.value = false;
    }
}

// Whenever we're enabled-but-unconfirmed (on mount or right after enabling), pull the QR + codes.
watch(
    () => props.enabled && !props.confirmed,
    (needSetup) => {
        if (needSetup) void loadSetup();
    },
    { immediate: true },
);

function enable(): void {
    busy.value = true;
    router.post('/user/two-factor-authentication', {}, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
        },
    });
}

function confirm(): void {
    confirmForm.post('/user/confirmed-two-factor-authentication', {
        preserveScroll: true,
        onSuccess: () => confirmForm.reset('code'),
    });
}

function disable(): void {
    busy.value = true;
    router.delete('/user/two-factor-authentication', {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
        },
    });
}

function regenerate(): void {
    busy.value = true;
    router.post('/user/two-factor-recovery-codes', {}, {
        preserveScroll: true,
        onFinish: () => {
            busy.value = false;
        },
        onSuccess: () => void loadSetup(),
    });
}
</script>

<template>
    <div class="tfa">
        <!-- Off -->
        <template v-if="!enabled">
            <p class="tfa__prose">
                Add a second step at sign-in using an authenticator app (Google Authenticator, 1Password,
                Authy). You'll enter a rotating code in addition to your password.
            </p>
            <MdsButton variant="primary" :loading="busy" @click="enable">
                Enable two-factor authentication
            </MdsButton>
        </template>

        <!-- Enabled, awaiting confirmation -->
        <template v-else-if="!confirmed">
            <p class="tfa__prose">
                Scan this QR code with your authenticator app, then enter the 6-digit code it shows to
                finish. Save your recovery codes somewhere safe — they let you sign in if you lose your
                device.
            </p>

            <div class="tfa__setup">
                <div class="tfa__qr" v-html="qrSvg" />
                <div class="tfa__codes" aria-label="Recovery codes">
                    <p class="tfa__codes-label">Recovery codes</p>
                    <ul class="tfa__codes-list">
                        <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
                    </ul>
                </div>
            </div>

            <form class="tfa__confirm" @submit.prevent="confirm">
                <MdsFormField
                    label="Confirmation code"
                    required
                    :error="confirmForm.errors.code"
                    v-slot="{ id, describedby, invalid }"
                >
                    <MdsTextInput
                        :id="id"
                        v-model="confirmForm.code"
                        name="code"
                        autocomplete="one-time-code"
                        :describedby="describedby"
                        :invalid="invalid"
                        placeholder="123456"
                    />
                </MdsFormField>
                <div class="tfa__actions">
                    <MdsButton variant="primary" type="submit" :loading="confirmForm.processing">
                        Confirm
                    </MdsButton>
                    <MdsButton variant="tertiary" type="button" @click="disable">Cancel</MdsButton>
                </div>
            </form>
        </template>

        <!-- Confirmed -->
        <template v-else>
            <p class="tfa__on">Two-factor authentication is on.</p>
            <p class="tfa__prose">
                You'll be asked for a code from your authenticator app when you sign in.
            </p>
            <div class="tfa__actions">
                <MdsButton variant="secondary" :loading="busy" @click="regenerate">
                    Regenerate recovery codes
                </MdsButton>
                <MdsButton variant="tertiary" :loading="busy" @click="disable">Turn off</MdsButton>
            </div>
            <div v-if="recoveryCodes.length" class="tfa__codes" aria-label="Recovery codes">
                <p class="tfa__codes-label">New recovery codes</p>
                <ul class="tfa__codes-list">
                    <li v-for="code in recoveryCodes" :key="code">{{ code }}</li>
                </ul>
            </div>
        </template>
    </div>
</template>

<style scoped>
.tfa {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.tfa__prose {
    margin: 0;
    max-width: 60ch;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-secondary);
}

.tfa__on {
    margin: 0;
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-status-success-fg);
}

.tfa__setup {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-6);
}

.tfa__qr {
    padding: var(--mds-space-3);
    /* QR must stay on true white in BOTH themes so scanners read the black modules — a surface token
       would flip to the dark ground and break scanning. This fixed white is a deliberate exception. */
    background-color: #ffffff;
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
}
.tfa__qr :deep(svg) {
    display: block;
    width: 160px;
    height: 160px;
}

.tfa__codes-label {
    margin: 0 0 var(--mds-space-2);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-body);
}

.tfa__codes-list {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--mds-space-1) var(--mds-space-4);
    margin: 0;
    padding: 0;
    list-style: none;
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-code-font-size);
    color: var(--mds-color-text-body);
}

.tfa__confirm {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    max-width: 320px;
}

.tfa__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}
</style>
