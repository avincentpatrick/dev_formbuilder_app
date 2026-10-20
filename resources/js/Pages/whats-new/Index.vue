<script setup lang="ts">
/**
 * What's new — every release and what changed in it (M181, `R-c7247dbf`, `D125` C).
 *
 * The user (2026-10-15): *"so every release, we know what changed, what version it is"*. Read from the `releases` table
 * that every deploy fills from the committed notes, newest first by version (the server orders them). Every signed-in
 * member, no gate, like Help; reached from the footer under every page and the sidebar's foot. The prompt that opens
 * after sign-in shows the same notes for the releases a person has not seen yet.
 */
import { Head } from '@inertiajs/vue3';
import { MdsCard, MdsEmptyState } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import ReleaseNotes from '@/components/whats-new/ReleaseNotes.vue';
import type { WhatsNewRelease } from '@/types/inertia';

defineProps<{ releases: WhatsNewRelease[] }>();
</script>

<template>
    <Head title="What's new" />
    <PageHeader title="What's new" icon="sparkle" />

    <div v-if="releases.length > 0" class="whats-new">
        <MdsCard v-for="release in releases" :key="release.version">
            <ReleaseNotes :release="release" :heading-level="2" />
        </MdsCard>
    </div>
    <MdsEmptyState
        v-else
        headline="No release notes yet"
        description="When Meridian changes, what changed is listed here, newest first."
    />
</template>

<style scoped>
.whats-new {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
}
</style>
