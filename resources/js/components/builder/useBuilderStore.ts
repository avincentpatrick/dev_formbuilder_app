// The builder's state core (Increment D4a, locked decision). A client-side edit-history stack is the
// builder from the first line: every add / delete / duplicate / move / config-edit is a reversible command
// pushed onto an undo stack (redo is symmetric — its UI affordance may surface in D4b, but the model
// cannot be retrofitted, so it is built now). Fields/sections carry a stable client `uid` decoupled from
// the server `id`, so a delete→undo cycle (which mints a brand-new server row) still resolves in history.
//
// Persistence is optimistic + serialized: the local model updates immediately, and a single promise queue
// pushes each change to the server so calls never interleave. Content edits carry the row's `updated_at`
// token; a 409 opens the ConflictDialog with BOTH sides (the in-flight edit is never silently discarded).
// Structural edits (add/delete/move) are serialized server-side by the form-row lock and carry no token.

import { computed, reactive, ref, type Ref } from 'vue';
import { builderClient, BuilderRequestError, type BuilderResult } from './builderClient';
import type {
    BuilderPageProps,
    CanvasGroup,
    LibraryItem,
    LocalField,
    LocalSection,
    Selection,
    ServerField,
    SaveState,
    ServerSection,
    Uid,
} from './types';

let uidSeq = 0;
const nextUid = (): Uid => `u${++uidSeq}`;

// The editable content of a field (everything the config panel can change) — the unit of history + the
// optimistic-concurrency payload. Excludes id/uid/version/sequence/section (structural, moved separately).
type FieldSnapshot = Omit<LocalField, 'uid' | 'id' | 'version' | 'sequence' | 'section_sequence' | 'form_section_id'>;
type SectionSnapshot = Omit<LocalSection, 'uid' | 'id' | 'version' | 'sequence'>;

interface HistoryEntry {
    label: string;
    undo: () => Promise<void>;
    redo: () => Promise<void>;
}

interface OrderSnapshot {
    fields: { uid: Uid; sequence: number; section: string | null }[];
    sections: { uid: Uid; sequence: number }[];
}

export interface ConflictState {
    kind: 'field' | 'section';
    uid: Uid;
    mine: LocalField | LocalSection;
    theirs: ServerField | ServerSection;
}

function clone<T>(value: T): T {
    return JSON.parse(JSON.stringify(value)) as T;
}

