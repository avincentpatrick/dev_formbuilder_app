# Security & Threat Model Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — measures this product's design against the **OWASP ASVS Level 1 (+ several Level 2 controls) baseline `docs/non-functional-requirements.md` §4 fixes**, using STRIDE per attack surface. This document does not re-derive mitigations already designed elsewhere (tenant isolation, RBAC, versioning) — it inventories the attack surfaces, states the threat, cites the existing mitigation, and calls out residual risk or a genuinely new recommendation where one doesn't already exist.

---

## 1. Methodology

For each attack surface (§2): a STRIDE pass (**S**poofing, **T**ampering, **R**epudiation, **I**nformation disclosure, **D**enial of service, **E**levation of privilege), each threat marked:
- **Mitigated** — an existing, cited control already addresses this.
- **Mitigated (new)** — this document adds a control not previously specified anywhere.
- **Residual** — an accepted, honestly-stated remaining risk, with rationale for why it's accepted rather than silently ignored.
- **Open** — a genuine unresolved question, not silently decided.

---

## 2. Attack Surface Inventory

| Surface | Who can reach it | Trust level |
|---|---|---|
| Guest/public form runtime | Anyone with a share link — no account | Untrusted |
| Authenticated Admin/Builder app | Tenant members (any of the 5 roles) | Semi-trusted, tenant-scoped |
| Versioned REST API (`/api/v1`) | API-key/token holders — tenant integrations, the offline PWA client | Semi-trusted, tenant-scoped |
| Outbound webhooks | Tenant-configured third-party endpoints | Platform → external, not inbound |
| File uploads (attachments, OCR scans, XLSForm imports) | Any authenticated user or guest, per form config | Untrusted content, semi-trusted or untrusted actor |
| Expression engine | Tenant form-builders (Owner/Admin/Form Editor) authoring `relevant`/`constraint`/calculated-field expressions | Semi-trusted actor, but the *content* they author is later evaluated against untrusted respondent data |
| Super-admin console | Platform staff only (`users.is_super_admin`) | Highly trusted, highest blast radius |

---

## 3. Tenant-Isolation Bypass Scenarios

`docs/adr/0002-multi-tenancy-shared-db-rls.md` is the authoritative isolation design; this section is the adversarial read of it — concrete attack attempts, and why each fails (or doesn't, honestly):

| Scenario | STRIDE | Verdict | Why |
|---|---|---|---|
| A Tenant A user manipulates a request parameter (e.g., a form or submission ID) to reference Tenant B's resource | Elevation of privilege / Information disclosure | **Mitigated** | Even if application-layer scoping is buggy or forgotten, Postgres RLS (`FORCE ROW LEVEL SECURITY`, fail-closed on missing session context) returns zero rows — ADR-0002 §D2. |
| A forged or tampered guest share token attempts to access a different tenant's form | Spoofing / Tampering | **Mitigated** | The token is HMAC-signed and embeds `{form_version_id, expiry}`; tampering invalidates the signature before any tenant context is even established (`docs/data-dictionary.md` §2 Design Notes; ADR-0002 §D3 guest-endpoint row). |
| A queued job runs with leftover tenant context from a previously-processed job on a reused worker process | Information disclosure | **Mitigated** | `tenant_id` is serialized into every job payload and re-establishes RLS context on execution, independent of whatever ran before it on that worker (ADR-0002 §D3). A job missing `tenant_id` fails fast rather than running ambiently. |
| An attacker enumerates IDs to probe for other tenants' resources | Information disclosure | **Residual (minor, accepted)** | IDs are UUIDv7, not sequential — not practically enumerable. **Honest caveat**: UUIDv7 is time-ordered by design (chosen for B-tree index locality, ADR-0002 §D1), which leaks approximate *creation time* ordering between two IDs, unlike a fully random UUIDv4. This is a strictly weaker disclosure than enumerability (you cannot derive a valid neighboring ID, only relative timing if you already have two real IDs to compare) — accepted as a deliberate trade-off for index performance, not revisited unless a specific customer's threat model demands UUIDv4 instead. |
| A Form Editor grants themselves (or another user) access to a form they weren't authorized for | Elevation of privilege | **Mitigated** | `forms.collaborators.manage` is Owner/Admin-only by explicit design (`docs/multi-tenancy-rbac-design.md` §5's Design Note) — a Form Editor has no path to writing a `form_collaborators` row at all. |
| A compromised or malicious super-admin account reads/modifies any tenant's data | Elevation of privilege / all STRIDE categories at once | **Residual, with new mitigations added below (§7)** | This is the single highest-blast-radius account in the system by design (ADR-0002 §D3) — see §7 for concrete hardening recommendations beyond what's already specified. |

