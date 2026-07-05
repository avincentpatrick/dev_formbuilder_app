<script setup lang="ts">
// Settings → Appearance (Increment C2). The theme (Light/Dark/Match System) persists to the user's
// account via the shared theme composable. Only Appearance exists for now; other Settings sections
// (Access, Modules, Maintenance — Feature #10) land in Phase 1. Rendered inside the persistent AppLayout.
import { MdsCard, MdsSegmentedControl, type IconName } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import type { ThemeMode } from '@/types/inertia';
import { useThemePreference } from '@/composables/useTheme';

const { mode, setMode } = useThemePreference();

const options: { value: ThemeMode; label: string; icon: IconName }[] = [
  { value: 'light', label: 'Light', icon: 'sun' },
  { value: 'dark', label: 'Dark', icon: 'moon' },
  { value: 'system', label: 'Match System', icon: 'monitor' },
];
</script>

<template>
  <div>
    <PageHeader title="Settings" />

    <MdsCard class="settings-card">
      <template #header>
        <h2 class="settings-card__title">Appearance</h2>
      </template>

      <div class="settings-row">
        <div class="settings-row__text">
          <p class="settings-row__label">Theme</p>
          <p class="settings-row__hint">
            Choose how Meridian looks. “Match System” follows your device setting.
          </p>
        </div>
        <MdsSegmentedControl
          :model-value="mode"
          :options="options"
          ariaLabel="Theme"
          @update:model-value="(v: string) => setMode(v as ThemeMode)"
        />
      </div>
    </MdsCard>
  </div>
</template>

<style scoped>
.settings-card {
  max-width: 640px;
}

.settings-card__title {
  margin: 0;
  font-family: var(--mds-font-family-display);
  font-size: var(--mds-type-heading-3-font-size);
  line-height: var(--mds-type-heading-3-line-height);
  font-weight: var(--mds-type-heading-3-font-weight);
  color: var(--mds-color-text-heading);
}

.settings-row {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: var(--mds-space-4);
}

.settings-row__text {
  min-width: 12rem;
  flex: 1;
}

.settings-row__label {
  margin: 0 0 var(--mds-space-1);
  font-weight: var(--mds-font-weight-semibold);
  color: var(--mds-color-text-heading);
}

.settings-row__hint {
  margin: 0;
  font-size: var(--mds-type-body-sm-font-size);
  color: var(--mds-color-text-secondary);
}
</style>