export function useBuilderStore(props: BuilderPageProps) {
    const base = `/forms/${props.form.id}`;

    const fields = ref<LocalField[]>(props.fields.map(toLocalField));
    const sections = ref<LocalSection[]>(props.sections.map(toLocalSection));
    const selection = ref<Selection>(null);
    const conflict = ref<ConflictState | null>(null);
    const undoStack = ref<HistoryEntry[]>([]);
    const redoStack = ref<HistoryEntry[]>([]);

    // Question library (Increment G9b): the picker list, seeded from the Inertia prop and kept fresh by an
    // optimistic bump on insert + a prepend on save (refreshLibrary() refetches for cross-session freshness).
    // `librarySaved` is a transient "Saved to library" confirmation the config panel shows then clears.
    const library = ref<LibraryItem[]>(props.library ?? []);
    const librarySaved = ref<string | null>(null);

    // Last-persisted content per uid — the "before" for the next edit's history entry + the equality base
    // that keeps a no-op blur from recording an empty command.
    const baselines = new Map<Uid, FieldSnapshot | SectionSnapshot>();
    fields.value.forEach((f) => baselines.set(f.uid, fieldSnapshot(f)));
    sections.value.forEach((s) => baselines.set(s.uid, sectionSnapshot(s)));

    // ── Serialized persistence queue + the EXPLICIT save verdict ──────────────
    // ⚠️ THE VERDICT IS NEVER INFERRED FROM "NOTHING IN FLIGHT", AND THAT INFERENCE WAS THE DEFECT. This used
    // to be an in-flight COUNTER read as a verdict, decremented in a `.finally()`. Because `guard()` catches
    // the throw, a FAILED write returned that counter to zero exactly as a successful one did, so the
    // toolbar's polite live region announced "All changes saved" at the instant a write failed, beside an
    // alert saying the opposite. Live at every width including 1440px, and older than the increment that
    // documented it. (WCAG 4.1.3 Status Messages; exceptions-log #13 §3.)
    const save = reactive<{ inFlight: number; error: string | null; wrote: boolean }>({
        inFlight: 0,
        error: null,
        wrote: false,
    });

    // Per-BURST bookkeeping, deliberately plain closure variables rather than reactive state: nothing renders
    // from them, and a reactive flag flipping mid-burst would make the indicator flicker between two verdicts
    // on a single drain.
    let burstAttempted = false;
    let burstFailed = false;

    let queue: Promise<unknown> = Promise.resolve();

    function enqueue<T>(task: () => Promise<T>): Promise<T> {
        // A burst is the run of work from the queue going non-empty to draining. `queue.then(task, task)`
        // serializes, so tasks in one burst are strictly ordered ─ which makes the burst the smallest unit a
        // verdict can honestly cover. "Concurrent" here means "queued", never "overlapping".
        if (save.inFlight === 0) {
            burstAttempted = false;
            burstFailed = false;
        }
        save.inFlight++;

        const run = queue.then(task, task).finally(() => {
            save.inFlight--;
            if (save.inFlight > 0) return;

            // ⚠️ A BURST THAT WROTE NOTHING REACHES NO VERDICT. `commitFieldEdit` returns early when the
            // snapshots are equal and `commitReorder` when the order did not move ─ both still enqueue. Letting
            // those clear an error would mean dragging a field back where it started erased a real failure.
            // (`builderClient.test.ts` already pins that a no-op edit records no PATCH.)
            if (!burstAttempted) return;

            save.wrote = true;

            // Cleared only on POSITIVE evidence: everything this burst attempted, landed. This replaces the two
            // `saveError` clears that used to sit on the success path of persistField and persistSection, which
            // let a LATER success in the same burst erase an EARLIER row's failure.
            if (!burstFailed) save.error = null;
        });

        queue = run.catch(() => undefined);
        return run;
    }

    /**
     * The builder's explicit save verdict.
     *
     * ⚠️ ONE SOURCE OF TRUTH. `saveError` below is a COMPUTED mirror of `save.error`, and this expression
     * tests `save.error` and `conflict` before it can reach 'saved' ─ so `saveState === 'saved'` implies the
     * alert is absent BY CONSTRUCTION, rather than by two writers agreeing to stay in step. A 409 counts as
     * 'failed' for the same reason: the server does not hold what is on screen, whatever the dialog says.
     */
    const saveState = computed<SaveState>(() => {
        if (save.inFlight > 0) return 'saving';
        if (save.error !== null || conflict.value !== null) return 'failed';

        return save.wrote ? 'saved' : 'idle';
    });

    // Read-only by design: the only writers are `guard()`, `reportFailure()` and the burst verdict above. The
    // narrowing from Ref to ComputedRef is itself the guarantee that nothing else can ever set it.
    const saveError = computed(() => save.error);

    const saving = computed(() => saveState.value === 'saving');
    const canUndo = computed(() => undoStack.value.length > 0);
    const canRedo = computed(() => redoStack.value.length > 0);

    /** The canvas model: the implicit ungrouped bucket first, then sections in order, each with its fields. */
    const groups = computed<CanvasGroup[]>(() => {
        const bySeq = (a: LocalField, b: LocalField) => a.sequence - b.sequence;
        const ungrouped = fields.value.filter((f) => f.form_section_id === null).slice().sort(bySeq);
        const ordered = sections.value.slice().sort((a, b) => a.sequence - b.sequence);
        return [
            { section: null, fields: ungrouped },
            ...ordered.map((section) => ({
                section,
                fields: fields.value.filter((f) => f.form_section_id === section.id).slice().sort(bySeq),
            })),
        ];
    });

    const selectedField = computed<LocalField | null>(() =>
        selection.value?.kind === 'field' ? findField(selection.value.uid) ?? null : null,
    );
    const selectedSection = computed<LocalSection | null>(() =>
        selection.value?.kind === 'section' ? findSection(selection.value.uid) ?? null : null,
    );

    // ── Lookups ───────────────────────────────────────────────────────────────
    function findField(uid: Uid): LocalField | undefined {
        return fields.value.find((f) => f.uid === uid);
    }
    function findSection(uid: Uid): LocalSection | undefined {
        return sections.value.find((s) => s.uid === uid);
    }
    function nextSequence(): number {
        return fields.value.reduce((max, f) => Math.max(max, f.sequence), 0) + 1;
    }

    // ── Error-guarded request wrapper ───────────────────────────────────────────
    async function guard<T>(fn: () => Promise<BuilderResult<T>>): Promise<BuilderResult<T> | null> {
        // Set BEFORE the await: this is what separates "the burst tried and everything landed" from "the burst
        // was a no-op", and a request that throws immediately must still count as an attempt.
        burstAttempted = true;

        try {
            return await fn();
        } catch (error) {
            burstFailed = true;
            save.error =
                error instanceof BuilderRequestError ? error.message : 'Something went wrong saving your change.';
            return null;
        }
    }

    // ── Server helpers (low-level; NOT enqueued — always called inside an enqueued action) ──────
    async function createFieldServer(typeValue: string, sectionId: string | null): Promise<ServerField | null> {
        const result = await guard(() =>
            builderClient.post<ServerField>(`${base}/fields`, { field_type: typeValue, section_id: sectionId }),
        );
        return result && !result.conflict ? result.data : null;
    }

    async function persistField(uid: Uid): Promise<void> {
        const field = findField(uid);
        if (!field) return;
        const result = await guard(() => builderClient.patch<ServerField>(`${base}/fields/${field.id}`, fieldPayload(field)));
        if (!result) return;
        if (result.conflict) {
            conflict.value = { kind: 'field', uid, mine: clone(field), theirs: result.current };
            return;
        }
        field.version = result.data.version;
    }

    async function deleteFieldServer(uid: Uid): Promise<boolean> {
        const field = findField(uid);
        if (!field) return false;
        const result = await guard(() => builderClient.delete(`${base}/fields/${field.id}`));
        if (!result) return false;
        removeFieldLocal(uid);
        return true;
    }

    /** Recreate a previously-deleted (or redo-of-add) field under the SAME uid — new server id, restored content. */
    async function restoreFieldServer(
        uid: Uid,
        typeValue: string,
        sectionId: string | null,
        snap: FieldSnapshot,
        sequence: number,
    ): Promise<void> {
        const created = await createFieldServer(typeValue, sectionId);
        if (!created) return;
        const local: LocalField = { ...created, uid };
        applyFieldSnapshot(local, snap);
        local.sequence = sequence;
        local.form_section_id = sectionId;
        fields.value.push(local);
        await persistField(uid);
        await persistOrder();
        baselines.set(uid, fieldSnapshot(local));
        selection.value = { kind: 'field', uid };
    }

    async function persistSection(uid: Uid): Promise<void> {
        const section = findSection(uid);
        if (!section) return;
        const result = await guard(() =>
            builderClient.patch<ServerSection>(`${base}/sections/${section.id}`, sectionPayload(section)),
        );
        if (!result) return;
        if (result.conflict) {
            conflict.value = { kind: 'section', uid, mine: clone(section), theirs: result.current };
            return;
        }
        section.version = result.data.version;
    }

    async function deleteSectionServer(uid: Uid): Promise<boolean> {
        const section = findSection(uid);
        if (!section) return false;
        const result = await guard(() => builderClient.delete(`${base}/sections/${section.id}`));
        if (!result) return false;
        // The FK is ON DELETE SET NULL — its fields become ungrouped (mirror that locally).
        fields.value.forEach((f) => {
            if (f.form_section_id === section.id) f.form_section_id = null;
        });
        sections.value = sections.value.filter((s) => s.uid !== uid);
        if (selection.value?.kind === 'section' && selection.value.uid === uid) selection.value = null;
        return true;
    }

    async function restoreSectionServer(
        uid: Uid,
        snap: SectionSnapshot,
        sequence: number,
        ownedFieldUids: Uid[],
    ): Promise<void> {
        const result = await guard(() => builderClient.post<ServerSection>(`${base}/sections`));
        if (!result || result.conflict) return;
        const local: LocalSection = { ...result.data, uid };
        applySectionSnapshot(local, snap);
        local.sequence = sequence;
        sections.value.push(local);
        await persistSection(uid);
        // Re-adopt the fields the section owned before it was deleted.
        ownedFieldUids.forEach((fieldUid) => {
            const field = findField(fieldUid);
            if (field) field.form_section_id = local.id;
        });
        await persistOrder();
        baselines.set(uid, sectionSnapshot(local));
        selection.value = { kind: 'section', uid };
    }

    async function persistOrder(): Promise<void> {
        await guard(() =>
            builderClient.post(`${base}/reorder`, {
                sections: sections.value.map((s) => ({ id: s.id, sequence: s.sequence })),
                fields: fields.value.map((f) => ({
                    id: f.id,
                    form_section_id: f.form_section_id,
                    sequence: f.sequence,
                    section_sequence: f.section_sequence,
                })),
            }),
        );
    }

    // ── History ─────────────────────────────────────────────────────────────────
    function pushHistory(label: string, undo: () => Promise<void>, redo: () => Promise<void>): void {
        undoStack.value.push({ label, undo, redo });
        redoStack.value = [];
    }

    function undo(): Promise<void> {
        return enqueue(async () => {
            const entry = undoStack.value.pop();
            if (!entry) return;
            const outcome = await runHistory(entry.undo);
            if (outcome !== 'dropped') (outcome === 'done' ? redoStack : undoStack).value.push(entry);
        });
    }

    function redo(): Promise<void> {
        return enqueue(async () => {
            const entry = redoStack.value.pop();
            if (!entry) return;
            const outcome = await runHistory(entry.redo);
            if (outcome !== 'dropped') (outcome === 'done' ? undoStack : redoStack).value.push(entry);
        });
    }

    // ── Public actions (each enqueued) ──────────────────────────────────────────
    function addField(typeValue: string, sectionId: string | null = null): Promise<void> {
        return enqueue(async () => {
            const created = await createFieldServer(typeValue, sectionId);
            if (!created) return;
            const local = toLocalField(created);
            fields.value.push(local);
            // M125 — the palette added "Text"/"Number", so the question says that rather than "Short text". Saved
            // before the baseline and the snapshot are taken, so the add stays ONE undo entry and redo replays it.
            const seeded = variantLabelOf(props.palette, typeValue);
            if (seeded !== null && local.label !== seeded) {
                local.label = seeded;
                await persistField(local.uid);
            }
            baselines.set(local.uid, fieldSnapshot(local));
            selection.value = { kind: 'field', uid: local.uid };

            const uid = local.uid;
            const snap = fieldSnapshot(local);
            const seq = local.sequence;
            pushHistory(
                `Add ${local.label}`,
                async () => {
                    await deleteFieldServer(uid);
                },
                async () => {
                    await restoreFieldServer(uid, typeValue, sectionId, snap, seq);
                },
            );
        });
    }

    function deleteField(uid: Uid): Promise<void> {
        return enqueue(async () => {
            const field = findField(uid);
            if (!field) return;
            const snap = fieldSnapshot(field);
            const type = field.field_type;
            const sectionId = field.form_section_id;
            const seq = field.sequence;
            if (!(await deleteFieldServer(uid))) return;
            pushHistory(
                `Delete ${snap.label}`,
                async () => {
                    await restoreFieldServer(uid, type, sectionId, snap, seq);
                },
                async () => {
                    await deleteFieldServer(uid);
                },
            );
        });
    }

    function duplicateField(uid: Uid): Promise<void> {
        return enqueue(async () => {
            const field = findField(uid);
            if (!field) return;
            const result = await guard(() => builderClient.post<ServerField>(`${base}/fields/${field.id}/duplicate`));
            if (!result || result.conflict) return;
            const local = toLocalField(result.data);
            fields.value.push(local);
            baselines.set(local.uid, fieldSnapshot(local));
            selection.value = { kind: 'field', uid: local.uid };

            const dupUid = local.uid;
            const snap = fieldSnapshot(local);
            const seq = local.sequence;
            const sectionId = local.form_section_id;
            const type = local.field_type;
            pushHistory(
                `Duplicate ${field.label}`,
                async () => {
                    await deleteFieldServer(dupUid);
                },
                async () => {
                    await restoreFieldServer(dupUid, type, sectionId, snap, seq);
                },
            );
        });
    }

    // ── Question library (Increment G9b) ────────────────────────────────────────
    /** Insert a library question into the current draft — server materializes it; merge exactly like duplicate. */
    function insertFromLibrary(itemId: string, sectionId: string | null = null): Promise<void> {
        return enqueue(async () => {
            const result = await guard(() =>
                builderClient.post<ServerField>(`${base}/fields/from-library`, {
                    library_item_id: itemId,
                    section_id: sectionId,
                }),
            );
            if (!result || result.conflict) return;
            const local = toLocalField(result.data);
            fields.value.push(local);
            baselines.set(local.uid, fieldSnapshot(local));
            selection.value = { kind: 'field', uid: local.uid };

            // Reflect the server-side usage bump locally so the picker's "popular" order stays honest.
            const item = library.value.find((i) => i.id === itemId);
            if (item) item.usage_count += 1;

            const newUid = local.uid;
            const snap = fieldSnapshot(local);
            const seq = local.sequence;
            const secId = local.form_section_id;
            const type = local.field_type;
            pushHistory(
                `Add ${local.label}`,
                async () => {
                    await deleteFieldServer(newUid);
                },
                async () => {
                    await restoreFieldServer(newUid, type, secId, snap, seq);
                },
            );
        });
    }

    /** Save the given field to the tenant's library (one-click: no name prompt; server names it from the label). */
    function saveFieldToLibrary(uid: Uid): Promise<void> {
        return enqueue(async () => {
            const field = findField(uid);
            if (!field) return;
            const result = await guard(() =>
                builderClient.post<LibraryItem>(`${base}/fields/${field.id}/save-to-library`, {}),
            );
            if (!result || result.conflict) return;
            // Prepend the new item (dedupe by id in case of a double-submit); surface the transient confirmation.
            library.value = [result.data, ...library.value.filter((i) => i.id !== result.data.id)];
            librarySaved.value = result.data.name;
        });
    }

    /** Refetch the picker list (cross-session freshness; the builder's fetch sidecar's only GET). */
    function refreshLibrary(): Promise<void> {
        return enqueue(async () => {
            const result = await guard(() => builderClient.get<LibraryItem[]>(`${base}/library-items`));
            if (!result || result.conflict) return;
            library.value = result.data;
        });
    }

    function addSection(): Promise<void> {
        return enqueue(async () => {
            const result = await guard(() => builderClient.post<ServerSection>(`${base}/sections`));
            if (!result || result.conflict) return;
            const local = toLocalSection(result.data);
            sections.value.push(local);
            baselines.set(local.uid, sectionSnapshot(local));
            selection.value = { kind: 'section', uid: local.uid };

            const uid = local.uid;
            const snap = sectionSnapshot(local);
            const seq = local.sequence;
            pushHistory(
                'Add section',
                async () => {
                    await deleteSectionServer(uid);
                },
                async () => {
                    await restoreSectionServer(uid, snap, seq, []);
                },
            );
        });
    }

    function deleteSection(uid: Uid): Promise<void> {
        return enqueue(async () => {
            const section = findSection(uid);
            if (!section) return;
            const snap = sectionSnapshot(section);
            const seq = section.sequence;
            const owned = fields.value.filter((f) => f.form_section_id === section.id).map((f) => f.uid);
            if (!(await deleteSectionServer(uid))) return;
            pushHistory(
                `Delete section “${snap.label}”`,
                async () => {
                    await restoreSectionServer(uid, snap, seq, owned);
                },
                async () => {
                    await deleteSectionServer(uid);
                },
            );
        });
    }

    // ── Reorder primitives (D4b) — LOCAL, synchronous moves used by pointer drag + keyboard grab-mode.
    // A reorder "session" wraps a run of these in beginReorder()…commitReorder() so the whole drag/grab
    // is ONE persist + ONE undo entry; cancelReorder() restores the pre-session order (Escape).

    /** Group ids in visual order: the implicit ungrouped bucket (null) first, then sections by sequence. */
    function orderedGroupIds(): (string | null)[] {
        return [null, ...orderedSections().map((s) => s.id)];
    }

    /** Every field in visual order across all groups (ungrouped first, then each section's fields). */
    function flattenedFields(): LocalField[] {
        return groups.value.flatMap((g) => g.fields);
    }

    function orderedSections(): LocalSection[] {
        return sections.value.slice().sort((a, b) => a.sequence - b.sequence);
    }

    /** Move a field into `group` at visual `index` within that group, reflowing all sequences (local). */
    function placeField(uid: Uid, group: string | null, index: number): void {
        const field = findField(uid);
        if (!field) return;
        field.form_section_id = group;

        const order = orderedGroupIds();
        const buckets = new Map<string | null, LocalField[]>();
        order.forEach((g) => buckets.set(g, []));

        for (const f of fields.value.slice().sort((a, b) => a.sequence - b.sequence)) {
            if (f.uid === uid) continue;
            (buckets.get(f.form_section_id) ?? buckets.get(null))?.push(f);
        }

        const bucket = buckets.get(group) ?? buckets.get(null);
        if (bucket) {
            bucket.splice(Math.max(0, Math.min(index, bucket.length)), 0, field);
        }

        let seq = 0;
        for (const g of order) for (const f of buckets.get(g) ?? []) f.sequence = seq++;
    }

    /** One step up/down in the flattened visual order, crossing section boundaries (local). */
    function stepFieldAcross(uid: Uid, direction: -1 | 1): boolean {
        const flat = flattenedFields();
        const i = flat.findIndex((f) => f.uid === uid);
        const j = i + direction;
        if (i < 0 || j < 0 || j >= flat.length) return false;

        const neighbor = flat[j];
        const targetGroup = neighbor.form_section_id;
        const groupFields = flat.filter((f) => f.uid !== uid && f.form_section_id === targetGroup);
        const ni = groupFields.findIndex((f) => f.uid === neighbor.uid);
        placeField(uid, targetGroup, direction === 1 ? ni + 1 : ni);
        return true;
    }

    /** Move a section to visual `index` among the sections, reflowing section sequences (local). */
    function placeSection(uid: Uid, index: number): void {
        const section = findSection(uid);
        if (!section) return;
        const rest = orderedSections().filter((s) => s.uid !== uid);
        rest.splice(Math.max(0, Math.min(index, rest.length)), 0, section);
        rest.forEach((s, idx) => (s.sequence = idx));
    }

    function stepSection(uid: Uid, direction: -1 | 1): boolean {
        const ordered = orderedSections();
        const i = ordered.findIndex((s) => s.uid === uid);
        const j = i + direction;
        if (i < 0 || j < 0 || j >= ordered.length) return false;
        [ordered[i].sequence, ordered[j].sequence] = [ordered[j].sequence, ordered[i].sequence];
        return true;
    }

    let reorderBefore: OrderSnapshot | null = null;

    function beginReorder(): void {
        reorderBefore = snapshotOrder();
    }

    function cancelReorder(): void {
        if (reorderBefore) {
            applyOrder(reorderBefore);
            reorderBefore = null;
        }
    }

    function commitReorder(label: string): Promise<void> {
        return enqueue(async () => {
            if (!reorderBefore) return;
            const before = reorderBefore;
            reorderBefore = null;
            const after = snapshotOrder();
            if (JSON.stringify(before) === JSON.stringify(after)) return; // no net change → no history/persist
            await persistOrder();
            pushHistory(label, () => applyOrderAndPersist(before), () => applyOrderAndPersist(after));
        });
    }

    /** Reparent a field to another section (or ungrouped) — a structural move, so it goes via reorder. */
    function moveFieldToSection(uid: Uid, sectionId: string | null): Promise<void> {
        return enqueue(async () => {
            const field = findField(uid);
            if (!field || field.form_section_id === sectionId) return;
            const before = snapshotOrder();
            field.form_section_id = sectionId;
            field.sequence = nextSequence();
            const after = snapshotOrder();
            await persistOrder();
            pushHistory('Move field to section', () => applyOrderAndPersist(before), () => applyOrderAndPersist(after));
        });
    }

    // ── Config-panel edits: immediate local change + debounced commit (one history entry per burst) ──
    let commitTimer: ReturnType<typeof setTimeout> | null = null;
    let pendingCommit: { uid: Uid; kind: 'field' | 'section' } | null = null;

    /** Called on every config-panel input: the local model already changed (v-model), schedule a persist. */
    function touch(uid: Uid, kind: 'field' | 'section'): void {
        if (pendingCommit && (pendingCommit.uid !== uid || pendingCommit.kind !== kind)) flushCommit();
        pendingCommit = { uid, kind };
        if (commitTimer) clearTimeout(commitTimer);
        commitTimer = setTimeout(flushCommit, 600);
    }

    function flushCommit(): void {
        if (commitTimer) {
            clearTimeout(commitTimer);
            commitTimer = null;
        }
        const target = pendingCommit;
        pendingCommit = null;
        if (!target) return;
        if (target.kind === 'field') void commitFieldEdit(target.uid);
        else void commitSectionEdit(target.uid);
    }

    function commitFieldEdit(uid: Uid): Promise<void> {
        return enqueue(async () => {
            const field = findField(uid);
            if (!field) return;
            const before = baselines.get(uid) as FieldSnapshot | undefined;
            const after = fieldSnapshot(field);
            if (before && snapshotsEqual(before, after)) return;
            await persistField(uid);
            if (conflict.value) return;
            baselines.set(uid, clone(after));
            if (!before) return;
            pushHistory(
                `Edit ${after.label || 'field'}`,
                async () => {
                    const current = sameTypeField(uid, before, 'undo');
                    if (!current) return;
                    applyFieldSnapshot(current, before);
                    await persistField(uid);
                    baselines.set(uid, clone(before));
                },
                async () => {
                    const current = sameTypeField(uid, after, 'redo');
                    if (!current) return;
                    applyFieldSnapshot(current, after);
                    await persistField(uid);
                    baselines.set(uid, clone(after));
                },
            );
        });
    }

    function commitSectionEdit(uid: Uid): Promise<void> {
        return enqueue(async () => {
            const section = findSection(uid);
            if (!section) return;
            const before = baselines.get(uid) as SectionSnapshot | undefined;
            const after = sectionSnapshot(section);
            if (before && snapshotsEqual(before, after)) return;
            await persistSection(uid);
            if (conflict.value) return;
            baselines.set(uid, clone(after));
            if (!before) return;
            pushHistory(
                `Edit section “${after.label}”`,
                async () => {
                    const current = findSection(uid);
                    if (!current) return;
                    applySectionSnapshot(current, before);
                    await persistSection(uid);
                    baselines.set(uid, clone(before));
                },
                async () => {
                    const current = findSection(uid);
                    if (!current) return;
                    applySectionSnapshot(current, after);
                    await persistSection(uid);
                    baselines.set(uid, clone(after));
                },
            );
        });
    }

    // ── Conflict resolution (409) — never silently discard either side ──────────
    function resolveConflict(choice: 'mine' | 'theirs'): Promise<void> {
        return enqueue(async () => {
            const state = conflict.value;
            conflict.value = null;
            if (!state) return;

            if (state.kind === 'field') {
                const field = findField(state.uid);
                if (!field) return;
                if (choice === 'theirs' || refuseMineAcrossTypes(state)) {
                    Object.assign(field, { ...(state.theirs as ServerField), uid: state.uid });
                } else {
                    // Keep my in-flight values; adopt THEIR version token so the overwrite is accepted.
                    Object.assign(field, { ...(state.mine as LocalField), version: (state.theirs as ServerField).version });
                    await persistField(state.uid);
                }
                baselines.set(state.uid, fieldSnapshot(field));
            } else {
                const section = findSection(state.uid);
                if (!section) return;
                if (choice === 'theirs') {
                    Object.assign(section, { ...(state.theirs as ServerSection), uid: state.uid });
                } else {
                    Object.assign(section, {
                        ...(state.mine as LocalSection),
                        version: (state.theirs as ServerSection).version,
                    });
                    await persistSection(state.uid);
                }
                baselines.set(state.uid, sectionSnapshot(section));
            }
        });
    }

    // ── Selection (flush any pending edit before switching target) ───────────────
    function select(next: Selection): void {
        flushCommit();
        selection.value = next;
    }

    /** Resolve once every queued write has settled (used before a full-page publish/leave). */
    function whenIdle(): Promise<unknown> {
        flushCommit();
        return queue;
    }

    // ── Changing a question's type (Increment M123, `B5b`) ─────────────────────────────────────────
    // ⛔ EVERY REQUEST HERE RUNS IN THE QUEUE, AFTER A FLUSH, AND READS THE ROW'S TOKEN INSIDE ITS TASK.
    // `updateField()` checks its token before it takes the row lock, so a PATCH racing the convert can write the
    // old type's rules back over it with no 409 — and the token is second-precision, so a PATCH queued in the
    // same second as the convert carries a token the convert did not visibly move. Serializing is the only
    // protection the client has.
    //
    // ⛔ A CONVERSION'S 409 NEVER REACHES THE CONFLICT DIALOG. Its three triggers (a stale token, a stale plan, a
    // missing fingerprint) share one body and one remedy — read the plans again — and "Keep mine" would PATCH the
    // old type's content onto the converted row. The server's row is adopted and the caller re-reads.

    /** A failure the author must see, booked the way `guard()` books a failed write. */
    function reportFailure(message: string): void {
        burstAttempted = true;
        burstFailed = true;
        save.error = message;
    }

    function blockedReason(): string | null {
        if (conflict.value !== null) return CONVERT_BLOCKED_BY_CONFLICT;
        if (save.error !== null) return CONVERT_BLOCKED_BY_FAILED_SAVE;
        return null;
    }

    function typeLabel(value: string): string {
        return props.palette.flatMap((g) => g.types).find((t) => t.value === value)?.label ?? value;
    }

    /** Replace the row with the server's IN PLACE — so the uid, the selection and every history entry survive. */
    function adoptField(field: LocalField, row: ServerField): void {
        Object.assign(field, { ...clone(row), uid: field.uid });
        baselines.set(field.uid, fieldSnapshot(field));
    }

    /** A READ: no burst bookkeeping, so it can never move the save verdict. */
    async function requestPlans(field: LocalField): Promise<ConversionPlan[] | string> {
        try {
            const result = await builderClient.get<{ plans: ConversionPlan[] }>(`${base}/fields/${field.id}/conversions`);
            // The GET carries no token and never 409s; the narrowing makes that a fact rather than a hope.
            return result.conflict ? result.message : result.data.plans;
        } catch (error) {
            return error instanceof BuilderRequestError ? error.message : PLANS_UNAVAILABLE;
        }
    }

    /** Every type this question may become, as the server plans each conversion right now. */
    function loadConversionPlans(uid: Uid): Promise<PlansOutcome> {
        flushCommit(); // the plans must describe what is on screen, not a keystroke ago
        return enqueue(async (): Promise<PlansOutcome> => {
            const field = findField(uid);
            if (!field) return { status: 'failed', message: QUESTION_GONE };
            const blocked = blockedReason();
            if (blocked !== null) return { status: 'failed', message: blocked };
            const plans = await requestPlans(field);
            if (typeof plans === 'string') return { status: 'failed', message: plans };
            // Every plan names the type the SERVER holds; a mismatch means it moved somewhere this page never saw.
            if (plans.some((p) => p.from !== field.field_type)) return { status: 'failed', message: TYPE_MOVED_ELSEWHERE };
            return { status: 'loaded', from: field.field_type, plans };
        });
    }

    /** Apply one plan the author confirmed. Always sends the plan's fingerprint, so the server refuses a plan that moved. */
    function convertField(uid: Uid, plan: ConversionPlan): Promise<ConvertOutcome> {
        flushCommit();
        return enqueue(async (): Promise<ConvertOutcome> => {
            const field = findField(uid);
            if (!field) return { status: 'failed', message: QUESTION_GONE };
            const blocked = blockedReason();
            if (blocked !== null) return { status: 'failed', message: blocked };
            if (field.field_type !== plan.from) return { status: 'stale' };
            const before = fieldSnapshot(field); // equals the baseline: the flush landed first
            let result: BuilderResult<ServerField>;
            try {
                result = await builderClient.post<ServerField>(`${base}/fields/${field.id}/convert`, {
                    to: plan.to,
                    version: field.version,
                    fingerprint: plan.fingerprint,
                });
            } catch (error) {
                // A refused conversion is not a failed save: nothing changed on either side, so the verdict stays.
                return { status: 'failed', message: error instanceof BuilderRequestError ? error.message : CONVERT_FAILED };
            }
            if (result.conflict) {
                adoptField(field, result.current);
                return { status: 'stale' };
            }
            burstAttempted = true; // a write landed, so this burst may now reach "saved"
            adoptField(field, result.data);
            pushConversion(uid, plan, before);
            return { status: 'converted' };
        });
    }

    /**
     * The conversion's ONE history entry. Undo converts back, then restores the pre-conversion content only when
     * the round trip did not already restore it — that PATCH re-sends every rule, and a rule's imported translations
     * and AND/OR grouping cannot survive it (`R-86a0426d`). Undo and redo apply a freshly read plan WITHOUT the
     * census: the author is reversing their own change, not making a new one.
     */
    function pushConversion(uid: Uid, plan: ConversionPlan, before: FieldSnapshot): void {
        const { from, to } = plan;
        let forward = plan; // the plan that produced the current state; every redo replaces it
        let restorePending = false; // the type is back but the pre-conversion content has not landed yet
        pushHistory(
            `Change ${before.label || 'field'} to ${typeLabel(to)}`,
            async () => {
                const live = findField(uid);
                if (!live) return; // deleted since — as the Edit entries do
                if (live.field_type === to) {
                    const reverse = await convertInHistory(live, from, UNDO_FAILED);
                    restorePending = !roundTripIsExact(forward, reverse);
                } else if (live.field_type !== from) {
                    throw new StaleHistoryStep(UNDO_TYPE_MOVED); // neither side of this entry: drop it
                } // `=== from`: an earlier attempt already converted back, so a retry only owes the restore
                if (restorePending) {
                    // A PATCH cannot change a type, so it runs only once the type is back.
                    await restoreSnapshot(live, before, UNDO_FAILED);
                    restorePending = false;
                }
                baselines.set(uid, fieldSnapshot(live));
            },
            async () => {
                const live = findField(uid);
                if (!live || live.field_type === to) return; // already there: idempotent
                if (live.field_type !== from) throw new StaleHistoryStep(REDO_TYPE_MOVED);
                forward = await convertInHistory(live, to, REDO_FAILED);
                restorePending = false;
            },
        );
    }

    /** A history step's conversion: a fresh plan, then the POST. Throws so `runHistory()` can keep or drop the entry. */
    async function convertInHistory(live: LocalField, target: string, failure: string): Promise<ConversionPlan> {
        const plans = await requestPlans(live);
        if (typeof plans === 'string') throw new HistoryStepFailed(`${failure} ${plans}`);
        const plan = plans.find((p) => p.to === target);
        if (plan === undefined || plan.from !== live.field_type) {
            throw new StaleHistoryStep(`${failure} ${TYPE_MOVED_ELSEWHERE}`);
        }
        const result = await guard(() =>
            builderClient.post<ServerField>(`${base}/fields/${live.id}/convert`, {
                to: target,
                version: live.version,
                fingerprint: plan.fingerprint,
            }),
        );
        if (result === null) throw new HistoryStepFailed(`${failure} ${save.error ?? ''}`.trim());
        if (result.conflict) {
            adoptField(live, result.current);
            throw new StaleHistoryStep(`${failure} ${CHANGED_ELSEWHERE}`);
        }
        adoptField(live, result.data);
        return plan;
    }

    async function restoreSnapshot(live: LocalField, snap: FieldSnapshot, failure: string): Promise<void> {
        const onServer = fieldSnapshot(live);
        applyFieldSnapshot(live, snap);
        const result = await guard(() => builderClient.patch<ServerField>(`${base}/fields/${live.id}`, fieldPayload(live)));
        if (result === null) {
            applyFieldSnapshot(live, onServer); // the screen shows what the server holds; a retry PATCHes again
            throw new HistoryStepFailed(`${failure} ${save.error ?? ''}`.trim());
        }
        if (result.conflict) {
            adoptField(live, result.current);
            throw new StaleHistoryStep(`${failure} ${CHANGED_ELSEWHERE}`);
        }
        live.version = result.data.version;
    }

    /**
     * Run one undo or redo step. Three outcomes, because a step can fail in two different ways: one that may
     * work next time (kept on its stack for a retry) and one that never can — the type moved elsewhere — which
     * is dropped rather than retried forever. Every entry older than this increment never throws, so it is
     * always `done` and moves to the other stack exactly as before.
     */
    async function runHistory(step: () => Promise<void>): Promise<'done' | 'retry' | 'dropped'> {
        try {
            await step();
            return 'done';
        } catch (error) {
            reportFailure(error instanceof Error && error.message !== '' ? error.message : HISTORY_STEP_FAILED);
            return error instanceof StaleHistoryStep ? 'dropped' : 'retry';
        }
    }

    /**
     * The row an Edit entry may apply its snapshot to. A row whose type has moved since is refused for good: the
     * snapshot carries `field_type`, the PATCH cannot send it, so applying it would flip the type on screen only.
     */
    function sameTypeField(uid: Uid, snap: FieldSnapshot, step: 'undo' | 'redo'): LocalField | undefined {
        const live = findField(uid);
        if (live !== undefined && live.field_type !== snap.field_type) {
            throw new StaleHistoryStep(step === 'undo' ? EDIT_UNDO_TYPE_MOVED : EDIT_REDO_TYPE_MOVED);
        }
        return live;
    }

    /** "Keep mine" re-sends content written for the type the author saw; when theirs is another type, theirs is kept. */
    function refuseMineAcrossTypes(state: ConflictState): boolean {
        const across = (state.mine as LocalField).field_type !== (state.theirs as ServerField).field_type;
        if (across) reportFailure(MINE_ACROSS_TYPES);
        return across;
    }

    // ── Order snapshot helpers ───────────────────────────────────────────────────
    function snapshotOrder(): OrderSnapshot {
        return {
            fields: fields.value.map((f) => ({ uid: f.uid, sequence: f.sequence, section: f.form_section_id })),
            sections: sections.value.map((s) => ({ uid: s.uid, sequence: s.sequence })),
        };
    }
    function applyOrder(order: OrderSnapshot): void {
        order.fields.forEach((row) => {
            const field = findField(row.uid);
            if (field) {
                field.sequence = row.sequence;
                field.form_section_id = row.section;
            }
        });
        order.sections.forEach((row) => {
            const section = findSection(row.uid);
            if (section) section.sequence = row.sequence;
        });
    }
    async function applyOrderAndPersist(order: OrderSnapshot): Promise<void> {
        applyOrder(order);
        await persistOrder();
    }

    // ── Local mutation helpers ───────────────────────────────────────────────────
    function removeFieldLocal(uid: Uid): void {
        fields.value = fields.value.filter((f) => f.uid !== uid);
        baselines.delete(uid);
        if (selection.value?.kind === 'field' && selection.value.uid === uid) selection.value = null;
    }

    return {
        // state
        fields: fields as Ref<LocalField[]>,
        sections: sections as Ref<LocalSection[]>,
        groups,
        selection,
        selectedField,
        selectedSection,
        saving,
        saveState,
        saveError,
        conflict,
        canUndo,
        canRedo,
        palette: props.palette,
        enums: props.enums,
        library,
        librarySaved,
        // actions
        addField,
        deleteField,
        duplicateField,
        insertFromLibrary,
        saveFieldToLibrary,
        refreshLibrary,
        addSection,
        deleteSection,
        moveFieldToSection,
        // changing a question's type (M123)
        loadConversionPlans,
        convertField,
        // reorder session (drag / keyboard grab)
        flattenedFields,
        orderedSections,
        placeField,
        stepFieldAcross,
        placeSection,
        stepSection,
        beginReorder,
        cancelReorder,
        commitReorder,
        touch,
        select,
        undo,
        redo,
        resolveConflict,
        whenIdle,
    };
}

