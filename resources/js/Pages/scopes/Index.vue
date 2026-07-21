<script setup lang="ts">
/**
 * The scoping hierarchy (Increment G10b2) — the UI over the G10b1 backend, and the surface that finally
 * makes the Reviewer role assignable by a human.
 *
 * TRANSPORT. Every mutation is an Inertia visit; the only fetches are the two read-only sidecars (a node's
 * blast-radius preview and its grant list). That is forced, not stylistic: bootstrap/app.php keys its
 * API/web exception split on the `api/v1/*` PATH and Laravel runs custom render callbacks before the
 * JSON-negotiation check, so a refused move on a web route comes back as a 302 carrying a flash toast — even
 * to an XHR. A fetch client would follow it, receive HTML with a 200, and throw parsing it.
 *
 * Two consequences worth stating, both easy to get wrong:
 *   - A REFUSAL ARRIVES THROUGH onSuccess, not onError. `back()->with('toast')` is a 2xx page response, so
 *     onError never fires on a cycle or depth-cap refusal. Handlers re-read the refreshed props.
 *   - SELECTION IS LOCAL STATE and never touches the router. router.get defaults preserveState to FALSE, so
 *     a `/scopes?node=…` visit would reset the layout props, re-key the page component and remount it —
 *     collapsing the tree and dropping focus to <body> on the page's most common interaction. (Mutations are
 *     safe by default: post/patch/delete already spread preserveState:true.)
 */
import { computed, ref, watch } from 'vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import {
    MdsBadge,
    MdsButton,
    MdsEmptyState,
    MdsFormField,
    MdsIcon,
    MdsModal,
    MdsTextInput,
} from '@meridian/design-system';
import PageHeader from '@/components/shell/PageHeader.vue';
import ScopeTree from '@/components/scopes/ScopeTree.vue';
import GrantModal from '@/components/scopes/GrantModal.vue';
import DeleteNodeModal from '@/components/scopes/DeleteNodeModal.vue';
import { useScopeTreeMove } from '@/components/scopes/useScopeTreeMove';
import { fetchNodeGrants } from '@/components/scopes/scopesClient';
import type { CapacityOption, NodeGrant, Recipient, ScopeNodeRow } from '@/components/scopes/types';

const props = defineProps<{
    nodes: ScopeNodeRow[];
    truncated: boolean;
    recipients: Recipient[];
    capacities: CapacityOption[];
    can: { create: boolean; grant: boolean };
}>();

const treeRef = ref<InstanceType<typeof ScopeTree> | null>(null);
const selectedId = ref<string | null>(null);
const grants = ref<NodeGrant[]>([]);
const grantsLoading = ref(false);

const selected = computed(() => props.nodes.find((n) => n.id === selectedId.value) ?? null);

/** Breadcrumb trail for the detail panel heading — the tree is flat, so ancestry is a parent_id walk. */
const selectedTrail = computed<string[]>(() => {
    const byId = new Map(props.nodes.map((n) => [n.id, n]));
    const trail: string[] = [];
    let node = selected.value;
    while (node) {
        trail.unshift(node.name);
        node = node.parent_id ? (byId.get(node.parent_id) ?? null) : null;
    }
    return trail;
});

// A monotonic guard: rapid selection changes can resolve out of order and paint one node's grants under
// another's heading.
let grantsSeq = 0;

async function select(id: string): Promise<void> {
    selectedId.value = id;
    const seq = ++grantsSeq;
    grantsLoading.value = true;
    const loaded = await fetchNodeGrants(id);
    if (seq !== grantsSeq) return;
    grants.value = loaded ?? [];
    grantsLoading.value = false;
}

/** Re-read the selected node's grants after a mutation that could have changed them. */
function refreshGrants(): void {
    if (selectedId.value) void select(selectedId.value);
}

function visit(method: 'post' | 'patch' | 'delete', url: string, data: Record<string, unknown> = {}, after?: () => void): void {
    treeRef.value?.rememberFocusAnchor();
    // preserveState/preserveScroll are the defaults for these verbs, but stated explicitly because the whole
    // page's focus and expansion state depends on them and a minor bump must not silently change it.
    router[method](url, data as never, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => after?.(),
    });
}

// ── Create ──────────────────────────────────────────────────────────────
const createOpen = ref(false);
const createForm = useForm({ name: '', code: '', node_type: '', parent_id: null as string | null });

