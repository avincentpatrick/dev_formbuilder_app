<script setup lang="ts">
/**
 * One feedback report as a member reads it (M175; M179, `D150`, `D151`) — on both tabs of the Feedback page.
 *
 * A report sent with the Feedback button names its page; a RELAYED one (a comment the team loaded, every member
 * sees it) has no page and names itself instead — its title, its reference (`U4`), and where it came from. Then
 * what was said, the support team's note, who confirmed the fix, **It works** / **Still a problem** where the
 * server offers them, and the thread with its comment box. The screenshot is named, never shown (ADR-0015 §D9).
 */
import { MdsBadge, MdsCard, MdsIcon, statusVariant } from '@meridian/design-system';
import FeedbackThread from '@/components/feedback/FeedbackThread.vue';
import FeedbackVerdict from '@/components/feedback/FeedbackVerdict.vue';
import type { MyFeedbackRow } from '@/components/feedback/types';

defineProps<{
    row: MyFeedbackRow;
}>();

function formatDate(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>

<template>
    <MdsCard :data-feedback-report="row.reference ?? row.id">
        <template #header>
            <div class="fbc__head">
                <div class="fbc__badges">
                    <MdsBadge v-if="row.reference" variant="neutral" :label="row.reference" />
                    <MdsBadge v-if="row.type" v-bind="statusVariant(row.type)" />
                    <MdsBadge v-bind="{ ...statusVariant(row.status), label: row.status_label }" dot />
                </div>
                <span v-if="row.source === 'button'" class="fbc__date">Sent {{ formatDate(row.submitted_at) }}</span>
            </div>
        </template>

        <h3 v-if="row.title" class="fbc__title">{{ row.title }}</h3>
        <p v-if="row.source_label" class="fbc__source">From: {{ row.source_label }}</p>

        <p v-if="row.source === 'button'" class="fbc__page">
            <a v-if="row.page_url" :href="row.page_url" class="fbc__link">{{ row.page_title || row.route }}</a>
            <span v-else class="fbc__mono">{{ row.route }}</span>
        </p>

        <p class="fbc__remarks">{{ row.remarks }}</p>

        <p v-if="row.has_screenshot" class="fbc__shot">
            <MdsIcon name="image" size="sm" aria-hidden="true" />
            Screenshot attached
        </p>

        <div v-if="row.resolution_note" class="fbc__note" data-feedback-reply>
            <p class="fbc__note-label">Our note</p>
            <p class="fbc__note-body">{{ row.resolution_note }}</p>
        </div>

        <p v-if="row.status === 'confirmed'" class="fbc__confirmed" data-feedback-confirmed>
            <MdsIcon name="check" size="sm" aria-hidden="true" />
            Confirmed working{{ row.confirmer ? ` by ${row.confirmer}` : '' }}
        </p>

        <FeedbackVerdict :report-id="row.id" :actions="row.actions" />
        <FeedbackThread :report-id="row.id" :comments="row.comments" :can-comment="row.can_comment" />
    </MdsCard>
</template>

<style scoped>
.fbc__head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2);
}

.fbc__badges {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}

.fbc__date,
.fbc__source {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.fbc__title {
    margin: 0 0 var(--mds-space-1);
    font-size: var(--mds-type-body-md-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.fbc__source {
    margin: 0 0 var(--mds-space-2);
}

.fbc__page {
    margin: 0 0 var(--mds-space-2);
}

/* Always underlined: the theme-aware primary text token keeps it legible in dark (audit/Index.vue's note). */
.fbc__link {
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
    text-underline-offset: 2px;
    overflow-wrap: anywhere;
}

.fbc__link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.fbc__mono {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    overflow-wrap: anywhere;
}

.fbc__remarks {
    margin: 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    color: var(--mds-color-text-body);
}

.fbc__shot,
.fbc__confirmed {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    margin: var(--mds-space-2) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.fbc__confirmed {
    display: flex;
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

/* A sunken panel needs its own border to read as one in dark, where sunken resolves to the canvas (M174). */
.fbc__note {
    margin-top: var(--mds-space-3);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.fbc__note-label {
    margin: 0 0 var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.fbc__note-body {
    margin: 0;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    color: var(--mds-color-text-body);
}
</style>