// ── Pure mappers / snapshots (module scope) ─────────────────────────────────────
function toLocalField(field: ServerField): LocalField {
    return { ...clone(field), uid: nextUid() };
}
function toLocalSection(section: ServerSection): LocalSection {
    return { ...clone(section), uid: nextUid() };
}

function fieldSnapshot(field: LocalField): FieldSnapshot {
    const { uid: _uid, id: _id, version: _v, sequence: _s, section_sequence: _ss, form_section_id: _f, ...rest } = field;
    return clone(rest);
}
function applyFieldSnapshot(field: LocalField, snap: FieldSnapshot): void {
    Object.assign(field, clone(snap));
}
function sectionSnapshot(section: LocalSection): SectionSnapshot {
    const { uid: _uid, id: _id, version: _v, sequence: _s, ...rest } = section;
    return clone(rest);
}
function applySectionSnapshot(section: LocalSection, snap: SectionSnapshot): void {
    Object.assign(section, clone(snap));
}

function snapshotsEqual(a: unknown, b: unknown): boolean {
    return JSON.stringify(a) === JSON.stringify(b);
}

function fieldPayload(field: LocalField): Record<string, unknown> {
    return {
        key: field.key,
        label: field.label,
        hint: field.hint,
        placeholder: field.placeholder,
        is_required: field.is_required,
        relevant_expression: field.relevant_expression,
        appearance: field.appearance,
        config: field.config,
        default_value: field.default_value,
        is_pii: field.is_pii,
        is_sensitive: field.is_sensitive,
        is_queryable: field.is_queryable,
        indexed_data_type: field.indexed_data_type,
        version: field.version,
        validations: field.validations.map((v) => ({
            rule_type: v.rule_type,
            operator: v.operator,
            rule_value: v.rule_value,
            expression: v.expression,
            error_message: v.error_message,
            related_field_key: v.related_field_key,
        })),
    };
}

