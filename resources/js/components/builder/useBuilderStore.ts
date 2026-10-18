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
import { builderFor, BuilderRequestError, DraftSupersededError, type BuilderResult, type SupersedingDraft } from './builderClient';
import { editJournal } from './edit-journal';
import { canonical } from './row-compare';
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
    // M172 (`R-81756e95`): every write names the draft this page was loaded with, so a page left open across a publish
    // is refused (409 `draft_superseded`) instead of being told "The key has already been taken".
    const client = builderFor(props.draft?.id ?? null);

    const fields = ref<LocalField[]>(props.fields.map(toLocalField));
    const sections = ref<LocalSection[]>(props.sections.map(toLocalSection));
    const selection = ref<Selection>(null);
    const conflict = ref<ConflictState | null>(null);
    // M176 (`R-164579e8`): the author closed the dialog without choosing. The conflict stays pending — the edit on screen
    // and in the journal, publish held — and the builder's notice brings the dialog back (`reviewConflict()`).
    const conflictDeferred = ref(false);
    // M172 (`R-81756e95`): the form was published since this page loaded. Every id the page holds is the old draft's, so
    // nothing more is sent; the dialog offers the reload, and the journal puts each kept edit back into the new draft.
    const superseded = ref<{ draft: SupersedingDraft | null } | null>(null);
    // M172 (`R-733eff09`, `D132`): the key a save asked for and the one the server kept instead, per row, while they differ.
    const keyNotices = ref<Record<Uid, { wanted: string; kept: string }>>({});
    // M176 (`R-c4802b60`): every payload this page sent for a row since the server last confirmed one — so a 409 whose
    // `current` is one of them is read as THIS page's own landed write (its reply lost), never as someone else's.
    // In memory, not the journal: the journal's storage can be refused, and this is about the page's own requests.
    const sentSinceHeld = new Map<Uid, Record<string, unknown>[]>();
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
    const save = reactive<{
        inFlight: number;
        error: string | null;
        wrote: boolean;
        fieldErrors: Record<Uid, Record<string, string[]>>;
        pending: boolean;
        unsaved: Record<Uid, 'field' | 'section'>;
        /** M172 (`R-d85d51d2`): one per failure, so the panel can re-announce a refusal identical to the last. */
        failures: number;
        /** M176 (`R-5554c526`): per question, the editor row of each rule its last save SENT — `validations.N` → row. */
        ruleRows: Record<Uid, number[]>;
    }>({ inFlight: 0, error: null, wrote: false, fieldErrors: {}, pending: false, unsaved: {}, failures: 0, ruleRows: {} });

    // M170 (`R-bfa3eb93`) — every edit the server has not confirmed, kept for this tab so a refresh puts it back. A
    // read-only builder (an archived form has no draft) edits nothing, so it keeps nothing. See `edit-journal.ts`.
    // M172: keyed by the FORM, so the edits of a page reloaded into a newer draft come back (see `edit-journal.ts`).
    const journal = props.draft === null ? null : editJournal(props.form.id, undefined, undefined, props.draft.id);

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
            // ⚠️ M170 (`R-fdc743ab`) — AND NOTHING FROM AN EARLIER BURST IS STILL UNSAVED. The rule above stopped one
            // burst erasing its own failure and left the NEXT burst free to: a save of question A failed on the
            // network, a save of question B landed, and the toolbar said "All changes saved" over A's lost edit.
            if (!burstFailed && Object.keys(save.unsaved).length === 0) save.error = null;
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
        // M170 (`R-bfa3eb93`): a change waiting out the debounce is not saved either — "All changes saved" there was
        // the sentence that let an author refresh away an edit the server never received.
        if (save.inFlight > 0 || save.pending) return 'saving';
        // A field still holding a refusal is a change the server does not have, whatever saved since (M128). One whose save
        // failed with no refusal to mark it — the network, an ended session — reads failed through `save.error`, which the
        // burst verdict above keeps while anything is unsaved (M170, `R-fdc743ab`); a second test of `save.unsaved` here
        // was measured redundant by mutation and removed.
        if (save.error !== null || conflict.value !== null || Object.keys(save.fieldErrors).length > 0) return 'failed';

        return save.wrote ? 'saved' : 'idle';
    });

    /** M170 — whether any edit failed to save in a way only sending it again can fix: what the alert's Retry offers. */
    const hasUnsaved = computed(() => superseded.value === null && Object.keys(save.unsaved).length > 0);

    // Read-only by design: the only writers are `guard()`, `reportFailure()` and the burst verdict above. The
    // narrowing from Ref to ComputedRef is itself the guarantee that nothing else can ever set it.
    const saveError = computed(() => save.error);

    /**
     * Per-field refusals, keyed by field uid (M128, `R-d001de0c`): the server's `{ errors }` map from the last
     * refused PATCH of that field, which `ConfigPanel` marks on the controls. ⚠️ KEYED BY UID AND CLEARED PER
     * FIELD, never at the burst verdict: `select()` flushes and then switches, so a refusal can land after
     * another question is selected, and a later success on THAT question must not erase this one's marks —
     * its refused value is still on screen and nothing has sent it again.
     */
    const saveFieldErrors = computed(() => save.fieldErrors);
    const saveRuleRows = computed(() => save.ruleRows);

    // ⚠️ M170 — A REQUEST IN FLIGHT, NOT `saveState === 'saving'`. The buttons this disables (Save to library, the
    // toolbar's) must stay usable while an edit only waits out its debounce: each sends that edit first.
    const saving = computed(() => save.inFlight > 0);
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
    async function guard<T>(fn: () => Promise<BuilderResult<T>>, owner?: { uid: Uid }): Promise<BuilderResult<T> | null> {
        // Set BEFORE the await: this is what separates "the burst tried and everything landed" from "the burst
        // was a no-op", and a request that throws immediately must still count as an attempt.
        burstAttempted = true;

        // M172: a page known to be stale sends nothing more — each write would be refused again, and the journal already
        // holds every edit for the reload the dialog offers.
        if (superseded.value !== null) {
            burstFailed = true;
            return null;
        }

        try {
            return await fn();
        } catch (error) {
            if (error instanceof DraftSupersededError) {
                noteSuperseded(error);
                return null;
            }
            burstFailed = true;
            save.failures++;
            save.error =
                error instanceof BuilderRequestError ? error.message : 'Something went wrong saving your change.';
            // Only a field's own PATCH names an owner. Its marks describe THIS refusal: one without a map (a
            // network failure, a `FormException`) leaves none standing from an earlier one.
            if (owner !== undefined) {
                const map = error instanceof BuilderRequestError ? error.errors : {};
                if (Object.keys(map).length > 0) save.fieldErrors[owner.uid] = map;
                else delete save.fieldErrors[owner.uid];
            }
            return null;
        }
    }

    // ── Server helpers (low-level; NOT enqueued — always called inside an enqueued action) ──────
    // M177 (`D140`): `restore` says a history step is putting an existing question back — the server refuses an author ADDING a
    // retired type (Yes/No, Duration), and the undo of a delete re-creates one through this same route.
    async function createFieldServer(typeValue: string, sectionId: string | null, restore = false): Promise<ServerField | null> {
        const result = await guard(() =>
            client.post<ServerField>(`${base}/fields`, { field_type: typeValue, section_id: sectionId, ...(restore ? { restore: true } : {}) }),
        );
        return result && !result.conflict ? result.data : null;
    }

    /**
     * M176 (`R-c4802b60`) — note a payload as sent, and answer what was sent BEFORE it since the last confirmed save. Bounded:
     * a row whose saves have failed for an hour is not one whose oldest attempt is what the server holds.
     */
    function noteSent(uid: Uid, sent: Record<string, unknown>): Record<string, unknown>[] {
        const earlier = sentSinceHeld.get(uid) ?? [];
        sentSinceHeld.set(uid, [...earlier, sent].slice(-10));
        return earlier;
    }

    function isEarlierSend(earlier: Record<string, unknown>[], current: Record<string, unknown>): boolean {
        return earlier.some((other) => samePayload(current, other));
    }

    /** True once the server holds the field as it is on screen — false on a refusal, a failure or a 409 (M155). */
    async function persistField(uid: Uid, resent = false): Promise<boolean> {
        const field = findField(uid);
        if (!field) return false;
        const sent = fieldPayload(field);
        const earlier = noteSent(uid, sent);
        // M176 (`R-5554c526`): which editor row each sent rule is, so a refusal of `validations.N` names the row on screen.
        save.ruleRows[uid] = field.validations.flatMap((rule, row) => (isUnfinishedRule(rule) ? [] : [row]));
        const result = await guard(() => client.patch<ServerField>(`${base}/fields/${field.id}`, sent), { uid });
        if (!result) return false;
        // M170 — a 409 whose question already holds exactly this edit is not a conflict. It is how the put-back after a
        // refresh meets the save sent as the page went, when that save landed after the server rendered the reload: the
        // token moved, the content did not. Take the token and say saved; a dialog asking "mine or theirs" between two
        // identical sides would be noise. ⚠️ Compared with what THIS REQUEST SENT, never with the field as it is now:
        // measured in a real browser, the author had typed the next setting while the request was out, so "now" never
        // matched, a conflict was raised over nothing, and the next save went at a token already spent.
        if (result.conflict && samePayload(fieldPayload(result.current as unknown as LocalField), sent)) {
            field.version = result.current.version;
            sentSinceHeld.delete(uid);
            delete save.fieldErrors[uid];
            return true;
        }
        // M176 (`R-c4802b60`) — the server holds an EARLIER save of this page, whose reply never came back, and the author has
        // typed on since. That is this page's own write, not someone else's: take its token and send the newer edit, once.
        if (result.conflict && !resent && isEarlierSend(earlier, fieldPayload(result.current as unknown as LocalField))) {
            field.version = result.current.version;
            return persistField(uid, true);
        }
        if (result.conflict) {
            raiseConflict({ kind: 'field', uid, mine: clone(field), theirs: result.current }, field.id);
            return false;
        }
        field.version = result.data.version;
        sentSinceHeld.delete(uid);
        delete save.fieldErrors[uid]; // the server holds this field now: its marks are answered
        adoptKeptKey(uid, field, String(sent.key), result.data.key);
        return true;
    }

    /**
     * M172 (`R-733eff09`, `D132`) — the server keeps a free key (`{key}_2` …) when the one sent is another row's; a clash is
     * never refused. The screen takes the kept key unless the author has typed on since, the panel says so, and every rule
     * of another question that compared with the row's previous key now names the kept one (the key half of `R-9ea69e29`).
     */
    function adoptKeptKey(uid: Uid, row: LocalField | LocalSection, sentKey: string, keptKey: string): void {
        if (keptKey !== sentKey) {
            keyNotices.value[uid] = { wanted: sentKey, kept: keptKey };
            if (row.key === sentKey) row.key = keptKey;
        } else {
            delete keyNotices.value[uid];
        }
        const previous = (baselines.get(uid) as { key?: string } | undefined)?.key;
        if ('validations' in row && previous !== undefined && previous !== keptKey) repointRules(uid, previous, keptKey);
    }

    /**
     * M172 (the key half of `R-9ea69e29`) — a question's key moved on the server: every OTHER question's rule comparing with
     * it names the new key, on screen and in its baseline. The server stores those rules by id, so it already agrees; what
     * was stale is the key the next save of those questions would send, which then resolved to no question at all.
     */
    function repointRules(uid: Uid, oldKey: string, newKey: string): void {
        for (const other of fields.value) {
            if (other.uid === uid) continue;
            const base = baselines.get(other.uid) as FieldSnapshot | undefined;
            for (const rules of [other.validations, base?.validations ?? []]) {
                rules.forEach((rule) => {
                    if (rule.related_field_key === oldKey) rule.related_field_key = newKey;
                });
            }
        }
    }

    async function deleteFieldServer(uid: Uid): Promise<boolean> {
        const field = findField(uid);
        if (!field) return false;
        const result = await guard(() => client.delete(`${base}/fields/${field.id}`));
        if (!result) return false;
        removeFieldLocal(uid);
        delete save.fieldErrors[uid]; // a deleted question has nothing left to mark
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
        const created = await createFieldServer(typeValue, sectionId, false);
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

    /** True once the server holds the section as it is on screen — false on a refusal, a failure or a 409 (M155). */
    async function persistSection(uid: Uid, resent = false): Promise<boolean> {
        const section = findSection(uid);
        if (!section) return false;
        const sent = sectionPayload(section);
        const earlier = noteSent(uid, sent);
        const result = await guard(() => client.patch<ServerSection>(`${base}/sections/${section.id}`, sent));
        if (!result) return false;
        if (result.conflict && samePayload(sectionPayload(result.current as unknown as LocalSection), sent)) {
            section.version = result.current.version; // M170: as `persistField()` — the same edit, landed twice
            sentSinceHeld.delete(uid);
            return true;
        }
        if (result.conflict && !resent && isEarlierSend(earlier, sectionPayload(result.current as unknown as LocalSection))) {
            section.version = result.current.version; // M176: as `persistField()` — this page's own landed write
            return persistSection(uid, true);
        }
        if (result.conflict) {
            raiseConflict({ kind: 'section', uid, mine: clone(section), theirs: result.current }, section.id);
            return false;
        }
        section.version = result.data.version;
        sentSinceHeld.delete(uid);
        adoptKeptKey(uid, section, String(sent.key), result.data.key); // M172: as `persistField()`
        return true;
    }

    async function deleteSectionServer(uid: Uid): Promise<boolean> {
        const section = findSection(uid);
        if (!section) return false;
        const result = await guard(() => client.delete(`${base}/sections/${section.id}`));
        if (!result) return false;
        // The FK is ON DELETE SET NULL — its fields become ungrouped (mirror that locally).
        fields.value.forEach((f) => {
            if (f.form_section_id === section.id) f.form_section_id = null;
        });
        sections.value = sections.value.filter((s) => s.uid !== uid);
        if (selection.value?.kind === 'section' && selection.value.uid === uid) selection.value = null;
        forget(uid, 'section', section.id); // M170: a deleted section has no edit left to put back or retry
        return true;
    }

    async function restoreSectionServer(
        uid: Uid,
        snap: SectionSnapshot,
        sequence: number,
        ownedFieldUids: Uid[],
    ): Promise<void> {
        const result = await guard(() => client.post<ServerSection>(`${base}/sections`));
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
            client.post(`${base}/reorder`, {
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
    /**
     * `index` — M176 (`R-5354b4b9`): the place in the section the author asked for ("Add a question here" at a page's end in
     * Preview). The server appends a new question after every other one in the draft, so the store places it and saves the
     * order once, only when that moved anything. Part of the add: one undo entry, and redo restores the order it held.
     */
    function addField(typeValue: string, sectionId: string | null = null, index?: number): Promise<void> {
        return enqueue(async () => {
            const created = await createFieldServer(typeValue, sectionId);
            if (!created) return;
            const local = toLocalField(created);
            fields.value.push(local);
            if (index !== undefined) {
                // The ARRANGEMENT, not the numbers: `placeField()` renumbers every sequence from 0, which is no move.
                const arrangement = () => JSON.stringify(groups.value.map((group) => group.fields.map((f) => f.uid)));
                const before = arrangement();
                placeField(local.uid, sectionId, index);
                if (arrangement() !== before) await persistOrder();
            }
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
            // M172 (the delete half of `R-9ea69e29`): every other question's rule comparing with this one went with it on the
            // server (its foreign key cascades). The screen drops them too — kept, the next save of those questions sent each
            // back comparing with nothing — and the undo entry names them and brings them back.
            let cleared = dropRulesComparingWith(snap.key);
            pushHistory(
                cleared.length === 0 ? `Delete ${snap.label}` : `Delete ${snap.label} and ${ruleCount(cleared)} that compared with it`,
                async () => {
                    await restoreFieldServer(uid, type, sectionId, snap, seq);
                    await restoreRules(cleared);
                },
                async () => {
                    if (await deleteFieldServer(uid)) cleared = dropRulesComparingWith(snap.key);
                },
            );
        });
    }

    type ClearedRules = { uid: Uid; rules: LocalField['validations'] }[];

    /** M172 — take every other question's rule naming `key` off the screen and out of its baseline; the server has. */
    function dropRulesComparingWith(key: string): ClearedRules {
        const cleared: ClearedRules = [];
        for (const other of fields.value) {
            const dropped = other.validations.filter((rule) => rule.related_field_key === key);
            if (dropped.length === 0) continue;
            cleared.push({ uid: other.uid, rules: clone(dropped) });
            other.validations = other.validations.filter((rule) => rule.related_field_key !== key);
            const base = baselines.get(other.uid) as FieldSnapshot | undefined;
            if (base) base.validations = base.validations.filter((rule) => rule.related_field_key !== key);
        }

        return cleared;
    }

    /** M172 — an undone delete puts the rules it took back, and saves each question that held one. */
    async function restoreRules(cleared: ClearedRules): Promise<void> {
        for (const { uid, rules } of cleared) {
            const other = findField(uid);
            if (!other) continue;
            other.validations = [...other.validations, ...clone(rules)];
            if (await persistField(uid)) baselines.set(uid, fieldSnapshot(other));
        }
    }

    function ruleCount(cleared: ClearedRules): string {
        const count = cleared.reduce((sum, entry) => sum + entry.rules.length, 0);

        return count === 1 ? '1 rule' : `${count} rules`;
    }

    /**
     * M172 (`R-a44638b6`, `D133`) — how many responses, in any version, hold an answer to this question: the number the
     * delete dialog states. Zero without asking when the form was never published, since no response can hold one. A
     * READ — no burst bookkeeping, so it never moves the save verdict. Null when it could not be read: the dialog then
     * says answers may exist, rather than the delete going through unexplained.
     */
    async function answersFor(uid: Uid): Promise<number | null> {
        const field = findField(uid);
        if (!field) return 0;
        if (!props.share.is_published && (props.draft?.version_number ?? 1) <= 1) return 0;
        try {
            const result = await client.get<{ responses: number }>(`${base}/fields/${field.id}/answers`);

            return result.conflict ? null : result.data.responses;
        } catch {
            return null;
        }
    }

    function duplicateField(uid: Uid): Promise<void> {
        return enqueue(async () => {
            const field = findField(uid);
            if (!field) return;
            const result = await guard(() => client.post<ServerField>(`${base}/fields/${field.id}/duplicate`));
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
                client.post<ServerField>(`${base}/fields/from-library`, {
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
        // M170 (`R-4d50e3c8`): the server copies the question AS STORED, so an edit still waiting out its debounce is
        // queued ahead of the copy rather than after it.
        flushCommit();
        return enqueue(async () => {
            const field = findField(uid);
            if (!field) return;
            const result = await guard(() =>
                client.post<LibraryItem>(`${base}/fields/${field.id}/save-to-library`, {}),
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
            const result = await guard(() => client.get<LibraryItem[]>(`${base}/library-items`));
            if (!result || result.conflict) return;
            library.value = result.data;
        });
    }

    function addSection(): Promise<void> {
        return enqueue(async () => {
            const result = await guard(() => client.post<ServerSection>(`${base}/sections`));
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

    /**
     * M151 (`R-250b57eb`) — an XLSForm import also numbers each question within its section (`section_sequence`), and
     * the runtime, Print blank and the export order a section by that number before `sequence`. The import assigns both in
     * one pass, so they agree until the builder moves something; a move therefore clears them all, leaving `sequence` —
     * which every reader falls back to — the one order. Clearing only the moved field would compare it on a different scale
     * from its neighbours.
     */
    function forgetSectionPlaces(): void {
        for (const f of fields.value) f.section_sequence = null;
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
        forgetSectionPlaces();
    }

    /**
     * One step up/down through every PLACE a field can sit, crossing section boundaries (local).
     *
     * M139 (`R-c0857303`): the places are each group's slots in visual order — n+1 for a group holding n other fields,
     * so an empty section is one slot — and a step moves to the next one. Stepping past the NEIGHBOURING field, as
     * this did before, could never enter a section with no fields, nor move a form's only field at all, while the
     * pointer could drop into either (WCAG 2.5.7: the keyboard must reach what the drag reaches).
     */
    function stepFieldAcross(uid: Uid, direction: -1 | 1): boolean {
        const field = findField(uid);
        if (!field) return false;

        const slots: Array<{ group: string | null; index: number }> = [];
        let current = -1;
        for (const g of orderedGroupIds()) {
            const members = groups.value.find((group) => (group.section?.id ?? null) === g)?.fields ?? [];
            const others = members.filter((f) => f.uid !== uid);
            if (g === field.form_section_id) {
                current = slots.length + members.findIndex((f) => f.uid === uid);
            }
            for (let index = 0; index <= others.length; index++) slots.push({ group: g, index });
        }

        const next = slots[current + direction];
        if (current < 0 || next === undefined) return false;

        placeField(uid, next.group, next.index);
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
            forgetSectionPlaces();
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
        save.pending = true;
        // M170 (`R-bfa3eb93`): written NOW, not when the debounce runs out — a refresh inside the wait is the case.
        remember(uid, kind);
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
        save.pending = false;
        if (!target) return;
        if (target.kind === 'field') void commitFieldEdit(target.uid);
        else void commitSectionEdit(target.uid);
    }

    /**
     * A label edited in place (M150, `R-34edf1f5`): set once and saved now — one PATCH, one undo entry — through the same
     * commit the settings pane's debounced edits use, so the baseline, a 409 and a refusal behave exactly as they do there.
     * A blank or unchanged label is not an edit, and the server refuses a blank one anyway (`required`).
     */
    function renameField(uid: Uid, label: string): void {
        const field = findField(uid);
        const next = label.trim();
        if (!field || next === '' || next === field.label) return;
        field.label = next;
        touch(uid, 'field');
        flushCommit();
    }

    function commitFieldEdit(uid: Uid): Promise<void> {
        return enqueue(async () => {
            const field = findField(uid);
            if (!field) return;
            const before = baselines.get(uid) as FieldSnapshot | undefined;
            const after = fieldSnapshot(field);
            if (before && snapshotsEqual(before, after)) {
                settled(uid, 'field', field.id, true); // M170: typed back to what the server holds — nothing is owed
                return;
            }
            journal?.markSent('field', field.id, after as unknown as Record<string, unknown>);
            const held = await persistField(uid);
            if (conflict.value) return;
            if (held && keyNotices.value[uid]?.wanted === after.key) after.key = keyNotices.value[uid].kept; // M172: the kept key is what the server holds
            settled(uid, 'field', field.id, held, snapshotsEqual(fieldSnapshot(field), after));
            // M155 (`R-0aa94dce`): the baseline and the undo entry move only once the server holds the edit. A refused
            // one stays on screen, marked, and its baseline stays where the server is — so its next commit sends it
            // again, even unchanged, and undo never replays a step the server never took.
            if (!held) return;
            baselines.set(uid, clone(after));
            if (!before) return;
            pushHistory(
                `Edit ${after.label || 'field'}`,
                async () => {
                    const current = sameTypeField(uid, before, 'undo');
                    if (!current) return;
                    await replayField(current, before, after);
                },
                async () => {
                    const current = sameTypeField(uid, after, 'redo');
                    if (!current) return;
                    await replayField(current, after, before);
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
            if (before && snapshotsEqual(before, after)) {
                settled(uid, 'section', section.id, true);
                return;
            }
            journal?.markSent('section', section.id, after as unknown as Record<string, unknown>);
            const held = await persistSection(uid);
            if (conflict.value) return;
            if (held && keyNotices.value[uid]?.wanted === after.key) after.key = keyNotices.value[uid].kept; // M172: as for a field
            settled(uid, 'section', section.id, held, snapshotsEqual(sectionSnapshot(section), after));
            if (!held) return; // M155: as `commitFieldEdit()` — only an edit the server holds moves the baseline
            baselines.set(uid, clone(after));
            if (!before) return;
            pushHistory(
                `Edit section “${after.label}”`,
                async () => {
                    const current = findSection(uid);
                    if (!current) return;
                    await replaySection(current, before, after);
                },
                async () => {
                    const current = findSection(uid);
                    if (!current) return;
                    await replaySection(current, after, before);
                },
            );
        });
    }

    /**
     * One undo or redo step of an edit (M155, `R-0aa94dce`): show `to`, save it, and move the baseline only once the
     * server holds it. A refusal puts the screen back on `from` — what the server still holds — and throws, so the
     * entry stays on its stack for a retry, as `restoreSnapshot()` does for a type change. A 409 is the conflict
     * dialog's, as it always was.
     */
    async function replayField(current: LocalField, to: FieldSnapshot, from: FieldSnapshot): Promise<void> {
        applyFieldSnapshot(current, to);
        if (!(await persistField(current.uid)) && conflict.value === null) {
            applyFieldSnapshot(current, from);
            delete save.fieldErrors[current.uid]; // the screen is back on what the server holds: nothing on it is refused
            throw new HistoryStepFailed(save.error ?? HISTORY_STEP_FAILED);
        }
        baselines.set(current.uid, clone(to));
    }

    async function replaySection(current: LocalSection, to: SectionSnapshot, from: SectionSnapshot): Promise<void> {
        applySectionSnapshot(current, to);
        if (!(await persistSection(current.uid)) && conflict.value === null) {
            applySectionSnapshot(current, from);
            throw new HistoryStepFailed(save.error ?? HISTORY_STEP_FAILED);
        }
        baselines.set(current.uid, clone(to));
    }

    // ── Conflict resolution (409) — never silently discard either side ──────────
    /**
     * M176 (`R-164579e8`) — a conflict opens the dialog (again, if one was deferred), and its row's journal entry is marked,
     * so a reload raises it again instead of dropping the author's edit for a token that moved to someone else's write.
     */
    function raiseConflict(state: ConflictState, id: string): void {
        conflict.value = state;
        conflictDeferred.value = false;
        journal?.markConflicted(state.kind, id);
    }

    /** The dialog was closed without a choice: nothing is chosen, nothing is forgotten. */
    function deferConflict(): void {
        if (conflict.value !== null) conflictDeferred.value = true;
    }

    /** The builder's notice: open the deferred dialog again. */
    function reviewConflict(): void {
        conflictDeferred.value = false;
    }

    function resolveConflict(choice: 'mine' | 'theirs'): Promise<void> {
        return enqueue(async () => {
            const state = conflict.value;
            conflict.value = null;
            conflictDeferred.value = false;
            if (!state) return;

            if (state.kind === 'field') {
                const field = findField(state.uid);
                if (!field) return;
                if (choice === 'theirs' || refuseMineAcrossTypes(state)) {
                    Object.assign(field, { ...(state.theirs as ServerField), uid: state.uid });
                } else {
                    // Keep my in-flight values; adopt THEIR version token so the overwrite is accepted.
                    Object.assign(field, { ...(state.mine as LocalField), version: (state.theirs as ServerField).version });
                    // M155: a refused "Keep mine" leaves the baseline where the server is, so its next commit sends it again.
                    if (!(await persistField(state.uid))) return;
                }
                baselines.set(state.uid, fieldSnapshot(field));
                forget(state.uid, 'field', field.id); // M170: resolved either way, so nothing is left to put back
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
                    if (!(await persistSection(state.uid))) return; // M155: as the field arm above
                }
                baselines.set(state.uid, sectionSnapshot(section));
                forget(state.uid, 'section', section.id);
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

    // ── M170: an edit is never lost to a refresh, a closed tab or a failed request ─────────────────────────────
    // (`R-bfa3eb93`, `R-fdc743ab`, `R-76474e31`.) Three pieces, each covering what the others cannot:
    //   - the JOURNAL (`edit-journal.ts`) keeps every unconfirmed edit for the tab, and the store puts it back on load —
    //     the only one that makes a plain refresh keep the edit EVERY time, because a reload can be served before any
    //     save sent as the page went has landed;
    //   - `flushForLeave()` sends a waiting edit at once as the page goes — the only one that helps a CLOSED tab;
    //   - `save.unsaved` keeps a failed edit reported, and `retryUnsaved()` sends it again.

    /**
     * Keep the row's current content as the edit the server has not confirmed yet. `confirmed` — a save of this row just
     * landed and the row was edited again meanwhile: the kept edit is now based on the token that save returned, and
     * nothing is on its way.
     */
    function remember(uid: Uid, kind: 'field' | 'section', confirmed = false): void {
        if (journal === null) return;
        const row = kind === 'field' ? findField(uid) : findSection(uid);
        if (!row) return;
        const snapshot = kind === 'field' ? fieldSnapshot(row as LocalField) : sectionSnapshot(row as LocalSection);
        // M172: the key the SERVER holds (the baseline), never one typed into this very edit — it is how the put-back finds
        // the row again in a newer draft, where every id is new and every key is kept.
        const key = (baselines.get(uid) as { key?: string } | undefined)?.key ?? row.key;
        journal.put({
            kind,
            id: row.id,
            key,
            base: row.version,
            snapshot: snapshot as unknown as Record<string, unknown>,
            ...(confirmed ? { sent: [] } : {}),
        });
    }

    /**
     * What one commit's outcome means for the row. `held` — the server has the edit; `current` — nothing was typed into
     * the row since the edit was sent, so the kept copy describes exactly what the server now holds and can go. A
     * failure that left no refusal to mark (network, an ended session, a 500) is recorded as unsaved, so no later burst
     * can report it saved; a refused one is already marked through `fieldErrors`.
     */
    function settled(uid: Uid, kind: 'field' | 'section', id: string, held: boolean, current = true): void {
        if (held) {
            delete save.unsaved[uid];
            if (current) journal?.drop(kind, id);
            else remember(uid, kind, true);
            return;
        }
        if (!(uid in save.fieldErrors)) save.unsaved[uid] = kind;
    }

    function forget(uid: Uid, kind: 'field' | 'section', id: string): void {
        delete save.unsaved[uid];
        sentSinceHeld.delete(uid);
        journal?.drop(kind, id);
    }

    /**
     * The page is going (`beforeunload`, `pagehide`, the tab hidden, an in-app visit away): send a waiting edit now
     * rather than when the debounce runs out. The client marks the request `keepalive`, so it outlives the page.
     */
    function flushForLeave(): void {
        flushCommit();
    }

    /** Send every edit whose save failed again — the alert's Retry, a reconnect, or the tab coming back. */
    function retryUnsaved(): Promise<unknown> {
        flushCommit();
        for (const [uid, kind] of Object.entries(save.unsaved)) {
            if (kind === 'field') void commitFieldEdit(uid);
            else void commitSectionEdit(uid);
        }

        return queue;
    }

    /**
     * On load: put back every kept edit the server does not hold, and save it. An entry is dropped instead when the
     * server already holds its content (the save sent as the page went landed first), or when the row was written
     * since the edit was made by anyone but this tab — the token moved to something this tab never sent — because a
     * put-back would write over that.
     */
    function restoreJournal(): void {
        if (journal === null) return;
        for (const entry of journal.read()) {
            const byId = entry.kind === 'field' ? fields.value.find((f) => f.id === entry.id) : sections.value.find((s) => s.id === entry.id);
            // M172 (`R-81756e95`): an edit made on a page whose draft a publish moved past names an id the new draft does not
            // have. Its row is found again by the key the server held for it — a publish keeps every key.
            const byKey =
                byId === undefined && entry.key !== undefined
                    ? entry.kind === 'field'
                        ? fields.value.find((f) => f.key === entry.key)
                        : sections.value.find((s) => s.key === entry.key)
                    : undefined;
            const row = byId ?? byKey;
            if (!row) {
                journal.drop(entry.kind, entry.id);
                continue;
            }
            const payload = (content: Record<string, unknown>) =>
                entry.kind === 'field' ? fieldPayload({ ...row, ...content } as LocalField) : sectionPayload({ ...row, ...content } as LocalSection);
            const onServer = payload({});
            if (byKey !== undefined) {
                // A NEW draft's row: its token says nothing about this edit, which was made against the old draft and never
                // reached the server — so it is put back unless the row already holds it (the author's edit is kept, as
                // "Keep mine" keeps it). Re-kept under the new id at once, so a second reload still finds it.
                journal.drop(entry.kind, entry.id);
                if (samePayload(payload(entry.snapshot), onServer)) continue;
            } else {
                const ours = row.version === entry.base || (entry.sent ?? []).some((sent) => samePayload(payload(sent), onServer));
                // M176 (`R-164579e8`): a choice the author left open before this reload. The token moved to someone else's
                // write — which would drop the entry below — but the author was promised the edit is not lost: the edit goes
                // back on screen and the dialog opens again. One dialog at a time; another such entry waits in the journal.
                if (entry.conflicted === true && !ours && !samePayload(payload(entry.snapshot), onServer)) {
                    if (conflict.value !== null) continue;
                    const { uid: _uid, ...server } = clone(row) as LocalField & LocalSection;
                    if (entry.kind === 'field') applyFieldSnapshot(row as LocalField, entry.snapshot as unknown as FieldSnapshot);
                    else applySectionSnapshot(row as LocalSection, entry.snapshot as unknown as SectionSnapshot);
                    raiseConflict({ kind: entry.kind, uid: row.uid, mine: clone(row), theirs: server } as ConflictState, row.id);
                    continue;
                }
                if (!ours || samePayload(payload(entry.snapshot), onServer)) {
                    journal.drop(entry.kind, entry.id);
                    continue;
                }
            }
            if (entry.kind === 'field') {
                applyFieldSnapshot(row as LocalField, entry.snapshot as unknown as FieldSnapshot);
                if (byKey !== undefined) remember(row.uid, 'field');
                void commitFieldEdit(row.uid);
            } else {
                applySectionSnapshot(row as LocalSection, entry.snapshot as unknown as SectionSnapshot);
                if (byKey !== undefined) remember(row.uid, 'section');
                void commitSectionEdit(row.uid);
            }
        }
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
        save.failures++;
        save.error = message;
    }

    /** M172 — the page's draft was superseded: one dialog, no further writes, the toolbar never "saved". */
    function noteSuperseded(error: DraftSupersededError): void {
        superseded.value = { draft: error.draft };
        reportFailure(error.message);
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
            const result = await client.get<{ plans: ConversionPlan[] }>(`${base}/fields/${field.id}/conversions`);
            // The GET carries no token and never 409s; the narrowing makes that a fact rather than a hope.
            return result.conflict ? result.message : result.data.plans;
        } catch (error) {
            if (error instanceof DraftSupersededError) {
                noteSuperseded(error);
                return error.message;
            }
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
                result = await client.post<ServerField>(`${base}/fields/${field.id}/convert`, {
                    to: plan.to,
                    version: field.version,
                    fingerprint: plan.fingerprint,
                });
            } catch (error) {
                if (error instanceof DraftSupersededError) {
                    noteSuperseded(error);
                    return { status: 'failed', message: error.message };
                }
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
            client.post<ServerField>(`${base}/fields/${live.id}/convert`, {
                to: target,
                version: live.version,
                fingerprint: plan.fingerprint,
                // M177 (`D145`): reversing a conversion may reach a retired type (Single choice back to Yes/No), which an
                // author may not pick — so the step says it is restoring.
                restore: true,
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
        const result = await guard(() => client.patch<ServerField>(`${base}/fields/${live.id}`, fieldPayload(live)));
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
        const gone = findField(uid);
        if (gone) forget(uid, 'field', gone.id); // M170: a deleted question has no edit left to put back or retry
        fields.value = fields.value.filter((f) => f.uid !== uid);
        baselines.delete(uid);
        if (selection.value?.kind === 'field' && selection.value.uid === uid) selection.value = null;
    }

    // M170 — last, so every `let` the commit path reads (the queue, the debounce) is initialised before it runs.
    restoreJournal();

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
        saveFieldErrors,
        saveRuleRows,
        hasUnsaved,
        conflict,
        conflictDeferred: computed(() => conflictDeferred.value),
        // M172: the form was published since this page loaded (read-only; the page offers the reload)
        superseded: computed(() => superseded.value),
        // M172 (`R-733eff09`): a key the server kept instead of the one typed, per row; and one per failure (`R-d85d51d2`)
        keyNotices: computed(() => keyNotices.value),
        saveFailures: computed(() => save.failures),
        canUndo,
        canRedo,
        palette: props.palette,
        enums: props.enums,
        // M129: the note's Content tab uploads images against the form, and the panel takes no page props.
        formId: props.form.id,
        // M133: the forms a choice question may take its choices from — the Options tab reads it, and takes no page props.
        linkableSources: props.linkable_sources ?? [],
        library,
        librarySaved,
        // actions
        addField,
        deleteField,
        answersFor,
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
        renameField,
        select,
        undo,
        redo,
        resolveConflict,
        deferConflict,
        reviewConflict,
        whenIdle,
        flushForLeave,
        retryUnsaved,
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

/**
 * M170 — whether two saves would write the same row: their payloads, the version token aside. Compared as the PAYLOAD
 * rather than the snapshot because that is all the server ever stores of a save, so a key the page sends and the store
 * never does cannot make two identical edits look different.
 *
 * ⚠️ AND AS THE SERVER WOULD STORE THEM, measured in a real browser: "Allow negative numbers" writes its minimum with no
 * `logic_group` key, which `fieldPayload()` then leaves out ("keep what is stored"), while the server sends the same rule
 * back with `logic_group: null` — so once that toggle was used, no comparison of the question ever matched again. An
 * absent group reads as null, and an index type the server clears when indexing is off reads as cleared.
 */
function samePayload(a: Record<string, unknown>, b: Record<string, unknown>): boolean {
    return JSON.stringify(canonical(asStored(a))) === JSON.stringify(canonical(asStored(b)));
}

function asStored(payload: Record<string, unknown>): Record<string, unknown> {
    const { version: _version, ...rest } = payload;
    if (Array.isArray(rest.validations)) {
        rest.validations = (rest.validations as Record<string, unknown>[]).map((rule) => ({
            ...rule,
            logic_group: rule.logic_group ?? null,
            logic_operator: rule.logic_operator ?? null,
        }));
    }
    if ('is_queryable' in rest && !rest.is_queryable) rest.indexed_data_type = null;

    return rest;
}

/**
 * M170 (`R-7ebfb08e`) — a rule with neither a check nor an expression is a row still being written: switching a rule to
 * Expression makes one, and the server refuses it ("Empty. Choose what it checks, or remove it."). Sent, it refused the
 * WHOLE question — every Basics and Advanced edit with it — until the row was finished. It stays on screen and is sent
 * once it is a rule. M176 (`R-5554c526`): a check chosen and then set back to the empty choice (`''`) is no check either.
 */
function isUnfinishedRule(rule: { rule_type: string | null; expression: string | null }): boolean {
    return (rule.rule_type ?? '') === '' && (rule.expression ?? '').trim() === '';
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
        default_value_is_expression: field.default_value_is_expression,
        is_pii: field.is_pii,
        is_sensitive: field.is_sensitive,
        is_queryable: field.is_queryable,
        indexed_data_type: field.indexed_data_type,
        version: field.version,
        validations: field.validations.filter((v) => !isUnfinishedRule(v)).map((v) => ({
            rule_type: v.rule_type,
            operator: v.operator,
            rule_value: v.rule_value,
            expression: v.expression,
            error_message: v.error_message,
            related_field_key: v.related_field_key,
            // M131: the group keys only when the row carries them — absent tells the server to keep what it stores.
            ...(v.logic_group === undefined ? {} : { logic_group: v.logic_group, logic_operator: v.logic_operator ?? null }),
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
