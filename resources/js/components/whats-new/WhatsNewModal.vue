<script setup lang="ts">
/**
 * The What's new prompt — M181 (`R-c7247dbf`, `D125` C, `D126` A, `D157` A).
 *
 * The user (2026-10-14): *"lets also have a what's new prompt to users for new login so users are aware for every
 * changes"*, and *"i want the whats new to be seen right after logging in"*. Mounted once in `AppLayout`, beside the
 * toast host; it opens on the first page loaded while the shared `whatsNew` prop carries releases this person has not
 * seen — for someone signing in, the page they land on — and lists them newest first.
 *
 * ── ANY CLOSE MARKS IT SEEN (`D126` A), ONCE ───────────────────────────────────────────────────────────────────────
 * Got it, the X, Escape and a click outside all close it at once and post the newest version shown to
 * `POST /whats-new/seen`; it never nags, and every release stays a click away under every page. The releases are
 * copied into local state on open, so the list does not empty under the leave transition when the reply clears the
 * prop. "See all changes" visits the page only once the close is recorded: an Inertia visit started while the post is
 * in flight would cancel it, and the prompt would come back on the next page.
 */
import { computed, ref, watch } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import { MdsButton, MdsModal } from '@meridian/design-system';
import type { WhatsNewRelease } from '@/types/inertia';
import ReleaseNotes from './ReleaseNotes.vue';

const page = usePage();

const open = ref(false);
const entries = ref<WhatsNewRelease[]>([]);
let recorded = false;

watch(
    () => page.props.whatsNew,
    (prompt) => {
        if (open.value || recorded || !prompt || prompt.entries.length === 0) {
            return;
        }

        entries.value = prompt.entries;
        open.value = true;
    },
    { immediate: true },
);

const newest = computed(() => entries.value[0] ?? null);
const title = computed(() => (newest.value ? `What's new in Meridian ${newest.value.version}` : "What's new"));

function close(then?: () => void): void {
    if (!open.value) {
        return;
    }

    open.value = false;

    if (recorded || newest.value === null) {
        then?.();
        return;
    }

    recorded = true;
    router.post(
        '/whats-new/seen',
        { version: newest.value.version },
        { preserveScroll: true, preserveState: true, onFinish: () => then?.() },
    );
}

function seeAll(): void {
    close(() => router.visit('/whats-new'));
}
</script>

<template>
    <MdsModal :open="open" :title="title" size="lg" @close="close()">
        <p v-if="entries.length > 1" class="whats-new__lede">
            {{ entries.length }} releases since you last looked, newest first.
        </p>
        <div class="whats-new__releases">
            <ReleaseNotes v-for="release in entries" :key="release.version" :release="release" :heading-level="3" />
        </div>
        <template #actions>
            <MdsButton variant="secondary" @click="seeAll">See all changes</MdsButton>
            <MdsButton variant="primary" @click="close()">Got it</MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.whats-new__lede {
    margin: 0 0 var(--mds-space-4);
    color: var(--mds-color-text-secondary);
}

.whats-new__releases {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-6);
}
</style>
