<script setup lang="ts">
/**
 * Import the identity provider's metadata, and show what the stored trust anchor is (P1a — ADR-0016 §D10/§D11).
 *
 * ⚠️ THE FILE PICKER READS THE DOCUMENT CLIENT-SIDE INTO THE SAME TEXTAREA, AND THAT IS NOT A CONVENIENCE.
 * `@inertiajs/core` does NOT method-spoof — there is no `_method` anywhere in the bundle — and it converts
 * any payload containing a `File` into `FormData` while KEEPING the verb. PHP populates `$_POST`/`$_FILES`
 * only for a multipart POST, so a `PUT` carrying a real upload arrives with an EMPTY request body and every
 * field 422s "required" with no visible cause. Reading the file here keeps the wire JSON, which is what lets
 * the route stay a `PUT`. If this ever becomes a genuine multipart upload, the route must become a `POST`.
 *
 * ⚠️ THE CERTIFICATE BODY IS NOT AVAILABLE TO THIS COMPONENT AND MUST NOT BECOME AVAILABLE. The props carry
 * facts ABOUT each key — subject, issuer, validity window, thumbprint, state — computed server-side by
 * `SsoCertificateInspector`. `types.ts` has no field that could hold the key itself, which makes vue-tsc a
 * second guard on the presenter's promise.
 *
 * The parser's refusals arrive as a `metadata_xml` field error rather than a toast: the paste box stays on
 * screen, the failure is attributable to the one field the admin typed into, and `withInput()` preserves a
 * multi-KB paste they would otherwise re-fetch from their IdP.
 */
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { MdsBadge, MdsButton, MdsCard, MdsFormField, MdsIcon, MdsTextarea } from '@meridian/design-system';
import type { BadgeVariant } from '@meridian/design-system';
import type { CertificateState, SsoConnectionRow } from './types';

const props = defineProps<{
    connection: SsoConnectionRow | null;
    /** Owner/Admin AND the plan. An import creates or replaces the trust anchor, so it is the gated write. */
    canConfigure: boolean;
}>();

const form = useForm({ metadata_xml: '' });
const fileInput = ref<HTMLInputElement | null>(null);
const fileError = ref<string | null>(null);

const CERTIFICATE_TONE: Record<CertificateState, BadgeVariant> = {
    valid: 'success',
    expiring_soon: 'warning',
    expired: 'danger',
    // Neutral, NOT a warning: a successor published ahead of a rollover is an IdP doing the right thing,
    // and colouring it as a problem is how an admin learns to ignore the indicator (ADR-0016 §D11).
    not_yet_valid: 'neutral',
    unreadable: 'danger',
};

const CERTIFICATE_LABEL: Record<CertificateState, string> = {
    valid: 'In use',
    expiring_soon: 'Expiring soon',
    expired: 'Expired',
    not_yet_valid: 'Not yet active',
    unreadable: 'Unreadable',
};

const warningTone = computed<'warning' | 'danger'>(() =>
    props.connection?.certificates_state === 'expiring_soon' ? 'warning' : 'danger',
);

