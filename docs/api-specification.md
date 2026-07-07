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
        status: { type: string, enum: [draft, submitted, under_review, approved, returned, archived] }
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
