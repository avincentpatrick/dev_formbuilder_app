<script setup lang="ts">
// Saved report views (ADR-0011 §D8, Increment H24b2).
//
// A REFUSED view renders beside the working ones, with its reason as readable text and its Apply control
// disabled — that is the whole point of §D8's read-time resolution. A stored declaration is a declaration,
// not a result: if the area it grouped by has been deleted, or it was written by an older build whose
// schema this one cannot read, the honest answer is to say so. Hiding it, or applying it anyway and
// showing a number computed from whatever survived, are the two failures the decision exists to prevent.
//
// There is no per-row permission check. SavedReportViewService::forUser() returns only rows this user
// owns, and the policy is `viewAny && owns` — so everything listed here is already editable by whoever is
// looking at it, and a `can` map per row would be a second definition of ownership.
import { MdsBadge, MdsButton, MdsCard, MdsEmptyState, MdsIconButton } from '@meridian/design-system';
import type { SavedView } from './types';

defineProps<{ views: SavedView[]; canCreate: boolean }>();

const emit = defineEmits<{
    apply: [SavedView];
    edit: [SavedView];
    remove: [SavedView];
    create: [];
}>();
</script>

<template>
    <section class="analytics__views" aria-labelledby="analytics-views-heading">
        <div class="analytics__views-head">
            <h2 id="analytics-views-heading" class="analytics__views-title">Saved reports</h2>
            <MdsButton v-if="canCreate" variant="secondary" icon-left="plus" @click="emit('create')">
                Save this report
            </MdsButton>
        </div>

        <MdsCard v-if="views.length === 0" class="analytics__views-empty">
            <MdsEmptyState
                headline="No saved reports yet"
                description="Set the filters you want, then save them here to come back to the same report later."
            />
        </MdsCard>

        <ul v-else class="analytics__views-list">
            <li v-for="view in views" :key="view.id" class="analytics__view">
                <div class="analytics__view-main">
                    <p class="analytics__view-name">
                        {{ view.name }}
                        <MdsBadge v-if="view.is_applied" variant="info" label="Showing now" />
                        <MdsBadge v-else-if="view.refused" variant="danger" label="Cannot be applied" />
                    </p>
                    <p v-if="view.refused && view.message" class="analytics__view-note">{{ view.message }}</p>
                    <p v-else-if="view.stale_form_ids.length > 0" class="analytics__view-note">
                        {{ view.stale_form_ids.length }} of the forms in this report no longer exist, so its
                        totals cover fewer forms than when it was saved.
                    </p>
                </div>

                <div class="analytics__view-actions">
                    <MdsButton
                        variant="tertiary"
                        size="sm"
                        :disabled="view.refused || view.is_applied"
                        @click="emit('apply', view)"
                    >
                        Apply
                    </MdsButton>
                    <MdsIconButton
                        icon="edit"
                        size="sm"
                        :label="`Rename ${view.name}`"
                        @click="emit('edit', view)"
                    />
                    <MdsIconButton
                        icon="trash"
                        size="sm"
                        variant="danger"
                        :label="`Delete ${view.name}`"
                        @click="emit('remove', view)"
                    />
                </div>
            </li>
        </ul>
    </section>
</template>

<style scoped>
.analytics__views {
    margin-bottom: var(--mds-space-6);
}

.analytics__views-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-3);
    margin-bottom: var(--mds-space-3);
}

.analytics__views-title {
    margin: 0;
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.analytics__views-empty {
    padding: 0;
}

.analytics__views-list {
    display: grid;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.analytics__view {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-3);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    /* JR2 — `lg`, the compact-surface tier (DSR §2.6). This is a row-card, the same species as the
       table's ≤480px card-per-row, and the survey that swept the app for card lookalikes missed it
       because it greps `background-color:` and this rule uses the `background` shorthand.
       ⚠️ It is the one place the mismatch was visible in a single view: THE EMPTY BRANCH OF THIS VERY
       LIST RENDERS AN `MdsCard` (`.analytics__views-empty` above), so a user creating their first
       saved report watched the corner radius change under them — 20px while the list was empty, 12px
       the moment it had one row. */
    border-radius: var(--mds-radius-lg);
    background: var(--mds-color-bg-surface);
}

.analytics__view-main {
    min-width: 0;
    flex: 1 1 240px;
}

.analytics__view-name {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    margin: 0;
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.analytics__view-note {
    margin: var(--mds-space-1) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.analytics__view-actions {
    display: flex;
    align-items: center;
    gap: var(--mds-space-1);
}
</style>
