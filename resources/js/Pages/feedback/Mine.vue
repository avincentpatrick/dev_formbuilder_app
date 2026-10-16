<script setup lang="ts">
/**
 * "My feedback" — every member's own reports (M175, `R-9bfd832e`, `D139`).
 *
 * The testers asked to be told what became of what they sent (*"also update user if its fixed/resolved"*). The
 * bell and the email say THAT a report was resolved or declined; this page is where the reporter reads WHAT —
 * the status in the testers' own words (New · Ongoing · Resolved · Declined) and the support team's reply.
 * Every role may open it; the server narrows it to the reporter's own rows (RLS on this table is tenant-wide).
 *
 * ── READ-ONLY, AND THE SCREENSHOT IS NAMED BUT NEVER SHOWN ───────────────────────────────────────────────
 * A reporter cannot move their own report through the support team's queue, so no verb is offered. And
 * ADR-0015 §D9 reads a feedback screenshot under `feedback.view` on every route that serves it or on none —
 * most reporters do not hold it — so the page says a screenshot was attached and links nothing.
 *
 * Cards rather than a table: a report is read, not compared column by column, and the reply needs room under
 * the words it answers. The grid fills the width from a 30rem floor, the M173 settings measure.
 */
import { Head, router } from '@inertiajs/vue3';
import { MdsBadge, MdsCard, MdsEmptyState, MdsIcon, MdsPagination, statusVariant } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import type { MyFeedbackPageProps, MyFeedbackRow } from '@/components/feedback/types';

defineProps<MyFeedbackPageProps>();

/** Paging pushes a history entry, as on every list. */
function goToPage(page: number): void {
    router.get('/feedback/mine', { page }, { preserveState: true, preserveScroll: true });
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function pageName(row: MyFeedbackRow): string {
    return row.page_title || row.route;
}
</script>

<template>
    <div>
        <Head title="My feedback" />

        <PageHeader title="My feedback" icon="feedback" />

        <p class="myfb__hint">
            Everything you have sent with the <strong>Feedback</strong> button, newest first. The support team
            marks each one <strong>Ongoing</strong> while they work on it, then <strong>Resolved</strong> or
            <strong>Declined</strong> — you get a notification and an email when they do.
        </p>

        <ul v-if="data.length > 0" class="myfb__list">
            <li v-for="row in data" :key="row.id">
                <MdsCard>
                    <template #header>
                        <div class="myfb__head">
                            <div class="myfb__badges">
                                <MdsBadge v-if="row.type" v-bind="statusVariant(row.type)" />
                                <MdsBadge v-bind="{ ...statusVariant(row.status), label: row.status_label }" dot />
                            </div>
                            <span class="myfb__date">Sent {{ formatDate(row.submitted_at) }}</span>
                        </div>
                    </template>

                    <p class="myfb__page">
                        <a v-if="row.page_url" :href="row.page_url" class="myfb__link">{{ pageName(row) }}</a>
                        <span v-else class="myfb__mono">{{ row.route }}</span>
                    </p>

                    <p class="myfb__remarks">{{ row.remarks }}</p>

                    <p v-if="row.has_screenshot" class="myfb__shot">
                        <MdsIcon name="image" size="sm" aria-hidden="true" />
                        Screenshot attached
                    </p>

                    <div v-if="row.resolution_note" class="myfb__reply" data-feedback-reply>
                        <p class="myfb__reply-label">Reply from the support team</p>
                        <p class="myfb__reply-body">{{ row.resolution_note }}</p>
                    </div>
                </MdsCard>
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
    </div>
</template>

<style scoped>
.myfb__hint {
    max-width: 80ch;
    margin: 0 0 var(--mds-space-4);
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

.myfb__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2);
}

.myfb__badges {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}

.myfb__date {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.myfb__page {
    margin: 0 0 var(--mds-space-2);
}

/* Always underlined: the theme-aware primary text token keeps it legible in dark (audit/Index.vue's note). */
.myfb__link {
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
    text-underline-offset: 2px;
    overflow-wrap: anywhere;
}

.myfb__link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.myfb__mono {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    overflow-wrap: anywhere;
}

.myfb__remarks {
    margin: 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    color: var(--mds-color-text-body);
}

.myfb__shot {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    margin: var(--mds-space-2) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

/* A sunken panel needs its own border to read as one in dark, where sunken resolves to the canvas (M174). */
.myfb__reply {
    margin-top: var(--mds-space-3);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.myfb__reply-label {
    margin: 0 0 var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.myfb__reply-body {
    margin: 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    color: var(--mds-color-text-body);
}
</style>
