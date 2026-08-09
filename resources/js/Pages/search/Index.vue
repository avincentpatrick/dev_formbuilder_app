<script setup lang="ts">
/**
 * Global search results (Increment J1b — PRD §3.7, "global search is non-negotiable").
 *
 * ── THE DISCLOSURE RULE, WHICH IS THE MOST IMPORTANT THING ON THIS PAGE ─────────────────────────────────
 * The zero-result state renders ONE string with ONE code path, whether nothing matched or everything that
 * matched is invisible to this user. There is deliberately no branch that could ever produce a different
 * message, because a distinct "some results are hidden from you" IS the disclosure. For the same reason the
 * illustration is `search` and never `lock` — a padlock on an empty result set says "something is there",
 * which is precisely what the user is not entitled to learn.
 *
 * The standing hint below the header is rendered UNCONDITIONALLY, on every state including populated ones.
 * Shown only on zero results it would be a signal; shown always it is a policy statement and carries no
 * information about any particular query.
 *
 * ── GROUPED, NOT FLAT, AND CAPPED RATHER THAN PAGINATED ─────────────────────────────────────────────────
 * Each entity is its own section with its own cap. Grouped results cannot be paginated coherently ("page 2
 * of what?" across arms with different predicates), so J1b caps instead — and says so out loud via
 * `has_more`, because a silent truncation reads as "that is everything". Narrowing to one entity raises the
 * cap. A real paginator is filed, not half-built.
 */
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { MdsBadge, MdsButton, MdsEmptyState, MdsFormField, MdsSelect, MdsTextInput } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';

interface SearchItem {
    id: string;
    title: string;
    subtitle: string;
    url: string;
}

interface SearchGroup {
    entity: string;
    label: string;
    items: SearchItem[];
    has_more: boolean;
}

const props = defineProps<{
    data: SearchGroup[];
    counts: Record<string, number>;
    filters: {
        entities: { value: string; label: string }[];
        applied: { q: string | null; entity: string | null };
    };
    limits: { per_entity: number; single_entity: number };
    empty_reason: 'no_query' | 'no_matches' | 'no_permitted_scopes' | null;
}>();

const keyword = ref(props.filters.applied.q ?? '');
const entity = ref(props.filters.applied.entity ?? '');

/**
 * Clamped for display. A 200-character query echoed into a headline at 375px is the horizontal-overflow trap
 * that `.app-shell`'s `overflow-x: clip` would hide rather than catch.
 */
const clamped = computed(() => {
    const q = props.filters.applied.q ?? '';
    return q.length > 60 ? `${q.slice(0, 60)}…` : q;
});

const entityOptions = computed(() => [
    { value: '', label: 'All results' },
    ...props.filters.entities.map((e) => ({
        value: e.value,
        label: props.counts[e.value] === undefined ? e.label : `${e.label} (${props.counts[e.value]})`,
    })),
]);

const hasFilters = computed(() => keyword.value !== '' || entity.value !== '');

function visit(): void {
    const params: Record<string, string> = {};
    if (keyword.value !== '') params.q = keyword.value;
    if (entity.value !== '') params.entity = entity.value;

    // `replace: true` so refining a query does not stack a history entry per keystroke-batch — the rule
    // `submissions/Inbox.vue` already applies to its filters.
    router.get('/search', params, { preserveState: true, preserveScroll: true, replace: true });
}

function clearFilters(): void {
    keyword.value = '';
    entity.value = '';
    router.get('/search', {}, { preserveState: true, preserveScroll: true, replace: true });
}
</script>

