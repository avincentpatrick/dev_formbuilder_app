<script setup lang="ts">
/**
 * Quick Light/Dark/Match-System toggle in the top nav (exceptions-log #3 — a beyond-spec addition;
 * canonical home is Settings → Appearance). Shares the theme logic with the Settings panel.
 */
import { MdsSegmentedControl, type IconName } from '@meridian/design-system';
import type { ThemeMode } from '@/types/inertia';
import { useThemePreference } from '@/composables/useTheme';

const { mode, setMode } = useThemePreference();

const options: { value: ThemeMode; label: string; icon: IconName }[] = [
    { value: 'light', label: 'Light', icon: 'sun' },
    { value: 'dark', label: 'Dark', icon: 'moon' },
    { value: 'system', label: 'System', icon: 'monitor' },
];
</script>

<template>
    <MdsSegmentedControl
        class="theme-quick"
        :model-value="mode"
        :options="options"
        ariaLabel="Theme"
        compact
        @update:model-value="(v: string) => setMode(v as ThemeMode)"
    />
</template>

<style scoped>
/* Keep the nav uncluttered on small screens — Settings → Appearance still covers it there. */
@media (max-width: 600px) {
    .theme-quick {
        display: none;
    }
}
</style>
