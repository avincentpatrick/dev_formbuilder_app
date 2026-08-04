<script setup lang="ts">
/**
 * One custom domain (Increment H22b / ADR-0012): its state, the DNS record it needs, and what the tenant
 * may do about it.
 *
 * A CARD RATHER THAN A TABLE ROW, unlike /webhooks. The DNS block is three labelled lines that must stay
 * copyable and must wrap at 375px, and a `MdsDataTable` cell that contains a definition list is a table
 * pretending to be a layout. The /integrations card list is the precedent.
 *
 * ⚠️ THERE IS NO ACTIVATE BUTTON, NOT EVEN A DISABLED ONE. `awaiting_operator` is the honest state
 * ADR-0012 §D6 creates: the tenant has finished, and a person must install a certificate before the
 * hostname serves anything. A greyed-out control would imply the tenant is one permission away from it;
 * a sentence says what is actually happening.
 *
 * Every mutation is an Inertia visit that redirects with a flash — never a JSON fetch. A refusal on a web
 * route returns a 302 even to an XHR (the integrationsClient trap), so a client that expected JSON would
 * parse HTML and throw.
 */
import { computed } from 'vue';
import { MdsBadge, MdsButton, MdsIcon, statusVariant } from '@meridian/design-system';
import DnsRecordBlock from './DnsRecordBlock.vue';
import type { DomainRow } from './types';

const props = defineProps<{
    domain: DomainRow;
    /** Owner/Admin AND the `custom_domain` plan feature — gates verify + make-primary. */
    canWrite: boolean;
    /** Owner/Admin alone. Remove stays available after a downgrade (ADR-0012 §D9). */
    canManage: boolean;
    /** True while an Inertia visit for THIS domain is in flight. */
    busy: boolean;
}>();

const emit = defineEmits<{ verify: []; primary: []; remove: [] }>();

const badge = computed(() => statusVariant(props.domain.status));

/** The one-line answer to "what is happening with this domain right now". */
const stateNote = computed<string>(() => {
    if (props.domain.awaiting_operator) {
        return 'You’re done — we’ve confirmed you control this domain. We install its certificate and put it into service next; it doesn’t serve anything until then.';
    }
    if (props.domain.status === 'live') {
        return props.domain.is_public_host
            ? 'Serving your public forms. New respondent links point here.'
            : 'Serving your public forms. Respondent links point at another of your domains.';
    }
    return props.domain.failure_hint ?? 'Publish the record below, then check DNS. We also check automatically every hour.';
});

function formatStamp(iso: string | null): string {
    if (iso === null) return 'never';
    return new Date(iso).toLocaleString(undefined, { dateStyle: 'medium', timeStyle: 'short' });
}
</script>

<template>
    <article class="domain-card">
        <header class="domain-card__head">
            <div class="domain-card__ident">
                <h3 class="domain-card__host">{{ domain.domain }}</h3>
                <div class="domain-card__tags">
                    <MdsBadge v-bind="badge" />
                    <!-- Text, not a colour or an icon alone: which host respondents actually get is the most
                         consequential fact on this page (WCAG 1.4.1). -->
                    <MdsBadge v-if="domain.is_public_host" variant="info" label="Respondent links" />
                </div>
            </div>
            <div v-if="canManage" class="domain-card__actions">
                <MdsButton
                    v-if="canWrite && domain.status !== 'live'"
                    variant="secondary"
                    icon-left="activity"
                    :loading="busy"
                    @click="emit('verify')"
                >
                    Check DNS
                </MdsButton>
                <MdsButton
                    v-if="canWrite && domain.can_be_primary"
                    variant="secondary"
                    icon-left="globe"
                    :loading="busy"
                    @click="emit('primary')"
                >
                    Use for links
                </MdsButton>
                <MdsButton variant="tertiary" icon-left="trash" :disabled="busy" @click="emit('remove')">
                    Remove
                </MdsButton>
            </div>
        </header>

        <p class="domain-card__note" :class="{ 'domain-card__note--wait': domain.awaiting_operator }">
            <MdsIcon
                v-if="domain.awaiting_operator"
                name="clock"
                size="sm"
                aria-hidden="true"
                class="domain-card__note-icon"
            />
            {{ stateNote }}
        </p>

        <DnsRecordBlock :name="domain.verification.name" :value="domain.verification.value" />

        <dl class="domain-card__meta">
            <div class="domain-card__meta-item">
                <dt>Last checked</dt>
                <dd>{{ formatStamp(domain.last_checked_at) }}</dd>
            </div>
            <div v-if="domain.verified_at" class="domain-card__meta-item">
                <dt>Verified</dt>
                <dd>{{ formatStamp(domain.verified_at) }}</dd>
            </div>
            <div v-if="domain.activated_at" class="domain-card__meta-item">
                <dt>In service since</dt>
                <dd>{{ formatStamp(domain.activated_at) }}</dd>
            </div>
        </dl>
    </article>
</template>

<style scoped>
.domain-card {
    padding: var(--mds-space-5);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    box-shadow: var(--mds-shadow-1);
}

.domain-card__head {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--mds-space-3);
    margin-bottom: var(--mds-space-3);
}

.domain-card__ident {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    /* Lets a long FQDN wrap rather than widen the flex row past the card. */
    min-width: 0;
}

.domain-card__host {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
    overflow-wrap: anywhere;
}

.domain-card__tags,
.domain-card__actions {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
}

.domain-card__note {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-2);
    margin: 0 0 var(--mds-space-4);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

/* The awaiting-operator state is the one a tenant is most likely to misread as "finished", so it gets a
   surface of its own rather than sitting in the same grey as every other hint. */
.domain-card__note--wait {
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    /* The same semantic pair MdsBadge's info variant uses — the only status surface pair the token set
       defines, so this notice cannot drift from the "Awaiting setup" badge sitting directly above it. */
    background-color: var(--mds-color-status-info-bg);
    color: var(--mds-color-status-info-fg);
}

.domain-card__note-icon {
    flex-shrink: 0;
    margin-top: 1px;
}

.domain-card__meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2) var(--mds-space-5);
    margin: var(--mds-space-4) 0 0;
}

.domain-card__meta-item {
    display: flex;
    align-items: baseline;
    gap: var(--mds-space-2);
}

.domain-card__meta dt {
    font-size: var(--mds-type-caption-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
}

.domain-card__meta dd {
    margin: 0;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-body);
}
</style>