function openCreate(under: string | null): void {
    createForm.reset();
    createForm.clearErrors();
    createForm.parent_id = under;
    createOpen.value = true;
}

function submitCreate(): void {
    treeRef.value?.rememberFocusAnchor();
    createForm.post('/scopes', {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            if (createForm.parent_id) treeRef.value?.expand(createForm.parent_id);
            createOpen.value = false;
        },
    });
}

// ── Rename ──────────────────────────────────────────────────────────────
const renameOpen = ref(false);
const renameForm = useForm({ name: '', code: '', node_type: '' });

function openRename(): void {
    if (!selected.value) return;
    renameForm.clearErrors();
    renameForm.name = selected.value.name;
    renameForm.code = selected.value.code ?? '';
    renameForm.node_type = selected.value.node_type ?? '';
    renameOpen.value = true;
}

function submitRename(): void {
    if (!selected.value) return;
    treeRef.value?.rememberFocusAnchor();
    renameForm.patch(`/scopes/${selected.value.id}`, {
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => { renameOpen.value = false; },
    });
}

function toggleActive(): void {
    if (!selected.value) return;
    visit('patch', `/scopes/${selected.value.id}`, { is_active: !selected.value.is_active });
}

// ── Delete ──────────────────────────────────────────────────────────────
const deleteOpen = ref(false);

function submitDelete(): void {
    if (!selected.value) return;
    const id = selected.value.id;
    visit('delete', `/scopes/${id}`, {}, () => {
        deleteOpen.value = false;
        selectedId.value = null;
        grants.value = [];
    });
}

// ── Grant ───────────────────────────────────────────────────────────────
const grantOpen = ref(false);

function revoke(grant: NodeGrant): void {
    visit('delete', `/resource-grants/${grant.id}`, {}, refreshGrants);
}

// ── Move ────────────────────────────────────────────────────────────────
const move = useScopeTreeMove({
    rows: () => props.nodes,
    onCommit: (nodeId, parentId) => {
        treeRef.value?.rememberFocusAnchor();
        router.post(`/scopes/${nodeId}/move`, { parent_id: parentId }, {
            preserveScroll: true,
            preserveState: true,
            // The backstop half of the settle (see the watcher below). Needed because a move that changes
            // nothing — re-rooting an already-rooted node, or a refusal — comes back with props identical to
            // what is already rendered, so the reference watcher never fires. Without this the announcement
            // would go silent AND the tree would stay stuck in grab mode. settleIfPending is idempotent, so
            // whichever signal arrives first wins.
            onFinish: () => move.settleIfPending(props.nodes),
        });
    },
});

// The move's outcome is announced from the REFRESHED rows, not from the visit's success callback. A cycle
// or depth-cap refusal arrives as `back()->with('toast')` — a redirect to a 2xx page — so onError never
// fires and a success callback cannot tell you whether the move actually happened. Reading it off the props
// answers that question directly.
watch(() => props.nodes, (rows) => move.settleIfPending(rows));

function startKeyboardMove(): void {
    if (!selected.value) return;
    move.grabViaKeyboard(selected.value.id);
    treeRef.value?.focusRow(selected.value.id);
}
</script>

