<script setup lang="ts">
/**
 * The form hub — `GET /forms/{form}` (Increment J2b, PRD §3.7 "a form is a hub … rather than a row that
 * fans out into unlinked screens").
 *
 * Until this page existed a GET on `/forms/{form}` matched the URI but not the method and answered **405**,
 * so nothing in the product could link to "a form": the audit ledger sent form targets to `/forms`, global
 * search sent them to the builder (a 403 for two of the five roles), and a submission printed its form's
 * title as an unlinked heading. This is the destination all of those get in J2d.
 *
 * ── READ BY ALL FIVE ROLES, WHICH IS WHY EVERY AFFORDANCE IS GATED SEPARATELY ──────────────────────────
 * The route is `can:viewOverview,form`, NOT `can:view,form` — see `FormPolicy::viewOverview()`. A Reviewer
 * and a Viewer reach this page and can do almost nothing on it, so each button reads the specific `can` flag
 * of the route behind it rather than a general "may edit". A refused affordance is ABSENT, never disabled:
 * ADR-0011 §D9's absent-not-locked doctrine, and the same rule the tab strip follows server-side.
 *
 * ── EVERY PROP HAS A READER, AND THAT IS A DELIBERATE COMPLETENESS RULE ────────────────────────────────
 * `form`, `stats`, `schedule`, `versions`, `recent`, `tabs`, `can` and the conditional `share` are all
 * rendered somewhere below, and `show.test.ts` asserts it. A payload key nothing reads is the write-only
 * smell this codebase already carries against `feedback_reports` — the presenter and the page drift apart
 * quietly, and the next author cannot tell whether a key is load-bearing or dead.
 *
 * ── The strip and the crumbs are Inertia visits, not document loads ────────────────────────────────────
 * `MdsTabNav` and `MdsBreadcrumb` render plain anchors by default because the design-system package imports
 * no router at all. Passing `Link` is what makes a tab switch a client visit instead of tearing down the
 * persistent AppLayout; see those components' docblocks for why the default must stay an anchor.
 */
