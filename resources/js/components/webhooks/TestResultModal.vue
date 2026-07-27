<script setup lang="ts">
/**
 * Synchronous test.ping result (Increment H14). Driven by the one-shot `flash.testResult` prop the Show page
 * hands in after a "Send test ping" Inertia visit — the WebhookTester runs the delivery inline (real SSRF
 * guard + signature + POST) and never throws, so this is always a plain, already-resolved outcome, not a
 * pending request. Delivered = success badge + HTTP status + latency; failed = danger badge + the parsed
 * `[marker]` reason (SSRF block / transport error) or the receiver's non-2xx status.
 */
import { computed } from 'vue';
import { MdsBadge, MdsButton, MdsModal } from '@meridian/design-system';
import type { FlashTestResult } from '@/types/inertia';

const props = defineProps<{ open: boolean; result: FlashTestResult | null }>();
const emit = defineEmits<{ 'update:open': [value: boolean] }>();

// A delivery-time outcome marker ("[blocked_private_host] …") rides at the front of `error`; split it into a
// friendly line + the raw remainder so the tenant sees WHY, not a bare timeout.
const parsedError = computed(() => {
    const raw = props.result?.error ?? '';
    const match = raw.match(/^\[(\w+)\]\s*(.*)$/s);
    if (!match) return { reason: raw || 'The delivery could not be completed.', detail: '' };
    const friendly: Record<string, string> = {
        blocked_private_host: 'The URL resolved to a private or internal address and was blocked.',
        transport_error: 'We couldn’t connect to the endpoint.',
    };
    return { reason: friendly[match[1]] ?? match[1], detail: match[2] };
});

function close(): void {
    emit('update:open', false);
}
</script>

<template>
    <MdsModal :open="open" title="Test ping" @close="close">
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

            <template v-if="result.delivered">
                <p class="test-result__lead">
                    Your endpoint received the test event and responded. Check that your integration verified the
                    signature before relying on it for real events.
                </p>
                <pre v-if="result.response_body_excerpt" class="test-result__body">{{ result.response_body_excerpt }}</pre>
            </template>

            <template v-else>
                <p class="test-result__lead">{{ parsedError.reason }}</p>
                <pre v-if="parsedError.detail" class="test-result__body">{{ parsedError.detail }}</pre>
                <pre v-else-if="result.response_body_excerpt" class="test-result__body">{{ result.response_body_excerpt }}</pre>
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
