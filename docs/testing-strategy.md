# Testing Strategy Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Status:** Draft v1.0 — formalizes the testing pyramid around several pieces `docs/architecture/technical-architecture.md` and `docs/adr/0002-multi-tenancy-shared-db-rls.md` already committed to by name (the CI tenant-isolation fuzz suite, the expression-evaluator golden-file suite, Larastan/Pint/Playwright as the CI toolchain) plus the explicit CI gaps the architecture plan calls out relative to legacy (static analysis, code style, a real deploy stage, a controller-complexity check).

---

## 1. What's Already Decided (not repeated in full)

- **CI/CD toolchain**: Docker Compose + GitHub Actions, Larastan/PHPStan + Pint + Playwright + a real deploy stage (`docs/architecture/technical-architecture.md` §8) — legacy's confirmed gap was "CI that ran PHPUnit only."
- **Cross-tenant fuzz test suite**: seeds two tenants, attempts every plausible cross-tenant read/write/list operation, asserts each fails appropriately — runs on every PR touching a model or migration (ADR-0002 §D6, restated as CI layer 12 in `docs/architecture/technical-architecture.md` §5).
  - **Harness note (Increment A/B1)**: fuzz tests connect as the non-superuser `meridian_app` role and set context with `TenantContext::applyLocal()` (transaction-scoped; auto-reset by `RefreshDatabase`), then issue raw `DB::` queries so what is proven is the *database's* RLS, not the ORM scope (see `tests/Feature/Tenancy/CrossTenantIsolationTest.php`, `UserRlsTest.php`).
  - **Pre-auth connection gotcha (Increment B1)**: authentication resolves users on a separate `pgsql_auth` session (the `meridian_auth` role — see `app/Auth/RlsAwareUserProvider.php`) so login/registration/password-reset work despite the fail-closed join-shape RLS on `users`. Because `RefreshDatabase` wraps only the *default* (`meridian_app`) connection in a transaction, `pgsql_auth` cannot see uncommitted rows — so **auth feature tests that need to log a user in must seed that user with a COMMITTED write on the privileged connection (`User::on('pgsql_privileged')->forceCreate(...)`) and clean it up in `afterEach()`** (the same pattern as the `global_probes` seed). Registration tests need no such seed — they create their user on the default connection inside the transaction.
- **Expression-evaluator golden-file suite**: a shared test-vector set consumed by both the PHP (server-authoritative) and TypeScript (client UX) engines, treating any drift between them as a production defect (`docs/architecture/technical-architecture.md` §4.3, Risk R3).
- **Migration-review checklist item** for RLS-policy coverage on new tenant-scoped tables (Risk R2).

---

## 2. Testing Pyramid

| Layer | Tooling | Scope |
|---|---|---|
| **Unit** | Pest (Laravel's modern PHPUnit-compatible test framework) | Expression evaluator (golden-file vectors, §1), individual validators, RBAC permission-matrix logic (`docs/multi-tenancy-rbac-design.md` §5 — one test per permission×role cell), DTOs/value objects (`SubmissionPayload`, per `docs/architecture/technical-architecture.md` §4.1). |
| **Integration** | Pest + a real (Dockerized) Postgres, not an in-memory/sqlite substitute (RLS behavior cannot be validated against sqlite) | The `SubmissionPipeline` exercised across **all six channels** (manual, guest, OCR-single, OCR-linelist, offline-sync replay, API-import) through **one shared, parametrized test suite** — not six independent suites — specifically because the architecture plan's central lesson from the legacy audit was "four divergent, duplicated validation code paths had drifted apart" (`docs/data-dictionary.md` reference); a single parametrized suite structurally prevents the six channels from silently drifting behaviorally apart the way legacy's four did, since a fix or regression shows up identically across every channel's row in the same test run. |
| **Contract** | Schema validation against the generated OpenAPI 3.1 document (`docs/api-specification.md`) — e.g., a Laravel test-response macro that validates every feature test's actual JSON response against its corresponding OpenAPI schema | Prevents the classic "the spec and the code silently drifted" failure mode this project explicitly wants to avoid (`docs/api-specification.md`'s own "docs-as-code" framing) — a contract-test failure means either the code or the spec is wrong, and CI cannot tell which without a human, but it *will* tell you they disagree. |
| **End-to-end (E2E)** | Playwright | Golden-path flows per persona (`docs/PRD.md` §2): a Form Editor building and publishing a form, a guest completing and submitting it, a Reviewer approving/returning a submission, an Admin managing tenant settings/roles, a Viewer's read-only boundaries. Also the home of the already-committed responsive-breakpoint checks (`docs/PRD.md` Feature #5) and the WCAG AA `axe-core` scans (`docs/non-functional-requirements.md` §5) — both run as part of the E2E suite against real rendered pages, not simulated separately. |
| **Load / performance** | A dedicated load-testing tool (e.g., k6 or Artillery — tool choice not architecturally significant, left to whichever team sets up the pipeline) | Validates `docs/non-functional-requirements.md` §1's stated response-time targets under §3's stated scalability assumptions — this document does not invent new performance numbers, it tests against the ones the NFR doc already set. Concrete scenarios: a burst of concurrent guest submissions against one form (the "concurrent ingest" case the architecture plan names explicitly), a webhook-delivery burst exercising the circuit breaker (`docs/webhook-integration-design.md`), and a sustained dashboard-query load. |

---

## 3. Security-Specific Test Requirements

