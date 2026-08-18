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

## 3. Client-Side Data Model (IndexedDB via Dexie)

Five Dexie tables, each namespaced by `form_version_id` where relevant so multiple cached forms/versions coexist on one device without collision:

| Table | Key | Purpose |
|---|---|---|
| `cached_manifests` | `form_version_id` | The manifest response (§2), keyed so a device can hold several forms' schemas simultaneously (a field enumerator often collects for more than one active form). |
| `draft_answers` | `(form_version_id, local_draft_id)` | In-progress, not-yet-finalized answers — autosaved continuously while filling, per the existing sequence diagram's "save draft answers per field (autosave)" step. |
| `outbox` | `client_submission_uuid` | Finalized submissions queued for replay — `status: pending / synced / conflict`, matching the sequence diagram's three outcomes. |
| `media_queue` | `attachment_local_id` | Queued respondent-submitted media awaiting the independent resumable upload (§5), referencing its parent `outbox` row by `client_submission_uuid`. |
| `app_state` | `key` | **(H23b, schema v2)** Device-scoped scalars belonging to no form. One key today — `brand_version`, the tenant-ramp fingerprint the cached guest shells were last refreshed for (§4.1). |

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
> **Still out of scope, and now for a sharper reason:** there is no automatic *merge* of two devices' divergent answers. The refusal names reloading, and reloading is what works — the second device re-reads the draft and continues from the fresh base. Merging divergent copies is the CRDT work §9 defers.

*Original text, superseded above:*

> If the same respondent/enumerator switches between two physical devices mid-collection (e.g., a phone dies, they continue on a tablet), **each device has its own independent IndexedDB — there is no live merge or handoff between them.** The "dominant single-device-per-respondent case" assumption `docs/architecture/technical-architecture.md` §4.2 already states for its last-write-wins policy means: starting the same logical submission on a second device produces a **second, independent** `client_submission_uuid`, not a continuation of the first device's draft. This is an accepted scope boundary (matching the plan's explicit CRDT deferral "unless concurrent multi-device editing proves common in practice"), stated here plainly so it isn't discovered as a surprise in the field rather than a documented limitation.

---

## 9. Out of Scope / Deferred

- **CRDT-based multi-device merge — DEFERRED, and the trigger is an input this project cannot generate.** Restated in Increment P3a (2026-08-17) because "revisit only if real usage data shows it's needed" reads like a scheduled follow-up and is not one. The deferral is on the record in **five** documents, not this one alone: `docs/PRD.md` §430/§441/§505 (which calls it *"not committed by default"* and files the question under **Open Questions**), `docs/architecture/technical-architecture.md:313`, `docs/form-versioning-schema-migration.md:179`, `docs/ux/form-filling-ux-flow.md:188`, and here. **The revisit trigger is *real usage data showing concurrent multi-device editing is common*** — nothing is deployed, so no such data exists or can be produced from inside the project. Structurally the same shape as data residency (`docs/adr/0017-tenant-isolation-tiering.md` §D6, whose trigger is "a second hosting region exists"): a decision waiting on an external input, not an unstarted task. ⚠️ **The roadmap row for this is worded "CRDT sync *if needed*" in `PROGRESS_ARCHIVE.md:207`, and later copies dropped the qualifier** — plan against the qualified original.
  **What P3a built INSTEAD, and why it is not a substitute:** verifying this row found §8's premise false as built (above), so one draft can now have two writers. That is a **lost-update** hazard — one device silently overwriting answers it never read — and it is closed by the baseline guard, not by a CRDT. A CRDT would additionally **merge** two divergent copies without refusing either; P3a refuses, names reloading as the remedy, and leaves both copies intact. Those are different problems, and only the first one is reachable today.
- Partial-submission save-and-resume UX detail (the feature exists per `docs/PRD.md` Feature #7, Phase 3 scope) → Doc #20 (Form-Filling UX Flow Spec §5.2) owns the UX; this document covers only the offline-storage mechanics a resumable draft depends on (`draft_answers`, §3).
- The exact `navigator.storage.estimate()` browser-support matrix and any polyfill/fallback for browsers lacking it → an implementation detail for whichever team builds the PWA client, not an architectural decision.
