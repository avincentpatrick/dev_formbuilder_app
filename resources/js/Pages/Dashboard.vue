<script setup lang="ts">
// Authenticated tenant landing page, rendered inside the persistent AppLayout (assigned in app.ts).
// KPI tiles show real, visibility-scoped counts from DashboardController → DashboardMetricsService (H11):
// Owner/Admin/Viewer see org-wide totals; a Form Editor/Reviewer sees own-forms counts and no Members tile.
import { computed } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { MdsButton, MdsCard, MdsEmptyState, MdsStatTile, type IconName } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';

const props = defineProps<{
  // `members` is null when the user lacks org-wide visibility → the Members tile is omitted.
  kpis: { forms: number; submissions: number; members: number | null };
}>();

const page = usePage();
const user = computed(() => page.props.auth.user);
const canCreate = computed(() => page.props.auth.can.manageForms);

const tiles = computed(() => {
  const list: { label: string; value: string; icon: IconName }[] = [
    { label: 'Forms', value: props.kpis.forms.toLocaleString(), icon: 'forms' },
    { label: 'Submissions', value: props.kpis.submissions.toLocaleString(), icon: 'submissions' },
  ];
  if (props.kpis.members !== null) {
    list.push({ label: 'Members', value: props.kpis.members.toLocaleString(), icon: 'users' });
  }
  return list;
});

// The create-form flow is the "New form" modal on the Forms page; land there rather than duplicate it.
const goToForms = () => router.visit('/forms');
</script>

<template>
  <div>
    <PageHeader title="Dashboard" icon="dashboard">
      <template v-if="canCreate" #actions>
        <MdsButton variant="primary" icon-left="plus" @click="goToForms">Create form</MdsButton>
      </template>
    </PageHeader>

    <p class="dash__welcome">Welcome back, {{ user?.name }}.</p>

    <div class="dash__stats">
      <MdsStatTile
        v-for="tile in tiles"
        :key="tile.label"
        :label="tile.label"
        :value="tile.value"
        :icon="tile.icon"
      />
    </div>

    <MdsCard v-if="kpis.forms === 0" class="dash__empty-card">
      <MdsEmptyState
        headline="No forms yet"
        description="Create your first form to start collecting responses."
      >
        <template v-if="canCreate" #action>
          <MdsButton variant="primary" icon-left="plus" @click="goToForms">Create form</MdsButton>
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

.dash__empty-card {
  padding: 0;
}
</style>