function sectionPayload(section: LocalSection): Record<string, unknown> {
    return {
        key: section.key,
        label: section.label,
        description: section.description,
        is_repeatable: section.is_repeatable,
        min_instances: section.min_instances,
        max_instances: section.max_instances,
        relevant_expression: section.relevant_expression,
        version: section.version,
    };
}

export type BuilderStore = ReturnType<typeof useBuilderStore>;

// ── Changing a question's type: the wire shapes and the store's own rules (Increment M123) ───────────────────
// Declared down here, not in `types.ts`, so the store needs no new import above the lines the ledger cites.

/** A rule row as a plan names it — by sequence and content, never by id (`ConversionPlan::publicRow()`). */
export interface ConversionRule {
    sequence: number;
    rule_type: string | null;
    operator: string | null;
    rule_value: string | null;
    expression: string | null;
    error_message: string | null;
}

export interface ConversionDroppedRule extends ConversionRule {
    reason: string;
    /** The server's own sentence for why the rule cannot survive the conversion. */
    reason_message: string;
}

export interface ConversionAddedRule {
    sequence: number;
    rule_type: string;
    rule_value: string;
    error_message: string;
}

/** `column` is one of `is_required`, `default_value`, `default_value_is_expression`, `is_queryable`, `indexed_data_type`. */
export interface ConversionChange {
    column: string;
    from: string | boolean | null;
    to: string | boolean | null;
}