---

## 4. Guest/Public Endpoint Attack Surface

The guest/public form runtime is this product's only fully untrusted-actor surface — no login, no rate-limit-by-account, reachable by anyone with a link.

| Threat | STRIDE | Verdict |
|---|---|---|
| Guest share-link token forgery/tampering | Spoofing | **Mitigated** — HMAC signature, per §3. |
| Token replay after expiry | Tampering | **Mitigated** — the token embeds an expiry, checked server-side before any tenant context is established (ADR-0002 §D3). |
| Automated bulk/bot submission flooding (spam, denial of service, or data-quality pollution) | Denial of service | **Partially mitigated, partially new.** `docs/architecture/technical-architecture.md` §7.2 already specifies the guest share-token auth path is "rate-limited per token and per IP" — that part is not a gap. **Recommendation (new)**: an additional, optional, tenant-configurable CAPTCHA/proof-of-work challenge (e.g., hCaptcha or an equivalent) for forms with `allow_guest_submissions = true` — rate limiting alone bounds *velocity* but not low-and-slow distributed bot submission spread across many IPs/tokens, which a challenge mechanism addresses differently. **Not enabled by default**, since many M&E/field-collection use cases run on trusted enumerator devices where a CAPTCHA would be actively harmful UX — available as a per-form toggle for public, spam-prone use cases (event registration, public surveys) instead. Should be added to `docs/data-dictionary.md`'s `forms` table (a `require_captcha` boolean) in a future consistency pass once a specific team is ready to implement it — flagged here, not implemented as a schema change in this document. |
| Guest endpoint used to enumerate valid `public_slug` values | Information disclosure | **Residual, low severity** — a valid slug reveals only that a form exists at that URL, not its content or any submission data; `forms.public_slug` is `NULL` until guest access is explicitly enabled by the tenant, so this is an accepted, low-value information leak (a slug is meant to be shared, by definition). |
| Malicious file upload via a guest-facing `file_upload`/`image_capture` field | Tampering / DoS | **Mitigated, see §6.** |
| SSRF via a URL-type field's value being fetched server-side | — | **Not applicable** — no field type in the current 31-value catalog (`docs/data-dictionary.md`'s `FieldType` enum) causes the server to fetch a URL supplied in an answer; `url`-typed fields are stored and displayed, never dereferenced server-side. Flagged here so a future field type (if ever added) is deliberately checked against this concern rather than silently introducing it. |

---

## 5. Webhook Security

Builds on `docs/data-dictionary.md` §14–15 (`webhook_endpoints`/`webhook_deliveries`) and the architecture plan §2.5's already-stated reliability requirements (queue-first ingestion, exponential backoff, mandatory idempotency, circuit breaker, dead-letter queue):