import { computed, reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsBreadcrumb,
    MdsButton,
    MdsDataTable,
    MdsEmptyState,
    MdsModal,
    MdsStatTile,
    MdsTabNav,
    statusVariant,
    type BreadcrumbItem,
    type DataTableColumn,
    type TabNavItem,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import ShareModal from '@/components/forms/ShareModal.vue';
import type { ShareProps } from '@/components/forms/types';

/**
 * ⚠️ `type`, NOT `interface`, and it is load-bearing rather than stylistic — `forms/Index.vue` declares its
 * row shapes the same way for the same reason. `MdsDataTable` is generic over `Row extends Record<string,
 * unknown>`, and TypeScript gives an implicit index signature to a type ALIAS but never to an interface. As
 * an interface this fails `vue-tsc` with "index signature is missing", and every `#cell-*` slot then binds
 * `row` as `Record<string, unknown>` instead of the real shape, so the errors arrive nowhere near the cause.
 */
type VersionRow = {
    id: string;
    version_number: number;
    status: string;
    published_at: string | null;
};

type RecentRow = {
    id: string;
    status: string;
    source_label: string;
    submitted_at: string | null;
};

const props = defineProps<{
    form: {
        id: string;
        title: string;
        description: string | null;
        status: string;
        scope_node_name: string | null;
        current_version: number | null;
        draft_version: number | null;
        updated_at: string | null;
    };
    stats: { responses: number; drafts: number; last_response_at: string | null };
    schedule: {
        opens_at: string | null;
        closes_at: string | null;
        timezone: string;
        max_responses: number | null;
        acceptance: 'open' | 'opens_soon' | 'closed' | 'capacity_reached';
        remaining: number | null;
    };
    versions: VersionRow[];
    recent: RecentRow[];
    tabs: TabNavItem[];
    /**
     * The breadcrumb trail, resolved SERVER-SIDE (Increment J2d).
     *
     * Every crumb's href is decided by `CrumbTrail` against the gate its own route carries, so this page
     * renders the trail and synthesises nothing. The previous client `computed` hard-coded `/forms/{id}`
     * on five pages, where no Pest test can reach it — which is how J2c shipped a `/forms` crumb that 403s
     * for a Reviewer and a Viewer. Do not reintroduce a locally-built crumb here.
     */
    crumbs: BreadcrumbItem[];
    can: { edit: boolean; publish: boolean; encode: boolean; template: boolean };
    /**
     * ABSENT, not empty, for a reader without `update` — the presenter omits the key entirely because it
     * carries settings (guest access, the bot challenge, the rate limit) rather than facts. The Share
     * affordance therefore keys off PRESENCE; an `undefined` check here and a `null` payload there is the
     * difference between "you may not change this" and "there is nothing to change".
     */
    share?: ShareProps;
}>();

// ── Formatting ───────────────────────────────────────────────────────────────────────────────────────────
// Hand-rolled, like every other page in `resources/js`: there is no shared date helper in this codebase and
// J2b is not the increment that invents one for a single new surface.
function formatDate(iso: string | null): string {
    if (!iso) return '—';

    return new Date(iso).toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
}

function formatDateTime(iso: string | null): string {
    if (!iso) return '—';

    return new Date(iso).toLocaleString(undefined, {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

/**
 * Rendered in the FORM's timezone, not the reader's, and the zone is named in the output. A schedule window
 * is authored against a place — "closes 5pm" means 5pm where the fieldwork is — so silently rebasing it onto
 * the reader's clock would make an administrator in another zone read a different deadline from the one the
 * author set. `submissions/Encode.vue` does exactly this for the same block.
 */
function formatInstant(iso: string): string {
    try {
        const formatted = new Intl.DateTimeFormat('en', {
            timeZone: props.schedule.timezone,
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(iso));

        return `${formatted} (${props.schedule.timezone})`;
    } catch {
        return iso;
    }
}

/** Ported verbatim from `forms/Index.vue` — the presenter emits the same two fields for the same reason. */
const versionLabel = computed(() => {
    if (props.form.current_version !== null) return `v${props.form.current_version} live`;
    if (props.form.draft_version !== null) return `v${props.form.draft_version} draft`;

    return '—';
});

/**
 * The Responses destination, taken from the tab set rather than rebuilt here.
 *
 * Reading it back off the tab set rather than rebuilding it is what made J2c a ONE-LINE server change: the
 * strip pointed at the filtered global inbox until that increment built `/forms/{form}/submissions`, and no
 * client-side URL had to be hunted down when it moved. Keep it that way.
 *
 * `undefined` when the reader has no Responses tab, which is also the gate for the tile's link.
 */
const responsesHref = computed(() => props.tabs.find((t) => t.key === 'submissions')?.href);

/**
 * ⚠️ WHETHER A RECENT ROW MAY BE LINKED IS PROVABLE, NOT A PROXY, AND THE COMPOSITION IS THE PROOF.
 *
 * `/submissions/{id}` is gated `can:view,submission` — a PER-ROW policy — while the Responses tab is gated
 * `viewAny`. They compose exactly:
 *
 *   viewAny        = submissions.view
 *   view(row)      = submissions.view AND (orgWide OR collaboratesWith(row.form) OR isRespondent(row))
 *   scopeVisibleTo = that same disjunction, MINUS the permission key
 *
 * `recent` was already filtered through `scopeVisibleTo`, so the second conjunct holds for every row here by
 * construction; the tab supplies the first. Hence `viewAny ∧ (row ∈ visibleTo) ⟹ view(row)`, and the tab's
 * presence is a sound gate rather than a convenient stand-in for one.
 *
 * ⚠️ All five shipped roles hold `submissions.view`, so deleting this guard reddens nothing until a member
 * holds a grant WITHOUT the key. It is a fail-closed guard, not a live rule — `show.test.ts` pins it with a
 * synthetic payload, and it is labelled here rather than left to look like a rule someone can observe.
 */
const canOpenResponse = computed(() => responsesHref.value !== undefined);

// ── The schedule tile ────────────────────────────────────────────────────────────────────────────────────
// One vocabulary for "is this form taking responses", shared with the guest runtime and the manual-encode
// screen through `FormScheduleView` on the server. The hub renders that answer; it never recomputes it.
const ACCEPTANCE_LABEL: Record<string, string> = {
    open: 'Accepting',
    opens_soon: 'Not open yet',
    closed: 'Closed',
    capacity_reached: 'Full',
};

const acceptanceCaption = computed(() => {
    const s = props.schedule;

    switch (s.acceptance) {
        case 'opens_soon':
            return s.opens_at ? `Opens ${formatInstant(s.opens_at)}` : 'Opens once its window starts';
        case 'closed':
            return s.closes_at ? `Closed ${formatInstant(s.closes_at)}` : 'No longer accepting responses';
        case 'capacity_reached':
            return `Reached its limit of ${s.max_responses?.toLocaleString()} responses`;
        default:
            // An uncapped form pays for no capacity count at all server-side, so `remaining` is null and
            // there is genuinely nothing to say beyond the closing date.
            if (s.remaining !== null) return `${s.remaining.toLocaleString()} of ${s.max_responses?.toLocaleString()} remaining`;

            return s.closes_at ? `Closes ${formatInstant(s.closes_at)}` : 'No closing date';
    }
});

// ── Tables ───────────────────────────────────────────────────────────────────────────────────────────────
const recentColumns: DataTableColumn[] = [
    { key: 'submitted_at', header: 'Received' },
    { key: 'status', header: 'Status' },
    { key: 'source_label', header: 'Channel' },
];

const versionColumns: DataTableColumn[] = [
    { key: 'version_number', header: 'Version' },
    { key: 'status', header: 'Status' },
    { key: 'published_at', header: 'Published' },
];

// ── Actions ──────────────────────────────────────────────────────────────────────────────────────────────
const shareOpen = ref(false);
const publishing = ref(false);
const restoreTarget = ref<VersionRow | null>(null);
const restoring = reactive({ busy: false });

function publish(): void {
    publishing.value = true;
    router.post(
        `/forms/${props.form.id}/publish`,
        {},
        { preserveScroll: true, onFinish: () => (publishing.value = false) },
    );
}

function submitRestore(): void {
    if (!restoreTarget.value) return;

    restoring.busy = true;
    router.post(
        `/forms/${props.form.id}/versions/${restoreTarget.value.id}/restore`,
        {},
        {
            preserveScroll: true,
            // ⚠️ The modal closes on SUCCESS, never on finish, and `forms/Index.vue` splits the two the same
            // way. Restoring can fail — the publish path takes a row lock and a concurrent edit answers 409 —
            // and clearing the target in `onFinish` would dismiss the dialog on failure too, throwing away
            // the flash error along with the context that explains it. Only `busy` belongs in `onFinish`.
            onFinish: () => {
                restoring.busy = false;
            },
            onSuccess: () => {
                restoreTarget.value = null;
            },
        },
    );
}

/**
 * A file download, so a real navigation rather than an Inertia visit — `router.visit` would expect an
 * Inertia response and this route returns a document. Same shape the forms list uses for the same route.
 */
function printBlank(version: VersionRow): void {
    window.location.href = `/forms/${props.form.id}/versions/${version.id}/print`;
}

/** A published version only. The print route 404s a draft, whose `schema_snapshot` is literally empty. */
function isPrintable(version: VersionRow): boolean {
    return version.status !== 'draft';
}
</script>

<template>
    <div class="hub">
        <Head :title="form.title" />

        <PageHeader :title="form.title" icon="forms">
            <template #breadcrumbs>
                <MdsBreadcrumb :items="crumbs" :link-component="Link" />
            </template>
            <template #actions>
                <MdsButton
                    v-if="can.encode"
                    variant="primary"
                    icon-left="plus"
                    @click="router.visit(`/forms/${form.id}/submissions/create`)"
                >
                    New response
                </MdsButton>
                <!-- `form.draft_version` as well as the permission: publishing is what turns a draft into a
                     version, so the button is meaningless with no draft to publish and would 422. -->
                <MdsButton
                    v-if="can.publish && form.draft_version !== null"
                    variant="secondary"
                    icon-left="check"
                    :loading="publishing"
                    @click="publish"
                >
                    Publish draft
                </MdsButton>
                <MdsButton v-if="share" variant="secondary" icon-left="share" @click="shareOpen = true">
                    Share
                </MdsButton>
                <MdsButton
                    v-if="can.edit"
                    variant="tertiary"
                    icon-left="edit"
                    @click="router.visit(`/forms/${form.id}/builder`)"
                >
                    Edit form
                </MdsButton>
            </template>
        </PageHeader>

        <!--
            ⚠️ `:ariaLabel`, in camelCase, and it must not be "tidied" to `:aria-label`. Vue's runtime
            camelizes hyphenated prop names, but `vue-tsc` keeps `aria-label` as an HTML attribute because it
            is a real one — so the kebab spelling type-checks as an attr while the REQUIRED `ariaLabel` prop
            reads as missing, and the gate fails. The strip is named for the resource, not "Tabs", because
            axe's `landmark-unique` distinguishes navigation landmarks by their accessible name.
        -->
        <MdsTabNav :items="tabs" current="overview" :ariaLabel="form.title" :link-component="Link" />

        <div class="hub__identity">
            <p v-if="form.description" class="hub__description">{{ form.description }}</p>
            <dl class="hub__meta">
                <div class="hub__meta-item">
                    <dt>Status</dt>
                    <dd><MdsBadge v-bind="statusVariant(form.status)" /></dd>
                </div>
                <div class="hub__meta-item">
                    <dt>Version</dt>
                    <dd>{{ versionLabel }}</dd>
                </div>
                <!-- The node's NAME, never a picker: assigning a scope is `scopes.manage` and lives on the
                     forms list. A reader who cannot manage the hierarchy can still legitimately see where in
                     it this form sits — the audit ledger already prints it on every row. -->
                <div v-if="form.scope_node_name" class="hub__meta-item">
                    <dt>Scope</dt>
                    <dd>{{ form.scope_node_name }}</dd>
                </div>
                <div class="hub__meta-item">
                    <dt>Last edited</dt>
                    <dd>{{ formatDate(form.updated_at) }}</dd>
                </div>
            </dl>
        </div>

        <div class="hub__tiles">
            <MdsStatTile
                label="Responses"
                icon="submissions"
                :value="stats.responses.toLocaleString()"
                :href="responsesHref"
            />
            <MdsStatTile label="Drafts in progress" icon="edit" :value="stats.drafts.toLocaleString()" />
            <MdsStatTile
                label="Accepting responses"
                icon="calendar"
                :value="ACCEPTANCE_LABEL[schedule.acceptance]"
                :caption="acceptanceCaption"
            />
            <!--
                ⚠️ NO `href` HERE, DELIBERATELY. Linking this to `recent[0]` looks obvious and would be a
                quiet lie: `last_response_at` is `max(submitted_at)` while `recent` is ordered by id (uuidv7
                creation order), so a backdated `submitted_at` makes the two name DIFFERENT rows — a tile
                whose timestamp and destination disagree, with nothing on screen to reveal it.
            -->
            <MdsStatTile
                label="Last response"
                icon="clock"
                :value="stats.last_response_at ? formatDateTime(stats.last_response_at) : null"
            />
        </div>

        <!--
            ⚠️ BOTH PANEL HEADINGS ARE UNCONDITIONAL h2 ELEMENTS. `PageHeader` renders this page's h1 and
            `MdsEmptyState` renders an h3, so dropping either would fail axe `heading-order` — but ONLY when
            the panel is empty, which for a brand-new form is both of them at once. J1e's Style-B rule,
            applied to a page that is not a list.
        -->
        <section class="hub__panel">
            <h2 class="hub__panel-title">Latest responses</h2>
            <MdsDataTable
                :columns="recentColumns"
                :rows="recent"
                caption="Latest responses to this form"
                row-key="id"
            >
                <template #cell-submitted_at="{ row }">
                    <Link v-if="canOpenResponse" :href="`/submissions/${row.id}`" class="hub__row-link">
                        {{ formatDateTime(row.submitted_at) }}
                    </Link>
                    <span v-else>{{ formatDateTime(row.submitted_at) }}</span>
                </template>
                <template #cell-status="{ value }">
                    <MdsBadge v-bind="statusVariant(String(value))" />
                </template>
                <template #empty>
                    <MdsEmptyState
                        headline="No responses yet"
                        description="Responses appear here as the form is filled out. Share the link or add one by hand to get started."
                    />
                </template>
            </MdsDataTable>
            <p v-if="recent.length > 0 && responsesHref" class="hub__more">
                <Link :href="responsesHref">View all responses</Link>
            </p>
        </section>

        <section class="hub__panel">
            <h2 class="hub__panel-title">Versions</h2>
            <MdsDataTable :columns="versionColumns" :rows="versions" caption="This form's versions" row-key="id">
                <template #cell-version_number="{ value }">v{{ value }}</template>
                <template #cell-status="{ value }">
                    <MdsBadge v-bind="statusVariant(String(value))" />
                </template>
                <template #cell-published_at="{ row }">{{ formatDate(row.published_at) }}</template>
                <template #row-actions="{ row }">
                    <div class="hub__row-actions">
                        <!--
                            `can.template` is the presenter's `can:view,form` flag, which is this route's
                            actual gate. The forms list gates the same button on `can.edit` and says in its
                            own comment that it is a proxy standing in for a view flag it does not have;
                            this page has the real one, so it uses it.
                        -->
                        <MdsButton
                            v-if="can.template && isPrintable(row)"
                            variant="tertiary"
                            size="sm"
                            icon-left="download"
                            @click="printBlank(row)"
                        >
                            Print blank
                        </MdsButton>
                        <MdsButton
                            v-if="can.edit && isPrintable(row)"
                            variant="tertiary"
                            size="sm"
                            icon-left="clock"
                            @click="restoreTarget = row"
                        >
                            Restore
                        </MdsButton>
                    </div>
                </template>
                <template #empty>
                    <MdsEmptyState
                        headline="No versions yet"
                        description="A version is created the first time this form is published."
                    />
                </template>
            </MdsDataTable>
        </section>

        <MdsModal :open="restoreTarget !== null" title="Restore version" @close="restoreTarget = null">
            <p class="hub__prose">
                Restore <strong>v{{ restoreTarget?.version_number }}</strong> into the current draft? This
                overwrites the draft's current content. Published versions are untouched — review the
                restored draft, then publish it as a new version.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="restoreTarget = null">Cancel</MdsButton>
                <MdsButton variant="primary" icon-left="clock" :loading="restoring.busy" @click="submitRestore">
                    Restore into draft
                </MdsButton>
            </template>
        </MdsModal>

        <ShareModal
            v-if="share"
            v-model:open="shareOpen"
            :form-id="form.id"
            :form-title="form.title"
            :share="share"
        />
    </div>
</template>

<style scoped>
.hub__identity {
    margin-top: var(--mds-space-5);
}

.hub__description {
    margin: 0 0 var(--mds-space-4);
    max-width: 70ch;
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

.hub__meta {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-6);
    margin: 0;
}

.hub__meta-item dt {
    margin-bottom: var(--mds-space-1);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
    line-height: var(--mds-type-caption-line-height);
}

.hub__meta-item dd {
    margin: 0;
    color: var(--mds-color-text-heading);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.hub__tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: var(--mds-space-4);
    margin-top: var(--mds-space-6);
}

.hub__panel {
    margin-top: var(--mds-space-8);
}

.hub__panel-title {
    margin: 0 0 var(--mds-space-3);
    color: var(--mds-color-text-heading);
    font-size: var(--mds-type-heading-3-font-size);
    font-weight: var(--mds-type-heading-3-font-weight);
    line-height: var(--mds-type-heading-3-line-height);
}

.hub__row-actions {
    display: flex;
    gap: var(--mds-space-2);
    justify-content: flex-end;
}

.hub__row-link,
.hub__more a {
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
}

.hub__more {
    margin: var(--mds-space-3) 0 0;
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.hub__prose {
    margin: 0;
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
}

/* The tiles collapse to one column before the grid's own minmax would, so a 375px viewport never gets a
   two-up row of tiles whose values wrap mid-number. §6's tokenized band, no invented breakpoint. */
@media (max-width: 480px) {
    .hub__tiles {
        grid-template-columns: 1fr;
    }

    .hub__meta {
        gap: var(--mds-space-4);
    }
}
</style>
