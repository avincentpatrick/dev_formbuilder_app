<script setup lang="ts">
/**
 * "Connect with Zapier" recipe (Increment H14, webhook-integration-design.md §4). Phase-1 Zapier support is
 * the generic webhook mechanism itself — a "Webhooks by Zapier" Catch Hook is just another endpoint the tenant
 * pastes in here. This is a pure help card (no backend, no network): it walks the tenant through creating the
 * Catch Hook, pasting its URL as an endpoint, and verifying with a test ping.
 */
import { MdsCard, MdsIcon } from '@meridian/design-system';
</script>

<template>
    <MdsCard>
        <template #header>
            <div class="zapier__head">
                <span class="zapier__badge" aria-hidden="true"><MdsIcon name="activity" size="md" /></span>
                <h2 class="zapier__title">Connect with Zapier</h2>
            </div>
        </template>
        <p class="zapier__lead">
            No dedicated app needed — a “Webhooks by Zapier” trigger consumes these webhooks directly.
        </p>
        <ol class="zapier__steps">
            <li>In Zapier, create a Zap with the <strong>Webhooks by Zapier</strong> trigger → <strong>Catch Hook</strong>.</li>
            <li>Copy the custom webhook URL Zapier generates.</li>
            <li>Here, click <strong>Add endpoint</strong>, paste that URL, name it (e.g. “Zapier”), and pick the events to forward.</li>
            <li>Open the endpoint and click <strong>Send test ping</strong> — Zapier’s “Test trigger” will show the caught request.</li>
            <li>Turn your Zap on. Real events now flow to Zapier.</li>
        </ol>
        <p class="zapier__foot">
            Payloads are HMAC-SHA256 signed. Signature verification is optional in Zapier — add a Code step using
            your signing secret if you need it.
        </p>
    </MdsCard>
</template>

<style scoped>
.zapier__head {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
}

.zapier__badge {
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

.zapier__title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.zapier__lead,
.zapier__foot {
    margin: 0;
    font-family: var(--mds-font-family-body);
    color: var(--mds-color-text-body);
}

.zapier__lead {
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.zapier__steps {
    margin: var(--mds-space-4) 0;
    padding-left: var(--mds-space-5);
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.zapier__foot {
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}
</style>