<template>
    <div>
        <Head :title="filters.applied.q ? `Search: ${clamped}` : 'Search'" />

        <PageHeader title="Search" icon="search" />

        <!--
            Style B (a labelled section with its own <h2>), matching audit/Index.vue and feedback/Index.vue.
            The heading is load-bearing rather than decorative: PageHeader renders the <h1> and
            MdsEmptyState renders an <h3>, so dropping it fails axe's heading-order check ONLY in the empty
            state — i.e. only on a query that matched nothing. Rendered unconditionally for the same reason.
        -->
        <section class="search__filters" aria-labelledby="search-filters-heading">
            <h2 id="search-filters-heading" class="search__filters-heading">Filters</h2>
            <div class="search__filters-grid">
                <MdsFormField label="Keywords" input-id="search-q">
                    <MdsTextInput
                        id="search-q"
                        v-model="keyword"
                        type="search"
                        name="q"
                        placeholder="Search forms and submissions"
                        @keyup.enter="visit"
                    />
                </MdsFormField>
                <MdsFormField label="Type" input-id="search-entity">
                    <MdsSelect
                        id="search-entity"
                        v-model="entity"
                        :options="entityOptions"
                        @update:model-value="visit"
                    />
                </MdsFormField>
            </div>
            <div class="search__filters-actions">
                <MdsButton variant="secondary" @click="visit">Search</MdsButton>
                <MdsButton v-if="hasFilters" variant="tertiary" @click="clearFilters">Clear filters</MdsButton>
            </div>
        </section>

        <p class="search__scope-note">Only results you have permission to see are shown.</p>

        <MdsEmptyState
            v-if="empty_reason === 'no_query'"
            illustration="search"
            headline="Search your workspace"
            description="Find forms and submissions by keyword, or paste a submission reference."
        />

        <!--
            ⚠️ ONE STATE, ONE STRING. `no_matches` and `no_permitted_scopes` share this block deliberately:
            a user who may search nothing must not be able to tell that apart from a query that found
            nothing. Do not split it "to be more helpful".
        -->
        <MdsEmptyState
            v-else-if="empty_reason !== null"
            illustration="search"
            :headline="`No results for “${clamped}”`"
            description="Check the spelling, or try a different word."
        >
            <template #action>
                <MdsButton variant="secondary" @click="clearFilters">Clear search</MdsButton>
            </template>
        </MdsEmptyState>

        <div v-else class="search__groups">
            <section v-for="group in data" :key="group.entity" class="search__group">
                <h2 class="search__group-heading">
                    {{ group.label }}
                    <MdsBadge
                        v-if="counts[group.entity] !== undefined"
                        :label="String(counts[group.entity])"
                        variant="neutral"
                    />
                </h2>

                <ul v-if="group.items.length > 0" class="search__list">
                    <li v-for="item in group.items" :key="item.id" class="search__row">
                        <Link :href="item.url" class="search__row-title">{{ item.title }}</Link>
                        <span class="search__row-sub">{{ item.subtitle }}</span>
                    </li>
                </ul>
                <p v-else class="search__group-empty">No {{ group.label.toLowerCase() }} match this search.</p>

                <!--
                    The truncation is stated, never silent. Without this a capped list reads as "that is
                    everything", which is the one thing a search result must not imply.
                -->
                <p v-if="group.has_more" class="search__more">
                    Showing the first {{ filters.applied.entity ? limits.single_entity : limits.per_entity }}.
                    <button
                        v-if="!filters.applied.entity"
                        type="button"
                        class="search__more-link"
                        @click="entity = group.entity; visit()"
                    >
                        Show more {{ group.label.toLowerCase() }}
                    </button>
                </p>
            </section>
        </div>
    </div>
</template>

<style scoped>
.search__filters {
    margin-bottom: var(--mds-space-5);
    padding: var(--mds-space-4);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
}

.search__filters-heading {
    margin: 0 0 var(--mds-space-3);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
    text-transform: uppercase;
    letter-spacing: 0.04em;
}

.search__filters-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
    gap: var(--mds-space-3);
}

.search__filters-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-3);
}

.search__scope-note {
    margin: 0 0 var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.search__groups {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.search__group-heading {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    margin: 0 0 var(--mds-space-3);
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-4-font-size);
    color: var(--mds-color-text-heading);
}

.search__list {
    margin: 0;
    padding: 0;
    list-style: none;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
}

.search__row {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-0-5);
    padding: var(--mds-space-3);
    background-color: var(--mds-color-bg-surface);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
}

.search__row-title {
    color: var(--mds-color-action-primary-fg);
    font-weight: var(--mds-font-weight-semibold);
    text-decoration: none;
    /* A long form title must wrap rather than push the card wider than the viewport at 375px. */
    overflow-wrap: anywhere;
}

.search__row-title:hover {
    text-decoration: underline;
}

.search__row-title:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

.search__row-sub,
.search__group-empty,
.search__more {
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
    overflow-wrap: anywhere;
}

.search__group-empty,
.search__more {
    margin: var(--mds-space-2) 0 0;
}

.search__more-link {
    padding: 0;
    border: 0;
    background: none;
    color: var(--mds-color-action-primary-fg);
    font: inherit;
    cursor: pointer;
    text-decoration: underline;
}

.search__more-link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}
</style>
