# Webhook & Integration Design Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — `docs/architecture/technical-architecture.md` §7.4 already specifies the reliability mechanism in detail (transactional post-commit dispatch, `event_id` idempotency, delivery envelope, retry schedule, circuit breaker, DLQ, creation-time SSRF validation) and explicitly names this document as where the retry schedule gets finalized. This document **confirms** that mechanism, adds the two hardening recommendations `docs/security-threat-model.md` §5 flagged, defines the full event payload catalog, and specifies the phased native-integration list.

> ✅ **Engine core BUILT in H13a (2026-07-26).** The `webhook_endpoints` + `webhook_deliveries` tables (strict RLS, composite FKs), the HMAC-signed SSRF-hardened `DeliverWebhookJob` (no redirect-follow; delivery-time DNS-rebinding re-validation), the `next_retry_at` retry ladder swept by `SweepWebhookRetriesJob`, the per-endpoint circuit breaker, the four-event fan-out (`submission.created`/`form.published`/`form.opened`/`form.closed` via auto-discovered listeners over the shared `DomainEventType` catalog), the `webhooks` Starter+ feature gate, the per-tier endpoint-count cap, and the monthly-delivery hard-cap are all live, with the management + delivery-log REST API under `/api/v1/webhooks`. **Still forward-spec (H13b):** `test.ping`, manual redeliver, payload archival (`WebhookPayloadArchive`), dual-secret rotation grace, and the breaker-open notification. **H14:** the management + delivery-log UI and the Zapier recipe. Build deviations from the sections below: the delivery `secret` is stored `text` (the `encrypted` cast overflows `varchar(255)`); the `dns_rebinding_blocked`/quota outcomes are recorded as `[marker]` prefixes in `response_body_excerpt`, not as new status values.

---

## 1. What's Already Decided (confirmed here, not re-derived)

- Transactional, post-commit ingestion — a domain event is raised inside the same transaction as the triggering write; enqueued only after commit, never for a write that subsequently rolls back.
- Idempotency via a stable `event_id` (once per event, not per delivery attempt), enforced by a unique `(endpoint_id, event_id)` constraint plus a Redis dedup cache.
- Delivery envelope: `{ event_id, event_type, occurred_at, tenant_id, api_version, data }`, HMAC-SHA256 signed, 10-second delivery timeout.
- **Retry schedule — confirmed final**: `1m → 5m → 30m → 2h → 6h → 12h → 24h → 48h → 72h` (10 attempts total, ~7 days), matching `webhook_deliveries.max_attempts`'s schema default. Nothing about the actual operational experience of this schedule is known yet (no webhook traffic exists), so "confirmed" here means "adopted as the Phase 1 default," not "empirically validated" — revisit once real delivery-failure data exists.
  - **Execution substrate (ADR-0007):** delivery runs on the **`webhooks`** queue — one of the five names §D6 makes binding on code — and the `next_retry_at` sweep is a `MaintenanceJob` on **`scheduled-maintenance`** (§D3), holding no tenant context and fanning out per-tenant children. **This ladder is webhook-specific and deliberately distinct from ADR-0007 §D7's generic job retry** (`$tries = 3`, `backoff() => [30, 120, 600]`): a customer endpoint being down for hours is an expected business condition warranting a ~7-day ladder, whereas a job failing three times is a defect. Implementing the ladder via `$tries`/`backoff()` would therefore be wrong — it belongs in the `next_retry_at` column, exactly as `docs/architecture/technical-architecture.md` §7.4 specifies. Per-tenant fairness (§D9) applies to the `webhooks` queue like any other.
- Per-endpoint circuit breaker at 20 consecutive failures; manual re-enable.
- Dead-letter queue with manual redeliver.
- Creation-time SSRF validation of endpoint URLs against private/internal ranges.

---

## 2. New Hardening (adopting `docs/security-threat-model.md` §5's recommendations)

### 2.1 Signed timestamp — replay protection

