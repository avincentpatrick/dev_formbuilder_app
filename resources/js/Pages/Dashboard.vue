<script setup lang="ts">
// Authenticated tenant landing page, rendered inside the persistent AppLayout (assigned in app.ts).
// Stat tiles + the "no forms yet" empty state are placeholders until form building lands (Phase 1).
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { MdsButton, MdsCard, MdsEmptyState } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);

const stats = [
  { label: 'Forms', value: '—' },
  { label: 'Submissions', value: '—' },
  { label: 'Members', value: '—' },
];
</script>

<template>
  <div>
    <PageHeader title="Dashboard">
      <template #actions>
        <MdsButton variant="primary" disabled>Create form</MdsButton>
      </template>
    </PageHeader>

    <p class="dash__welcome">Welcome back, {{ user?.name }}.</p>

    <div class="dash__stats">
      <MdsCard v-for="stat in stats" :key="stat.label">
        <p class="dash__stat-value">{{ stat.value }}</p>
        <p class="dash__stat-label">{{ stat.label }}</p>
      </MdsCard>
    </div>

    <MdsCard class="dash__empty-card">
      <MdsEmptyState
        headline="No forms yet"
        description="Once form building lands, the forms you create will appear here."
      >
        <template #action>
          <MdsButton variant="primary" disabled>Create form</MdsButton>
        </template>
      </MdsEmptyState>
    </MdsCard>
  </div>
</template>

<style scoped>
.dash__welcome {
  margin: 0 0 var(--mds-space-6);
  font-size: var(--mds-type-body-lg-font-size);
  color: var(--mds-color-text-secondary);
}

.dash__stats {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: var(--mds-space-4);
  margin-bottom: var(--mds-space-6);
}

.dash__stat-value {
  margin: 0 0 var(--mds-space-1);
  font-family: var(--mds-font-family-display);
  font-size: var(--mds-type-heading-1-font-size);
  line-height: var(--mds-type-heading-1-line-height);
  font-weight: var(--mds-type-heading-1-font-weight);
  color: var(--mds-color-text-heading);
}

.dash__stat-label {
  margin: 0;
  font-size: var(--mds-type-body-md-font-size);
  color: var(--mds-color-text-secondary);
}

.dash__empty-card {
  padding: 0;
}
</style>
