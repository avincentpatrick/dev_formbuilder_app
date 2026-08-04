<script setup lang="ts">
/**
 * The DNS challenge a tenant must publish (Increment H22b / ADR-0012 §D5) — type, name, value, each
 * copyable on its own.
 *
 * SHOWN ON EVERY STATE, including `live`, which is what the API resource already does and for the same
 * reason: a tenant whose record is deleted at the registrar needs it back to recover, and the sweep
 * re-reads it on a cadence as the dangling-DNS control. It is NOT a secret — the token lives in public DNS
 * at a name only that zone's controller can write, which is exactly why ADR-0012 §D11 keeps it out of
 * AuditRedactor::SECRETS too.
 *
 * ⚠️ THREE COPY TARGETS, NOT ONE. Registrar UIs take the name and the value in separate inputs, and a
 * combined "copy record" button produces a string that has to be taken apart again by hand — which is how
 * a trailing space or a pasted `IN TXT` ends up in the zone and returns `mismatch` rather than `not_found`.
 *
 * The clipboard write is the SecretRevealModal.vue pattern: optional-chained (`navigator.clipboard` is
 * undefined on an insecure origin, which is exactly how this is served in local development) and the
 * copied flag only flips on success, so the button never claims something that did not happen.
 */
import { ref } from 'vue';
import { MdsIconButton } from '@meridian/design-system';

defineProps<{ name: string; value: string }>();

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
    <div class="dns">
        <p class="dns__lead">Add this record at your DNS provider:</p>
        <dl class="dns__grid">
            <dt class="dns__term">Type</dt>
            <dd class="dns__def">
                <code class="dns__code">TXT</code>
            </dd>

            <dt class="dns__term">Name</dt>
            <dd class="dns__def">
                <code class="dns__code">{{ name }}</code>
                <MdsIconButton
                    icon="copy"
                    :label="copied === 'name' ? 'Record name copied' : 'Copy record name'"
                    size="sm"
                    @click="copy('name', name)"
                />
            </dd>

            <dt class="dns__term">Value</dt>
            <dd class="dns__def">
                <code class="dns__code">{{ value }}</code>
                <MdsIconButton
                    icon="copy"
                    :label="copied === 'value' ? 'Record value copied' : 'Copy record value'"
                    size="sm"
                    @click="copy('value', value)"
                />
            </dd>
        </dl>
        <!-- A polite live region rather than a label swap alone: the icon button's accessible name changes,
             but a screen-reader user who has already moved focus on would never hear it. -->
        <p class="dns__status" role="status">
            <span v-if="copied === 'name'">Record name copied to the clipboard.</span>
            <span v-else-if="copied === 'value'">Record value copied to the clipboard.</span>
        </p>
    </div>
</template>

<style scoped>
.dns {
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.dns__lead {
    margin: 0 0 var(--mds-space-3);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.dns__grid {
    display: grid;
    /* `auto` for the term column and `minmax(0, 1fr)` for the value: without the explicit zero minimum a
       64-hex token establishes the grid's minimum width and the whole page scrolls sideways at 375px. */
    grid-template-columns: auto minmax(0, 1fr);
    gap: var(--mds-space-2) var(--mds-space-4);
    margin: 0;
    align-items: start;
}

.dns__term {
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
    /* Aligns the label with the first line of a value that has wrapped onto several. */
    padding-top: 2px;
}

.dns__def {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-2);
    margin: 0;
    min-width: 0;
}

.dns__code {
    min-width: 0;
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-body);
    /* The token has no break opportunities of its own, so it must be allowed to break anywhere. */
    overflow-wrap: anywhere;
}

.dns__status {
    margin: var(--mds-space-2) 0 0;
    min-height: 1lh;
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-status-success-fg);
}
</style>