function submit(): void {
    form.put('/settings/sso/idp-metadata', {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

async function loadFile(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];

    fileError.value = null;

    if (!file) {
        return;
    }

    try {
        form.metadata_xml = await file.text();
        form.clearErrors('metadata_xml');
    } catch {
        fileError.value = 'That file could not be read. Open it and paste its contents instead.';
    } finally {
        // Reset so choosing the SAME file twice fires `change` again — otherwise a failed import cannot be
        // retried from the picker without first choosing something else.
        input.value = '';
    }
}

/** A date, rendered in the viewer's locale. The DAY COUNT is server-computed and never recalculated here. */
function formatDate(iso: string | null): string {
    if (!iso) {
        return '—';
    }

    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}
</script>

<template>
    <MdsCard class="settings-card">
        <template #header>
            <div class="settings-card__head">
                <MdsIcon name="upload" size="sm" aria-hidden="true" />
                <h2 class="settings-card__title">Identity provider</h2>
            </div>
        </template>

        <!-- The stored anchor, when there is one. -->
        <div v-if="connection" class="sso-idp__current">
            <dl class="sso-idp__facts">
                <dt class="sso-idp__term">Entity ID</dt>
                <dd class="sso-idp__def"><code class="sso-idp__code">{{ connection.idp_entity_id }}</code></dd>
                <dt class="sso-idp__term">Sign-in URL</dt>
                <dd class="sso-idp__def"><code class="sso-idp__code">{{ connection.idp_sso_url }}</code></dd>
                <dt class="sso-idp__term">Imported</dt>
                <dd class="sso-idp__def">{{ formatDate(connection.metadata_imported_at) }}</dd>
                <dt class="sso-idp__term">Key set fingerprint</dt>
                <dd class="sso-idp__def"><code class="sso-idp__code">{{ connection.fingerprint_short }}</code></dd>
            </dl>

            <p
                v-if="connection.certificate_warning"
                class="sso-idp__warning"
                :class="`sso-idp__warning--${warningTone}`"
                role="status"
            >
                {{ connection.certificate_warning }}
            </p>

            <h3 class="sso-idp__subtitle">
                Signing {{ connection.certificate_count === 1 ? 'certificate' : 'certificates' }}
            </h3>
            <ul class="sso-idp__certs">
                <li v-for="(certificate, index) in connection.certificates" :key="index" class="sso-idp__cert">
                    <div class="sso-idp__cert-head">
                        <span class="sso-idp__cert-name">{{ certificate.subject ?? 'Unnamed certificate' }}</span>
                        <MdsBadge
                            :variant="CERTIFICATE_TONE[certificate.state]"
                            :label="CERTIFICATE_LABEL[certificate.state]"
                        />
                    </div>
                    <p class="sso-idp__cert-meta">
                        <template v-if="certificate.state === 'unreadable'">
                            This certificate can no longer be read. Re-import your provider’s metadata.
                        </template>
                        <template v-else>
                            <template v-if="certificate.thumbprint_short">
                                Thumbprint {{ certificate.thumbprint_short }} ·
                            </template>
                            Valid {{ formatDate(certificate.not_before) }} – {{ formatDate(certificate.not_after) }}
                            <template v-if="certificate.expires_in_days !== null">
                                ·
                                <template v-if="certificate.expires_in_days < 0">
                                    expired {{ Math.abs(certificate.expires_in_days) }} days ago
                                </template>
                                <template v-else-if="certificate.state === 'not_yet_valid'">
                                    the replacement your provider has published ahead of a key change
                                </template>
                                <template v-else>
                                    {{ certificate.expires_in_days }} days left
                                </template>
                            </template>
                        </template>
                    </p>
                </li>
            </ul>
        </div>

        <p v-else class="sso-idp__lede">
            Paste the XML metadata document from your identity provider. It tells this workspace which sign-in
            service to trust and which keys it signs with.
        </p>

        <form v-if="canConfigure" class="sso-idp__form" @submit.prevent="submit">
            <MdsFormField
                :label="connection ? 'Replace metadata' : 'Identity provider metadata'"
                required
                help="Paste the XML, or load it from a file your provider gave you. Re-importing is also how you pick up a new signing key."
                :error="form.errors.metadata_xml ?? fileError ?? undefined"
                v-slot="{ id, describedby, invalid }"
            >
                <MdsTextarea
                    :id="id"
                    v-model="form.metadata_xml"
                    name="metadata_xml"
                    :rows="8"
                    placeholder="&lt;EntityDescriptor xmlns=&quot;urn:oasis:names:tc:SAML:2.0:metadata&quot; …&gt;"
                    :describedby="describedby"
                    :invalid="invalid"
                />
            </MdsFormField>

            <div class="settings-form__foot">
                <MdsButton variant="primary" type="submit" :loading="form.processing">
                    {{ connection ? 'Replace metadata' : 'Import metadata' }}
                </MdsButton>
                <MdsButton variant="secondary" type="button" icon-left="upload" @click="fileInput?.click()">
                    Load from file
                </MdsButton>
                <!-- Driven by the visible button above; hidden from the tab order so there is one control,
                     not two, and labelled for anyone who reaches it another way. -->
                <input
                    ref="fileInput"
                    class="sso-idp__file"
                    type="file"
                    accept=".xml,text/xml,application/xml,application/samlmetadata+xml"
                    tabindex="-1"
                    aria-hidden="true"
                    @change="loadFile"
                />
            </div>
        </form>

        <p v-else-if="connection" class="sso-idp__hint">
            Changing your identity provider’s details isn’t part of your current plan. What you have already
            configured keeps working, and you can still switch single sign-on off or remove it.
        </p>
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

.settings-form__foot {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-3);
    margin-block-start: var(--mds-space-4);
    min-block-size: 1.5rem;
}

.sso-idp__lede,
.sso-idp__hint {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.sso-idp__facts {
    margin: 0 0 var(--mds-space-4);
}

.sso-idp__term {
    margin-block-end: var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.sso-idp__def {
    margin: 0 0 var(--mds-space-3);
    min-width: 0;
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-sm-font-size);
}

.sso-idp__code {
    display: block;
    overflow-x: auto;
    white-space: nowrap;
    padding: var(--mds-space-2) var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-bg-sunken);
    font-family: var(--mds-font-family-mono);
    color: var(--mds-color-text-body);
}

.sso-idp__subtitle {
    margin: 0 0 var(--mds-space-2);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

/* `-fg`, never `-bg`, for a coloured indicator — the J2a WCAG 1.4.11 finding. */
.sso-idp__warning {
    margin: 0 0 var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.sso-idp__warning--warning {
    color: var(--mds-color-status-warning-fg);
}

.sso-idp__warning--danger {
    color: var(--mds-color-status-danger-fg);
}

.sso-idp__certs {
    margin: 0 0 var(--mds-space-4);
    padding: 0;
    list-style: none;
}

.sso-idp__cert + .sso-idp__cert {
    margin-block-start: var(--mds-space-3);
    padding-block-start: var(--mds-space-3);
    border-block-start: 1px solid var(--mds-color-border-default);
}

.sso-idp__cert-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

.sso-idp__cert-name {
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.sso-idp__cert-meta {
    margin: var(--mds-space-1) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.sso-idp__form {
    margin-block-start: var(--mds-space-4);
}

.sso-idp__file {
    display: none;
}
</style>
