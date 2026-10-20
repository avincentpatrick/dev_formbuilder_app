<script setup lang="ts">
/**
 * The platform support console for in-app feedback (Increment I7a) — PRD Feature #11's "dedicated internal
 * view … visible in aggregate to the platform support team", and the first surface anywhere in the product
 * that READS `feedback_reports`. Until this page existed, feedback was write-only: two writers, zero
 * readers.
 *
 * Rows come from SuperAdminService::listFeedback (the elevated SELECT-only carve-out); a transition PATCHes
 * back through the adopt-tenant-context path, so it lands in the reporting workspace's OWN audit log.
 *
 * ── EACH ROW'S BUTTONS COME FROM THE SERVER ─────────────────────────────────────────────────────────────
 * `row.transitions` is FeedbackStatus::allowedTransitions() — literally the same source the server guards
 * with. The page renders whatever it is handed rather than re-deriving the lifecycle in TypeScript, which
 * is how the offered verbs and the accepted verbs stay identical. (submissions/Show.vue computes its map
 * client-side because it shows ONE record; a console shows twenty-five in different states at once.)
 *
 * No column is `sortable`: MdsDataTable sorts the current page client-side, which over a server-paginated,
 * cross-tenant queue would reorder 25 of N rows and call it sorted.
 */
import { computed, reactive, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';
import {
    MdsAlert,
    MdsBadge,
    MdsButton,
    MdsDataTable,
    MdsEmptyState,
    MdsFilterBar,
    MdsFormField,
    MdsIconButton,
    MdsModal,
    MdsPagination,
    MdsSelect,
    MdsTextarea,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import AdminLayout from '@/Layouts/AdminLayout.vue';
import type { ConsoleFeedbackComment, ConsoleFeedbackPageProps, ConsoleFeedbackRow } from '@/components/feedback/types';

const props = defineProps<ConsoleFeedbackPageProps>();

const page = usePage();
const adminError = computed(() => page.props.errors?.admin);

const columns: DataTableColumn[] = [
    { key: 'submitted_at', header: 'Sent' },
    { key: 'tenant_name', header: 'Workspace' },
    { key: 'reporter', header: 'From' },
    { key: 'type', header: 'Kind' },
    { key: 'remarks', header: 'Remarks' },
    { key: 'status', header: 'Status' },
];

const selected = reactive({
    status: props.filters.applied.status ?? '',
    tenant_id: props.filters.applied.tenant_id ?? '',
    type: props.filters.applied.type ?? '',
});

const statusOptions = [{ value: '', label: 'All statuses' }, ...props.filters.statuses];
const tenantOptions = [{ value: '', label: 'All workspaces' }, ...props.filters.tenants];
const typeOptions = [{ value: '', label: 'All kinds' }, ...props.filters.types];

/**
 * The open report is looked up from the page's rows by id, never held as a copy (M179): a reply reloads the props,
 * and the modal must show the thread as it now is.
 */
const detailId = ref<string | null>(null);
const detailRow = computed<ConsoleFeedbackRow | null>(() => props.data.find((row) => row.id === detailId.value) ?? null);
const busy = ref(false);

function queryParams(extra: Record<string, string | number> = {}): Record<string, string | number> {
    const params: Record<string, string | number> = {};
    if (selected.status) params.status = selected.status;
    if (selected.tenant_id) params.tenant_id = selected.tenant_id;
    if (selected.type) params.type = selected.type;
    return { ...params, ...extra };
}

function visit(params: Record<string, string | number>, replace: boolean): void {
    router.get('/admin/feedback', params, {
        preserveState: true,
        preserveScroll: true,
        ...(replace ? { replace: true } : {}),
        onStart: () => (busy.value = true),
        onFinish: () => (busy.value = false),
    });
}

function applyFilters(): void {
    visit(queryParams(), true);
}

function goToPage(target: number): void {
    visit(queryParams({ page: target }), false);
}

function clearFilters(): void {
    selected.status = '';
    selected.tenant_id = '';
    selected.type = '';
    visit({}, true);
}

/** The reply typed for the open report (M175); sent with the next status change, then cleared. */
const note = ref('');

/** The support team's reply in the report's thread (M179, `D151`) — moves nothing; tells the reporter and the thread. */
const reply = ref('');
const replyError = ref<string | null>(null);

function openReport(row: ConsoleFeedbackRow): void {
    note.value = '';
    reply.value = '';
    replyError.value = null;
    detailId.value = row.id;
}

function sendReply(row: ConsoleFeedbackRow): void {
    if (reply.value.trim() === '') {
        replyError.value = 'Write the reply first.';

        return;
    }

    replyError.value = null;
    busy.value = true;
    router.post(
        `/admin/feedback/${row.id}/comments`,
        { body: reply.value.trim() },
        {
            preserveScroll: true,
            onFinish: () => (busy.value = false),
            onSuccess: () => (reply.value = ''),
        },
    );
}

/** Who wrote a thread message: the Support team, a member by name, or a Former member — never "nobody". */
function commentAuthor(comment: ConsoleFeedbackComment): string {
    if (comment.author_kind === 'support') {
        return 'Support team';
    }

    return comment.author?.name ?? 'Former member';
}

function transition(row: ConsoleFeedbackRow, status: string): void {
    busy.value = true;
    router.patch(
        `/admin/feedback/${row.id}`,
        // An empty box is no reply at all, never an empty one — the server keeps the last reply then.
        { status, note: note.value.trim() === '' ? null : note.value.trim() },
        {
            preserveScroll: true,
            onFinish: () => (busy.value = false),
            onSuccess: () => (detailId.value = null),
        },
    );
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** Three states: a named reporter, a removed member, or (never) the system — feedback always has an author. */
function reporterLabel(row: ConsoleFeedbackRow): string {
    return row.reporter?.name ?? 'Former member';
}

function excerpt(text: string): string {
    return text.length > 80 ? `${text.slice(0, 80)}…` : text;
}

/** `browser_info` is free-form jsonb; render whatever keys are actually there rather than assuming two. */
function browserPairs(row: ConsoleFeedbackRow): Array<[string, string]> {
    return Object.entries(row.browser_info ?? {}).map(([key, value]) => [key, String(value)]);
}
</script>

<template>
    <AdminLayout title="Feedback" icon="feedback">
        <MdsAlert v-if="adminError" tone="danger" assertive :message="adminError" />

        <!--
            Increment J2e — the section, its <h2> and the grid moved into MdsFilterBar, the last of the three
            pages DSR §3.2 note 5 named as still hand-rolling this markup.

            Geometry unchanged, and MEASURED: the three rules deleted below were byte-identical to
            FilterBar's on every property, and its one extra declaration (`font-family` on the heading) is a
            no-op because app.css already sets that family on `body` with no h1..h6 override anywhere.

            ⚠️ The <h2> was load-bearing here too — AdminLayout renders the <h1> and MdsEmptyState an <h3>, so
            without it the EMPTY state skips a level — and unlike its sibling this page had NO Vitest case
            pinning it, only the /admin/feedback axe scan. J2e adds one.
        -->
        <MdsFilterBar>
                <MdsFormField label="Status" input-id="admin-feedback-status">
                    <MdsSelect
                        id="admin-feedback-status"
                        v-model="selected.status"
                        :options="statusOptions"
                        :disabled="busy"
                        @update:model-value="applyFilters"
                    />
                </MdsFormField>
                <MdsFormField label="Workspace" input-id="admin-feedback-tenant">
                    <MdsSelect
                        id="admin-feedback-tenant"
                        v-model="selected.tenant_id"
                        :options="tenantOptions"
                        :disabled="busy"
                        @update:model-value="applyFilters"
                    />
                </MdsFormField>
                <MdsFormField label="Kind" input-id="admin-feedback-type">
                    <MdsSelect
                        id="admin-feedback-type"
                        v-model="selected.type"
                        :options="typeOptions"
                        :disabled="busy"
                        @update:model-value="applyFilters"
                    />
                </MdsFormField>
        </MdsFilterBar>

        <p class="admin-fb__hint">
            Newest first, across every workspace. Changing a status is recorded in that workspace's own audit
            log — they can see how their report was handled.
        </p>

        <MdsDataTable :columns="columns" :rows="data" :loading="busy" caption="Feedback reports" row-key="id">
            <template #cell-submitted_at="{ row }">
                {{ formatDate((row as ConsoleFeedbackRow).submitted_at) }}
            </template>

            <template #cell-reporter="{ row }">
                {{ reporterLabel(row as ConsoleFeedbackRow) }}
            </template>

            <template #cell-type="{ row }">
                <MdsBadge
                    v-if="(row as ConsoleFeedbackRow).type"
                    v-bind="statusVariant((row as ConsoleFeedbackRow).type as string)"
                />
                <span v-else class="admin-fb__none">—</span>
            </template>

            <template #cell-remarks="{ row }">
                <div class="admin-fb__remarks">
                    <span>{{ excerpt((row as ConsoleFeedbackRow).remarks) }}</span>
                    <MdsBadge
                        v-if="(row as ConsoleFeedbackRow).has_screenshot"
                        variant="neutral"
                        label="Screenshot"
                    />
                </div>
            </template>

            <template #cell-status="{ row }">
                <MdsBadge v-bind="statusVariant((row as ConsoleFeedbackRow).status)" dot />
            </template>

            <template #row-actions="{ row }">
                <MdsIconButton
                    icon="search"
                    label="Open report"
                    size="sm"
                    @click="openReport(row as ConsoleFeedbackRow)"
                />
            </template>

            <template #empty>
                <MdsEmptyState
                    v-if="empty_reason === 'no_matches'"
                    illustration="search"
                    headline="No matching reports"
                    description="Try a different status or workspace, or clear the filters."
                >
                    <template #action>
                        <MdsButton variant="secondary" @click="clearFilters">Clear filters</MdsButton>
                    </template>
                </MdsEmptyState>
                <MdsEmptyState
                    v-else
                    illustration="default"
                    headline="No feedback yet"
                    description="Reports sent from the Feedback button in any workspace arrive here."
                />
            </template>
        </MdsDataTable>

        <MdsPagination
            :current-page="meta.current_page"
            :last-page="meta.last_page"
            :total="meta.total"
            :per-page="meta.per_page"
            @update:page="goToPage"
        />

        <MdsModal
            :open="detailRow !== null"
            :title="detailRow ? `${detailRow.tenant_name} · ${reporterLabel(detailRow)}` : 'Feedback'"
            size="lg"
            @close="detailId = null"
        >
            <div v-if="detailRow" class="admin-fb__detail">
                <dl class="admin-fb__meta">
                    <div>
                        <dt>Sent</dt>
                        <dd>{{ formatDate(detailRow.submitted_at) }}</dd>
                    </div>
                    <div>
                        <dt>Kind</dt>
                        <dd>
                            <MdsBadge v-if="detailRow.type" v-bind="statusVariant(detailRow.type)" />
                            <span v-else>—</span>
                        </dd>
                    </div>
                    <div v-if="detailRow.source === 'relayed'">
                        <dt>Comment</dt>
                        <dd>
                            <!-- A relayed report (M179): its reference, title and where it came from. -->
                            <MdsBadge variant="neutral" :label="detailRow.reference ?? 'Relayed'" />
                            {{ detailRow.title }}
                            <span v-if="detailRow.source_label" class="admin-fb__role"> · {{ detailRow.source_label }}</span>
                        </dd>
                    </div>
                    <div v-else>
                        <dt>Page</dt>
                        <dd>
                            <!-- The title, linked to the full address (M175); an older report shows its path. -->
                            <a v-if="detailRow.page_url" :href="detailRow.page_url" class="admin-fb__page">{{
                                detailRow.page_title || detailRow.route
                            }}</a>
                            <span v-else class="admin-fb__mono">{{ detailRow.route }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt>Reporter</dt>
                        <dd>
                            {{ detailRow.reporter?.email ?? '—' }}
                            <!-- The role they held when they sent it (M175); absent on older reports. -->
                            <span v-if="detailRow.reporter_role_label" class="admin-fb__role">
                                · {{ detailRow.reporter_role_label }}
                            </span>
                        </dd>
                    </div>
                    <div>
                        <dt>Status</dt>
                        <dd><MdsBadge v-bind="statusVariant(detailRow.status)" dot /></dd>
                    </div>
                    <div v-if="detailRow.resolved_at">
                        <dt>Closed</dt>
                        <dd>{{ formatDate(detailRow.resolved_at) }} · {{ detailRow.resolver?.name ?? '—' }}</dd>
                    </div>
                    <div v-if="detailRow.status === 'confirmed'">
                        <dt>Confirmed working</dt>
                        <dd>{{ detailRow.confirmer?.name ?? 'Former member' }}</dd>
                    </div>
                </dl>

                <p class="admin-fb__body">{{ detailRow.remarks }}</p>

                <img
                    v-if="detailRow.has_screenshot"
                    class="admin-fb__shot"
                    :src="`/admin/feedback/${detailRow.id}/screenshot`"
                    alt="Screenshot attached to this report"
                />

                <dl v-if="browserPairs(detailRow).length" class="admin-fb__browser">
                    <template v-for="[key, value] in browserPairs(detailRow)" :key="key">
                        <dt>{{ key }}</dt>
                        <dd>{{ value }}</dd>
                    </template>
                </dl>

                <!-- M175 (`R-9bfd832e`): the last reply the reporter was sent, then the box for the next one. -->
                <div v-if="detailRow.resolution_note" class="admin-fb__reply">
                    <p class="admin-fb__reply-label">Reply sent</p>
                    <p class="admin-fb__body">{{ detailRow.resolution_note }}</p>
                </div>

                <MdsFormField
                    v-if="detailRow.transitions.length"
                    label="Reply to the reporter (optional)"
                    input-id="admin-feedback-note"
                    help="Sent with the status you choose. On Resolved or Declined the reporter and everyone in the thread are told by notification and email, and read the reply on their Feedback page."
                >
                    <MdsTextarea id="admin-feedback-note" v-model="note" :rows="3" maxlength="1000" />
                </MdsFormField>

                <!-- M179 (`D151`): the report's thread, and a reply in it that moves nothing. -->
                <section class="admin-fb__thread" aria-label="Thread" data-console-thread>
                    <h3 class="admin-fb__thread-title">Thread</h3>
                    <ol v-if="detailRow.comments?.length" class="admin-fb__comments">
                        <li v-for="comment in detailRow.comments" :key="comment.id" class="admin-fb__comment">
                            <p class="admin-fb__comment-meta">
                                <strong>{{ commentAuthor(comment) }}</strong>
                                <span v-if="comment.action === 'reopened'"> · said it is still a problem</span>
                                <span v-else-if="comment.action === 'confirmed'"> · said it works</span>
                                · {{ formatDate(comment.created_at) }}
                            </p>
                            <p v-if="comment.body" class="admin-fb__body">{{ comment.body }}</p>
                        </li>
                    </ol>
                    <p v-else class="admin-fb__role">No messages yet.</p>

                    <MdsFormField
                        label="Reply in the thread"
                        input-id="admin-feedback-reply"
                        help="Changes no status. The reporter and everyone in the thread are told."
                        :error="replyError ?? undefined"
                    >
                        <MdsTextarea id="admin-feedback-reply" v-model="reply" :rows="2" maxlength="2000" />
                    </MdsFormField>
                    <MdsButton variant="secondary" size="sm" :loading="busy" data-console-reply @click="sendReply(detailRow)">Send reply</MdsButton>
                </section>
            </div>

            <template #actions>
                <MdsButton variant="tertiary" @click="detailRow = null">Close</MdsButton>
                <MdsButton
                    v-for="option in detailRow?.transitions ?? []"
                    :key="option.value"
                    variant="primary"
                    :loading="busy"
                    @click="detailRow && transition(detailRow, option.value)"
                >
                    Mark {{ option.label }}
                </MdsButton>
            </template>
        </MdsModal>
    </AdminLayout>
</template>

<style scoped>

/* The three `.admin-fb__filters*` rules moved into MdsFilterBar verbatim (J2e) — geometry unchanged. */

.admin-fb__hint {
    margin: 0 0 var(--mds-space-3);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

/* Always underlined, as admin/Tenants.vue's name link is: /admin/* is never axe-scanned, so a colour-only
   link would be invisible to every gate. The theme-aware primary text token keeps it legible in dark. */
.admin-fb__page {
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
    text-underline-offset: 2px;
    overflow-wrap: anywhere;
}

.admin-fb__page:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.admin-fb__none {
    color: var(--mds-color-text-secondary);
}

.admin-fb__role {
    color: var(--mds-color-text-secondary);
}

/* A sunken panel needs its own border to read as one in dark, where sunken resolves to the canvas (M174). */
.admin-fb__reply {
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.admin-fb__reply-label {
    margin: 0 0 var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.admin-fb__remarks {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
}

.admin-fb__detail {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
}

.admin-fb__meta {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(9rem, 1fr));
    gap: var(--mds-space-3);
    margin: 0;
}

.admin-fb__meta dt {
    margin-bottom: var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.admin-fb__meta dd {
    margin: 0;
    color: var(--mds-color-text-body);
}

.admin-fb__mono {
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
}

.admin-fb__body {
    margin: 0;
    white-space: pre-wrap;
    color: var(--mds-color-text-body);
}

.admin-fb__shot {
    width: 100%;
    max-height: 420px;
    object-fit: contain;
    object-position: top left;
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.admin-fb__browser {
    display: grid;
    grid-template-columns: auto 1fr;
    gap: var(--mds-space-1) var(--mds-space-3);
    margin: 0;
    padding-top: var(--mds-space-3);
    border-top: 1px solid var(--mds-color-border-default);
    font-size: var(--mds-type-body-sm-font-size);
}

.admin-fb__browser dt {
    color: var(--mds-color-text-secondary);
}

.admin-fb__browser dd {
    margin: 0;
    overflow-wrap: anywhere;
    color: var(--mds-color-text-body);
}

.admin-fb__thread {
    display: grid;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-4);
    padding-top: var(--mds-space-3);
    border-top: 1px solid var(--mds-color-border-default);
}

.admin-fb__thread-title {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-heading);
}

.admin-fb__comments {
    display: grid;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.admin-fb__comment {
    padding: var(--mds-space-2) var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-sunken);
}

.admin-fb__comment-meta {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}
</style>
