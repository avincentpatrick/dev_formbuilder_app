<script setup lang="ts">
/**
 * Single-page presentation (UX §3.1): every relevant section rendered in one continuous scroll with one
 * persistent Submit and the submit-time summary banner (§4.2). No progress indicator (scroll position is the
 * progress).
 */
import { computed, nextTick, ref } from 'vue';
import { MdsButton } from '@meridian/design-system';
import SectionView from './SectionView.vue';
import SummaryBanner from './SummaryBanner.vue';
import { useAnnouncer, useRuntime, useSubmitFlow } from '../composables/context';

const runtime = useRuntime();
const announcer = useAnnouncer();
const flow = useSubmitFlow();

const banner = ref<InstanceType<typeof SummaryBanner> | null>(null);
const bannerVisible = ref(false);

const bannerItems = computed(() =>
    runtime.erroredItems.value.map((item) => ({ address: item.address, label: item.label })),
);

async function onSubmit(): Promise<void> {
    const outcome = await flow.submit();
    if (outcome === 'field-errors') {
        bannerVisible.value = true;
        await nextTick();
        banner.value?.focus();
        announcer.announce(
            `${bannerItems.value.length} ${bannerItems.value.length === 1 ? 'field needs' : 'fields need'} your attention before submitting.`,
        );
    }
}
</script>

<template>
    <form class="page-view" novalidate @submit.prevent="onSubmit">
        <SummaryBanner v-if="bannerVisible && bannerItems.length" ref="banner" :items="bannerItems" />
        <SectionView v-for="step in runtime.visibleSteps.value" :key="step.key" :step="step" />
        <div class="page-view__actions">
            <MdsButton type="submit" size="lg" :loading="flow.submitting.value">Submit</MdsButton>
        </div>
    </form>
</template>

<style scoped>
.page-view {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
}

.page-view__actions {
    display: flex;
    justify-content: flex-end;
    padding-top: var(--mds-space-2);
}
</style>
