# API Specification (OpenAPI 3.1) — Reference Guide

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — formalizes `docs/architecture/technical-architecture.md` §7.1–§7.3 (resource inventory, auth-per-caller-type, export modes) into API-specification detail: request/response schemas, pagination, error format, versioning, and rate limits. **This document does not re-derive the resource list or auth model** — those are already decided; this document fills in what wasn't yet specified at the level an actual OpenAPI 3.1 contract needs.
**Deliverable note**: the literal, exhaustive machine-validated OpenAPI document is a **Phase 0 code-adjacent deliverable**, generated from the routes + request/resource types rather than hand-authored in this markdown doc and then left to drift from the actual routes — consistent with this project's "docs-as-code" discipline (architecture plan §5). This document is the human-readable design spec that generation target is built against, with representative schema examples, not a substitute for the generated file.

> **Delivered — Phase 0 Increment E (2026-07-07).** The generated contract lives at the repo root as **`openapi.json`** (OpenAPI 3.1), produced by **`dedoc/scramble`** from the `/api/v1` routes + FormRequest/JsonResource types, with the bearer security scheme + a deterministic `{tenant}`-templated server applied via `Scramble::extendOpenApi` (AppServiceProvider). The CI **`contract-tests`** job re-exports the spec, validates it with **Redocly** (`redocly.yaml`), and fails on any drift from the committed file. This first slice documents the already-built surface (auth-token issuance, Forms read, Form versions read + publish, tenant profile); the remaining §7.1 resources extend the same pattern in Phase 1.

---

## 1. What's Already Decided (not repeated in full — see the source)

`docs/architecture/technical-architecture.md` §7.1's resource table (Auth & API keys, Tenant, Forms, Form versions, Form draft, XLSForm interop, Public form schema, Submissions, Sync, Attachments, Exports, OCR intake, Field library & templates, Webhook endpoints/deliveries, Users & roles, Subscription, Audit log) is the authoritative resource inventory. §7.2's per-caller-type auth table and §7.3's export-mode split (async/queued vs. sync/live-connect, with the 5,000-row/30-second sync-mode ceiling) are likewise authoritative and not restated here.

---

## 2. Conventions

### 2.1 Versioning