export interface ConversionWarningItem {
    code: string;
    message: string;
}

/** `site` is one of `relevance`, `section_relevance`, `formula`, `constraint`, `rule`, `template`. */
export interface ConversionCensusEntry {
    code: string;
    site: string;
    /** The OWNER of the reference: a field key, a section key, or `confirmation_message`. */
    key: string;
    message: string;
}

/** One conversion as `GET …/fields/{field}/conversions` plans it, with the census of what it re-means elsewhere. */
export interface ConversionPlan {
    from: string;
    to: string;
    lossless: boolean;
    requires_confirmation: boolean;
    fingerprint: string;
    config_dropped: string[];
    changes: ConversionChange[];
    kept: ConversionRule[];
    dropped: ConversionDroppedRule[];
    added: ConversionAddedRule[];
    warnings: ConversionWarningItem[];
    census: ConversionCensusEntry[];
}

export type PlansOutcome = { status: 'loaded'; from: string; plans: ConversionPlan[] } | { status: 'failed'; message: string };

export type ConvertOutcome = { status: 'converted' } | { status: 'stale' } | { status: 'failed'; message: string };

/** A history step that can never apply again — the type moved elsewhere — so its entry is dropped. */
class StaleHistoryStep extends Error {}

/** A history step that failed this time, so its entry stays on its stack for a retry. */
class HistoryStepFailed extends Error {}

