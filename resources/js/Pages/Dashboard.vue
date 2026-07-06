<script setup lang="ts">
// Authenticated tenant landing page, rendered inside the persistent AppLayout (assigned in app.ts).
// Stat tiles + the "no forms yet" empty state are placeholders until form building lands (Phase 1).
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { MdsButton, MdsCard, MdsEmptyState, MdsIcon, type IconName } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);

const stats: { label: string; value: string; icon: IconName }[] = [
  { label: 'Forms', value: '—', icon: 'forms' },
  { label: 'Submissions', value: '—', icon: 'submissions' },
  { label: 'Members', value: '—', icon: 'users' },
];
</script>

<template>
  <div>
    <PageHeader title="Dashboard" icon="dashboard">
      <template #actions>
        <MdsButton variant="primary" icon-left="plus" disabled>Create form</MdsButton>
      </template>
    </PageHeader>

    <p class="dash__welcome">Welcome back, {{ user?.name }}.</p>

    <div class="dash__stats">
      <MdsCard v-for="stat in stats" :key="stat.label">
        <div class="dash__stat">
          <span class="dash__stat-badge" aria-hidden="true">
            <MdsIcon :name="stat.icon" size="md" />
          </span>
          <div class="dash__stat-text">
            <p class="dash__stat-value">{{ stat.value }}</p>
            <p class="dash__stat-label">{{ stat.label }}</p>
          </div>
        </div>
      </MdsCard>
    </div>

    <MdsCard class="dash__empty-card">
      <MdsEmptyState
        headline="No forms yet"
        description="Once form building lands, the forms you create will appear here."
      >
        <template #action>
          <MdsButton variant="primary" icon-left="plus" disabled>Create form</MdsButton>
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

.dash__stat {
  display: flex;
  align-items: center;
  gap: var(--mds-space-4);
}

.dash__stat-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 44px;
  height: 44px;
  flex-shrink: 0;
  border-radius: var(--mds-radius-lg);
  background-color: var(--mds-color-action-primary-tint);
  color: var(--mds-color-action-primary-fg);
}

.dash__stat-text {
  min-width: 0;
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
