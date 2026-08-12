<script setup lang="ts">
/**
 * Forms list + draft/publish lifecycle (Increment D3, re-composed by JR3). The tenant's forms as a CARD
 * GRID by default, with the DataTable kept behind an `MdsSegmentedControl` and addressable as
 * `?view=table`; status Badges + the nine per-row actions (rename, publish, archive, version
 * history/restore, …) are identical in both views because both render `FormRowActions`. All writes hit
 * the D3 endpoints and surface outcome as a Toast via the controller flash.
 *
 * ⚠️ THE TWO VIEWS ARE NOT TWO FEATURES. Cards suit the tenant with four forms; the table suits the one
 * with sixty sorted by response count. The card grid is the default because it is also the fix for a
 * measured defect: `MdsDataTable` only collapses to card-per-row at ≤480px, so across 481–1024px — every
 * tablet, every small laptop — this page was a sideways-scrolling strip. A grid reflows 1/2/3/4-up and
 * therefore cannot have that problem, or the empty-gutter problem above 1440px, at any width.
 */
import { computed, reactive, ref } from 'vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsDataTable,
    MdsEmptyState,
    MdsFilterBar,
    MdsFormField,
    MdsModal,
    MdsSearchField,
    MdsSegmentedControl,
    MdsTextInput,
    MdsTextarea,
    statusVariant,
    type DataTableColumn,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import SaveAsTemplateModal from '@/components/forms/SaveAsTemplateModal.vue';
import AssignScopeModal from '@/components/forms/AssignScopeModal.vue';
import FormCard from '@/components/forms/FormCard.vue';
import FormRowActions from '@/components/forms/FormRowActions.vue';
import { useEntitlements } from '@/composables/useEntitlements';
import type { FormListFacet, FormRow, FormVersionRow } from '@/types/forms';

// Hide the form_templates affordances when the plan doesn't include them (H5c) — the /forms/templates and
// save-as-template routes are server-gated (feature:form_templates), so this only spares a 402 click.
const { feature } = useEntitlements();

type ScopeOption = { id: string; name: string; parent_id: string | null; is_active: boolean };

const props = defineProps<{
    forms: FormRow[];
    scopes: ScopeOption[];
    filters: { applied: { q: string | null; state: string | null }; facets: FormListFacet[] };
    /** Server-computed (J1e). See the `#empty` slot for what this page used to claim without it. */
    empty_reason: 'no_matches' | 'no_rows' | null;
    /** `grid` | `table` — presentational, and server-echoed so SSR and the client agree on first paint. */
    view: string;
}>();

// ── Keyword filter (J1e) + facets (JR3) ─────────────────────────────────
const selected = reactive({ q: props.filters.applied.q ?? '' });
const busy = ref(false);

/** Every navigation on this page goes through here, so the three URL keys can never drift apart. */
function go(overrides: { q?: string | null; state?: string | null; view?: string } = {}): void {
    const q = overrides.q !== undefined ? overrides.q : selected.q;
    const state = overrides.state !== undefined ? overrides.state : props.filters.applied.state;
    const view = overrides.view !== undefined ? overrides.view : props.view;

    router.get(
        '/forms',
        {
            ...(q ? { q } : {}),
            ...(state ? { state } : {}),
            // Absent for the default, so the plain `/forms` URL stays clean and a shared link only
            // carries the view when it was actually chosen.
            ...(view === 'table' ? { view } : {}),
        },
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (busy.value = true),
            onFinish: () => (busy.value = false),
        },
    );
}

/**
 * Filtering REPLACES the history entry — the Inbox contract, and the reason is sharper for a keyword than
 * for a select: typing four words one Enter at a time would otherwise leave four dead entries between the
 * user and wherever they came from.
 */
function applyFilters(): void {
    go();
}

function clearFilters(): void {
    selected.q = '';
    go({ q: '' });
}

/**
 * The view lives in the URL rather than in a stored preference (user decision, JR3). It costs a round
 * trip the client could have avoided, and buys three things a `localStorage` flag would not: it is
 * SSR-safe with no hydration guard — the app has no client-side storage anywhere today — it is
 * shareable, and it is assertable server-side. `replace: true` keeps it out of the back button, which is
 * the same contract the filter already has.
 */