/**
 * Whether converting back lands on exactly the pre-conversion content, so the lossy restoring PATCH must NOT run.
 *
 * ⚠️ "THE FORWARD PLAN WAS LOSSLESS" IS NOT ENOUGH, and that was the first rule written. A lossless forward plan
 * still fails to round-trip when the reverse re-adds a shipped check the author had deleted (an email field with no
 * pattern → short text is lossless; the way back adds the pattern), when the reverse drops an author-written rule
 * that happens to equal the source type's shipped one, or when the reverse resets columns the forward never touched.
 * Exact means: nothing lost forward, and the way back removes precisely the rows the forward added — matched by
 * CONTENT, because any intervening PATCH re-sequences rows.
 */
export function roundTripIsExact(forward: ConversionPlan, reverse: ConversionPlan): boolean {
    if (!forward.lossless) return false;
    if (reverse.added.length > 0 || reverse.changes.length > 0 || reverse.config_dropped.length > 0) return false;
    const pool = [...reverse.dropped];
    for (const added of forward.added) {
        const i = pool.findIndex(
            (d) =>
                d.rule_type === added.rule_type &&
                d.rule_value === added.rule_value &&
                d.error_message === added.error_message &&
                d.operator === null &&
                d.expression === null,
        );
        if (i < 0) return false;
        pool.splice(i, 1);
    }
    return pool.length === 0;
}

