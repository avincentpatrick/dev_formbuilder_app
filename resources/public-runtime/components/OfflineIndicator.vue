<script setup lang="ts">
/**
 * Ambient "you are offline" pill (Increment G8a), patterned on SavedIndicator.vue. Shown while the device
 * reports offline: the form still renders and autosaves from cache, but submitting is deferred until
 * reconnect. `role="status"` announces the state change once (not on every autosave tick).
 */
</script>

<template>
    <span class="offline-indicator" role="status">
        <span class="offline-indicator__dot" aria-hidden="true" />
        Offline — your answers are saved on this device
    </span>
</template>

<style scoped>
.offline-indicator {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
    padding: var(--mds-space-1) var(--mds-space-3);
    border-radius: var(--mds-radius-full);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
    color: var(--mds-color-text-body);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
}

.offline-indicator__dot {
    width: 8px;
    height: 8px;
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-border-strong);
}
</style>
