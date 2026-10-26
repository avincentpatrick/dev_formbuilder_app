<script setup lang="ts">
/**
 * M189 (`version-diff-ui`, `D169`) — two versions of a form compared, question by question and setting by setting.
 *
 * PRD Feature #8's acceptance line: *"An interactive, field-by-field version-comparison view (draft vs. currently-published,
 * or any two historical versions)"*. Opened from the hub's Versions panel — Compare on a published version (against the one
 * before it), Compare with live on the draft — and either side can be changed here. Publish is untouched (`D169`): the
 * warning form-versioning-schema-migration.md §5 promised for a breaking-but-permitted change is the mark on this page.
 *
 * Every setting reads in the builder's own words (`settingWords()`, via `version-compare.ts`), so a change looks the same
 * here, in the conflict dialog and in a question's History. Built in the shared shell from the design system's components.
 */
import { computed, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    MdsAlert,
    MdsBadge,
    MdsBreadcrumb,
    MdsButton,
    MdsCheckbox,
    MdsEmptyState,
    MdsFilterBar,
    MdsFormField,
    MdsSelect,
    type BadgeVariant,
    type BreadcrumbItem,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import {
    MARK_WORDS,
    breakingWords,
    changeLines,
    counts,
    groups,
    summary,
    type CompareChange,
    type CompareLabels,
    type CompareStatus,
    type CompareWords,
    type Comparison,
} from './version-compare';

interface Side {
    id: string;
    version_number: number;
    status: string;
    name: string;
    published_at: string | null;
}

const props = defineProps<{
    form: { id: string; title: string };
    from: Side;
    to: Side;
    versions: { id: string; name: string }[];
    comparison: Comparison;
    labels: CompareLabels;
    words: CompareWords;
    compare_base: string;
    hub_href: string;
    crumbs: BreadcrumbItem[];
}>();

const onlyMarked = ref(false);
const showUnchanged = ref(false);

const options = computed(() => props.versions.map((version) => ({ value: version.id, label: version.name })));
const tally = computed(() => counts(props.comparison));
const visibleGroups = computed(() => groups(props.comparison, { onlyMarked: onlyMarked.value, showUnchanged: showUnchanged.value }));

function go(fromId: string, toId: string): void {
    router.visit(`${props.compare_base}/${fromId}/${toId}`);
}

const STATUS: Record<Exclude<CompareStatus, 'same'>, { variant: BadgeVariant; label: string }> = {
    added: { variant: 'success', label: 'Added' },
    removed: { variant: 'danger', label: 'Removed' },
    changed: { variant: 'info', label: 'Changed' },
};

function lines(kind: 'question' | 'section', changes: readonly CompareChange[]) {
    return changeLines(kind, changes, props.words, props.labels);
}

function typeName(value: string): string {
    return props.words.type_labels[value] ?? value;
}
</script>

<template>
    <div class="version-compare">
        <Head :title="`${form.title} — compare versions`" />

        <PageHeader :title="`${form.title} — compare versions`" icon="forms">
            <template #breadcrumbs>
                <MdsBreadcrumb :items="crumbs" :link-component="Link" />
            </template>
        </PageHeader>

        <MdsFilterBar>
            <MdsFormField label="Compare" input-id="compare-from">
                <MdsSelect id="compare-from" :model-value="from.id" :options="options" @update:model-value="(id: string) => go(id, to.id)" />
            </MdsFormField>
            <MdsFormField label="With" input-id="compare-to">
                <MdsSelect id="compare-to" :model-value="to.id" :options="options" @update:model-value="(id: string) => go(from.id, id)" />
            </MdsFormField>
            <MdsButton variant="secondary" data-compare-swap @click="go(to.id, from.id)">Swap</MdsButton>
        </MdsFilterBar>

        <p class="version-compare__summary" data-compare-summary>
            <strong>{{ from.name }}</strong> compared with <strong>{{ to.name }}</strong>. {{ summary(tally) }}
        </p>

        <div class="version-compare__controls">
            <MdsCheckbox v-model="onlyMarked" :label="`Only marked changes (${tally.marked})`" data-compare-only-marked />
            <MdsCheckbox v-model="showUnchanged" :disabled="onlyMarked" label="Show unchanged questions" data-compare-show-unchanged />
        </div>

        <MdsAlert tone="info" title="What is compared">
            Every setting of every question and section. A question that takes its choices from a list file or the Philippine
            places is compared by the list it names, so a list refreshed between two publishes is not shown as a change. To see
            a version as it asked its questions, use View on the
            <Link :href="hub_href" class="version-compare__alert-link">form's overview</Link>.
        </MdsAlert>

        <MdsEmptyState
            v-if="visibleGroups.length === 0"
            :headline="onlyMarked ? 'No marked changes' : 'No differences'"
            :description="onlyMarked ? 'Nothing here changes who is asked, the answers a question shows, or whether answers compare.' : 'These two versions ask the same questions in the same way.'"
        />

        <section
            v-for="group in visibleGroups"
            :key="group.key ?? ''"
            class="version-compare__group"
            :data-compare-group="group.key ?? ''"
        >
            <div class="version-compare__group-head">
                <h2 class="version-compare__group-title">{{ group.label }}</h2>
                <MdsBadge v-if="group.section && group.section.status !== 'same'" v-bind="STATUS[group.section.status]" />
                <MdsBadge v-for="mark in group.section?.marks ?? []" :key="mark" variant="warning" :label="MARK_WORDS[mark]" />
            </div>
            <table
                v-if="group.section && group.section.changes.length > 0"
                class="version-compare__table"
                :aria-label="`The section ${group.label}, in both versions`"
            >
                <thead>
                    <tr>
                        <th scope="col">Setting</th>
                        <th scope="col">{{ from.name }}</th>
                        <th scope="col">{{ to.name }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="line in lines('section', group.section.changes)" :key="line.setting">
                        <th scope="row">{{ line.setting }}</th>
                        <td>{{ line.from }}</td>
                        <td>{{ line.to }}</td>
                    </tr>
                </tbody>
            </table>

            <article
                v-for="question in group.questions"
                :key="question.key"
                class="version-compare__question"
                :data-compare-question="question.key"
            >
                <div class="version-compare__question-head">
                    <h3 class="version-compare__question-title">{{ question.label }}</h3>
                    <span class="version-compare__type">{{ typeName(question.field_type) }}</span>
                    <MdsBadge v-if="question.status !== 'same'" v-bind="STATUS[question.status]" />
                    <MdsBadge v-for="mark in question.marks" :key="mark" variant="warning" :label="MARK_WORDS[mark]" />
                </div>
                <p v-if="question.breaking" class="version-compare__note">
                    Answers may not compare across these versions: {{ breakingWords(question.breaking) }}.
                </p>
                <p v-if="question.status === 'added'" class="version-compare__note">Not in {{ from.name }}.</p>
                <p v-else-if="question.status === 'removed'" class="version-compare__note">Not in {{ to.name }}.</p>
                <p v-else-if="question.status === 'same'" class="version-compare__note">The same in both.</p>
                <table
                    v-if="question.changes.length > 0"
                    class="version-compare__table"
                    :aria-label="`${question.label}, in both versions`"
                >
                    <thead>
                        <tr>
                            <th scope="col">Setting</th>
                            <th scope="col">{{ from.name }}</th>
                            <th scope="col">{{ to.name }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="line in lines('question', question.changes)" :key="line.setting">
                            <th scope="row">{{ line.setting }}</th>
                            <td>{{ line.from }}</td>
                            <td>{{ line.to }}</td>
                        </tr>
                    </tbody>
                </table>
            </article>
        </section>
    </div>
</template>

<style scoped>
.version-compare {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
    min-width: 0;
}

.version-compare__summary {
    margin: 0;
    color: var(--mds-color-text-body);
}

.version-compare__controls {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-3) var(--mds-space-6);
}

.version-compare__group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
}

.version-compare__group-head,
.version-compare__question-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    min-width: 0;
}