function setView(next: string): void {
    if (next !== props.view) go({ view: next });
}

const viewOptions = [
    { value: 'grid', label: 'Cards', icon: 'layout' as const },
    { value: 'table', label: 'Table', icon: 'list' as const },
];

/** The chip's own value, or null for "All" — the absence of `?state`, not a magic string. */
function setFacet(value: string | null): void {
    go({ state: props.filters.applied.state === value ? null : value });
}

/** Shared by both views, so neither can render its own idea of "nothing here". */
const isEmpty = computed(() => props.forms.length === 0);

// ── Scope picker (G10b2) ────────────────────────────────────────────────
const scopeTarget = ref<FormRow | null>(null);

/**
 * The table view gains the three columns the row was always missing (JR3) — the same data the cards
 * show, for the tenant who would rather sort sixty forms by response count than scan them.
 *
 * `responses` is sortable and end-aligned; `capacity` is not sortable, because "312 / 500" and "—" have
 * no total order that would not mislead.
 */
const columns: DataTableColumn[] = [
    { key: 'title', header: 'Form', sortable: true },
    { key: 'status', header: 'Status' },
    { key: 'responses', header: 'Responses', align: 'end', sortable: true },
    { key: 'capacity', header: 'Capacity', align: 'end' },
    { key: 'last_response', header: 'Last response' },
    { key: 'version', header: 'Version' },
    { key: 'updated_at', header: 'Updated', sortable: true },
];

/**
 * `MdsDataTable` sorts on `row[column.key]`, so a purely-slotted column sorts by `undefined`. Flattening
 * the two nested values the table sorts or shows keeps the component's own comparator honest rather than
 * silently comparing nothing — `responses` is the one that matters, being sortable.
 */
const tableRows = computed(() =>
    props.forms.map((row) => ({
        ...row,
        responses: row.stats.responses,
        capacity: row.schedule.max_responses,
        last_response: row.stats.last_response_at,
    })),
);

function capacityLabel(row: FormRow): string {
    const { max_responses: cap, remaining } = row.schedule;
    if (cap === null || remaining === null) return '—';

    return `${Math.max(0, cap - remaining)} / ${cap}`;
}

function versionLabel(row: FormRow): string {
    if (row.current_version !== null) return `v${row.current_version} live`;
    if (row.draft_version !== null) return `v${row.draft_version} draft`;
    return '—';
}