<template>
    <div>
        <Head title="Scopes" />

        <PageHeader title="Scopes" icon="building">
            <template #actions>
                <MdsButton v-if="can.create" variant="primary" icon-left="plus" @click="openCreate(null)">
                    New top-level scope
                </MdsButton>
            </template>
        </PageHeader>

        <p v-if="truncated" class="scopes__notice" role="status">
            This hierarchy is larger than the tree view can render. Showing the first {{ nodes.length }} scopes.
        </p>

        <div class="scopes__layout">
            <!--
                A role-less wrapper. NOTHING with a role, a global aria-* attribute or focusability may be a
                direct child of the tree element: axe treats such a child as an OWNED child of role="tree",
                and anything outside [group, treeitem] fails aria-required-children — critical and wcag2a.
                That is why the announcer and the empty state live out here rather than inside the <ul>.
            -->
            <section class="scopes__pane" aria-label="Hierarchy">
                <MdsEmptyState
                    v-if="!nodes.length"
                    headline="No scopes yet"
                    description="Scopes are your own hierarchy — regions, offices, teams. Assign forms to them, then grant a teammate access to a whole branch at once."
                >
                    <template #action>
                        <MdsButton v-if="can.create" variant="primary" icon-left="plus" @click="openCreate(null)">
                            Add your first scope
                        </MdsButton>
                    </template>
                </MdsEmptyState>

                <ScopeTree
                    v-else
                    ref="treeRef"
                    :nodes="nodes"
                    :selected-id="selectedId"
                    :drop-target-id="move.dropTargetId.value"
                    :grabbed-id="move.grabbedId.value"
                    @select="select"
                    @grip-pointer-down="move.onGripPointerDown"
                    @grip-keydown="move.onGripKeydown"
                />

                <div class="scopes__sr" role="status" aria-live="assertive">{{ move.announcement.value }}</div>
            </section>

            <section class="scopes__detail" aria-label="Scope details">
                <p v-if="!selected" class="scopes__hint" role="status">
                    Select a scope to see the forms and people it reaches.
                </p>

                <template v-else>
                    <p class="scopes__trail">{{ selectedTrail.join(' / ') }}</p>
                    <h2 class="scopes__title">
                        {{ selected.name }}
                        <MdsBadge v-if="!selected.is_active" variant="neutral" label="Inactive" />
                    </h2>
                    <p class="scopes__meta">
                        {{ selected.form_count }} forms assigned directly<span v-if="selected.code"> · {{ selected.code }}</span>
                    </p>

                    <div class="scopes__actions">
                        <MdsButton v-if="can.create" variant="secondary" icon-left="plus" @click="openCreate(selected.id)">
                            Add child
                        </MdsButton>
                        <MdsButton v-if="selected.can.update" variant="secondary" icon-left="edit" @click="openRename">
                            Rename
                        </MdsButton>
                        <MdsButton v-if="selected.can.update" variant="secondary" icon-left="grip" @click="startKeyboardMove">
                            Move…
                        </MdsButton>
                        <MdsButton v-if="selected.can.update" variant="secondary" @click="toggleActive">
                            {{ selected.is_active ? 'Deactivate' : 'Reactivate' }}
                        </MdsButton>
                        <MdsButton v-if="selected.can.delete" variant="destructive" icon-left="trash" @click="deleteOpen = true">
                            Delete
                        </MdsButton>
                    </div>

                    <div v-if="can.grant" class="scopes__grants">
                        <div class="scopes__grants-head">
                            <h3 class="scopes__subtitle">Who can reach this</h3>
                            <MdsButton variant="primary" icon-left="user-plus" @click="grantOpen = true">
                                Grant access
                            </MdsButton>
                        </div>

                        <p v-if="grantsLoading" class="scopes__hint" role="status">Loading access…</p>
                        <p v-else-if="!grants.length" class="scopes__hint" role="status">
                            Nobody holds access through this scope yet.
                        </p>
                        <ul v-else class="scopes__grant-list">
                            <li v-for="grant in grants" :key="grant.id" class="scopes__grant">
                                <span class="scopes__grant-who">
                                    <MdsIcon name="user" size="sm" />
                                    {{ grant.user_name }}
                                </span>
                                <MdsBadge :variant="grant.capacity === 'editor' ? 'info' : 'neutral'" :label="grant.capacity" />
                                <span v-if="grant.includes_descendants" class="scopes__count">and everything below</span>
                                <span v-if="grant.granted_by_name" class="scopes__count">granted by {{ grant.granted_by_name }}</span>
                                <MdsButton variant="tertiary" @click="revoke(grant)">Revoke</MdsButton>
                            </li>
                        </ul>
                    </div>
                </template>
            </section>
        </div>

        <MdsModal v-model:open="createOpen" title="New scope">
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Name" required :error="createForm.errors.name">
                <MdsTextInput :id="id" v-model="createForm.name" :describedby="describedby" :invalid="invalid" placeholder="National Capital Region" />
            </MdsFormField>
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Code" help="Your own reference code — the platform never interprets it." :error="createForm.errors.code">
                <MdsTextInput :id="id" v-model="createForm.code" :describedby="describedby" :invalid="invalid" placeholder="NCR" />
            </MdsFormField>
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Type" help="A label of your choosing — region, office, team." :error="createForm.errors.node_type">
                <MdsTextInput :id="id" v-model="createForm.node_type" :describedby="describedby" :invalid="invalid" placeholder="region" />
            </MdsFormField>
            <template #actions>
                <MdsButton variant="tertiary" @click="createOpen = false">Cancel</MdsButton>
                <MdsButton variant="primary" icon-left="plus" :loading="createForm.processing" @click="submitCreate">
                    Add scope
                </MdsButton>
            </template>
        </MdsModal>

        <MdsModal v-model:open="renameOpen" title="Rename scope">
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Name" required :error="renameForm.errors.name">
                <MdsTextInput :id="id" v-model="renameForm.name" :describedby="describedby" :invalid="invalid" />
            </MdsFormField>
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Code" :error="renameForm.errors.code">
                <MdsTextInput :id="id" v-model="renameForm.code" :describedby="describedby" :invalid="invalid" />
            </MdsFormField>
            <MdsFormField v-slot="{ id, describedby, invalid }" label="Type" :error="renameForm.errors.node_type">
                <MdsTextInput :id="id" v-model="renameForm.node_type" :describedby="describedby" :invalid="invalid" />
            </MdsFormField>
            <template #actions>
                <MdsButton variant="tertiary" @click="renameOpen = false">Cancel</MdsButton>
                <MdsButton variant="primary" :loading="renameForm.processing" @click="submitRename">Save</MdsButton>
            </template>
        </MdsModal>

        <DeleteNodeModal
            v-if="selected"
            v-model:open="deleteOpen"
            :node="selected"
            @confirm="submitDelete"
        />

        <GrantModal
            v-if="selected && can.grant"
            v-model:open="grantOpen"
            :node="selected"
            :nodes="nodes"
            :recipients="recipients"
            :capacities="capacities"
            :existing="grants"
            @granted="refreshGrants"
        />

        <MdsModal v-if="move.confirming.value" :open="true" title="Move scope" @close="move.cancel()">
            <p>
                Move <strong>{{ move.movingName.value }}</strong>
                {{ move.targetName.value ? `under ${move.targetName.value}` : 'to the top level' }}?
            </p>
            <p class="scopes__hint">
                Everything beneath it moves too. Access granted on a branch follows the branch, so this can
                change who reaches the forms below.
            </p>
            <template #actions>
                <MdsButton variant="tertiary" @click="move.cancel()">Cancel</MdsButton>
                <MdsButton variant="primary" @click="move.confirm()">Move scope</MdsButton>
            </template>
        </MdsModal>
    </div>