- URL-path versioning: `/api/v1/...`. A breaking change ships as `/api/v2` with `/v1` maintained on a stated deprecation timeline (minimum 12 months' notice before sunset, announced via the `Deprecation`/`Sunset` HTTP headers on every `v1` response once `v2` exists — per the emerging IETF draft convention for API deprecation signaling).
- A non-breaking addition (a new optional field, a new endpoint) never requires a version bump — only removing or changing the meaning of an existing field/endpoint does.

### 2.2 Pagination

**Cursor-based**, per the architecture plan §2.5 ("cursor-based pagination for exports beyond 1000 records," generalized here to every paginated list endpoint, not only exports):

```
GET /api/v1/forms?limit=50&cursor=eyJpZCI6IjAxOTAi...
```
```json
{
  "data": [ /* array of resource objects */ ],
  "meta": {
    "next_cursor": "eyJpZCI6IjAxOTAi...",
    "has_more": true
  }
}
```
`limit` defaults to 25, max 100 (higher volumes go through the async export path, §7.3). Offset-based pagination (`?page=2`) is **not** offered — cursor pagination is the only mode, avoiding two paginated-list code paths to maintain.

### 2.3 Error Format

A single, consistent error envelope across every endpoint (not yet specified anywhere prior to this document):

```json
{
  "error": {
    "code": "form_version_not_draft",
    "message": "This form version is published and cannot be edited.",
    "details": { "form_version_id": "0190...", "status": "published" }
  }
}
```
- `code` is a stable, machine-readable snake_case identifier (safe for integration-consumer code branching) — **not** the HTTP status text and **not** a translated string.
- `message` is a human-readable, English-only string for developer/support consumption — never shown directly to a respondent in the public runtime, which renders its own localized copy keyed by `code`.
- `details` is optional, endpoint-specific structured context.
- Validation errors (`422`) additionally include a `details.fields` map of field name → array of violation messages, matching Laravel's native validation-error shape closely enough that existing Laravel API-client tooling on the integration-consumer side doesn't need special-casing.

### 2.4 Idempotency

- **Submissions**: `client_submission_uuid` (already in `docs/data-dictionary.md` §7) is the domain-specific idempotency key for the offline-sync replay case (Doc #18 owns the full mechanism).
- **Every other unsafe (`POST`/`PATCH`/`DELETE`) request** may optionally include an `Idempotency-Key` header (a client-generated UUID); the API deduplicates against a short-lived (24-hour) Redis-backed cache keyed on `(tenant_id, endpoint, Idempotency-Key)`, returning the original response for a repeated key rather than re-executing the action — a general-purpose mechanism beyond the submission-specific one, useful for any integration consumer that needs safe retries (e.g., a Zapier action retrying after a network timeout).

### 2.5 Rate Limits

Concrete numbers, since `docs/architecture/technical-architecture.md` §7.2 states rate limiting exists per caller type without pinning figures:

| Caller type | Limit |
|---|---|
| Guest respondent (per share token) | 30 requests/minute |
| Guest respondent (per IP, across all tokens) | 100 requests/minute |
| Authenticated Admin/Builder session | 300 requests/minute per user |
| Integration/API-key consumer | 600 requests/minute per key (a plan-tier-adjustable ceiling — Doc #24 may raise this for higher tiers) |
| Sync/live-connect export (§7.3) | 1 concurrent sync export per form at a time; additional requests return `429` until the in-flight one completes |

Every rate-limited response includes standard `X-RateLimit-Limit`/`X-RateLimit-Remaining`/`X-RateLimit-Reset` headers.

### 2.6 Sanctum Ability Catalog

`docs/architecture/technical-architecture.md` §7.2 gives illustrative examples (`read:submissions`, `write:forms`) without an exhaustive list. This document aligns the full catalog with `docs/multi-tenancy-rbac-design.md` §5's permission-string catalog rather than inventing a second, parallel vocabulary for API tokens:

| Sanctum ability | Maps to RBAC permission(s) |
|---|---|
| `read:forms` | `forms.create`/`edit.*` roles implicitly get read; a read-only integration key can hold just this |
| `write:forms` | `forms.create`, `forms.edit.any`/`.own` |
| `read:submissions` | `submissions.view` |
| `write:submissions` | `submissions.create` |
| `review:submissions` | `submissions.review.any`/`.own` |
| `export:submissions` | `submissions.export` |
| `manage:webhooks` | `webhooks.manage` |
| `manage:settings` | `tenant.settings.manage` |
| `manage:scopes` *(G10b)* | `scopes.manage` / `forms.collaborators.manage` |
| `manage:integrations` *(H15a)* | `integrations.manage` |
| `read:analytics` *(H24a)* | `dashboard.org.view` / `dashboard.form.view` |
| `read:gamification` *(K1d)* | `dashboard.org.view` / `dashboard.form.view` |

> **Increment G10b note.** `manage:scopes` covers both authoring the `scope_nodes` hierarchy and granting
> access on it. It is a **new** ability rather than a reuse of `manage:settings`, which preserves the
> property G10a added `scopes.manage` for in the first place: no already-minted token gains authority over
> authorization structure, because none was minted carrying this ability.
>
> One ability rather than two, with one safeguard that has to stay true. The map's semantics are "holding
> **any** listed permission grants the ability", so on its own `manage:scopes` would let a
> `scopes.manage`-only principal mint a token that reaches the grant routes. It is safe because the
> ability is a token **scope**, never the authorization: every `/scopes` and `/resource-grants` route also
> carries its own `can:` policy gate, re-checked against the acting user's real permissions, and
> `POST /resource-grants` adds the `grantCapacity` escalation check on top. **A route added to this group
> without a `can:` gate would break that argument** — see `ApiAbilities::MANAGE_SCOPES`.

> ### ⛔ THE SENTENCE ABOVE IS A GROUP-WIDE RULE, NOT A `manage:scopes` ASIDE — AND IT HAD A HOLE (M13)
>
> **An ability scopes the TOKEN and RLS scopes the TENANT. Neither answers "may THIS member touch THIS
> resource", and only a policy gate does.** That was written down as an argument local to one ability, and
> the two routes it did not reach are the two that broke it. Both `/api/v1/sync` routes take their resource
> from the **body or the query** rather than the URL, so there was no bound model for `can:` to attach to —
> and a rule phrased about *resource-bound* routes silently did not apply to them. `GET /sync/manifest`
> served any form's complete authored schema to any holder of `read:forms`; `POST /sync/submissions` created
> submissions against any form in the tenant for any holder of `write:submissions`, while the bound-model
> routes onto the same two artefacts required a per-form grant.
>
> **The rule's second half, now stated: WHEN A ROUTE IS NOT RESOURCE-BOUND, THE GATE MOVES INTO THE
> CONTROLLER — IT DOES NOT DISAPPEAR.** `POST /form-templates` had been doing exactly that since G9a (its
> `form_version_id` arrives in the body and `FormTemplateApiController` runs
> `Gate::forUser($user)->authorize('view', $version->form)` itself); it was the precedent rather than the
> exception, and nothing said so.
>
> **Eight Group-B routes carry no `can:` middleware, and six of them are right to.** `GET /tenant` (the
> tenant *is* the request's context), `GET /form-templates` and `GET /field-library` (RLS returns platform
> items plus the tenant's own and nothing else), `POST /field-library` (authored from explicit attributes;
> it references no other resource), `GET /gamification/me` (ADR-0020 §D7 — every member may read their own
> standing, which names nobody else), and `POST /form-templates` (gated in its controller). The other two
> are the sync pair, now gated in theirs. That enumeration is not prose: `tests/Feature/Api/
> GroupBPolicyGateTest.php` walks the live route table and fails on a route carrying neither a `can:`
> middleware nor a written reason it needs none — because this is a property of a **route table**, and a
> guard that lives in one controller's test cannot see the next route somebody adds.
>
> **And since M63 the guard asks what the gate NAMES, not only that one is present.** It used to discard
> everything after the first colon, so `can:viewAny,SavedReportView` and `can:viewAny,Submission` were
> indistinguishable to it. Two halves now cover that: six derived checks in the same file (a class subject
> must resolve to a registered policy implementing the ability; a **non-class subject must be a declared
> route parameter**, because `Authorize::getModel()` otherwise hands the gate `null` and it refuses every
> caller; a bare ability must be a real permission key), and `GroupBGateSubjectTest`, which declares the
> **set of permission keys that opens each route** and computes the actual set from the live policy. The
> declaration is an audience rather than an echo of the middleware string, so a re-pointed gate makes it
> false in words instead of being kept true by a matching edit.

> **H15a note.** `manage:integrations` scopes the native-connector surface (`/connections` and the delivery
> rules nested under it). New rather than a reuse of `manage:webhooks` for the reason above, one step
> sharper: a connection holds an OAuth credential that lets the platform act inside the tenant's own
> third-party workspace (ADR-0009 §D1), so reusing the webhook permission would retroactively grant every
> already-minted webhook token an authority whose blast radius leaves this platform. Note what the surface
> deliberately does **not** offer: there is no `POST /connections` and no `PATCH /connections/{id}` — a
> grant can only be created by the interactive OAuth flow, and an API that accepted a token as input would
> be a path to writing a credential the platform then acts with.

> **ADR-0011 note (H1e) — `read:analytics`, minted by H24a (2026-08-03).** It is a
> **new** ability rather than serving aggregates under `read:submissions` or `export:submissions`, for the
> same reason as the two notes above: folding it in would retroactively grant analytics access to every
> token already issued. It differs from those two in one respect worth recording — it needs **no new RBAC
> permission**. `dashboard.org.view` and `dashboard.form.view` are already seeded and already in the role
> matrix, and the org-wide/scoped split they encode is exactly the visibility split an analytics read needs,
> so the ability maps onto existing permissions instead of coining a thirtieth. The analytics routes also
> carry `feature:advanced_analytics`, which is a plan gate rather than an authorization one.
>
> **As built.** Eight routes under `/analytics`: `report`, `report/export`, `questions`,
> `questions/{key}` and the five `views` verbs. Every one carries the standing triplet
> `ability:read:analytics` + a `can:` gate + `feature:advanced_analytics`. The report and question routes
> have no model of their own and gate on `can:viewAny,SavedReportView` — whose policy predicate IS this
> ability map, so the token scope and the permission check agree by construction;
> `can:viewAny,Submission::class` was rejected there because it authorizes on `submissions.view`, a widening
> of a different capability. One consequence worth stating: a **Viewer** holds `dashboard.org.view`, so
> `read:analytics` is the broadest-issuable read ability in the catalog — intended, since a Viewer already
> sees every submission in the inbox.
>
> A declaration that violates one of ADR-0011 §D7's bounds (missing range, span over 366 days, unknown IANA
> zone, form list over 100) returns **422 `invalid_analytics_query`** with a machine-readable `reason`. The
> bounds live in the query object's constructor rather than only in validator rules, so the saved-view and
> export paths inherit them.

> **ADR-0020 note (K1d) — `read:gamification`, minted 2026-08-18.** A **new** ability rather than serving
> the ladder under `read:analytics`, for the same reason as the three notes above and with the sharpest
> subject matter yet: folding it in would retroactively hand every already-issued analytics token a
> **named, per-person productivity ranking of the tenant's staff**. Issuers of those tokens agreed to
> aggregates. Like `read:analytics` it needs **no new RBAC permission** — ADR-0020 §D7 is a product
> decision of record that the 29-key catalog stays closed, because *"who may see workspace-wide numbers
> about other people"* is a question `dashboard.org.view` already answers for the dashboard and the inbox.
>
> **As built.** Two read-only routes. `GET /gamification/me` returns the caller's own points, badges,
> streak and standing and **carries no `can:` gate at all** — every member may see their own numbers, and
> the payload names nobody else. `GET /gamification/leaderboard` returns the named ladder plus workspace
> totals and adds `can:viewAny,PointAward`, whose single arm is `dashboard.org.view`. So the ability is
> mintable by all five roles while the *list* is Owner/Admin/Viewer only. ⚠️ The `manage:scopes` rule above
> — that a route in this group without a `can:` gate breaks the token-scope argument — is deliberately
> excepted for `me`, because the gap it closes does not exist there: the resource IS the caller.
>
> **Neither route carries `feature:gamification`, and that is a requirement rather than an omission.**
> ADR-0020 §D6 grants that key on every plan tier, so `RequireFeature` could only ever fire on a tenant
> that switched the module off itself — and would answer *"Upgrade your plan to use it"*, pointing at a
> purchase that would change nothing. Both routes carry **`module:gamification`** instead
> (`RequireModule`), which refuses with **403 `module_disabled`** and `details.module` — distinct from the
> entitlement family's 402 because nothing is owed and the state is undoable from inside the workspace.
>
> ⚠️ **A Free tenant cannot reach either route**, since the whole group carries `feature:api_access` which
> Free does not grant, even though `gamification` is granted on every tier. The feature exists there; its
> API does not.
>
> ⚠️ **The workspace totals in the leaderboard payload do not reconcile with the entries beside them, in
> three places, by design** — guest submissions credit nobody, and departed members keep their ledger
> history while leaving the ladder. ADR-0020 §D11(c) tabulates all three, and each endpoint's OpenAPI
> description repeats it. **There are no write routes**: the ledger is append-only under RLS with no
> UPDATE or DELETE policy, so an endpoint that awarded points by hand is the one thing the substrate
> exists to prevent.


| `read:audit_log` | `audit_log.view` |

An API key (personal access token) is issued with an explicit subset of these abilities, independent of — but never exceeding — the issuing user's own RBAC permissions (a key can be narrower than its issuer's own access, never broader; enforced by intersecting the requested ability set against the issuer's actual permissions at token-creation time).

---

## 3. Representative OpenAPI 3.1 Fragments

Illustrative, not exhaustive — the generated `openapi.yaml` (§0) is authoritative once Phase 0 produces it. These fragments exist so this design document grounds its conventions (§2) in a concrete shape rather than only prose.

```yaml
openapi: 3.1.0
info:
  title: Form-Builder SaaS API
  version: "1.0"
paths:
  /api/v1/forms/{form}/submissions:
    post:
      summary: Create a submission (manual encoding or API import channel)
      security:
        - sanctumToken: [write:submissions]
      requestBody:
        content:
          application/json:
            schema:
              type: object
              required: [answers]
              properties:
                answers:
                  type: object
                  description: Keyed by form_fields.key, per docs/data-dictionary.md §8
                client_submission_uuid:
                  type: string
                  format: uuid
      responses:
        "201":
          description: Submission created
          content:
            application/json:
              schema:
                $ref: "#/components/schemas/Submission"
        "422":
          $ref: "#/components/responses/ValidationError"
  /api/v1/forms/{form}/versions/{version}/publish:
    post:
      summary: Publish the current draft (docs/form-versioning-schema-migration.md §3.2)
      security:
        - sanctumToken: [write:forms]
      responses:
        "200":
          description: Version published; response includes the auto-generated change_summary
components:
  securitySchemes:
    sanctumToken:
      type: http
      scheme: bearer
  schemas:
    Submission:
      type: object
      properties:
        id: { type: string, format: uuid }
        form_version_id: { type: string, format: uuid }
        status: { type: string, enum: [draft, submitted, screened_out, under_review, approved, returned, archived] }
                 # `screened_out` (I9a) — finalized having been shown no questions; consumes no max_responses slot.
                 # A consumer that switches exhaustively on this enum must handle it: it is emitted by the
                 # public guest submit, by the encode channel, and in the `submission.created` webhook payload.
        source: { type: string, enum: [manual, guest, ocr_single, ocr_linelist, offline_sync, api_import] }
  responses:
    ValidationError:
      description: Validation failed
      content:
        application/json:
          schema:
            type: object
            properties:
              error:
                type: object
                properties:
                  code: { type: string, example: validation_failed }
                  message: { type: string }
                  details:
                    type: object
                    properties:
                      fields: { type: object }
```

---

## 4. Out of Scope / Deferred

- ~~The full, generated `openapi.yaml`~~ — **delivered in Phase 0 Increment E** as `openapi.json` at the repo root (Scramble-generated, Redocly-validated, drift-checked in CI). See §0.
- Webhook event payload schemas (the `data` shape per `event_type`) → Doc #15.
- XLSForm import/export request/response detail beyond the endpoint's existence (already in `docs/architecture/technical-architecture.md` §7.1) → Doc #16.
- OCR intake endpoint payload/polling detail → Doc #17.
- Sync manifest/batch-replay endpoint detail → Doc #18.
