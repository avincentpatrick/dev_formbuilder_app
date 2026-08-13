<script setup lang="ts">
/**
 * Recent sign-in failures — the one surface that tells a tenant admin why SSO is not working (P1c,
 * ADR-0016 §D26).
 *
 * ── ⚠️ THIS IS NOT THE ORACLE §D19 REFUSES TO BE, AND THE DIFFERENCE IS THE AUDIENCE ────────────────
 * The ACS answers every refusal with an identical 404 because an endpoint that explains itself to an
 * UNAUTHENTICATED caller is an oracle for anyone tuning a forgery — "wrong audience" says the signature
 * verified. This page is behind `auth` plus `can:tenant.settings.manage` on the reader's own workspace, and
 * the question being asked is "why can my colleague not sign in?". §D19 stated the cost of never answering
 * it in writing; this is the answer, given to the only people entitled to it.
 *
 * ── THE ROWS ARE A DIAGNOSTIC, NOT A LEDGER, AND THE COPY SAYS SO ───────────────────────────────────
 * The store behind this is trimmed on every write — a per-tenant row cap and a retention window — because
 * an unauthenticated endpoint fills it. So the card promises "recent" and offers no pagination, no export
 * and no total. Inviting an admin to walk backwards through a list that silently shortens would be
 * promising history the store does not keep. `audits` is where a ledger lives, and the whole reason this is
 * a different table is that `audits` may never be trimmed.
 *
 * ⚠️ THE EMPTY STATE IS THE GOOD STATE, and it is worded that way. Every other empty state in the product
 * says "nothing here yet" and means "go and make one". Here, nothing means everything is working.
 */
import { computed } from 'vue';
import { MdsBadge, MdsCard, MdsIcon } from '@meridian/design-system';
import { absoluteTime, relativeTime } from '@/components/notifications/relative-time';
import type { SsoFailureRow } from './types';

const props = defineProps<{
    failures: SsoFailureRow[];
    /** False while the connection is Draft — nothing can have failed on a connection that never served. */
    serving: boolean;
}>();

const hasFailures = computed<boolean>(() => props.failures.length > 0);
</script>

<template>
    <MdsCard class="settings-card">
        <template #header>
            <div class="settings-card__head">
                <MdsIcon name="alert" size="sm" aria-hidden="true" />
                <h2 class="settings-card__title">Recent sign-in failures</h2>
                <MdsBadge v-if="hasFailures" variant="warning" :label="String(failures.length)" />
            </div>
        </template>

        <p class="settings-card__intro">
            Attempts your identity provider sent that this workspace turned away. People who hit one of these
            see a page-not-found — the sign-in page cannot say why, so this is where the reason lives.
        </p>

        <!-- The good state. Deliberately not an MdsEmptyState illustration: this is a panel inside a page,
             and a full empty-state block would read as "set this up" rather than "nothing is wrong". -->
        <p v-if="!hasFailures" class="sso-failures__none">
            <MdsIcon name="check" size="sm" aria-hidden="true" />
            <span v-if="serving">No failed sign-ins recorded. Recent attempts have all been accepted.</span>
            <span v-else>Nothing recorded yet — single sign-on has not served a sign-in on this workspace.</span>
        </p>

        <ul v-else class="sso-failures">
            <li v-for="failure in failures" :key="failure.id" class="sso-failures__row">
                <div class="sso-failures__head">
                    <span class="sso-failures__label">{{ failure.reason_label }}</span>
                    <!-- `title` carries the reader's-timezone absolute instant; the visible text is
                         relative, which is what an admin scanning for "did this just start?" needs. -->
                    <time class="sso-failures__when" :datetime="failure.occurred_at" :title="absoluteTime(failure.occurred_at)">
                        {{ relativeTime(failure.occurred_at) }}
                    </time>
                </div>

                <p class="sso-failures__hint">{{ failure.hint }}</p>

                <dl class="sso-failures__meta">
                    <!-- Present only when a verified signature vouched for the address. Its absence is not a
                         gap in the record: before validation there is no address anyone should be shown. -->
                    <div v-if="failure.subject_email" class="sso-failures__field">
                        <dt>Account</dt>
                        <dd>{{ failure.subject_email }}</dd>
                    </div>
                    <div v-if="failure.ip_address" class="sso-failures__field">
                        <dt>From</dt>
                        <dd>{{ failure.ip_address }}</dd>
                    </div>
                    <div v-if="failure.request_id" class="sso-failures__field">
                        <dt>Request</dt>
                        <dd class="sso-failures__mono">{{ failure.request_id }}</dd>
                    </div>
                </dl>
            </li>
        </ul>
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

.settings-card__intro {
    margin: 0 0 var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.sso-failures__none {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.sso-failures {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
}

.sso-failures__row + .sso-failures__row {
    margin-block-start: var(--mds-space-3);
    padding-block-start: var(--mds-space-3);
    border-block-start: 1px solid var(--mds-color-border-default);
}

.sso-failures__head {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    justify-content: space-between;
    gap: var(--mds-space-2);
}

.sso-failures__label {
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.sso-failures__when {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
    white-space: nowrap;
}

.sso-failures__hint {
    margin: var(--mds-space-1) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-secondary);
}

.sso-failures__meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-1) var(--mds-space-4);
    margin: var(--mds-space-2) 0 0;
}

.sso-failures__field {
    display: flex;
    align-items: baseline;
    gap: var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
}

.sso-failures__field dt {
    color: var(--mds-color-text-secondary);
}

.sso-failures__field dd {
    margin: 0;
    color: var(--mds-color-text-body);
}

.sso-failures__mono {
    font-family: var(--mds-font-family-mono);
}
</style>