const QUESTION_GONE = 'This question no longer exists.';
const CONVERT_BLOCKED_BY_CONFLICT = 'Resolve the editing conflict first, then change the type.';
const CONVERT_BLOCKED_BY_FAILED_SAVE =
    'Your last change hasn’t saved, so the type can’t be changed yet. Close this and fix the problem shown at the top of the settings panel.';
const TYPE_MOVED_ELSEWHERE = 'This question’s type was changed somewhere else. Reload the page to see it.';
const PLANS_UNAVAILABLE = 'Couldn’t load the types this question can change to.';
const CONVERT_FAILED = 'Something went wrong changing the type. Try again.';
const CHANGED_ELSEWHERE = 'The question was changed somewhere else.';
const UNDO_FAILED = 'Couldn’t undo the type change.';
const REDO_FAILED = 'Couldn’t redo the type change.';
const UNDO_TYPE_MOVED = 'The type change can’t be undone: this question’s type was changed somewhere else.';
const REDO_TYPE_MOVED = 'The type change can’t be redone: this question’s type was changed somewhere else.';
const EDIT_UNDO_TYPE_MOVED = 'That edit can’t be undone: this question’s type has changed since it was made.';
const EDIT_REDO_TYPE_MOVED = 'That edit can’t be redone: this question’s type has changed since it was made.';
const MINE_ACROSS_TYPES =
    'This question was changed to another type somewhere else, so your edit couldn’t be kept. The other version is shown.';
const HISTORY_STEP_FAILED = 'That step couldn’t be completed.';

/** The label a new field of a palette variant group arrives with (M125), or null for a type the palette lists by itself. */
function variantLabelOf(palette: BuilderPageProps['palette'], typeValue: string): string | null {
    for (const group of palette) {
        const type = group.types.find((candidate) => candidate.value === typeValue);
        if (type !== undefined) return type.variant?.label ?? null;
    }

    return null;
}