The HMAC signature is computed over `"{timestamp}.{raw_body}"`, not the raw body alone (mirroring Stripe's own webhook-security convention, which this product already draws UX inspiration from elsewhere). The `timestamp` is the same value as the envelope's `occurred_at`. Receivers are documented (in the tenant-facing integration guide, not just this internal doc) as expected to reject any delivery whose `timestamp` is more than **5 minutes** from their own clock — bounding the window in which a captured, intercepted delivery could be usefully replayed, even though `event_id`-based idempotency already prevents *duplicate processing*; this additionally prevents an old, legitimately-captured request from being replayed as if new.

### 2.2 Delivery-time SSRF re-validation

Creation-time URL validation (already specified) does not defend against DNS rebinding — a hostname that resolves to a public IP when the endpoint is first configured, later repointed at an internal address before a retry fires (relevant given the retry schedule spans ~7 days). **New requirement**: the resolved IP is re-checked against the same private/internal-range blocklist **immediately before every delivery attempt** (including retries), not only once at creation. A delivery whose target now resolves internally is treated as a permanent failure for that attempt (not retried further against that specific resolution) and surfaces a distinct `dns_rebinding_blocked`-style delivery status in the observability log (§5) so a tenant sees *why* a delivery stopped, rather than a generic timeout.

---

## 3. Event Catalog — Payload Schemas

`docs/data-dictionary.md`'s `WebhookEventType` enum names the starter catalog (`submission.created`, `submission.updated`, `submission.approved`, `form.published`, `form.archived`, `subscription.updated`) without specifying each event's `data` shape — this section does:

| `event_type` | `data` payload shape |
|---|---|
| `submission.created` | `{ submission_id, form_id, form_version_id, source, status, submitted_at }` — deliberately **excludes** the actual `answers` content by default (avoids pushing potentially-sensitive respondent data to a third-party endpoint the tenant may not have vetted as carefully as the platform itself); a tenant can opt in per-endpoint to an `include_answers: true` configuration if their integration genuinely needs the payload inline rather than fetching it via the REST API using the included `submission_id`. |
| `submission.updated` | `{ submission_id, form_id, previous_status, new_status, updated_at }` |
| `submission.approved` | `{ submission_id, form_id, validated_by, validated_at }` |
| `form.published` | `{ form_id, form_version_id, version_number, published_by, published_at, change_summary }` — `change_summary` is the auto-generated changelog from `docs/form-versioning-schema-migration.md` §3.2, letting an integration (e.g., a Slack notification) show *what changed* without a separate API call. |
| `form.archived` | `{ form_id, archived_at }` |
| `subscription.updated` | `{ subscription_id, plan_code, stripe_status, current_period_ends_at }` — deliberately excludes `stripe_customer_id`/full billing detail, which stays behind the authenticated `/api/v1/subscription` endpoint rather than pushed to a webhook receiver by default. |

**Default-exclude-sensitive-content principle, stated once**: every event above defaults to identifiers and metadata, not full record content, specifically because a webhook endpoint is a third-party destination the tenant configures and this platform cannot vet — this is a deliberate, security-conscious default (consistent with `docs/security-threat-model.md`'s general posture), not an oversight; tenants needing full content can always fetch it via the authenticated REST API using the included ID, or opt in explicitly per-endpoint where noted above.

---

## 4. Phased Native Integration List

Per the architecture plan §2.5's explicit instruction ("start with a short native list... phase in more — not 50 at once"):

| Phase | Integration | Connection mechanism |
|---|---|---|
| 1 | Generic webhook | The mechanism this entire document specifies — any endpoint the tenant configures directly. |
| 1 | Zapier | A "Webhooks by Zapier" trigger consuming the generic webhook mechanism above — no bespoke Zapier app needed for Phase 1; a dedicated Zapier app (richer trigger/action picker UX) is a Phase 3 candidate once integration volume justifies the App directory listing/review process. |
| 3 | Slack | A dedicated OAuth-based connector (Slack App with incoming-webhook or bot-token scopes) — richer than the generic webhook mechanism (channel picker, formatted message blocks), built as its own small service on top of the same underlying event catalog (§3), not a replacement for it. **BACKEND BUILT in H15a** — see the note below. |
| 3 | Google Sheets | OAuth-based, using the sync/live-connect export mode (`docs/api-specification.md` §2.2's cursor pagination, or `docs/architecture/technical-architecture.md` §7.3's sync-export ceiling) to push rows on a schedule or on `submission.created`. **BACKEND BUILT in H16a** — see the note below. |
| 3 | Airtable | Same pattern as Sheets — OAuth-based, built on the existing export/webhook primitives, not a new data-access mechanism. |

No integration beyond this list is committed to a phase — consistent with the plan's explicit "not 50 at once" instruction, additional integrations are added only once a specific, demonstrated tenant need justifies the build.

> ✅ **Native-connector framework + Slack backend BUILT in H15a (2026-07-27).** §6 deferred the connectors' own auth-flow design to Phase 3; **ADR-0009 is that design** and this is its build. As predicted above it is "its own small service on top of the same event catalog": `connections` holds the OAuth grant (encrypted tokens, strict RLS) and `connection_subscriptions` holds the routing rules, four new auto-discovered listeners fan the SAME four `DomainEventType` cases out through a `ConnectorEventDispatcher` twin, and delivery rows land in **`webhook_deliveries` itself** — generalized into a shared outbound ledger owned by either a webhook endpoint or a connector subscription, so both channels share one retry ladder, one delivery quota and one log shape. Two things worth knowing before reading the sections below as if they applied unchanged: the connector channel is **bearer-token authed, not HMAC-signed** (there is no shared secret to sign with — the provider authenticates us), and Slack reports failure with **HTTP 200 + `{"ok": false}`**, so transport status is never the verdict. **Still forward-spec:** the connect UI and channel picker (H15b), author-editable message templates (needs H6a piping — H15a sends a fixed Block Kit layout), and the Sheets/Airtable adapters (H16).
>
> ✅ **Google Sheets connector BACKEND BUILT in H16a (2026-08-05), and the "on a schedule" half of row 4 above is NOT built.** Delivery is event-driven only — one appended row per matching `submission.*` event — with scheduled sync and historical backfill named out of scope rather than silently dropped. It reuses the H15a framework almost entirely (the four-method `ConnectorProvider` contract, the central-domain OAuth callback, the shared `webhook_deliveries` ledger, the retry ladder, the breaker, the `native_connectors` Starter+ gate) and adds one adapter plus one shared engine. **Four things about it contradict what the rest of this document says about connectors, and each is deliberate:**
>
> **(1) THIS CHANNEL SENDS ANSWERS.** §3's "metadata not answers" default holds for webhooks and Slack and cannot hold here — a spreadsheet of submission ids is not a feature. The adapter resolves the submission at delivery time under RLS and projects it through the rule's stored column mapping, so the **ledger still stores only the metadata envelope** and `webhook_deliveries` never becomes a second copy of answer content. `docs/data-privacy-gdpr-compliance.md` §7 is revised in this same increment, as that bullet itself demanded of whichever increment made this true.
>
> **(2) A COLUMN MAPPING, AND A DRIFT ENGINE THAT IS NOT SHEETS' OWN.** The rule stores `external column → form field key` plus a fingerprint of the header row it was authored against; every append re-reads the destination's first row and refuses to write if it no longer matches. The engine lives in a provider-neutral, dependency-free `App\Support\Mapping\*` — pure, no HTTP, no Eloquent, pinned by a source-parsing test that forbids importing from the connector or OCR layers — because **H19a's OCR linelist reuses it verbatim** (`docs/ocr-pipeline-design.md` §4 specifies the identical rule for scanned tables). Both directions ship now (`project()` writes, `interpret()` reads) so the reuse is demonstrable rather than asserted.
>
> **(3) A FOURTH DELIVERY OUTCOME.** `blocked` — the destination is unusable but the credential is healthy. Drift, a deleted spreadsheet and an unreachable file all pause **that one rule** and notify the owner once, leaving the connection and its other rules delivering. The three existing outcomes are each wrong for it: `failed` retries a condition only a human can fix, and `credentialRejected` would revoke a working OAuth grant because one spreadsheet grew a column.
>
> **(4) FORMULAS ARE DISARMED STRUCTURALLY.** Appends use `valueInputOption=RAW`, under which Google stores `=IMPORTXML(…)` as literal text. This is the `security-threat-model.md` §5 formula-injection row in its sharpest form — a live Sheet evaluates server-side, with no human opening a file — and RAW is a stronger fix than the `'`-prefix the CSV/XLSX export path still owes. `insertDataOption=INSERT_ROWS` is set for a different reason: the API defaults to OVERWRITE, which would write over whatever the tenant keeps below their table.
>
> **Still forward-spec:** the Sheets rule UI, field-map UI and destination Picker (H16b); Airtable (H16c); scheduled/backfill sync.

> **The message-template seam now has a design: Doc #26** (piping grammar + output-encoding contract). Two things it fixes here rather than deferring further. **(1) The template grammar is the same one form labels use** — `${key}` holes, one parser, one vector suite — so an author-editable Block Kit message is not a second templating language, and `SlackMessageFormatter` becomes a *renderer* of that grammar rather than a fixed layout. **(2) Slack `mrkdwn` is its own escaping context, and it is currently unescaped.** The formatter interpolates the raw form title straight into an `mrkdwn` section, so `<https://evil.example|text>` is already a live link and `*`/`_`/`` ` `` are already live emphasis — before piping adds a respondent-controlled character. Doc #26 §5 assigned the fix (escape `&`, `<`, `>` before interpolation) to **H6a**, and **H6a shipped it** — `SlackMessageFormatter::mrkdwn()`, applied to each untrusted variable before interpolation and covering all three `mrkdwn` sinks (the section block, the context row and the `text` fallback), pinned by a net-new `tests/Unit/Connectors/SlackMessageFormatterTest.php`. The lone `str_replace('*', '', …)` in the notification-fallback text is cosmetic de-emphasis, explicitly not a security control — it now inherits escaping from the already-escaped headline rather than performing any. **What H6a did NOT do, deliberately: make the message author-editable.** The layout is still fixed, so the "metadata not answers" default this document states in §3 still holds and no answer content reaches a provider. Templating stays a forward-spec item for whichever increment takes it (H16 is the likely one); the sub-processor question in `docs/data-privacy-gdpr-compliance.md` §7 therefore also stays elective, and that bullet was revised in H6a to say so.

---

## 5. Delivery Observability & Management

- **Per-endpoint delivery log**: status, latency, response code/body excerpt, attempt count — already specified in `docs/architecture/technical-architecture.md` §7.4; this document adds the `dns_rebinding_blocked` status (§2.2) as a new distinguishable outcome alongside the existing success/failure/timeout states.
- **Manual redeliver** (`POST /api/v1/webhooks/deliveries/{delivery}/redeliver`, per `docs/api-specification.md`'s resource inventory) re-enters the same delivery pipeline as a fresh attempt — including re-running the SSRF re-validation (§2.2), since the redeliver could happen long after the original attempt.
- **Endpoint testing**: a "Send test event" action (a synthetic `test.ping` event type, not counted against the real event catalog's semantics) lets a tenant verify their endpoint receives and correctly verifies the signature before relying on it for real events.
- **Secret rotation**: dual-secret grace period (already named in `docs/architecture/technical-architecture.md` §7.2) — during rotation, deliveries are signed with the new secret but the old secret remains valid for signature verification on the receiving end for a bounded window, so a receiver's own rotation isn't a hard cutover.

---

## 6. Out of Scope / Deferred

- The dedicated Zapier App, Slack App, and Sheets/Airtable OAuth connectors' own detailed design (auth flow screens, field mapping UI) — Phase 3 work, not designed here beyond the phasing decision (§4).
- Webhook payload signing library choice (a Laravel package vs. hand-rolled HMAC) — an implementation detail, not an architectural one.
- Per-plan-tier webhook endpoint count/rate limits → Doc #24.