function formatDate(iso: string | null): string {
    if (!iso) return '—';
    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

// ── Create ──────────────────────────────────────────────────────────────
const createOpen = ref(false);
const createForm = useForm({ title: '', description: '' });

function openCreate(): void {
    createForm.reset();
    createForm.clearErrors();
    createOpen.value = true;
}

function submitCreate(): void {
    createForm.post('/forms', {
        preserveScroll: true,
        onSuccess: () => {
            createOpen.value = false;
        },
    });
}

// ── Rename / edit metadata ───────────────────────────────────────────────
const editTarget = ref<FormRow | null>(null);
const editForm = useForm({ title: '', description: '' });

function openEdit(row: FormRow): void {
    editTarget.value = row;
    editForm.title = row.title;
    editForm.description = row.description ?? '';
    editForm.clearErrors();
}

function submitEdit(): void {
    if (!editTarget.value) return;
    editForm.patch(`/forms/${editTarget.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editTarget.value = null;
        },
    });
}

// ── Publish ──────────────────────────────────────────────────────────────
const publishing = reactive<{ id: string | null }>({ id: null });

function publish(row: FormRow): void {
    publishing.id = row.id;
    router.post(
        `/forms/${row.id}/publish`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                publishing.id = null;
            },
        },
    );
}

// ── Archive ──────────────────────────────────────────────────────────────
const archiveTarget = ref<FormRow | null>(null);
const archiving = reactive({ busy: false });

function submitArchive(): void {
    if (!archiveTarget.value) return;
    archiving.busy = true;
    router.post(
        `/forms/${archiveTarget.value.id}/archive`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                archiving.busy = false;
            },
            onSuccess: () => {
                archiveTarget.value = null;
            },
        },
    );
}

// ── Save as template (G9a) ────────────────────────────────────────────────
const templateTarget = ref<FormRow | null>(null);
const templateOpen = ref(false);

function openSaveAsTemplate(row: FormRow): void {
    templateTarget.value = row;
    templateOpen.value = true;
}

// ── Version history + restore ─────────────────────────────────────────────
const historyTarget = ref<FormRow | null>(null);
const restoreTarget = ref<{ form: FormRow; version: FormVersionRow } | null>(null);
const restoring = reactive({ busy: false });

// ── Printable blank form (I12) ────────────────────────────────────────────
// A streamed PDF download, so it navigates the browser rather than making an Inertia visit — the
// `exportXlsform()` pattern from the builder. An Inertia <Link> here would fetch the bytes as an XHR
// and then find no page component to swap in.
function printBlank(form: FormRow, version: FormVersionRow): void {
    window.location.href = `/forms/${form.id}/versions/${version.id}/print`;
}

function submitRestore(): void {
    if (!restoreTarget.value) return;
    restoring.busy = true;
    const { form, version } = restoreTarget.value;
    router.post(
        `/forms/${form.id}/versions/${version.id}/restore`,
        {},
        {
            preserveScroll: true,
            onFinish: () => {
                restoring.busy = false;
            },
            onSuccess: () => {
                restoreTarget.value = null;
                historyTarget.value = null;
            },
        },
    );
}
</script>

<template>
    <div>
        <Head title="Forms" />

        <PageHeader title="Forms" icon="forms">
            <template #actions>
                <!-- In PageHeader's actions, NOT inside MdsFilterBar: that bar is a
                     `repeat(auto-fit, minmax(12rem, 1fr))` grid of filter controls, and a view switch is
                     not a filter — it would take a filter column and read as one. -->
                <MdsSegmentedControl
                    :model-value="view"
                    :options="viewOptions"
                    ariaLabel="Layout"
                    compact
                    @update:model-value="setView"
                />
                <Link v-if="feature('form_templates')" href="/forms/templates">
                    <MdsButton variant="tertiary" icon-left="layout">New from template</MdsButton>
                </Link>
                <MdsButton variant="primary" icon-left="plus" @click="openCreate">New form</MdsButton>
            </template>
        </PageHeader>

        <MdsFilterBar>
            <MdsSearchField
                v-model="selected.q"
                :applied="filters.applied.q ?? ''"
                label="Search forms"
                placeholder="Title, description or slug"
                @submit="applyFilters"
            />
        </MdsFilterBar>

        <!--
            Counted facet chips (JR3). Toggle buttons rather than links, and `aria-pressed` rather than a
            radio group, because "All" is the ABSENCE of a filter and clicking an active chip clears it —
            a radio group cannot express "none selected" without a fourth control that means nothing.

            ⚠️ The counts come from the server and are computed BEFORE the facet narrows the set, so every
            chip keeps its own total while one is active. And `empty_reason` was widened to see this
            filter — without that, clicking "Draft" in a tenant with no drafts would say "Create your
            first form", which is verbatim the J1e defect one filter over.
        -->
        <div class="forms__facets" role="group" aria-label="Filter by state">
            <button
                v-for="facet in filters.facets"
                :key="facet.value ?? 'all'"
                type="button"
                class="forms__facet"
                :class="{ 'forms__facet--on': (filters.applied.state ?? null) === facet.value }"
                :aria-pressed="(filters.applied.state ?? null) === facet.value"
                @click="setFacet(facet.value)"
            >
                {{ facet.label }}
                <b>{{ facet.count }}</b>
            </button>
        </div>

        <!--
            The empty state is rendered ONCE, outside both views, rather than inside `MdsDataTable`'s
            `#empty` slot where it used to live. Two reasons: the card grid has no such slot, and the two
            views must not be able to disagree about what "no forms" says. The headlines are load-bearing
            strings — `forms/index.test.ts` asserts both, and `responsive-axe.spec.ts` waits on the
            "No matching" heading before it scans the filtered-to-zero page.

            ⚠️ THIS SLOT USED TO BE THE "Create your first form" BRANCH ALONE, AND THAT WAS THE SINGLE
            MOST VISIBLE DEFECT J1e HAD TO FIX. The moment this page took a `?q`, an established tenant
            searching for a word no form contains would have been told it had no forms at all — and
            offered a button to make one. The branch is server-computed (`empty_reason`) rather than
            inferred from `selected.q`, because the client cannot see what the server clamped or
            defaulted; see AuditLogPresenter for the rule.
        -->
        <template v-if="isEmpty">
            <MdsEmptyState
                v-if="empty_reason === 'no_matches'"
                illustration="search"
                headline="No matching forms"
                description="No form's title, description or slug matches that. Try fewer words, or clear the search."
            >
                <template #action>
                    <MdsButton variant="secondary" @click="clearFilters">Clear search</MdsButton>
                </template>
            </MdsEmptyState>
            <MdsEmptyState
                v-else
                headline="Create your first form"
                description="Start from a ready-made template, or build one from a blank canvas."
            >
                <template #action>
                    <div class="forms__empty-actions">
                        <Link v-if="feature('form_templates')" href="/forms/templates">
                            <MdsButton variant="primary" icon-left="layout">Start from a template</MdsButton>
                        </Link>
                        <MdsButton
                            :variant="feature('form_templates') ? 'tertiary' : 'primary'"
                            icon-left="plus"
                            @click="openCreate"
                        >
                            Start from blank
                        </MdsButton>
                    </div>
                </template>
            </MdsEmptyState>
        </template>

        <!--
            `role="list"` is NOT redundant and must not be tidied away: `list-style: none` removes list
            semantics in WebKit, so without it VoiceOver announces six cards with no sense of how many
            there are. (`forms/Templates.vue` predates this and does not carry it — it should, and that is
            a JR4 clean-up rather than a silent inconsistency.)
        -->
        <ul v-else-if="view === 'grid'" class="forms__grid" role="list" aria-label="Forms">
            <li v-for="row in forms" :key="row.id" class="forms__cell" data-form-entry>
                <FormCard :row="row">
                    <template #actions>
                        <FormRowActions
                            :row="row"
                            :publishing-id="publishing.id"
                            @history="historyTarget = $event"
                            @template="openSaveAsTemplate"
                            @rename="openEdit"
                            @scope="scopeTarget = $event"
                            @publish="publish"
                            @archive="archiveTarget = $event"
                        />
                    </template>
                </FormCard>
            </li>
        </ul>

        <MdsDataTable v-else :columns="columns" :rows="tableRows" :loading="busy" caption="Forms" row-key="id">
            <!--
                J2b. Two changes, one line: the destination is the form's HUB rather than the builder, and
                the link is UNCONDITIONAL.

                It pointed at `/forms/{id}/builder` because that was the only per-form page that existed, and
                it was `v-if="row.can.edit"` because that route refuses everyone else — so a reader who could
                see the row but not edit it got inert text and no way into the form at all. `/forms/{id}` is
                gated `viewOverview`, which every role that can reach this list already satisfies, so the
                title is now a link for all of them. `FormListHubLinkTest` pins that pairing; if `viewAny`
                and `viewOverview` ever diverge, this needs a real per-row flag rather than the assumption.

                (Pulled forward from J2d's dead-end sweep, which owns the rest of the inbound links. Without
                it the hub would have shipped with no route to it from the forms list.)
            -->
            <template #cell-title="{ row }">
                <Link :href="`/forms/${row.id}`" class="forms__title-link">{{ row.title }}</Link>
            </template>
            <!-- `dot` (JR2): this is a scannable status COLUMN, which is the case the disc was added
                 for — the eye finds a row by its dot and reads the word to confirm. Inline badges
                 elsewhere in the app deliberately do not set it. -->
            <template #cell-status="{ value }">
                <MdsBadge v-bind="statusVariant(String(value))" dot />
            </template>
            <!-- The three columns the row was always missing (JR3), from the same grouped query the cards
                 read. `—` rather than `0 / null` for an uncapped form: a blank cell says "not applicable"
                 where a zero would say "none used yet". -->
            <template #cell-responses="{ row }">
                {{ row.stats.responses }}
            </template>
            <template #cell-capacity="{ row }">
                {{ capacityLabel(row) }}
            </template>
            <template #cell-last_response="{ row }">
                {{ formatDate(row.stats.last_response_at) }}
            </template>
            <template #cell-version="{ row }">
                {{ versionLabel(row) }}
            </template>
            <template #cell-updated_at="{ row }">
                {{ formatDate(row.updated_at) }}
            </template>
            <template #row-actions="{ row }">
                <FormRowActions
                    :row="row"
                    :publishing-id="publishing.id"
                    @history="historyTarget = $event"
                    @template="openSaveAsTemplate"
                    @rename="openEdit"
                    @scope="scopeTarget = $event"
                    @publish="publish"
                    @archive="archiveTarget = $event"
                />
            </template>
            <template #empty>
                <!-- Unreachable in practice — the page renders the shared empty state above and never
                     mounts the table with zero rows — but `MdsDataTable` has no `emptyText` prop and its
                     default is a bare colspan cell, so this keeps the two in agreement if a future edit
                     removes the `v-if`. -->
                <MdsEmptyState headline="No matching forms" />
            </template>
        </MdsDataTable>


        <!-- Create -->
        <MdsModal v-model:open="createOpen" title="New form">
            <form class="forms__form" @submit.prevent="submitCreate">
                <MdsFormField label="Title" required :error="createForm.errors.title" v-slot="{ id, describedby, invalid }">
                    <MdsTextInput
                        :id="id"
                        v-model="createForm.title"
                        :describedby="describedby"
                        :invalid="invalid"
                        placeholder="Household survey"
                    />
                </MdsFormField>
                <MdsFormField label="Description" :error="createForm.errors.description" v-slot="{ id, describedby, invalid }">
                    <MdsTextarea
                        :id="id"
                        v-model="createForm.description"
                        :describedby="describedby"
                        :invalid="invalid"
                        placeholder="What is this form for?"
                    />
                </MdsFormField>
            </form>
            <template #actions>
                <MdsButton variant="tertiary" @click="createOpen = false">Cancel</MdsButton>
                <MdsButton variant="primary" icon-left="plus" :loading="createForm.processing" @click="submitCreate">
                    Create form
                </MdsButton>
            </template>
        </MdsModal>

        <!-- Rename -->
        <MdsModal :open="editTarget !== null" title="Rename form" @close="editTarget = null">
            <form class="forms__form" @submit.prevent="submitEdit">
                <MdsFormField label="Title" required :error="editForm.errors.title" v-slot="{ id, describedby, invalid }">
                    <MdsTextInput :id="id" v-model="editForm.title" :describedby="describedby" :invalid="invalid" />
                </MdsFormField>
                <MdsFormField label="Description" :error="editForm.errors.description" v-slot="{ id, describedby, invalid }">
                    <MdsTextarea :id="id" v-model="editForm.description" :describedby="describedby" :invalid="invalid" />
                </MdsFormField>
            </form>
            <template #actions>
                <MdsButton variant="tertiary" @click="editTarget = null">Cancel</MdsButton>
                <MdsButton variant="primary" icon-left="check" :loading="editForm.processing" @click="submitEdit">
                    Save changes
                </MdsButton>
            </template>
        </MdsModal>

        <!-- Archive -->
        <MdsModal :open="archiveTarget !== null" title="Archive form" @close="archiveTarget = null">
            <p class="forms__prose">
                Archive <strong>{{ archiveTarget?.title }}</strong>? Its current draft is discarded. Every
                published version and the responses collected against them are kept.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="archiveTarget = null">Cancel</MdsButton>
                <MdsButton variant="destructive" icon-left="trash" :loading="archiving.busy" @click="submitArchive">
                    Archive form
                </MdsButton>
            </template>
        </MdsModal>

        <!-- Version history -->
        <MdsModal :open="historyTarget !== null" title="Version history" @close="historyTarget = null">
            <ul v-if="historyTarget" class="forms__versions">
                <li v-for="v in historyTarget.versions" :key="v.id" class="forms__version">
                    <span class="forms__version-label">
                        <strong>v{{ v.version_number }}</strong>
                        <MdsBadge v-bind="statusVariant(v.status)" dot />
                    </span>
                    <span class="forms__version-actions">
                        <!--
                            Print blank (I12) — a published version typeset for a pen. Conditioned on
                            `status !== 'draft'` to match the route, which 404s a draft: a draft's
                            schema_snapshot is literally [] and ADR-0013 makes only a published
                            version's content immutable.

                            The `can.edit` conjunct looks too strict for a read and is not: the route
                            gate is `can:view,form`, and FormPolicy::view() DELEGATES TO canEdit() —
                            the same predicate `can.edit` is built from (`$user->can('update', …)`).
                            The two sets are identical today, so gating on the flag the page actually
                            has is what keeps the button from ever appearing where the route would
                            403. If FormPolicy::view() is ever narrowed away from update(), this
                            needs a real `can.view` flag from FormPresenter rather than this proxy.
                        -->
                        <MdsButton
                            v-if="historyTarget.can.edit && v.status !== 'draft'"
                            variant="tertiary"
                            size="sm"
                            icon-left="download"
                            @click="printBlank(historyTarget, v)"
                        >
                            Print blank
                        </MdsButton>
                        <MdsButton
                            v-if="historyTarget.can.edit && v.status !== 'draft'"
                            variant="tertiary"
                            size="sm"
                            icon-left="clock"
                            @click="restoreTarget = { form: historyTarget, version: v }"
                        >
                            Restore
                        </MdsButton>
                    </span>
                </li>
            </ul>
            <template #actions>
                <MdsButton variant="tertiary" @click="historyTarget = null">Close</MdsButton>
            </template>
        </MdsModal>

        <!-- Restore confirm -->
        <MdsModal :open="restoreTarget !== null" title="Restore version" @close="restoreTarget = null">
            <p class="forms__prose">
                Restore <strong>v{{ restoreTarget?.version.version_number }}</strong> into the current draft?
                This overwrites the draft's current content. Published versions are untouched — review the
                restored draft, then publish it as a new version.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="restoreTarget = null">Cancel</MdsButton>
                <MdsButton variant="primary" icon-left="clock" :loading="restoring.busy" @click="submitRestore">
                    Restore into draft
                </MdsButton>
            </template>
        </MdsModal>

        <!-- Save as template (G9a) -->
        <AssignScopeModal
            v-if="scopeTarget"
            :open="true"
            :form-id="scopeTarget.id"
            :current-node-id="scopeTarget.scope_node_id"
            :scopes="scopes"
            @update:open="scopeTarget = null"
        />

        <SaveAsTemplateModal
            v-if="templateTarget"
            v-model:open="templateOpen"
            :form-id="templateTarget.id"
            :default-name="templateTarget.title"
        />
    </div>
</template>

<style scoped>
/*
    ⚠️ `:deep()` IS LOAD-BEARING HERE AND MUST NOT BE "TIDIED" BACK TO A PLAIN CLASS. The title link is
    rendered by THIS file in the table view and by `FormCard.vue` in the card view, and a scoped rule
    only reaches the second through `:deep`. Without it the class still lands (so the two Vitest cases
    asserting `.forms__title-link` keep passing) and every card title silently renders as body text —
    exactly the kind of change no gate in this repo can see.
*/
:deep(.forms__title-link) {
    color: var(--mds-color-action-primary-fg);
    font-weight: var(--mds-font-weight-medium);
    text-decoration: none;
}

:deep(.forms__title-link:hover) {
    text-decoration: underline;
}

:deep(.forms__title-link:focus-visible) {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

/*
    ⚠️ THE `min(100%, …)` INSIDE `minmax()` IS LOAD-BEARING, AND CI CANNOT SEE IT GO WRONG. A bare
    `minmax(300px, 1fr)` sets a track floor wider than the container at 375px (or at any width once
    `[data-font-size="extra_large"]` scales the content ~25%), and the grid overruns. `.app-shell` is
    `overflow-x: clip`, so the overrun is CLIPPED rather than scrolled — and the e2e assertion measures
    `documentElement.scrollWidth`, which the clip keeps at zero. The failure is structurally invisible to
    every gate; the guard is the only thing preventing it. (`forms/Templates.vue:108` is the one grid in
    the app without it.)

    `auto-fill`, not `auto-fit`: with `auto-fit` the empty tracks collapse, so a tenant with ONE form
    gets a single card stretched across 1200px. `auto-fill` keeps it card-sized, which is also what the
    approved direction draws.
*/
.forms__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(min(100%, 300px), 1fr));
    gap: var(--mds-space-4);
    margin: 0;
    padding: 0;
    list-style: none;
}

.forms__cell {
    display: flex;
}

/*
    ⚠️ THE ACTION CLUSTER WRAPS IN A CARD AND MUST NOT IN A ROW, and the running app is what showed why.
    `FormRowActions` sets `flex-wrap: wrap` because nine 28px buttons need ~284px against a card's ~260px
    of content. The table inherited that, and with the three columns JR3 adds the actions cell is narrow
    enough that EVERY row wrapped to two lines — ragged, because rows carry different numbers of actions,
    and ~100px tall. Scanning sixty forms quickly is the only reason to choose this view, and two-line
    rows are precisely what defeats it.

    So the table gets one line and, when that does not fit, `MdsDataTable`'s own horizontal scroll — a
    designed, focusable (`tabindex="0"`, `role="group"`) region rather than a defect.

    ⚠️ THE COST IS REAL AND MEASURED, SO IT IS STATED RATHER THAN DISCOVERED LATER: with seven columns
    and nine actions the table needs ~1136px, so it scrolls sideways at **1280px and below** where the
    four-column version did not, and stops at 1440px (uniform 81px rows, no scroll). Nothing cheap buys
    that back — dropping `Version` saves ~90px against the ~160px needed at 1280, and dropping `Updated`
    as well would take the list's own sort key. It is also exactly why the CARD grid is the default and
    why this row exists: the grid reflows and cannot scroll at any width, and a tenant who deliberately
    chooses a dense table at 1024px is choosing a table.
*/
:deep(.mds-table .form-actions) {
    flex-wrap: nowrap;
}

.forms__facets {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    margin-bottom: var(--mds-space-5);
}

/*
    Hand-rolled rather than reached for: there is no chip/toggle primitive in the design system, and the
    three existing hand-rolled menus are already DSR §1.3's evidence that one is missing. Registered as a
    J4 candidate rather than made into a fourth silent duplicate.
*/
.forms__facet {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
    padding: var(--mds-space-1) var(--mds-space-3);
    min-height: 32px;
    border: 1px solid var(--mds-color-border-strong);
    border-radius: var(--mds-radius-full);
    background-color: var(--mds-color-bg-surface);
    color: var(--mds-color-text-secondary);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-medium);
    cursor: pointer;
    transition:
        background-color var(--mds-duration-fast) var(--mds-ease-standard),
        border-color var(--mds-duration-fast) var(--mds-ease-standard);
}

.forms__facet b {
    font-variant-numeric: tabular-nums;
    color: var(--mds-color-text-body);
    font-weight: var(--mds-font-weight-semibold);
}

.forms__facet:hover {
    border-color: var(--mds-color-action-primary-fg);
    color: var(--mds-color-text-body);
}

.forms__facet:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

/* Selected state is fill + weight, never colour alone (WCAG 1.4.1) — and `aria-pressed` carries it to
   anyone not looking at the fill at all. */
.forms__facet--on,
.forms__facet--on:hover {
    background-color: var(--mds-color-action-primary-bg);
    border-color: var(--mds-color-action-primary-bg);
    color: var(--mds-color-text-on-primary);
    font-weight: var(--mds-font-weight-semibold);
}

.forms__facet--on b {
    color: var(--mds-color-text-on-primary);
}

.forms__empty-actions {
    display: inline-flex;
    gap: var(--mds-space-3);
    flex-wrap: wrap;
    justify-content: center;
}

.forms__form {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-5);
}

.forms__prose {
    margin: 0;
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
}

.forms__versions {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin: 0;
    padding: 0;
    list-style: none;
}

.forms__version {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-4);
    padding: var(--mds-space-2) 0;
    border-bottom: 1px solid var(--mds-color-border-default);
}

.forms__version-label {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-2);
    color: var(--mds-color-text-body);
}

/* Two actions per row since I12, so they need their own flex context — the row's own
   `justify-content: space-between` would otherwise push them to opposite ends of the modal. */
.forms__version-actions {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
}
</style>
