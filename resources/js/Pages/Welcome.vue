<script setup lang="ts">
defineProps<{
    appName: string;
    phase: string;
}>();
</script>

<template>
    <main class="skeleton">
        <h1>{{ appName }}</h1>
        <p>Walking skeleton — Inertia v2 + Vue 3 + TypeScript. It works.</p>
        <p class="phase">{{ phase }}</p>
    </main>
</template>

<style scoped>
/* Intentionally minimal. Real pages consume @meridian/design-system (Increment C — app shell). */
.skeleton {
    font-family: system-ui, -apple-system, sans-serif;
    max-width: 40rem;
    margin: 4rem auto;
    padding: 0 1rem;
    line-height: 1.5;
}

h1 {
    font-size: 2rem;
    margin: 0 0 0.5rem;
}

.phase {
    margin-top: 1.5rem;
    color: #64748b;
    font: 0.875rem ui-monospace, "Cascadia Code", monospace;
}
</style>