Beyond the already-committed tenant-isolation fuzz suite (§1), this document adds test coverage for the *new* recommendations `docs/security-threat-model.md` introduced, so they don't remain permanently unimplemented recommendations:

- A test asserting the expression engine rejects (or safely times out on) a pathologically deep/recursive expression, per `docs/security-threat-model.md` §7's resource-limit recommendation.
- A test asserting a webhook endpoint URL resolving to a private/internal IP is rejected both at creation time and at delivery time (the DNS-rebinding re-validation `docs/webhook-integration-design.md` §2.2 adds).
- A test asserting an erasure-triggered audit entry never contains raw pre-erasure PII in `old_values` (`docs/audit-compliance-logging-spec.md` §2.1's deliberate exception) — this is exactly the kind of subtle, easy-to-regress behavior that needs an explicit regression test, not just a documented intention.
- The migration linter (ADR-0002 §D6) is itself CI-enforced, not a manual review step alone: a CI job fails the build if a new migration creates a `tenant_id`-shaped column with no corresponding RLS policy, or a new table appears on neither the tenant-scoped nor exemption lists.
- **Dependency/supply-chain scanning (SCA), static application security testing (SAST), and secret scanning run in CI** — `composer audit` + `npm audit` (or Snyk/Dependabot) for known-vulnerable dependencies, a SAST pass, and `gitleaks`/GitHub secret scanning to block committed credentials. A high/critical vulnerability or a detected secret blocks merge. This closes a promised-but-orphaned control: `docs/security-threat-model.md` deferred dependency/supply-chain scanning and pentest cadence to the infra doc, which does not cover them — so they are pinned here. A recurring third-party penetration test is a Phase 2+ commitment (once there are paying customers), tracked in `docs/feature-backlog.md`.

---

## 4. Static Analysis & Code Style

- **Larastan/PHPStan at level 8** (Laravel-aware static analysis) — a concrete, CI-blocking level, chosen as the highest level realistically achievable without excessive `@phpstan-ignore` noise for a Laravel codebase at this project's start (level 9's stricter mixed-type handling is a plausible later tightening once the codebase's own type coverage matures, not a Phase 0 requirement).
- **Pint** (Laravel's official code-style fixer) — zero-config default ruleset, CI-blocking on any diff, auto-fixable locally via `pint --dirty` before commit.
- **Controller complexity/line-count gate** (the architecture plan §5 names this explicitly as a new discipline: "add a CI complexity/line-count check this time," citing legacy's 1,747-line `FormController.php` and 1,574-line `DataSubmissionController.php` as the concrete cautionary precedent). **Concrete thresholds**: a controller class fails CI at **250 lines** (excluding blank lines/docblocks) or a single method exceeding **cyclomatic complexity 10** — both enforced via a static-analysis rule (e.g., a custom PHPStan rule or PHP Mess Detector integrated into the same CI stage as Larastan), not a manual code-review convention alone, since legacy's own stated "thin controllers" rule existed but was never tooling-enforced and controllers grew unchecked anyway.

---

## 5. Test Data & Fixtures

- **Factories, not fixture files**, for every model (Laravel's standard `Model::factory()` pattern) — keeps test data generation co-located with the models it represents and trivially variable per test case.
- **No real PII in test fixtures, ever** — synthetic/generated data only (e.g., via a library like `fakerphp/faker`), consistent with `docs/data-privacy-gdpr-compliance.md`'s posture; this also means the tenant-isolation fuzz suite (§1) and integration suites can run against realistic-shaped data without any actual respondent information ever touching a CI environment or a developer's local machine.
- **Multi-tenant test seeding is a first-class factory concern**: every model factory accepts (or defaults to creating) a `tenant_id`, so writing a cross-tenant test (§1, §3) is a one-line factory call, not boilerplate tenant setup repeated per test file.

---

## 6. CI Pipeline Stages (order)

1. **Static analysis, style & security scanning** (Larastan, Pint, the controller-complexity gate, §4; plus the SCA/SAST/secret-scanning checks in §3) — fails fast, cheapest checks first; a high/critical dependency vulnerability or a detected committed secret blocks merge.
2. **Unit tests** (§2).
3. **Integration tests** (§2) — against a real, Dockerized Postgres service container.
4. **Contract tests** (§2) — depends on the OpenAPI document being up to date; a mismatch here blocks merge.
5. **Build** (the actual application build/asset compilation step).
6. **E2E tests** (§2) — against the built application, in a CI-provisioned ephemeral environment.
7. **Deploy** (per `docs/architecture/technical-architecture.md` §8's "a real deploy stage" — the concrete deploy mechanics belong to Doc #22, not re-derived here).

Load testing (§2) runs on a separate, less-frequent cadence (e.g., nightly or pre-release, not on every PR) given its cost/duration relative to the rest of the pipeline — flagged here as a scope decision, not an oversight.

---

## 7. Out of Scope / Deferred

- Specific numeric load-testing pass/fail thresholds beyond referencing the NFR doc's existing targets (§2) — this document validates against those, it does not set new ones.
- Deploy pipeline mechanics (environments, secrets, the actual CD stage's steps) → Doc #22.
- Ongoing production performance monitoring/alerting (as opposed to pre-merge/pre-release testing) → Doc #23.
- Mutation testing / property-based testing adoption — not currently planned; a plausible future enhancement once the core suites above are mature, not a Phase 0 requirement.
