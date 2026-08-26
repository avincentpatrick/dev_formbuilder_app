# Lane B — active claim

**One writer: Lane B.** Lane A never edits this file, and Lane B never edits `lane-a.md`. That is
what makes a claim conflict structurally impossible rather than merely unlikely.

**The protocol is Standing Rule 7(g)**, not this header. In one line: **a claim is a *pushed*
commit** — write it here, `git push origin HEAD:main`, and only then open the first file. An
unpushed claim does not exist; M14 proved that by writing a perfect one that nobody could see.

**Before opening any shared or paired artefact**, `git fetch` and read both lane files.

Shared artefacts, which are claimed and never owned: `docs/**`, `openapi.json`, `phpunit.xml`,
`PROGRESS.md` (own block only), and the top-level `tests/e2e/*.spec.ts`.
Paired files — where a change obliges you to edit *both* halves in the same PR — are listed in
Standing Rule 7(b-bis). ⚠️ **The next Lane B row that touches `SyncStatus.vue` takes one**:
`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` lists it in an
exact-equality `KNOWN_UNGUARDED` assertion, so the list shrinks in the *same* PR as the fix.

---

## CLAIMED — M18, the SSO email-domain trust anchor (`m18-sso-domain-verification`)

Opened: 2026-08-26, cut from `origin/main` at `eb45eb9`. **Numbered M18**: M16 and M17 are Lane A's and
both merged; Lane A holds no claim, so the next free increment number is mine.

Row: `docs/feature-backlog.md:1514` — *"`major` · Nothing verifies that a workspace controls the email
domain its identity provider asserts."* Carried as `docs/security-threat-model.md` **residual 32** and
named as ADR-0016 §D33's revisit trigger.

