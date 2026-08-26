# Offline-First Sync Design Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — `docs/architecture/technical-architecture.md` §4.2 already specifies the 3-layer architecture (service worker, IndexedDB/Dexie, Background Sync API), a full sequence diagram (manifest fetch → offline fill → replay → new/duplicate/conflict handling), server-timestamp-authoritative ordering, independent resumable media sync, and two concrete decisions (5-retry-then-banner; UUIDv7/ULID client IDs). **This document does not re-derive any of that** — it specifies the remaining implementation-level detail: the manifest schema, the client-side data model, storage-quota handling, the precise conflict-detection mechanism, and an honest platform-capability caveat that doc doesn't cover.
**Phase**: 2, per `docs/PRD.md` Feature #5.

---

## 1. What's Already Decided (not repeated in full)

Three-layer architecture; the full manifest→fill→replay sequence including its three outcomes (new/duplicate/conflict); `client_submission_uuid` as the idempotency key, checked against `(tenant_id, client_submission_uuid)`; server timestamp authoritative, client timestamp informational only; last-write-wins as the default conflict policy with a `409` for genuine conflicts; independent, resumable media-attachment sync; the 5-retry "needs attention" banner; time-sortable client-generated IDs. See `docs/architecture/technical-architecture.md` §4.2 directly.

---

## 2. Manifest Schema