.version-compare__group-title {
    margin: 0;
    font-size: var(--mds-type-heading-3-font-size);
    color: var(--mds-color-text-heading);
}

.version-compare__question {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: var(--mds-space-4);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
    min-width: 0;
}

.version-compare__question-title {
    margin: 0;
    font-size: var(--mds-type-heading-4-font-size);
    color: var(--mds-color-text-heading);
    overflow-wrap: anywhere;
}

.version-compare__type,
.version-compare__note {
    margin: 0;
    color: var(--mds-color-text-secondary);
}

.version-compare__table {
    width: 100%;
    table-layout: fixed;
    border-collapse: collapse;
}

.version-compare__table th,
.version-compare__table td {
    padding: var(--mds-space-2);
    border-top: 1px solid var(--mds-color-border-default);
    text-align: left;
    vertical-align: top;
    overflow-wrap: anywhere;
}

.version-compare__table thead th {
    border-top: 0;
    color: var(--mds-color-text-secondary);
    font-weight: var(--mds-font-weight-semibold);
}

.version-compare__table tbody th {
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

/* M189: a link inside the info alert takes the alert's own foreground — `status-info-{bg,fg}` is a measured pair — and keeps its
   underline, so colour is never its only signifier. Left alone it was the browser's default blue, 1.04:1 on the dark alert. */
.version-compare__alert-link {
    color: inherit;
    text-decoration: underline;
}
</style>
