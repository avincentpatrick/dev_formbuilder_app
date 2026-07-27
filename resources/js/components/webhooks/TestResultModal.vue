<script setup lang="ts">
/**
 * Synchronous test-send result (Increment H14; reused by the H15b connector test). Driven by the one-shot
 * `flash.testResult` prop the calling page hands in after a "Send test" Inertia visit — WebhookTester and
 * ConnectorTester both run the delivery inline and never throw, so this is always a plain, already-resolved
 * outcome, not a pending request. Delivered = success badge + HTTP status + latency; failed = danger badge +
 * the parsed `[marker]` reason or the receiver's non-2xx status.
 *
 * ONE component for both surfaces (design-system rule #2) rather than a near-identical connector twin: the
 * result shape is byte-identical by design, so only the words differ, and words are props.
 */
import { computed } from 'vue';
import { MdsBadge, MdsButton, MdsModal } from '@meridian/design-system';
import type { FlashTestResult } from '@/types/inertia';

const props = withDefaults(
    defineProps<{
        open: boolean;
        result: FlashTestResult | null;
        title?: string;
        /** Shown when the send succeeded — what the tenant should check next differs per channel. */
        successLead?: string;
    }>(),
    {
        title: 'Test ping',
        successLead:
            'Your endpoint received the test event and responded. Check that your integration verified the signature before relying on it for real events.',
    },
);
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

// A delivery-time outcome marker ("[blocked_private_host] …") rides at the front of `error`; split it into a
// friendly line + the raw remainder so the tenant sees WHY, not a bare timeout. Unknown markers fall back to
// the code itself, which is still more use than nothing and never renders provider prose as our copy.
const parsedError = computed(() => {
    const raw = props.result?.error ?? '';
    const match = raw.match(/^\[(\w+)\]\s*(.*)$/s);
    if (!match) return { reason: raw || 'The delivery could not be completed.', detail: '' };
    const friendly: Record<string, string> = {
        blocked_private_host: 'The URL resolved to a private or internal address and was blocked.',
        transport_error: 'We couldn’t connect to the endpoint.',
        // Connector markers (H15b). `not_in_channel` is the one tenants hit most: Slack lists every public
        // channel but only lets the app post to channels it has been invited to.
        not_in_channel: 'The app isn’t in that channel yet — invite it in Slack, then try again.',
        channel_not_found: 'That channel no longer exists. Pick another one.',
        missing_channel: 'This rule has no channel configured. Edit it and choose one.',
        is_archived: 'That channel is archived. Pick another one.',
        invalid_auth: 'The workspace rejected our credentials. Reconnect it, then try again.',
        token_revoked: 'The workspace revoked our access. Reconnect it, then try again.',
        connection_inactive: 'Reconnect this workspace before sending a test message.',
        provider_unavailable: 'This integration isn’t available right now.',
        malformed_response: 'We got an unreadable response back.',
    };
    return { reason: friendly[match[1]] ?? match[1], detail: match[2] };
});

function close(): void {
    emit('update:open', false);
}
</script>

<template>
    <MdsModal :open="open" :title="title" @close="close">
        <div v-if="result" class="test-result">
            <div class="test-result__head">
                <MdsBadge
                    v-if="result.delivered"
                    variant="success"
                    label="Delivered"
                    icon="check"
                />
                <MdsBadge v-else variant="danger" label="Failed" icon="alert" />
                <span v-if="result.response_status !== null" class="test-result__meta">
                    HTTP {{ result.response_status }}<template v-if="result.response_time_ms !== null"> · {{ result.response_time_ms }} ms</template>
                </span>
            </div>

            <!-- The excerpt boxes scroll (max-height + overflow-y), so each needs to be focusable and named:
                 axe's `scrollable-region-focusable` fails a scroll container a keyboard user cannot reach. -->
            <template v-if="result.delivered">
                <p class="test-result__lead">{{ successLead }}</p>
                <pre
                    v-if="result.response_body_excerpt"
                    class="test-result__body"
                    tabindex="0"
                    role="region"
                    aria-label="Response body"
                >{{ result.response_body_excerpt }}</pre>
            </template>

            <template v-else>
                <p class="test-result__lead">{{ parsedError.reason }}</p>
                <pre
                    v-if="parsedError.detail"
                    class="test-result__body"
                    tabindex="0"
                    role="region"
                    aria-label="Failure detail"
                >{{ parsedError.detail }}</pre>
                <pre
                    v-else-if="result.response_body_excerpt"
                    class="test-result__body"
                    tabindex="0"
                    role="region"
                    aria-label="Response body"
                >{{ result.response_body_excerpt }}</pre>
            </template>
        </div>
        <template #actions>
            <MdsButton type="button" variant="primary" @click="close">Done</MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.test-result {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.test-result__head {
    display: flex;
    align-items: center;
    gap: var(--mds-space-3);
}

.test-result__meta {
    font-size: var(--mds-type-body-sm-font-size);
    font-variant-numeric: tabular-nums;
    color: var(--mds-color-text-secondary);
}

.test-result__lead {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.test-result__body {
    margin: 0;
    padding: var(--mds-space-3);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    max-height: 12rem;
    overflow-y: auto;
}
</style>