`GET /api/v1/sync/manifest?form_version_id=...` (`docs/api-specification.md`'s resource inventory) returns:

```json
{
  "form_version_id": "0190...",
  "checksum": "sha256:...",
  "schema_snapshot": { /* per docs/data-dictionary.md §3 — sections, fields, validations, expressions */ },
  "choice_lists": { "list_name": [ { "value": "...", "label": "..." } ] },
  "media_refs": [ { "field_key": "...", "url": "...", "checksum": "..." } ],
  "manifest_generated_at": "2026-07-03T12:00:00Z"
}
```

- `checksum` is the same value `docs/form-versioning-schema-migration.md` §3.2 already computes over the canonical `schema_snapshot` serialization — the client compares this against its cached copy's checksum before deciding whether to re-download the full manifest, avoiding unnecessary transfer on a poor connection.
- `media_refs` covers static, form-level media (e.g., an image embedded in a field's hint/label) that must be cached alongside the schema for genuinely offline rendering — distinct from *respondent-submitted* media (§5), which flows the opposite direction.

---

## 2A. Who May Reach Either Sync Endpoint (as-built, Increment M13)

The two Group-B sync routes are the only ones in `/api/v1` that take their resource from the **body or the
query** rather than the URL. `routes/api.php` has stated since G10b that a resource-bound Group-B route
carries an `ability:` gate *and* a `can:` policy gate on the bound resource — and because neither of these
is resource-bound, there was nothing for `can:` to attach to and that rule silently did not reach them.
Until M13 the whole of their authorization was the token ability plus RLS.

**An ability scopes the TOKEN and RLS scopes the TENANT. Neither answers "may this member touch this
form."** So:

| Endpoint | Was | Is (M13) |
|---|---|---|
| `GET /api/v1/sync/manifest` | `ability:read:forms` + `feature:offline_sync` + RLS. Returned the **complete** `schema_snapshot` — every section, field, label, hint, choice, validation and expression — for **any** published or superseded version in the tenant. | Adds an in-controller `FormPolicy::view` on the version's form, which is what `GET /forms/{form}/versions/{version}` already required for the identical payload. |
| `POST /api/v1/sync/submissions` | `ability:write:submissions` + RLS. Created submissions against **any** form in the tenant. | Adds an in-controller `SubmissionPolicy::create` **per item**, which is what `can:create,Submission,form` already required on the web encode route. |

**The exposed principals are not the ones a first reading suggests.** `write:submissions` maps to the
`submissions.create` permission, so a **Viewer can never mint that token at all** — `ApiAbilities::intersect()`
drops the ability at issue time. The two who could are a **Form Editor** (widened from their granted forms
to all of them) and, more sharply, a **Reviewer**: they hold `submissions.create`, but `SubmissionPolicy::create`
additionally requires `forms.edit.any` or EDITOR capacity — the deliberate G10a tightening — and a reviewer's
grant is reviewer capacity. **A Reviewer was authorized to encode on zero forms through the web app and
reached every form through this route.** On the read side `read:forms` maps to `forms.create` /
`forms.edit.any` / `forms.edit.own`, so the exposed principal there is a Form Editor reading every other
form's authored schema. RLS bounded all of it to the tenant and no further; nothing crossed a tenant.

**Consequences of a replay that lands where it should not:** it consumes that form's purchased
`max_responses` slot, fires its notifications and its webhooks, and appears in its inbox — so this was a
write with side effects, not a scoping tidiness question.

**No first-party client is affected.** The guest PWA replays through the public guest endpoints, not this
surface, which the controller has always described as being "for future authenticated encoder clients +
integrators". What changes is what an integrator's token can reach.

⚠️ **One asymmetry is left standing deliberately and is filed rather than fixed:** the read is gated on
form-**authoring** permissions (`read:forms`) while the write is gated on `submissions.create`, so a
Reviewer can replay a batch but can never fetch a manifest to collect against. The offline-encoder story
therefore only works today for Owner, Admin and Form Editor. Widening an ability map is an authorization
decision, not a defect fix.

### The batch contract is now what it always claimed to be

`SyncSubmissionController::replayOne()`'s docblock said *"Never throws: a failure is reported inline so it
cannot abort the rest of the batch"*, and that was an intention read afterwards as a measurement. **Three
outcomes escaped it**, each aborting the whole request after earlier items had already committed — so the
client received a bare 4xx and no way to know which of its rows had landed:

1. **An unauthorized form** — there was no gate at all.
2. **A soft-deleted form.** `forms` soft-deletes and `form_versions` does not, and neither RLS policy
   filters on `deleted_at`, so a deleted form's versions stay fully resolvable and outlive it. The
   pipeline's own `Form::findOrFail()` then raised a `ModelNotFoundException` nothing caught → a top-level
   **404**. Worse than a lost response: the offending item re-raised on every retry, so one row stalled a
   device's outbox permanently. Now a per-item `form_not_found`.
3. **A closed, not-yet-open, or full form.** `FormNotAcceptingSubmissionException` is a **sibling** of
   `SubmissionException` rather than a subclass — every exception in `app/Exceptions/Submissions` is
   `final class … extends RuntimeException` — so the `SubmissionException` catch arm never saw it and it
   rendered as a top-level **403**. A device that collected for a month and replayed after the form closed
   lost every item's result, which is the exact inverse of the never-block posture in §1. Now per-item
   `form_not_open` / `form_closed` / `max_responses_reached`, each carrying the same `details` payload
   (schedule boundary or cap figures) the guest SPA already renders for those causes.

Refusals are reported with the codes the platform already uses: `forbidden` is what `bootstrap/app.php`
returns for an authorization failure on `/api/v1`, so it reads the same at item level and envelope level,
and `insufficient_ability` stays reserved for the token-scope 403, which is a different refusal about a
different subject.

⚠️ **`openapi.json` moved, and not on the axis that was predicted.** Adding these gates was expected to
leave the contract byte-identical, because `POST /form-templates` documents only `200`/`422` while carrying
the identical in-controller `Gate::forUser()->authorize()` — Scramble infers a 403 from route *middleware*,
not from a controller call. It moved anyway: the manifest route gained a **`404`**, because the new
`Form::findOrFail()` is a shape Scramble traces where the pre-existing `firstOrFail()` on the version query
is not. That 404 has been a real response since G8b and `SyncApiTest` has asserted it since G8b; the
document simply never said so. The **403** both routes can now return is still undocumented, and is filed
on the backlog beside the identical open row for the promote endpoint's 409.

---

## 3. Client-Side Data Model (IndexedDB via Dexie)

Five Dexie tables, each namespaced by `form_version_id` where relevant so multiple cached forms/versions coexist on one device without collision:

| Table | Key | Purpose |
|---|---|---|
| `cached_manifests` | `form_version_id` | The manifest response (§2), keyed so a device can hold several forms' schemas simultaneously (a field enumerator often collects for more than one active form). |
| `draft_answers` | `(form_version_id, local_draft_id)` | In-progress, not-yet-finalized answers — autosaved continuously while filling, per the existing sequence diagram's "save draft answers per field (autosave)" step. **(M21)** Rows carry an un-indexed `respondent_session_id`, and both readers refuse a row the current visit did not write; the key is deliberately unchanged, because Dexie **throws** on a primary-key change (`dexie.js:3832`) and because the shared key is what collects an abandoned row. **(M22)** The seven-day expiry is now SWEPT by `lib/reap.ts` over `updated_at`'s v1 index, not only checked by whichever reader happens to hold the key — before that, a republish moved `form_version_id` and the pre-republish row could never be read or deleted again by anything. |
| `outbox` | `client_submission_uuid` | Finalized submissions queued for replay — `status: pending / synced / conflict`, matching the sequence diagram's three outcomes. |
| `media_queue` | `attachment_local_id` | Queued respondent-submitted media awaiting the independent resumable upload (§5), referencing its parent `outbox` row by `client_submission_uuid`. **(M22)** A blob picked mid-fill has `client_submission_uuid: null` until finalize, and **IndexedDB does not index null** — so an abandoned pick was outside the one index that names the field and neither uuid-keyed deleter could reach it. `lib/reap.ts` collects by REACHABILITY instead: a null-uuid row no answer document still names, past a one-hour in-flight grace, is deleted. |
| `app_state` | `key` | **(H23b, schema v2)** Device-scoped scalars belonging to no form. One key today — `brand_version`, the tenant-ramp fingerprint the cached guest shells were last refreshed for (§4.1). |

**Collection**: every store on the device now has something that collects it — `outbox` has `pruneSynced()` (I10d/M15), and from **M22** `draft_answers` and `media_queue` have `lib/reap.ts`, called from `useSyncOutbox.refresh()` (boot, with a tab) and from `replay.ts`'s drain (Background Sync, without one), exactly where `pruneSynced` is called. ⛔ **It tests reachability, never ownership**: `sw.ts`'s graph is re-checked under `tsconfig.sw.json` with `types: []`, so a module it can reach has no `sessionStorage` and cannot know whose visit anything is. Nothing UNSENT is ever reaped, at any age — the sweeper reads `outbox` only to learn what to spare.

**Schema versioning**: Dexie's own native schema-migration mechanism (`db.version(N).stores({...})`) handles client-side schema evolution across app updates — flagged here only so it isn't assumed to need a bespoke migration system; Dexie already provides one. The line worth knowing in practice: **a new STORE requires a version bump, an un-indexed field on an existing row shape does not** — G8c added `outbox.conflict_code` at v1, H23b's `app_state` took the schema to v2, and both were the correct call.

---

## 4. Cache Invalidation & Re-Fetch Triggers

The manifest is re-fetched (checksum-compared, §2) in three situations, not just "periodically":
1. **On app open/foreground** — the always-present baseline check.
2. **On a Reverb "sync-ready" push** (already named as existing in the C4 Container diagram's `PUBLICPWA <--> Reverb` connection, not previously detailed) — when a tenant publishes a new version of a form a device has cached, the server pushes a lightweight `form_version.published`-triggered notification over the same private/presence channel pattern ADR-0002 already establishes for tenant-scoped Reverb channels; a connected, currently-online client can proactively re-check rather than waiting for its next foreground event. **This is a convenience, not a correctness requirement** — the checksum-based cache-busting (§2) is what actually keeps a device correct even if it never receives the push (e.g., it was offline when the publish happened).
3. **Never mid-collection** — once a respondent has started filling against a specific `form_version_id`, that device continues against that exact version until the current submission is finalized, even if a newer version becomes available in the interim (this is `docs/architecture/technical-architecture.md` §6.2's "no mid-collection schema surprise" guarantee, restated here as a client-side rule: the manifest re-check happens at natural boundaries — app open, between submissions — never interrupting an in-progress fill).

### 4.1 Tenant branding — RE-PRIME, never purge (Increment H23b)

The tenant's brand ramp (ADR-0014) reaches the guest runtime as an inline `<style>` block in the shell HTML, which the service worker caches `NetworkFirst` under `guest-shell-html`. That makes the page a respondent is *currently* looking at self-healing: any successful online navigation replaces the entry with a freshly-branded copy, so no invalidation logic is needed for it.

What does not self-heal is every **other** `/f/…` shell the device cached earlier — a second form, an emailed resume link. Those keep rendering the superseded brand offline until their 7-day expiry, because they are only rewritten when the respondent happens to navigate to them online, which on a field device may be never.

**The obvious fix — `caches.delete('guest-shell-html')` — is the wrong one, and the reasoning is the design.** A stale brand is cosmetic; a purged shell costs a fieldworker offline access to a form they deliberately primed, which is a core promise of this document. So the stale entries are **refreshed** and the cache is never emptied:

1. The shell embeds `data-brand-version`, a 12-hex fingerprint of the rendered ramp (`'none'` when unbranded), derived only from the ramp hexes — no clock, no row id, so it is stable across renders and across web nodes.
2. After mount, the SPA compares it with `app_state['brand_version']`. Equal ⇒ do nothing, which is the overwhelmingly common path.
3. Different **and offline** ⇒ **defer**: change nothing, including the stored fingerprint. Advancing it without having refreshed anything would be a lie that permanently suppresses the retry, since every later boot would compare equal and skip.
4. Different **and online** ⇒ re-`fetch` every cached shell URL except the current one and `cache.put` **only on a 200** (writing a 404 or a 500 would replace a working offline shell with an error page — strictly worse than the stale colour), then store the new fingerprint. One dead URL never aborts the sweep for the others.

The per-form web manifest carries the same fingerprint as a `?b=` query parameter, because the service worker does not cache that URL at all and the browser caches it aggressively — moving the URL is the only lever available. It is safe for app identity: the manifest declares an explicit `id`, so the manifest URL is not what pins an installed app.

`guest-schema` and `guest-shell-assets` are deliberately untouched: neither carries brand.

---

## 5. Precise Conflict-Detection Mechanism

`docs/architecture/technical-architecture.md` §4.2's sequence diagram distinguishes "duplicate replay" (200, idempotent no-op) from "genuine concurrent-edit conflict" (409) but doesn't specify how the server tells them apart beyond "materially different answers." **Concretely**: on a replay against an already-`(tenant_id, client_submission_uuid)`-matched row, the server computes a checksum over the incoming `answers` payload (the same canonical-serialization approach `docs/form-versioning-schema-migration.md` §3.2 already uses for `schema_snapshot`) and compares it against a checksum stored alongside the original persisted submission:
- **Checksums match** → byte-identical resubmission (e.g., the client never received the original `201`/`200` acknowledgment and is safely retrying) → `200 OK`, idempotent no-op.
- **Checksums differ** → the same `client_submission_uuid` now carries different content than what was already persisted, meaning the local draft was edited *after* an earlier sync already succeeded (or is racing a concurrent edit from another channel, e.g., an authenticated encoder correcting the same record) → `409 Conflict`, surfaced for manual merge review per the existing sequence diagram.

**As-built (Increment G8c).** Implemented exactly as above: `submission_answers.answers_content_checksum` (data-dictionary §8) stores `AnswersContentChecksum::of()` — a SHA-256 over the canonically-serialized, structurally-normalized answers — set in `SubmissionPipeline::persist()`. The pipeline's idempotency path (both the pre-check and the `23505` race catch) compares it against the incoming replay's checksum: match (or a `NULL` legacy checksum) → the existing idempotent `200` no-op; differ → `SubmissionConflictException` → **`409 submission_conflict`** (a distinct code from the version-drift `409 form_updated` / `submission_version_superseded`). The guest PWA parks the row (`outbox.status = 'conflict'`, tagged with the 409 `conflict_code`) and resolves it through the same review-and-resubmit UX as a version drift.

**⚠️ THE UNIQUENESS DOMAIN AND THE RESOLVE SCOPE ARE NOT THE SAME SET, AND THE GAP BETWEEN THEM IS A REFUSAL — NOT A RESOLVE (Increment M11).** Everything above says "an already-`(tenant_id, client_submission_uuid)`-matched row", and that phrasing is right about the INDEX and wrong about the LOOKUP. The index is tenant-wide because a uuid must be unique tenant-wide; the scope a caller is entitled to resolve within is narrower — **one form and one author** — because on every channel the form and the uuid arrive as two independent, caller-influenced inputs (the guest's form comes from the share token and the uuid from the body; the encode page's form from the URL; `/api/v1/sync`'s version from the item). Resolving on the uuid alone therefore hands a caller entitled to form A a row from form B: its `id`, `reference` and `status` back as an idempotent 200, and — on the encode channel, whose race backstop promotes whatever Stage 2b returned — a **finalization** of a draft on a form the caller may hold no grant on. As built, one `App\Services\Submissions\ClientUuidResolver::resolve()` scopes every channel to `(tenant_id, form_id, respondent, uuid)`; a uuid that exists in the tenant but resolves to nothing under that scope raises the same `409 submission_conflict` with a distinct message (*"This submission identifier already belongs to another response."*), rather than falling through to an insert whose `23505` no recovery arm could classify — which, before M11, was a repeatable **unauthenticated 500** on both public guest routes. The existence probe reads `withTrashed()`, because the partial unique index filters on `client_submission_uuid IS NOT NULL` and **not** on `deleted_at IS NULL`, so a soft-delete tombstone still owns the uuid — the same fact `ReapTenantDraftsJob` hard-deletes for.

**✅ AND SINCE INCREMENT M14 THE TWO CAUSES NO LONGER SHARE ONE CODE — `clientUuidClaimed()` CARRIES `409 submission_uuid_claimed`.** M11 deliberately kept them sharing `submission_conflict` and wrote its reason down: *"the guest runtime folds all 409s alike today (its own filed row), so a new code would buy nothing and cost a contract."* M14 **is** that row — the guest runtime now reads `error.code` on every 409 and picks a remedy from it — so the premise is gone and the conclusion goes with it. The two causes are different refusals with different remedies (*review the copy that already exists* versus *mint a fresh identifier; the answers were never in dispute*), and sharing a code obliged every client to tell them apart by string-matching a human-facing sentence, which this document and the exception both name as the trap that makes a test pass for the wrong cause. **The clearest evidence is the draft channel**: `contentConflict()` is suspended for drafts, so on `POST /api/v1/public/f/{shareToken}/draft` the shared code could only ever have meant the entitlement cause — and no client had any way to know that. Four causes, four codes; the message stays asserted everywhere because it is the half a person reads.

**Narrowing (no server-side merge).** The 409 body does **not** return the server's already-persisted answers, so there is no true side-by-side merge of local-vs-server content. Resolution re-opens the current schema pre-filled with the respondent's **local** copy for review; resubmitting records it as a fresh submission (a new `client_submission_uuid`) — the server's existing copy is left intact for a human to reconcile in the inbox. A true two-way merge (returning the conflicting server answers in the 409) is deferred. In practice, because a queued outbox row's answers are immutable, the guest replay path can only reach this 409 via a genuinely concurrent write from another channel; its everyday effect is to make idempotent replays *content-aware* (provably safe no-ops).

---

## 6. Storage Quota Management

Not addressed by any prior doc: browser-enforced IndexedDB storage quotas are a real constraint for a field-collection tool that may accumulate many queued submissions plus media (photos/audio/video) while offline for extended periods (the exact use case this feature targets — NGO/research enumerators in low-connectivity areas).

- The client monitors its estimated storage usage (the `navigator.storage.estimate()` API, where supported) and surfaces a **proactive warning** (not a silent failure) once usage crosses a threshold (e.g., 80% of the browser-granted quota) — "you have N submissions queued and using X MB; sync soon to free up space," rather than discovering the problem only when a write fails.
- **Media is prioritized for eviction pressure over structured answers**: if storage genuinely fills before the device can sync, the system must never silently drop a queued *submission* (the structured answers are the actual survey data and are small); large media attachments are the appropriate thing to flag as "sync required before this device can safely collect more media-heavy responses" — a policy choice consistent with §5 of the architecture doc's own framing that media sync is secondary/independent of the higher-priority structured-answer replay.
- This is a genuinely new addition this document introduces, since neither the architecture plan nor the Technical Architecture Doc addresses storage-quota exhaustion — flagged as a real Phase 2 implementation requirement, not an edge case to discover in the field.

---

## 7. Honest Platform-Capability Caveat: iOS Safari & Background Sync

`docs/non-functional-requirements.md` §7 commits to PWA installability on "the latest 2 major versions of iOS Safari" — but the Background Sync API `docs/architecture/technical-architecture.md` §4.2 relies on for automatic reconnect-triggered replay has **historically had weak or absent support in iOS Safari**, unlike Chrome/Android where it's well-supported. This is a real platform gap this document surfaces rather than silently assumes away:

- **Fallback for iOS**: rather than relying solely on the Background Sync API firing automatically on reconnect, the client also attempts a sync pass **on every app foreground/resume event** (already one of §4's cache-invalidation triggers, reused here for the outbox-replay case too) and offers a manual **"Sync now"** action always visible in the app's status area — so an iOS user isn't dependent on a background capability their platform may not reliably provide.
- **This is a documented, accepted platform limitation, not a design flaw in this system** — it reflects a real, external constraint (browser vendor feature support) that this document surfaces explicitly rather than promising uniform behavior across platforms that don't actually provide it. Verify current iOS Safari Background Sync support at implementation time, consistent with this project's general "verify version-specific behavior before relying on it" discipline (e.g., ADR-0002's own such caveat) — browser capability support is not static.

---

## 8. Multi-Device Behaviour

> ⚠️ **AMENDED IN INCREMENT P3a (2026-08-17) — THIS SECTION WAS FALSE AS BUILT, AND IT IS THE SECTION §9's CRDT DEFERRAL RESTS ON.** It is corrected in place rather than rewritten, because the *reasoning* it recorded is still right and only its factual premise stopped being true. The original text is kept below the line so the change is legible.
>
> **What changed, and it was H9b/H10 rather than anything in this document.** Save-and-resume shipped a **resume link** (`GuestDraftResumeController`, emailed by `SaveForLater.vue`, whose respondent-facing copy reads *"it works on any device"*). That link hands a second device the **same `client_submission_uuid`** — `useFormRuntime.ts:296` seeds the resumed session from the server draft's uuid on purpose, so the eventual submit **promotes** that row instead of creating a duplicate. And `resources/public-runtime/lib/reconcile.ts` is a precedence rule between the server tier and a device's local Dexie draft, locked with the user on 2026-07-23 as *"newest-wins, non-destructive"*.
>
> So both of the original claims are now wrong: there **is** a handoff, there **is** a merge rule, and a second device continues the **same** logical submission rather than starting an independent one. What remains true is the narrow part: **each device still has its own IndexedDB, and nothing syncs between them live.** Handoff happens at the resume link, not continuously.
>
> **The consequence this document owes the reader is that one draft can now have two writers**, which is the case §9's deferral assumed did not arise. That is a *lost-update* hazard, not a merge one, and P3a closes it: every save on both draft channels carries the `answers_content_checksum` it was based on, and the server refuses a save whose base has moved (**`409 draft_conflict`**, `SubmissionDraftService::updateDraft()`). ⚠️ **This is deliberately NOT the content-409 that §5 describes and `saveDraft()` suspends** — that one compares the *incoming* answers against the stored ones and would refuse every keystroke of an ordinary autosave. The lost-update guard compares the *base* against the stored value, so a same-device autosave never sees it. The suspension is narrowed, not reversed. The identical guard has protected the authenticated **edit** channel since I9c (`SubmissionAnswerEditService::edit()`); P3a applies it to the channel it was never applied to.
>
> ⚠️ **AMENDED AGAIN IN INCREMENT M12 (2026-08-25) — THE PARAGRAPH ABOVE NAMED ONE WRITE DOOR AND THERE ARE TWO, SO "P3a closes it" WAS TRUE OF THE SAVE AND NOT OF THE DOCUMENT.** A draft's answer document is replaced wholesale by exactly two writers: `SubmissionDraftService::updateDraft()`, which P3a guarded, and `SubmissionFinalizer::finalize()`, reached through `SubmissionDraftService::promote()`, which it did not. **`promote()` read the answer document outside any transaction, ran the full Stage 3 and the DB attachment check over that copy for tens of milliseconds, took the row lock only afterwards, and then finalized with the PRE-LOCK values** — so a save committing inside that window was reverted, and the row was `submitted` afterwards, which is the one loss no later save can undo. The only in-lock guard was a status re-assert, and a concurrent autosave does not move the status.
>
> **Why P3a's guard could not see it, stated rather than left as an oversight.** That guard compares the base a SAVING DEVICE carries; a promotion carries none — the encode and guest channels send answers but no promote-time baseline, and `/api/v1/submissions/{submission}/promote` sends only the submission id. So the comparison M12 adds is the OTHER one the sibling edit channel has held since I9c: the document's checksum is captured from the same row read as the answers and **re-compared under the lock**, unconditionally, because it compares two SERVER reads inside one request rather than a client's claim. `SubmissionAnswerEditService::edit()` holds both checks because its read precedes its lock; `updateDraft()` holds only the client one because its read follows its lock; `promote()` now holds only the server one, for the same reason inverted. **Each of the three has exactly the check its own read/lock ordering makes authoritative.**
>
> ⚠️ **THE DAMAGE WAS NEVER ONLY A MISSING ANSWER, WHICH IS WHY THIS SECTION RATHER THAN A CHANGELOG RECORDS IT.** `FinalizedStatus::for()` is handed promote's pre-lock `SemanticResult`, and its own docblock demands *"this finalize's OWN Stage-3 result — never a cached or borrowed one"*: a racing save that routes the respondent into a section is the difference between `submitted` and `screened_out`, and therefore between consuming a purchased `max_responses` slot and not. `attachment_refs` and the attachment ownership re-point derive from the same pre-lock document, so a file the second device uploaded stayed owned by its `form_field` and unreferenced by the finalized submission.
>
> **The refusal reuses `409 draft_conflict` unchanged — one code and one sentence for both doors.** The cause a client is told is the same (*another device wrote to this draft*) and so is the remedy (*re-read the draft, keep the uuid*), so no client contract moved and `openapi.json` stayed byte-identical. ⚠️ **What is still true is the shape of the answer: refuse, never merge.** Merging two divergent copies is the CRDT work §9 defers; refusing costs one device a reload and costs the other nothing, because its save is already committed.
>
> ⚠️ **AMENDED IN INCREMENT M14 (2026-08-25) — THE SERVER HAD BEEN NAMING THE REMEDY SINCE P3a AND THE GUEST CLIENT HAD NEVER READ IT.** Everything above describes a refusal whose whole point is that it tells the saving device to re-read the draft. `resources/public-runtime/lib/error-normalizer.ts` classified a 409 by STATUS ALONE and returned one kind, `refresh`, meaning *"the form was republished — re-mint and re-fetch the schema."* So the sentence a respondent actually saw for a `draft_conflict` was **"This form was updated"** — false, since nothing had been republished — and the recovery it triggered re-fetched the schema, remounted under a **fresh `client_submission_uuid`** and reseeded the baseline to **null**. The device abandoned the server draft it was editing mid-edit, and the resubmit it invited carried no baseline at all: the guard fired, and the client responded by removing the only input that lets the guard fire again.
>
> **The same fold made the deliberate save silent.** On the "Save and finish later" channel — where `GuestDraftController` sets `checkBaseline: true` unconditionally, so this 409 is reachable on *every* save — the refusal returned `null` into `SaveForLater.vue`, which read it as "the session is remounting" and simply closed the panel. **No save, no resume link, no error.** The remount then minted a *second* server draft with a *second* resume link, emailed to the same respondent.
>
> **As built.** The classifier reads `error.code` for a 409 exactly as it has read it for a 403 since I8b, and four causes now carry four `ErrorKind`s: `draft_stale` (re-read the draft, **keep the uuid**), `conflict`, `uuid_claimed` and `finalized`. `refresh` keeps its original meaning and remains the default for an unrecognised or bodyless 409, which is the safe direction. `draft_stale` routes through `App.vue`'s existing `loadResume()` — the H10 machinery that already preserves the draft's uuid, reconciles the two answer tiers under the newest-wins precedence, and always takes the **server's** checksum as the new baseline — so the recovery this document has described since P3a is finally the one the client performs. `lib/replay.ts`'s outcome table became a `Record<ErrorKind, …>` in the same change: the background replay cannot re-read a draft (it holds no resume token, and writing one to disk would put a live credential on the shared-kiosk hardware `outbox.ts` names as the threat), so it parks the row for review — and the `Record` makes it a **compile error** for a future kind to fall through to the retry arm and re-POST a baseline the server has already refused.
>
> **Still out of scope, and now for a sharper reason:** there is no automatic *merge* of two devices' divergent answers. The refusal names reloading, and reloading is what works — the second device re-reads the draft and continues from the fresh base. Merging divergent copies is the CRDT work §9 defers.

*Original text, superseded above:*

> If the same respondent/enumerator switches between two physical devices mid-collection (e.g., a phone dies, they continue on a tablet), **each device has its own independent IndexedDB — there is no live merge or handoff between them.** The "dominant single-device-per-respondent case" assumption `docs/architecture/technical-architecture.md` §4.2 already states for its last-write-wins policy means: starting the same logical submission on a second device produces a **second, independent** `client_submission_uuid`, not a continuation of the first device's draft. This is an accepted scope boundary (matching the plan's explicit CRDT deferral "unless concurrent multi-device editing proves common in practice"), stated here plainly so it isn't discovered as a surprise in the field rather than a documented limitation.

> ⚠️ **INCREMENT M15 (2026-08-26) — THE ASSUMPTION ABOVE WAS LOAD-BEARING FOR SOMETHING IT NEVER CLAIMED TO
> COVER, AND IS NOT ANY MORE.** *"The dominant single-device-per-respondent case"* is a statement about ONE
> RESPONDENT AND TWO DEVICES — a phone dying mid-collection — and it is still exactly right about that. It
> was silently doing a second job it was never written for: the client outbox surface read it as licence to
> treat everything on a device as belonging to one person, and that surface mounts **above the phase machine
> on an unauthenticated page**. On the shared kiosk hardware §3's own module docblock names as the threat,
> the case is **TWO RESPONDENTS AND ONE DEVICE**, which is the exact inverse and which no document here had
> ever addressed.
>
> Rows now carry `respondent_session_id` — an un-indexed field, so no Dexie `version()` bump, the same
> precedent `conflict_code` and `server_reference` set. Every read that **discloses or destroys** is scoped
> to the current visit; the **drain is deliberately not**, because `lib/replay.ts` and `sw.ts` call
> `listPending`/`replayOutbox` with no session (a service worker has none) and scoping it would strand an
> earlier respondent's response forever. See `docs/adr/0021-respondent-scoped-device-outbox.md`.
>
> Nothing above changes: two devices still produce two independent `client_submission_uuid`s, and the
> last-write-wins policy is untouched.

> ⚠️ **INCREMENT M21 (2026-08-26) — M15 SCOPED THE OUTBOX AND LEFT THE LARGER CHANNEL OPEN, AND THE
> DEFERRAL WAS RECORDED WHERE THE DOCUMENTS DEFINING THE CONCEPT COULD NOT SEE IT.** M15's own sweep found
> that `draft_answers` had the identical shared-device shape — keyed `(form_version_id, local_draft_id)`
> with no visit in it — and filed it in `docs/feature-backlog.md` and in the *Residual* clause of the
> threat model's M15 row, **and in no ADR and not here**. So the paragraph above, which defines what "a
> visit" means for device-local state, gave a reader no way to learn that the channel carrying **answers a
> respondent is still typing** had been considered and left live. **A deferral recorded only in a backlog
> row is invisible to the document that defines the concept it defers.**
>
> **As built:** `DraftRow` carries `respondent_session_id`, un-indexed, so **no `db.version()` bump** — the
> rule §3 states outright and that `conflict_code`, `server_reference`, `synced_at`,
> `base_content_checksum` and M15's own stamp have all set. Both readers of the table — the autosave
> restore and `App.vue`'s resume read — consult **one shared predicate**, because two readers of one table
> disagreeing about ownership is exactly how the sibling defect in `reconcile.ts` came to exist. Null
> still means *an earlier visit*; `undefined` still means *do not scope*.
>
> ⛔ **THE VISIT IS DELIBERATELY NOT IN THE PRIMARY KEY, AND THE FIRST REASON IS MEASURED:** Dexie throws
> `Upgrade('Not yet support for changing primary key')` (`node_modules/dexie/dist/dexie.js:3832`), so a
> three-part key does not degrade — **the database fails to open on every device that already has one**,
> taking the outbox and the queued media with it. The second reason is that the shared key is what
> **collects** an abandoned row: the next respondent's first keystroke overwrites it. Containment and
> collection are the same mechanism, which is also why nothing is deleted on a read.
>
> ⚠️ **AND THE HARM CROSSED INTO THIS DOCUMENT'S OWN SUBJECT, WHICH IS WHY IT IS RECORDED HERE RATHER THAN
> ONLY IN THE ADR.** A restored stranger's draft carried a **fresh** `client_submission_uuid` and a **null**
> baseline, so the guest draft POST wrote those answers as a **new server draft** under the next
> respondent's identity and emailed them a resume link to it. On the RESUME path it was worse: the seed
> keeps the **server's** uuid and the **server's** `content_checksum` (deliberately, per P3a) while taking
> the **local** tier's answers, so a stranger's draft winning §5's newest-wins precedence was written over
> the resuming respondent's own server record and **passed P3a's lost-update guard by construction** —
> the guard cannot fire, because the baseline genuinely is theirs. **P3a and M12 close the two-device
> lost-update door; M21 closes the two-RESPONDENT one, which neither had considered.**
>
> ⛔ **A CORRECTION TO THE VISIT BOUNDARY ITSELF, BECAUSE SCOPING THE DRAFT MADE A WRONG BOUNDARY INTO LOST
> WORK.** M15's ten-minute window was documented as **idle** time and was in fact **elapsed-since-boot**:
> the visit id had exactly one production call site, at the composition root, once per page load, so
> `lastSeen` recorded boot time. Live consequence before M21: a reload more than ten minutes after boot —
> including the app's own reload after a conflict discard — issued a fresh visit, and `pruneSynced()`
> deleted the respondent's **own** delivered receipts as a stranger's. After M21 it would additionally have
> thrown away their own half-filled form. `touchRespondentSession()` now refreshes the stamp on real
> respondent input — never minting, never reviving an expired visit — so the window is idle time as
> documented. **A containment change inherits every defect in the boundary it starts trusting.**

---

## 9. Out of Scope / Deferred

- **CRDT-based multi-device merge — DEFERRED, and the trigger is an input this project cannot generate.** Restated in Increment P3a (2026-08-17) because "revisit only if real usage data shows it's needed" reads like a scheduled follow-up and is not one. The deferral is on the record in **five** documents, not this one alone: `docs/PRD.md` §430/§441/§505 (which calls it *"not committed by default"* and files the question under **Open Questions**), `docs/architecture/technical-architecture.md:313`, `docs/form-versioning-schema-migration.md:179`, `docs/ux/form-filling-ux-flow.md:188`, and here. **The revisit trigger is *real usage data showing concurrent multi-device editing is common*** — nothing is deployed, so no such data exists or can be produced from inside the project. Structurally the same shape as data residency (`docs/adr/0017-tenant-isolation-tiering.md` §D6, whose trigger is "a second hosting region exists"): a decision waiting on an external input, not an unstarted task. ⚠️ **The roadmap row for this is worded "CRDT sync *if needed*" in `PROGRESS_ARCHIVE.md:207`, and later copies dropped the qualifier** — plan against the qualified original.
  **What P3a built INSTEAD, and why it is not a substitute:** verifying this row found §8's premise false as built (above), so one draft can now have two writers. That is a **lost-update** hazard — one device silently overwriting answers it never read — and it is closed by the baseline guard, not by a CRDT. A CRDT would additionally **merge** two divergent copies without refusing either; P3a refuses, names reloading as the remedy, and leaves both copies intact. Those are different problems, and only the first one is reachable today.
- Partial-submission save-and-resume UX detail (the feature exists per `docs/PRD.md` Feature #7, Phase 3 scope) → Doc #20 (Form-Filling UX Flow Spec §5.2) owns the UX; this document covers only the offline-storage mechanics a resumable draft depends on (`draft_answers`, §3).
- The exact `navigator.storage.estimate()` browser-support matrix and any polyfill/fallback for browsers lacking it → an implementation detail for whichever team builds the PWA client, not an architectural decision.