| Threat | STRIDE | Verdict |
|---|---|---|
| A third party intercepts or forges a webhook delivery | Spoofing / Tampering | **Mitigated** — `webhook_deliveries.signature`, HMAC-SHA256, verifiable by the receiving endpoint against the shared `webhook_endpoints.secret`. |
| A captured, valid webhook delivery is replayed later by an attacker who intercepted it in transit | Tampering / Repudiation | **Recommendation (new)**: the signed payload should include a timestamp, with the signature computed over `timestamp + '.' + body` (mirroring Stripe's own webhook-security practice) rather than the body alone, and receivers are documented as expected to reject deliveries outside a small tolerance window (e.g., 5 minutes). Nothing in the existing Data Dictionary schema currently models a signed timestamp separately from `created_at` — this is implementable within the existing `signature` column (the timestamp is embedded in the signed string, not a new column) and should be specified precisely in Doc #15 (Webhook & Integration Design Doc), which owns the payload format. |
| A tenant configures a webhook endpoint pointing at an internal/private network address to probe the platform's own infrastructure (SSRF via webhook target) | Information disclosure / Elevation of privilege | **Mitigated, with one refinement recommended.** `docs/architecture/technical-architecture.md` §7.4 already specifies: "endpoint URLs are validated against internal/private IP ranges at creation time (blocked by default; explicitly allow-listable for enterprise/on-prem integration cases)." **Recommendation (new, narrower than originally scoped)**: extend that existing creation-time check with a **re-validation at delivery time** — creation-time validation alone doesn't catch DNS rebinding (a hostname that resolves to a public IP at creation time but is repointed to an internal address before a later delivery attempt fires, especially relevant given this system's exponential-backoff retry schedule spanning up to 7 days per §7.4). Should explicitly re-resolve and re-check the IP at each delivery attempt, not just once at endpoint-creation time. |
| Webhook secret leaked via API response or logs | Information disclosure | **Mitigated** — `docs/data-dictionary.md` §14's Design Notes already specify the secret is masked after initial creation and never returned in full again. |
| A failing/malicious downstream endpoint used to exhaust platform delivery-worker capacity | Denial of service | **Mitigated** — the per-endpoint circuit breaker and dead-letter queue (architecture plan §2.5) already bound retry amplification. |

---

## 6. File Upload Handling

Covers `attachments` (`docs/data-dictionary.md` §10) across every channel that produces one: manual/guest submission file fields, OCR source scans, XLSForm import files, avatars, branding logos, feedback screenshots.

| Threat | STRIDE | Verdict |
|---|---|---|
| Malware embedded in an uploaded file, later served to another user | Tampering | **Mitigated** — `attachments.virus_scan_status` (`ScanStatus` enum: `pending`/`clean`/`infected`/`skipped`) already gates serving: "files are not served to other users until `clean`" (`docs/data-dictionary.md` §10). `docs/ux/form-filling-ux-flow.md` Appendix A item 14 already decided the handling policy for a confirmed `infected` file: out-of-band tenant-admin/security handling only, with no respondent-facing after-the-fact notification — consistent with, not contradicting, this section. |
| MIME-type spoofing (a file's extension/declared type doesn't match its actual content) | Tampering | **Recommendation (new)**: content-based MIME sniffing (not trusting the client-declared `Content-Type` header alone) should be part of the upload-processing pipeline before `mime_type` is persisted — not currently specified anywhere; a concrete requirement for whichever implementation builds the attachment-ingestion service. |
| Oversized upload exhausting storage or bandwidth | Denial of service | **Recommendation (new)**: a per-tenant, per-plan-tier maximum file size and aggregate storage quota, enforced at upload time — `docs/data-dictionary.md` §16's `plans.quotas` (keyed by `UsageMetric`, which already includes `storage_bytes`) is the natural enforcement point; Doc #24 (Pricing & Feature-Gating Matrix) should pin the actual per-tier numbers. |
| Direct object-storage URL guessing/enumeration bypassing the application | Information disclosure | **Mitigated** — S3 keys are namespaced `tenants/{tenant_id}/...` (ADR-0002 §D3) and signed URLs are generated server-side per authenticated request, re-validating the requester's tenant against the attachment's own `tenant_id` before signing (`docs/architecture/technical-architecture.md` §5 row 6) — never a long-lived or guessable public URL. |
| Path traversal or filename-based injection via `original_filename` | Tampering | **Mitigated (new, clarifying an existing gap)**: `original_filename` (`docs/data-dictionary.md` §10) is stored purely as **display metadata**, never used to construct the actual storage `path` (which is a server-generated key) or passed unsanitized into any shell/filesystem operation — this should be stated explicitly as an implementation constraint since the existing column documentation doesn't rule it out explicitly. |

---

## 7. Expression Engine Sandboxing

The architecture plan already commits to "no dynamic `eval()`-style code execution at any point" (`docs/PRD.md` Feature #8's Phase 2 acceptance criteria) — this section defines what "sandboxed" needs to mean concretely, since that phrase alone doesn't specify a threat model.

| Threat | STRIDE | Verdict |
|---|---|---|
| A malicious or careless tenant author writes an expression that executes arbitrary server-side code | Elevation of privilege | **Mitigated** — no `eval()`/dynamic code execution; the expression language (`docs/data-dictionary.md` §6, modeled on XLSForm's `relevant`/`constraint`) is a **closed grammar** of a fixed function allow-list (`if()`, `selected()`, `count()`, `today()`, `now()`, comparison operators) parsed and evaluated by a dedicated interpreter, not a general-purpose scripting language — there is no code path by which an expression string reaches a PHP `eval`, `call_user_func` on an unvalidated string, or any OS-level command execution. |
| A pathological expression (deeply nested, recursive via cross-repeat `count()`, or referencing a very large repeat group) causes excessive CPU/memory consumption | Denial of service | **Recommendation (new)**: the evaluator enforces a maximum expression AST depth/node count at authoring time (rejected at publish, per `docs/form-versioning-schema-migration.md` §4's structural validation gate — a natural place to add this check) and a maximum evaluation-time budget (e.g., abort and treat as `false`/`constraint failed` past a few hundred milliseconds) at runtime, consistent with the performance targets in `docs/non-functional-requirements.md` §1. Neither limit is currently specified anywhere else. |
| An expression referencing a field from a different form version (a dangling/cross-version reference) | Tampering (of data integrity, not security per se) | **Mitigated** — `docs/form-versioning-schema-migration.md` §4 already requires every `related_form_field_id` to belong to the same `form_version_id` being published. |
| Information disclosure via an expression that can reference another respondent's data (cross-submission leakage) | Information disclosure | **Mitigated by design** — the expression grammar's `${field}` references and `count()` resolve only within the *current* submission's own answer document and its own repeat-group instances (`docs/data-dictionary.md` §8's Design Notes on `submission_answers`) — there is no grammar construct that can address a different submission's row at all, so this isn't a guarded-against case so much as a structurally absent capability. |

---

## 8. Super-Admin Hardening (elaborating `docs/multi-tenancy-rbac-design.md` §9)

Given this is the account with, by definition, no per-tenant blast-radius ceiling:

- **Recommendation (new): mandatory multi-factor authentication for every account with `is_super_admin = true`.** Nothing in any existing doc currently requires this — flagged here as a hard requirement given the account's blast radius, not an optional hardening.
- **Recommendation (new): a dedicated, narrower network/access boundary for the super-admin console** (e.g., IP allowlisting, or a separate admin subdomain not linked from ordinary tenant-facing navigation) — reduces the attack surface an external attacker can even discover, independent of the account's own credential strength.
- Every recommendation in `docs/multi-tenancy-rbac-design.md` §9 (narrow audited service layer, elevated Postgres role, full `Auditable` logging) already applies and is not repeated here.
- The three open questions `docs/multi-tenancy-rbac-design.md` §9 already flagged (impersonation mechanics, tenant-visible audit-log surfacing of super-admin actions, internal-staff access graduation) remain **open** from a security perspective too — resolving them has direct security implications (e.g., impersonation without a distinguishing audit column would make a compromised super-admin session indistinguishable from the impersonated user's own actions in the audit trail), which is worth weighing when that doc's open questions are eventually settled, not a new question this document introduces.

---

## 9. Residual Risks — Summary

Honestly restating, in one place, every "Residual" and "Open" verdict from the sections above, so nothing is buried in a large document:

1. UUIDv7's time-ordering leaks relative creation-time ordering between two known IDs (§3) — accepted trade-off for index performance.
2. Guest bot/spam flooding is rate-limited already (`docs/architecture/technical-architecture.md` §7.2), but the optional CAPTCHA challenge for spam-prone public forms (§4) is not yet implemented.
3. Webhook replay protection (signed timestamp + tolerance window) and delivery-time SSRF re-validation (creation-time validation already exists) have no mitigation until Doc #15 adopts this document's recommendations (§5).
4. File-upload MIME sniffing and quota enforcement have no mitigation until implemented (§6).
5. Expression-engine resource limits (AST depth, evaluation-time budget) have no mitigation until implemented (§7).
6. Super-admin MFA and network hardening have no mitigation until implemented (§8).
7. The three open RBAC-doc questions (impersonation, audit visibility, staff graduation) remain unresolved and have direct security weight, not just a UX one.

None of these are load-bearing blockers for Phase 0 scaffolding to begin — they are concrete, scoped implementation requirements to carry into the relevant future work (Docs #15, #24, and the actual Phase 1 build), tracked here so they aren't silently forgotten between "the doc that mentions it" and "the code that implements it."

---

## 10. Out of Scope / Deferred

- Full penetration-testing scope/cadence, dependency/supply-chain scanning policy, and secrets-management specifics → Doc #22 (Deployment & Infrastructure Doc).
- GDPR-specific data-handling threats (subject access, erasure abuse, consent bypass) → Doc #12 (Data Privacy & GDPR/Compliance Doc) — this document's scope is security, not privacy-compliance, though the two obviously overlap at the edges (e.g., an information-disclosure threat is also a GDPR concern; each doc cites the other rather than duplicating).
- Detailed audit-event redaction rules → Doc #13 (Audit & Compliance Logging Spec).
- CI/CD pipeline security (build-artifact integrity, secrets in CI) → Doc #22.
