<script setup lang="ts">
// The four headline tiles (Increment H24b2).
//
// Both draft tiles are driven ENTIRELY by draft-metrics.ts, which /dashboard also uses. That is the point:
// the "0% of 6 saved drafts" beside "No drafts were explicitly saved" contradiction was a single shared
// sentence, and a second hand-rolled copy on this page would reintroduce it silently.
import { computed } from 'vue';
import { MdsStatTile } from '@meridian/design-system';
import { conversionTile, medianTile } from './draft-metrics';
import { dateLabel } from './bucket-label';
import type { Report } from './types';

const props = defineProps<{ report: Report }>();

const conversion = computed(() => conversionTile(props.report.drafts));
const median = computed(() => medianTile(props.report.drafts));

// Named from the RESOLVED prior range, never hard-coded: the range here is the user's, so "vs. previous 30
// days" would be a claim the page has no basis for the moment they pick a different window.
const deltaLabel = computed(
    () =>
        `vs. ${dateLabel(props.report.prior_range.from)} – ` +
        `${dateLabel(props.report.prior_range.to)}`,
);
</script>

<template>
    <div class="analytics__tiles">
        <MdsStatTile
            label="Responses"
            icon="submissions"
            :value="report.total.current.toLocaleString()"
            :delta="report.total.change"
            :delta-label="deltaLabel"
        />
        <MdsStatTile
            label="Accepting responses"
            icon="inbox"
            :value="report.forms_accepting.toLocaleString()"
            caption="Right now"
        />
        <MdsStatTile
            label="Draft conversion"
            icon="activity"
            :value="conversion.value"
            :unavailable="conversion.unavailable"
            :unavailable-note="conversion.note"
            :caption="conversion.caption"
        />
        <MdsStatTile
            label="Median time to submit"
            icon="clock"
            :value="median.value"
            :unavailable="median.unavailable"
            :unavailable-note="median.note"
            :caption="median.caption"
        />
    </div>
</template>

<style scoped>
.analytics__tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 200px), 1fr));
    gap: var(--mds-space-4);
    margin-bottom: var(--mds-space-6);
}
</style>