**Files.**
*New:* `database/migrations/2026_08_17_000109_create_sso_verified_domains_table.php` ·
`database/migrations/2026_08_17_000110_recreate_sso_auth_failures_reason_check_for_unverified_domain_refusal.php` ·
`app/Models/SsoVerifiedDomain.php` · `app/Services/Sso/SsoDomainService.php` ·
`app/Console/Commands/Sso/SsoDomainsCommand.php` · `database/factories/SsoVerifiedDomainFactory.php` ·
`tests/Feature/Sso/SsoDomainVerificationTest.php`.
*Edited:* `app/Services/Sso/SsoUserProvisioner.php` · `app/Services/Sso/SsoAuthenticationException.php` ·
`app/Enums/SsoFailureReason.php` · `app/Support/Tenancy/TenantScopedTables.php` ·
`app/Support/Tenancy/ConstraintBoundaries.php` · `app/Support/Tenancy/TenantExtractColumns.php` ·
`config/saml.php` · `tests/Feature/Sso/SsoAcsWebTest.php` ·
`tests/Feature/Sso/SsoLoginCompletionWebTest.php` · `tests/Feature/Sso/SsoStepUpWebTest.php` ·
`docs/adr/0016-sso-saml-federation.md` · `docs/security-threat-model.md` · `docs/feature-backlog.md` ·
`docs/data-dictionary.md` · `docs/claims/lane-b.md` · `PROGRESS.md` (Lane B's block only).

⚠️ **EXTENDED 2026-08-26, BEFORE THE FILE WAS OPENED — `tests/Feature/Tenancy/TenantExtractColumnDriftTest.php`.**
A new tenant-scoped table joins `TenantScopedTables::STRICT`, which is the list the P2b extractor walks, so its
`EXTRACTED_COLUMN_CENSUS` gains an entry the moment the table exists — and that constant lives in the **test
file**, not in `TenantExtractColumns`. **Found by reading the gate rather than by CI reddening**, and it is
exactly the shape this claim's own prediction flagged as most likely wrong: a registry the `sso_auth_failures`
grep did not reach.

⚠️ **EXTENDED AGAIN 2026-08-26 — two more suites, and this time CI's local proxy found them rather than a
read of the gate: `tests/Feature/Sso/SsoAuthFailureLogTest.php` and `tests/Feature/Sso/SsoSettingsWebTest.php`.**
Four cases across them refuse with `domain_not_verified` where they used to refuse (or succeed) for their
own reason — three of M1's and M9's failure-panel cases, plus P1a's whole-round-trip canary. **Every one is
the control working on a fixture that has not verified a domain, which is the same thing that will happen to
every live deployment**, and the fix is the `SsoAcsWebTest` one: verify the suite's fixture domain in
`beforeEach` so each case keeps certifying what it was written to certify. `evil.test` in the failure-log
suite is deliberately left UNVERIFIED — it is an attacker fixture and now has a second reason to be refused.

⛔ **AND A PATH DEVIATION, RECORDED RATHER THAN QUIETLY ABSORBED: the artisan command shipped as
`app/Console/Commands/SsoDomainCommand.php`, not the `app/Console/Commands/Sso/SsoDomainsCommand.php` this
claim reserved.** All three existing commands in this repository sit flat in `app/Console/Commands/`, so a
one-file subdirectory would have been a new convention introduced by accident. The claim's substantive
property — that no other lane could be holding it — held either way, since the whole directory is
unassigned and Lane A has no console work. Corrected here because a claim nobody can trust to be accurate
is worse than no claim, which is M16's own lesson about a stale one.

✅ **THE PREDICTION THAT SAID THIS WOULD HAPPEN WAS RIGHT ABOUT THE SHAPE AND WRONG ABOUT THE PLACE.** It
named "a fourth registry" as the likely miss and said the tell would be a red gate naming a file the diff
does not touch. There was no fourth registry — `TenantScopedTables` and the extract census were the whole
set — and the misses were **test fixtures** instead. The tell was exactly as described.

✅ **AND THE SAME READ SETTLED TWO SCHEMA DECISIONS, SO `ConstraintBoundaries.php` AND ITS DRIFT TEST STAY
UNTOUCHED — BY DESIGN NOW, NOT BY ASSUMPTION.** `ConstraintBoundaryDriftTest` pins two censuses by exact
equality: composite FKs whose key contains `tenant_id`, and unique indexes on a `tenant_id`-carrying table whose
key does **not**. So (a) the table is scoped to the **TENANT** and carries **no** FK to `sso_connections` — a
workspace controls a domain, an IdP does not, and a metadata re-import must not destroy the proof of control —
which keeps the FK census still; and (b) its unique key is **`(tenant_id, domain)`**, never a global unique on
`domain`, which keeps the second sweep still. ⚠️ **(b) has a product consequence worth stating rather than
discovering: two workspaces may each verify the same email domain**, each with its own token. That is correct —
one controller can legitimately run two workspaces — and a global unique would have let whichever claimed first
deny the other.

⚠️ **THE THREE `app/Support/Tenancy/` REGISTRIES AND `SsoStepUpWebTest.php` ARE CLAIMED ON A PREDICTION,
NOT A PLAN** — a new tenant-scoped table may oblige all three registries, and the step-up suite may not
need touching at all because that arm never provisions. **Claimed anyway, because a file that might get
written to is a file another lane must not be holding**, whatever my current intention for it (M17's
`DnsRecordBlock.vue` precedent, released untouched by design).

**Shared artefacts taken:** four `docs/` files and `PROGRESS.md` (own block only). **`openapi.json` is NOT
taken** — no `/api/v1` route is reached, so it must stay byte-identical and that is an assertion rather
than a regeneration. **`ci.yml` and `phpunit.xml` are NOT taken.** **No `tests/e2e/*.spec.ts`** — grepped:
nothing there drives an SSO assertion.

**Paired files taken: NONE, and that is verified rather than assumed.** Neither 7(b-bis) PHP gate is
reachable: no `NotificationType` moves and no ability key moves (an artisan command carries no policy).
The third gate cannot fire — no `clip: rect(0 0 0 0)` is added or removed. ⚠️ **The one that looked like a
paired file is not one**: this row adds a `SsoFailureReason` case, and `resources/js/components/sso/types.ts:88`
types `SsoFailureRow.reason` as a plain `string` with `reason_label` and `hint` **composed server-side**,
its docblock saying in words that a second map in that package is forbidden. `cards.test.ts` uses two
string literals and enumerates nothing. **Grepped both trees: there is no parity gate on this enum.**

**Namespaces spent:** migration block `2026_08_17_000109` **AND** `2026_08_17_000110` — both, so the next
free prefix is `2026_08_17_000111`. ADR-0016 sub-decision **`§D34`** is **SPENT**. ⛔ **No new ADR number:
`0022` STAYS FREE and stays Lane A's block-opener.** This is an SSO trust decision inside ADR-0016's own
scope, and minting `0022` for it would spend the scarcer namespace to say the same thing. `0010` stays
reserved for H1d; `#16` stays free.

### What is already measured, so the plan is not built on the row's own framing

**All eight of the row's citations HOLD, which is the first time in nine rows.** `SsoUserProvisioner::provision()`
carries exactly the two refusals named, at `:110-126`; `app/Support/Tenancy/DnsTxtResolver.php` and
`CustomDomainService::verify()` are real and reusable; the grep for
`verified_domain|domain_verified|assertEmailDomain|domainOwn` returns **zero hits — not merely in the two
SSO directories the row names, but across all of `app/`, `database/` and `config/`**; residual 32 and
ADR-0016 §D33's revisit trigger are both present and say what the row says they say.

⛔ **BUT THE ROW UNDERSTATES ITS OWN SEVERITY, AND THE SWEEP FOUND TWO THINGS IT DOES NOT NAME.** It says
*"NOT LIVE — both known exploits are closed"*. Two consequences survive M9 and are live today:

1. ⚠️ **THE SSO DOOR WRITES A PLATFORM-GLOBAL IDENTITY FACT FROM A TENANT-SCOPED TRUST ROOT.**
   `SsoUserProvisioner::createUser():207` stamps `email_verified_at => now()`, and its docblock defends
   that as *"the IdP's claim rather than a convenience… the assertion is signed by the tenant's own trust
   anchor and names this address."* **That reasoning is exactly what this row attacks**: a trust anchor the
   asserting workspace installed vouches for nothing about a domain that workspace does not control. So a
   paying SSO tenant can mint a global `users` row for **any** unregistered address with a **forged
   mailbox-control claim** on it. And `TenantMembershipService::identityIsEstablished():877` **reads that
   very column**, so the forged stamp feeds M8's own authentication predicate: the true owner, invited
   later by their real employer, is refused the password-setting arm and sent to a sign-in-then-accept hop
   they cannot complete. **Identity squatting plus a denial of M8's recovery path — filed nowhere.**
2. ⚠️ **A CROSS-TENANT ACCOUNT-EXISTENCE ORACLE ON THE FAILURES PANEL.** `existing_account_not_member`
   renders as *"Address already has an account elsewhere"* while `jit_disabled` renders as *"Nobody here
   matches that address"*. An SSO-entitled admin can therefore assert any address and read back, from
   their own settings page, whether it has an account anywhere in the deployment — having proven nothing
   about the domain. §D19's uniform-404 posture is intact on the wire and is simply not the surface that
   leaks.

✅ **THE ENFORCEMENT POINT IS SINGULAR, AND THAT IS GREPPED RATHER THAN ASSUMED.** `SsoIdentity` has exactly
two consumers: `SsoUserProvisioner::provision()` and `SsoStepUpService::matchSubject()`. The step-up arm is
already bound — it compares the resolved user against `sso_auth_requests.user_id`, written before the
redirect by an authenticated session — so it never lets an address *select* a person. `provision()` is the
whole seam, exactly as the audit said.

✅ **THE GRANDFATHERING CALL THE ROW CALLS "the part that makes it a decision rather than a feature" IS
DISSOLVED RATHER THAN ANSWERED, AND THE PREDICATE IS VERIFIED.** The gate sits **after** the `Active`
early-return, so **an Active membership IS the grandfather** — no backfill, no per-connection mode column,
no public-mailbox exclusion list, and **not one existing member of any live deployment is locked out.**
That rests on a claim I enumerated instead of reasoning about: the only writers of
`TenantUserStatus::Active` are `accept()` (needs the emailed token *and*, since M8, either a fresh identity
or the real person signed in as themselves), `joinOpenTenant()` (self-registration, which is a separate and
older door where nothing is forged), `joinViaGoogle()` (Google verified the mailbox) and `joinViaSso()`
(downstream of this gate). **No door mints an Active row for a stranger's address on an assertion alone.**
⛔ Rejected: *"enforce only once a connection has verified a domain"* — that is opt-in security whose switch
is held by the threat actor, and it closes nothing.

⚠️ **THE ORDER INSIDE `provision()` IS LOAD-BEARING, WHICH IS M12's LESSON POINTED AT A NEW METHOD.** The
domain check goes **before** both identity refusals, not after: that is what closes the oracle in (2), and
it makes the panel's answer the one the admin can act on. **The cost is stated rather than discovered — it
moves M1's and M9's own regression cases**, which assert `existing_account_not_member` and
`established_identity_not_joined` against `@identity.test` addresses on an Acme connection. Those cases
gain a verified domain so they keep certifying their own refusal, which makes them assert the **stronger**
statement (*even with the domain verified, adoption is refused*), and new cases cover the gate firing ahead
of them.

✅ **THE FIXTURE DOMAINS ARE FIXED, NOT FAKER-RANDOM, SO THE M9 DICE-ROLL TRAP DOES NOT APPLY HERE.**
`FakeIdp::$nameId` is the literal `ada@acme.test` and `tests/Pest.php:157` builds committed identities as
`Str::random(12).'@identity.test'` — **the local part is random and the domain is not.** Two verified-domain
rows in the shared `beforeEach` therefore cover the 42-case ACS suite and the 22-case completion suite.

### Deliberately NOT in this PR, each filed rather than dropped

- **The tenant-facing `/settings/sso` domain panel.** It is `resources/js/**`, which is **Lane A's outright**
  since the 2026-08-25 widening, and splitting a paired change across two lanes is the one thing 7(b-bis)
  says cannot work. The interim surface is `php artisan sso:domains`, which is the `domains:activate`
  precedent in ADR-0012 §D6. **Filed as a Lane A backlog row.**
- **Periodic re-verification of a verified domain.** `CustomDomainService::sweep()` re-reads on a cadence as
  its dangling-DNS control; this table gets `verification_checked_at` so the column is there, but no sweep
  job, because `routes/console.php` records that nothing runs the scheduler on the production box and trust
  decay is a product call rather than a defect. **Filed, with the residual named.**
- **`MemberController::invite()`'s missing domain check**, which is the same root on the invitation door and
  is named in this row's own chain. Out of scope here and filed separately.

### Prediction, written before the run so the measurement has something to disagree with

- **CI Pest MOVES, and it is the only gate that should.** ~4515 → ~4545 (+25 to +35 new cases), with the
  existing 4515 all still passing; 2 pre-existing warnings unchanged.
- **PHPStan CI `[OK]`; local 18 = baseline BY FILE LIST, not by count** — a new model brings new
  `property.notFound` phantoms local PHPStan invents and CI does not, so the count may rise and the file
  list is the measurement.
- **Four host lint gates: 97 controllers unchanged · 111 → 113 migrations · 31 jobs unchanged ·
  constraint-boundary 111/119/0 MOVES** (a new tenant-scoped table adds constrained columns), read without
  a pipe.
- **`openapi.json` byte-identical** — asserted from the diff, no `/api/v1` route is reached.
- **Vitest 130 files / 2,213 and axe 42 / 299 unmoved, and ASSERTED rather than re-measured**, because no
  `.vue`, no `packages/` source and no `resources/` file is in the diff. **E2E 551 passed + 10 skipped, no
  flaky line.**
- ⚠️ **The prediction I most expect to be wrong: that the three `app/Support/Tenancy/` registries are the
  complete set a new tenant-scoped table must join.** I found `TenantScopedTables::STRICT` and three drift
  tests by grepping for `sso_auth_failures`, which is a floor and not a census — the same limitation
  7(b-bis)'s own sweep records about itself. **If a fourth registry exists, the first red gate will name a
  file this diff does not touch, and the rule is to treat that as structural rather than as a flake.**

## RELEASED — M15, the respondent-scoped device outbox (merged as PR #207, `f052dd5`, 6/6)

Every claimed file was edited except one: `resources/public-runtime/components/SubmissionOutbox.vue` was
claimed and **not touched**, because scoping `rows` upstream means the list simply receives fewer — which
is also why every selector `tests/e2e/public-runtime-offline.spec.ts` locates survived untouched. The claim
was **not extended** mid-build; nothing outside it was opened.

**Both halves of the 7(b-bis) paired file moved in the one PR**, which is what that rule exists to force:
`packages/design-system/src/theme/__tests__/clipped-node-containment.test.ts` lost its `SyncStatus.vue`
entry in the same commit as `.sync-status` gained `position: relative`. `RuntimeShell.vue` stays listed —
untouched here, and a containing block added without a look at the running app is what that file refuses.

**Namespaces:** ADR **`0021`** is **SPENT**. Next free overall is **`0022`**; `0010` stays reserved for
H1d and `#16` stays free; ADR-0016's `§D34` and the migration block `2026_08_17_000109` are **UNSPENT** —
M15 touched no PHP and added no migration.

**Prediction vs measurement, since the point of writing one first is to be measured against it.**
The file counts were exact and the test count was under-predicted: predicted `public-runtime` 35 files /
~765 and a repo total of 130 / ~2,196; **measured 35 / 782 and 130 / 2,213**. `design-system` 35/545,
`resources/js` 60/886, Pest **4515 / 19,161 with 2 warnings**, E2E **551 passed + 10 skipped**, axe,
the four lint gates, the byte-identical `openapi.json` and the absence of a Dexie version bump all held
exactly as predicted. **One prediction was wrong in a useful direction**: I expected to have to fix
`sync-outbox.test.ts:194-195` and `outbox.test.ts:191-203`, because their fixtures build rows with no
session. In the event **one** existing assertion in the whole suite needed changing — `listSubmissions
honours its limit`, an options-object signature change — because an unscoped driver deliberately answers
the pre-M15 device-wide numbers, so every existing case kept meaning what it meant.

---

## Template

```
## CLAIMED — <row name> (<branch>)
Opened: <date>. Row: <the backlog row, quoted enough to identify it>.
Files: <every file to be edited, repo-relative>.
Shared artefacts taken: <docs/…, openapi.json, … — or "none">.
Paired files taken: <7(b-bis) entries, and the other half of each — or "none">.
Namespaces spent: <migration prefix / ADR number — or "nothing from either namespace">.
Prediction: <what you expect the gates to do, written BEFORE the run so it can be measured
             against rather than explained afterwards>.

## RELEASED — <row name> (merged as PR #<n>, <sha>, 6/6)
<what was actually taken; whether every claimed file was edited; anything the claim was
 extended to mid-build, each of which was its own pushed commit before the file was opened>
```