</template>

<style scoped>
.scopes__layout {
    display: grid;
    grid-template-columns: minmax(0, 1fr);
    gap: var(--mds-space-6);
}

@media (min-width: 900px) {
    .scopes__layout {
        grid-template-columns: minmax(0, 1fr) minmax(0, 1.2fr);
    }
}

.scopes__pane {
    /* The tree scrolls itself rather than widening the document: /scopes is in the responsive-axe sweep,
       whose assertClean fails the page on any horizontal overflow at 375px. */
    min-width: 0;
    overflow-x: auto;
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.scopes__detail {
    min-width: 0;
}

.scopes__notice {
    margin-block-end: var(--mds-space-4);
    color: var(--mds-color-text-secondary);
}

.scopes__trail {
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

.scopes__title {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    margin: var(--mds-space-1) 0;
    font-size: var(--mds-type-heading-4-font-size);
    line-height: var(--mds-type-heading-4-line-height);
    color: var(--mds-color-text-heading);
}

.scopes__subtitle {
    margin: 0;
    font-size: var(--mds-type-label-font-size);
    color: var(--mds-color-text-heading);
}

.scopes__meta,
.scopes__hint {
    color: var(--mds-color-text-secondary);
}

.scopes__actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    margin-block: var(--mds-space-4);
}

.scopes__grants {
    padding-block-start: var(--mds-space-4);
    border-block-start: 1px solid var(--mds-color-border-default);
}

.scopes__grants-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: var(--mds-space-2);
    margin-block-end: var(--mds-space-3);
}

.scopes__grant-list {
    list-style: none;
    margin: 0;
    padding: 0;
}

.scopes__grant {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: var(--mds-space-2);
    padding-block: var(--mds-space-2);
    border-block-start: 1px solid var(--mds-color-border-default);
}

.scopes__grant-who {
    display: inline-flex;
    align-items: center;
    gap: var(--mds-space-1);
    min-width: 0;
}

.scopes__count {
    font-size: var(--mds-type-caption-font-size);
    color: var(--mds-color-text-secondary);
}

.scopes__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}
</style>
