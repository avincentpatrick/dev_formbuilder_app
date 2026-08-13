<script setup lang="ts">
/**
 * The three values an admin pastes into their identity provider's console (P1a — ADR-0016 §D3).
 *
 * Modelled on `components/domains/DnsRecordBlock.vue`, which solves the identical problem: here is a string,
 * put it into somebody else's admin UI without mangling it.
 *
 * ⚠️ THREE SEPARATE COPY TARGETS, NOT ONE BLOCK. An IdP console takes the entity id and the ACS URL in
 * different fields, and a combined "copy configuration" button produces something that has to be taken
 * apart by hand — which is how a trailing space ends up in an audience string and produces an
 * audience-mismatch failure that reads like a signing problem.
 *
 * SHOWN IN EVERY STATE, including before any metadata is imported: these values are what the admin needs
 * FIRST, since the IdP has to be told about this SP before it can hand back metadata worth importing.
 *
 * The clipboard write is the DnsRecordBlock pattern — optional-chained (`navigator.clipboard` is undefined
 * on an insecure origin, which is exactly how this is served in local development) and the copied flag
 * flips only on success, so the button never claims something that did not happen.
 */
import { ref } from 'vue';
import { MdsCard, MdsIcon, MdsIconButton } from '@meridian/design-system';
import type { SsoServiceProvider } from './types';

defineProps<{ sp: SsoServiceProvider }>();

/** Which field was copied last, so exactly one button shows its confirmation. */
const copied = ref<string | null>(null);

async function copy(field: string, text: string): Promise<void> {
    try {
        await navigator.clipboard?.writeText(text);
        copied.value = field;
    } catch {
        copied.value = null;
    }
}
</script>

<template>
    <MdsCard class="settings-card">
        <template #header>
            <div class="settings-card__head">
                <MdsIcon name="globe" size="sm" aria-hidden="true" />
                <h2 class="settings-card__title">Your service provider details</h2>
            </div>
        </template>

        <p class="sso-sp__lede">
            Give these to your identity provider. Most providers can read them all at once from the metadata
            URL; the others take the first two by hand.
        </p>

        <dl class="sso-sp">
            <dt class="sso-sp__term">Entity ID</dt>
            <dd class="sso-sp__def">
                <code class="sso-sp__code">{{ sp.entity_id }}</code>
                <MdsIconButton
                    icon="copy"
                    :label="copied === 'entity' ? 'Entity ID copied' : 'Copy entity ID'"
                    @click="copy('entity', sp.entity_id)"
                />
                <span v-if="copied === 'entity'" class="sso-sp__copied" role="status">Copied</span>
            </dd>

            <dt class="sso-sp__term">Assertion Consumer Service (ACS) URL</dt>
            <dd class="sso-sp__def">
                <code class="sso-sp__code">{{ sp.acs_url }}</code>
                <MdsIconButton
                    icon="copy"
                    :label="copied === 'acs' ? 'ACS URL copied' : 'Copy ACS URL'"
                    @click="copy('acs', sp.acs_url)"
                />
                <span v-if="copied === 'acs'" class="sso-sp__copied" role="status">Copied</span>
            </dd>

            <dt class="sso-sp__term">Metadata URL</dt>
            <dd class="sso-sp__def">
                <code class="sso-sp__code">{{ sp.metadata_url }}</code>
                <MdsIconButton
                    icon="copy"
                    :label="copied === 'metadata' ? 'Metadata URL copied' : 'Copy metadata URL'"
                    @click="copy('metadata', sp.metadata_url)"
                />
                <span v-if="copied === 'metadata'" class="sso-sp__copied" role="status">Copied</span>
            </dd>
        </dl>

        <p class="sso-sp__hint">
            These addresses always use your workspace’s own subdomain, even if you serve public forms on a
            custom domain — your identity provider needs an address that does not change.
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

.sso-sp__lede,
.sso-sp__hint {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.sso-sp__hint {
    margin: var(--mds-space-4) 0 0;
}

.sso-sp {
    margin: 0;
}

.sso-sp__term {
    margin-block-end: var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.sso-sp__def {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    margin: 0 0 var(--mds-space-3);
    min-width: 0;
}

/* The value can be long and must not push the card wide — it scrolls inside its own box instead, which is
   also what keeps the page's own horizontal scrollbar at zero. */
.sso-sp__code {
    flex: 1 1 auto;
    min-width: 0;
    overflow-x: auto;
    white-space: nowrap;
    padding: var(--mds-space-2) var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    background: var(--mds-color-bg-sunken);
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}

.sso-sp__copied {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-status-success-fg);
}
</style>
