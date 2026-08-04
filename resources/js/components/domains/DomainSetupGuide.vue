<script setup lang="ts">
/**
 * "How a custom domain goes live" (Increment H22b / ADR-0012, deployment-infrastructure.md §8.1). A pure
 * help card — no backend, no network — on the ZapierRecipe.vue shape.
 *
 * ⚠️ THE HONESTY IS THE FEATURE, NOT THE COPY-EDITING. The single most surprising thing about this
 * product surface is that proving control of a hostname does NOT put it into service: per-domain TLS
 * issuance is structurally Track B, Track B is deferred, and until it exists a certificate is installed by
 * a person. ADR-0012 §D6 turns that runbook step into the only code path that can set `activated_at`.
 *
 * So step 3 belongs to us and says so. A tenant who publishes a TXT record, sees "verified", and is left
 * to conclude the hostname now works will point live traffic at an origin with no certificate for it —
 * which is the exact failure the TXT-over-CNAME choice (§D5) exists to prevent, undone by a UI that stayed
 * quiet about the last step.
 */
import { MdsCard, MdsIcon } from '@meridian/design-system';

defineProps<{ appHost: string }>();
</script>

<template>
    <MdsCard>
        <template #header>
            <div class="domain-guide__head">
                <span class="domain-guide__badge" aria-hidden="true"><MdsIcon name="globe" size="md" /></span>
                <h2 class="domain-guide__title">How a custom domain goes live</h2>
            </div>
        </template>
        <p class="domain-guide__lead">
            Your public forms are served at <code>{{ appHost }}</code> today. A custom domain serves those same
            forms on a hostname you own — the rest of your workspace stays here.
        </p>
        <ol class="domain-guide__steps">
            <li>
                <strong>Add your domain</strong> and publish the TXT record we give you at your DNS provider.
                It proves you control the name.
            </li>
            <li>
                <strong>We check the record</strong> — automatically within the hour, or straight away with
                <strong>Check DNS</strong>. Your domain then reads <em>Awaiting setup</em>.
            </li>
            <li>
                <strong>We install the certificate and put it into service.</strong> This step is ours, and a
                person does it — so it is not instant, and your domain serves nothing until it is done.
            </li>
            <li>
                <strong>You point traffic here last.</strong> Once the domain reads <em>Live</em>, update its
                A record. Doing it in this order means there is never a moment when visitors arrive and we
                cannot serve them.
            </li>
        </ol>
        <p class="domain-guide__foot">
            Links already sent to respondents keep working throughout. To remove a domain later, take it out
            here and let us know so we can retire its certificate.
        </p>
    </MdsCard>
</template>

<style scoped>
.domain-guide__head {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
}

.domain-guide__badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    flex-shrink: 0;
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-action-primary-tint);
    color: var(--mds-color-action-primary-fg);
}

.domain-guide__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.domain-guide__lead,
.domain-guide__foot {
    margin: 0;
    font-family: var(--mds-font-family-body);
    color: var(--mds-color-text-body);
}

.domain-guide__lead {
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.domain-guide__lead code {
    font-family: var(--mds-font-family-mono);
    font-size: 0.9em;
    /* `anywhere` rather than `break-all`: a long app host must be allowed to wrap at 375px, but only when
       the line genuinely cannot fit — the page owns the no-horizontal-scroll contract. */
    overflow-wrap: anywhere;
}

.domain-guide__steps {
    margin: var(--mds-space-4) 0;
    padding-left: var(--mds-space-5);
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.domain-guide__foot {
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}
</style>
