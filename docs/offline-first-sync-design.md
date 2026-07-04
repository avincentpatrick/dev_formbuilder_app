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

Four Dexie tables, each namespaced by `form_version_id` where relevant so multiple cached forms/versions coexist on one device without collision:

| Table | Key | Purpose |
|---|---|---|
| `cached_manifests` | `form_version_id` | The manifest response (§2), keyed so a device can hold several forms' schemas simultaneously (a field enumerator often collects for more than one active form). |
| `draft_answers` | `(form_version_id, local_draft_id)` | In-progress, not-yet-finalized answers — autosaved continuously while filling, per the existing sequence diagram's "save draft answers per field (autosave)" step. |
| `outbox` | `client_submission_uuid` | Finalized submissions queued for replay — `status: pending / synced / conflict`, matching the sequence diagram's three outcomes. |
| `media_queue` | `attachment_local_id` | Queued respondent-submitted media awaiting the independent resumable upload (§5), referencing its parent `outbox` row by `client_submission_uuid`. |

**Schema versioning**: Dexie's own native schema-migration mechanism (`db.version(N).stores({...})`) handles client-side schema evolution across app updates — flagged here only so it isn't assumed to need a bespoke migration system; Dexie already provides one.

---

## 4. Cache Invalidation & Re-Fetch Triggers

The manifest is re-fetched (checksum-compared, §2) in three situations, not just "periodically":
1. **On app open/foreground** — the always-present baseline check.
2. **On a Reverb "sync-ready" push** (already named as existing in the C4 Container diagram's `PUBLICPWA <--> Reverb` connection, not previously detailed) — when a tenant publishes a new version of a form a device has cached, the server pushes a lightweight `form_version.published`-triggered notification over the same private/presence channel pattern ADR-0002 already establishes for tenant-scoped Reverb channels; a connected, currently-online client can proactively re-check rather than waiting for its next foreground event. **This is a convenience, not a correctness requirement** — the checksum-based cache-busting (§2) is what actually keeps a device correct even if it never receives the push (e.g., it was offline when the publish happened).
3. **Never mid-collection** — once a respondent has started filling against a specific `form_version_id`, that device continues against that exact version until the current submission is finalized, even if a newer version becomes available in the interim (this is `docs/architecture/technical-architecture.md` §6.2's "no mid-collection schema surprise" guarantee, restated here as a client-side rule: the manifest re-check happens at natural boundaries — app open, between submissions — never interrupting an in-progress fill).

---

## 5. Precise Conflict-Detection Mechanism

`docs/architecture/technical-architecture.md` §4.2's sequence diagram distinguishes "duplicate replay" (200, idempotent no-op) from "genuine concurrent-edit conflict" (409) but doesn't specify how the server tells them apart beyond "materially different answers." **Concretely**: on a replay against an already-`(tenant_id, client_submission_uuid)`-matched row, the server computes a checksum over the incoming `answers` payload (the same canonical-serialization approach `docs/form-versioning-schema-migration.md` §3.2 already uses for `schema_snapshot`) and compares it against a checksum stored alongside the original persisted submission:
- **Checksums match** → byte-identical resubmission (e.g., the client never received the original `201`/`200` acknowledgment and is safely retrying) → `200 OK`, idempotent no-op.
- **Checksums differ** → the same `client_submission_uuid` now carries different content than what was already persisted, meaning the local draft was edited *after* an earlier sync already succeeded (or is racing a concurrent edit from another channel, e.g., an authenticated encoder correcting the same record) → `409 Conflict`, surfaced for manual merge review per the existing sequence diagram.

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

## 8. Multi-Device Limitation (explicitly out of scope, stated plainly)

If the same respondent/enumerator switches between two physical devices mid-collection (e.g., a phone dies, they continue on a tablet), **each device has its own independent IndexedDB — there is no live merge or handoff between them.** The "dominant single-device-per-respondent case" assumption `docs/architecture/technical-architecture.md` §4.2 already states for its last-write-wins policy means: starting the same logical submission on a second device produces a **second, independent** `client_submission_uuid`, not a continuation of the first device's draft. This is an accepted scope boundary (matching the plan's explicit CRDT deferral "unless concurrent multi-device editing proves common in practice"), stated here plainly so it isn't discovered as a surprise in the field rather than a documented limitation.

---

## 9. Out of Scope / Deferred

- CRDT-based multi-device merge — explicitly deferred per the architecture plan, revisit only if real usage data shows it's needed (§8).
- Partial-submission save-and-resume UX detail (the feature exists per `docs/PRD.md` Feature #7, Phase 3 scope) → Doc #20 (Form-Filling UX Flow Spec §5.2) owns the UX; this document covers only the offline-storage mechanics a resumable draft depends on (`draft_answers`, §3).
- The exact `navigator.storage.estimate()` browser-support matrix and any polyfill/fallback for browsers lacking it → an implementation detail for whichever team builds the PWA client, not an architectural decision.
