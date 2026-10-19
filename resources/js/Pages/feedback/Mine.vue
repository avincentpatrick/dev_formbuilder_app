<script setup lang="ts">
/**
 * "Feedback & testing" — every member's own reports, and the workspace's testing-round comments (M175,
 * `R-9bfd832e`, `D139`; M179, `R-db9dec62`, `R-129c44c3`, `D150`, `D151`).
 *
 * The testers asked to be told what became of what they sent (*"also update user if its fixed/resolved"*), and
 * the user asked for the comments relayed outside the Feedback button to be followed and checked here too:
 * *"so the users are aware of the progress and they can also click the resolve if they tested it"*. So two tabs:
 *
 * - **My reports** — what this member sent with the Feedback button, its status in the testers' words, our note.
 * - **Testing-round comments** — every RELAYED report of the workspace, the ones still open counted on the tab.
 *   The tab shows only while something is open, so the view goes away by itself once the round is done.
 *
 * On a report the support team marked Resolved, a tester presses **It works** or **Still a problem**; anyone who
 * may see a report may comment on it. Which of those a row offers is the SERVER's answer (`actions`,
 * `can_comment`) — this page only draws it. The screenshot is named, never shown (ADR-0015 §D9).
 *
 * The tab opens where the server says (`view`): the testing round when the link asked for it (`?view=testing`,
 * from the Feedback panel and from the notifications) or when the member has sent nothing themselves. Switching
 * tabs is local — `MdsTabs` swaps the panel in place and navigates nowhere.
 */
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { MdsEmptyState, MdsPagination, MdsTabs, type TabItem } from '@meridian/design-system';
import FeedbackReportCard from '@/components/feedback/FeedbackReportCard.vue';
import PageHeader from '@/components/shell/PageHeader.vue';
import type { MyFeedbackPageProps } from '@/components/feedback/types';

const props = defineProps<MyFeedbackPageProps>();

const tabs = computed<TabItem[]>(() => [
    { key: 'mine', label: 'My reports' },
    ...(props.testing_round.open > 0
        ? [{ key: 'testing', label: `Testing-round comments (${props.testing_round.open} open)` }]
        : []),
]);

const active = ref<string>(props.view === 'testing' && props.testing_round.open > 0 ? 'testing' : 'mine');

/**
 * Both panels live inside `MdsTabs`' tab panel when there are two tabs; with only "My reports" there is no strip
 * to draw (a one-tab tablist reads as a broken control), so the same panels sit in a plain element instead.
 */
const showTabs = computed(() => tabs.value.length > 1);
const frame = computed(() => (showTabs.value ? MdsTabs : 'div'));
const frameProps = computed(() => (showTabs.value ? { items: tabs.value, modelValue: active.value, ariaLabel: 'Feedback' } : {}));

/** Paging pushes a history entry, as on every list. */
function goToPage(page: number): void {
    router.get('/feedback/mine', { page }, { preserveState: true, preserveScroll: true });
}
</script>

<template>
    <div>
        <Head title="Feedback & testing" />

        <PageHeader title="Feedback & testing" icon="feedback" />

        <component :is="frame" v-bind="frameProps" @update:model-value="(key: string) => (active = key)">
            <template v-if="active === 'testing'">
                <p class="myfb__hint">
                    Comments the testers and the team raised outside the Feedback button, and where each one stands.
                    When one is marked <strong>Resolved</strong>, check it in the app and press
                    <strong>It works</strong> — or <strong>Still a problem</strong> and say what is still wrong.
                </p>
                <ul class="myfb__list" data-testing-round>
                    <li v-for="row in testing_round.data" :key="row.id">
                        <FeedbackReportCard :row="row" />
                    </li>
                </ul>
            </template>

            <template v-if="active === 'mine'">
                <p class="myfb__hint">
                    Everything you have sent with the <strong>Feedback</strong> button, newest first. The support team
                    marks each one <strong>Ongoing</strong> while they work on it, then <strong>Resolved</strong> or
                    <strong>Declined</strong> — you get a notification and an email when they do. When one is Resolved,
                    tell them whether the fix worked.
                </p>
    
                <ul v-if="data.length > 0" class="myfb__list" data-my-reports>
                    <li v-for="row in data" :key="row.id">
                        <FeedbackReportCard :row="row" />
                    </li>
                </ul>
    
                <MdsEmptyState
                    v-else
                    illustration="default"
                    headline="You haven't sent any feedback yet"
                    description="Use the Feedback button in the top bar to report a problem, suggest an idea or ask a question. It appears here, and so does the reply."
                />
    
                <MdsPagination
                    :current-page="meta.current_page"
                    :last-page="meta.last_page"
                    :total="meta.total"
                    :per-page="meta.per_page"
                    @update:page="goToPage"
                />
            </template>
        </component>
    </div>
</template>

<style scoped>
.myfb__hint {
    max-width: 80ch;
    margin: var(--mds-space-4) 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.myfb__list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 30rem), 1fr));
    gap: var(--mds-space-4);
    margin: 0 0 var(--mds-space-4);
    padding: 0;
    list-style: none;
}

.myfb__list > li {
    display: flex;
}

.myfb__list > li > :deep(.mds-card) {
    flex: 1;
}
</style>
