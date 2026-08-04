<script setup lang="ts">
// Cross-form analytics (ADR-0011, Increment H24b2) — the Business-gated page over H24a's substrate and
// H24b1's chart primitives. Rendered inside the persistent AppLayout (assigned in app.ts).
//
// ── The declaration lives in the URL, and the SERVER owns it ────────────────────────────────────────────
// Local control state is seeded from `applied` — what the server actually resolved — and RE-SEEDED on
// every response. That watch is the honesty mechanism: when the server coerces something (a defaulted
// window, a clamped top_n, a range it refused and replaced) the controls must move with it, or the page
// shows one declaration and reports another. The `filters.applied` round-trip on the submissions inbox is
// the same idea; this page just has more of it to round-trip.
//
// Filter changes are Inertia PARTIAL reloads, not /api/v1 calls. A tenant page takes Inertia props, there
// is no session-authed API client, and a domain exception on a web route returns a 302 with a flash —
// which an XHR follows, sees `ok`, and then chokes parsing as JSON.
//
// `filters` and `summary` are excluded from RELOAD_ONLY on purpose: the option catalogues are
// tenant-scoped, not range-scoped, so they cannot change when a filter changes. See query-params.ts.
import { computed, reactive, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import { MdsButton, MdsCard, MdsEmptyState } from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import AnalyticsViewSwitcher from '@/components/analytics/AnalyticsViewSwitcher.vue';
import AnalyticsFilterBar from '@/components/analytics/AnalyticsFilterBar.vue';
import AnalyticsSummaryTiles from '@/components/analytics/AnalyticsSummaryTiles.vue';
import AnalyticsChartsCard from '@/components/analytics/AnalyticsChartsCard.vue';
import SavedViewList from '@/components/analytics/SavedViewList.vue';
import SaveViewModal from '@/components/analytics/SaveViewModal.vue';
import QuestionPicker from '@/components/analytics/QuestionPicker.vue';
import QuestionResultCard from '@/components/analytics/QuestionResultCard.vue';
import { QUESTION_ONLY, RELOAD_ONLY, toQueryParams, toSearchString } from '@/components/analytics/query-params';
import { rangeLabel } from '@/components/analytics/bucket-label';
import type {
    AppliedQuery,
    FilterOptions,
    QuestionAggregate,
    QuestionRow,
    Report,
    SavedView,
    SelectionRefusal,
    Summary,
} from '@/components/analytics/types';

const props = defineProps<{
    applied: AppliedQuery;
    refusal: SelectionRefusal | null;
    can: { createView: boolean };
    filters: FilterOptions;
    summary: Summary;
    report: Report | null;
    questions: QuestionRow[];
    question: QuestionAggregate | null;
    views: SavedView[];
}>();

const state = reactive<AppliedQuery>({ ...props.applied });
const selectedQuestion = ref<string | null>(props.question?.key ?? null);
const busy = ref(false);
const modalOpen = ref(false);
const editing = ref<SavedView | null>(null);

watch(
    () => props.applied,
    (applied) => Object.assign(state, applied),
    { deep: true },
);

watch(
    () => props.question,
    (question) => {
        selectedQuestion.value = question?.key ?? null;
    },
);

const banner = computed(() => rangeLabel(state.from, state.to, state.timezone));

/**
 * A question is picked but its aggregate is not here yet.
 *
 * Found by LOOKING at the real page, not by any gate: with the radio checked, the card still read "Pick a
 * question" until the response landed — two controls on one screen disagreeing about whether a question had
 * been picked, which is the same class of defect as H24b1's two draft tiles. The comparison is on `key`, not
 * merely on null, because switching from one question to another must also show pending rather than keep
 * the PREVIOUS question's numbers under the new question's name.
 */
const questionPending = computed(
    () => selectedQuestion.value !== null && props.question?.key !== selectedQuestion.value,
);

const exportsRemaining = computed(() => {
    const { used, limit } = props.summary.exports;

    return limit === null ? null : Math.max(0, limit - used);
});

function visit(patch: Partial<AppliedQuery>, only: readonly string[]): void {
    Object.assign(state, patch);

    const extra: Record<string, string> =
        selectedQuestion.value === null ? {} : { question: selectedQuestion.value };

    router.get('/analytics', toQueryParams(state, extra), {
        only: [...only],
        preserveState: true,
        preserveScroll: true,
        // `replace`, so twenty filter twiddles do not put twenty entries in the back stack. Applying a
        // saved view is the same class of action, so it replaces too.
        replace: true,
        onStart: () => {
            busy.value = true;
        },
        onFinish: () => {
            busy.value = false;
        },
    });
}

function apply(patch: Partial<AppliedQuery>): void {
    visit(patch, RELOAD_ONLY);
}

function selectQuestion(key: string | null): void {
    selectedQuestion.value = key;
    visit({}, QUESTION_ONLY);
}

function applyView(view: SavedView): void {
    // The stored definition IS an AppliedQuery — one shape, authored once by AnalyticsQuery::toArray().
    apply(view.definition as unknown as AppliedQuery);
}

function openCreate(): void {
    editing.value = null;
    modalOpen.value = true;
}

function openRename(view: SavedView): void {
    editing.value = view;
    modalOpen.value = true;
}

function removeView(view: SavedView): void {
    router.delete(`/analytics/views/${view.id}`, { preserveScroll: true, preserveState: true });
}

/**
 * A real browser navigation, not an Inertia visit — the response is a streamed file, and Inertia would
 * try to read it as a page. The inbox export does the same thing for the same reason.
 */
function download(format: 'csv' | 'xlsx'): void {
    window.location.href = `/analytics/export?${toSearchString(toQueryParams(state, { format }))}`;
}
</script>

<template>
    <div>
        <Head title="Analytics" />

        <PageHeader title="Analytics" icon="chart-bar">
            <template #actions>
                <AnalyticsViewSwitcher current="analytics" />
                <MdsButton
                    variant="secondary"
                    icon-left="download"
                    :disabled="refusal !== null || exportsRemaining === 0"
                    @click="download('csv')"
                >
                    Export CSV
                </MdsButton>
                <MdsButton
                    variant="tertiary"
                    icon-left="download"
                    :disabled="refusal !== null || exportsRemaining === 0"
                    @click="download('xlsx')"
                >
                    Export Excel
                </MdsButton>
            </template>
        </PageHeader>

        <p class="analytics__banner">{{ banner }}</p>
        <p v-if="exportsRemaining !== null" class="analytics__banner analytics__banner--quota">
            {{ exportsRemaining.toLocaleString() }} exports left this period.
        </p>

        <AnalyticsFilterBar :applied="state" :options="filters" :busy="busy" @apply="apply" />

        <SavedViewList
            :views="views"
            :can-create="can.createView"
            @apply="applyView"
            @edit="openRename"
            @remove="removeView"
            @create="openCreate"
        />

        <MdsCard v-if="refusal !== null" class="analytics__refusal">
            <MdsEmptyState
                illustration="lock"
                headline="This report cannot be produced"
                :description="refusal.message"
            />
        </MdsCard>

        <template v-else-if="report !== null">
            <AnalyticsSummaryTiles :report="report" />
            <AnalyticsChartsCard :report="report" />

            <section class="analytics__questions" aria-labelledby="analytics-questions-heading">
                <h2 id="analytics-questions-heading" class="analytics__section-title">Answers</h2>
                <p class="analytics__banner">
                    Summaries of individual questions, for the questions an author marked “Indexed for
                    reporting”.
                </p>

                <div class="analytics__questions-grid">
                    <QuestionPicker
                        :questions="questions"
                        :selected="selectedQuestion"
                        @select="selectQuestion"
                    />

                    <QuestionResultCard
                        v-if="question !== null || questionPending"
                        :question="question"
                        :loading="questionPending"
                    />
                    <MdsCard v-else>
                        <MdsEmptyState
                            illustration="search"
                            headline="Pick a question"
                            description="Choose a question on the left to summarise its answers across every form in this report."
                        />
                    </MdsCard>
                </div>
            </section>
        </template>

        <SaveViewModal v-model:open="modalOpen" :view="editing" :applied="state" />
    </div>
</template>

<style scoped>
.analytics__banner {
    margin: 0 0 var(--mds-space-4);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.analytics__banner--quota {
    margin-top: calc(-1 * var(--mds-space-3));
}

.analytics__refusal {
    padding: 0;
}

.analytics__section-title {
    margin: 0 0 var(--mds-space-2);
    font-family: var(--mds-font-family-display);
    font-size: var(--mds-type-heading-3-font-size);
    line-height: var(--mds-type-heading-3-line-height);
    font-weight: var(--mds-type-heading-3-font-weight);
    color: var(--mds-color-text-heading);
}

.analytics__questions-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(min(100%, 320px), 1fr));
    gap: var(--mds-space-4);
    align-items: start;
}
</style>
