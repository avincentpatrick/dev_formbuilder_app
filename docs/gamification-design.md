# Gamification Design Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Doc #28.** The substrate decisions live in **ADR-0020** (`docs/adr/0020-gamification-awarding-substrate.md`); this document is the engine's specification and its as-built record.
**Status:** Draft v1.1 — **K1a BUILT (2026-08-17)**: the `point_awards` ledger, the `PointRule` vocabulary, `PointsRecorder`, the eight listeners, the plain `FormCreated` event, and the every-tier `gamification` module toggle. **K1b BUILT (2026-08-17)**: the `BadgeKey` catalog, the `badge_awards` ledger, `BadgeAwarder`, and `NotificationType::BadgeEarned`. **Forward-spec below:** streaks + team progress + the `audits` backfill (K1c), the leaderboard and its API (K1d), the UI (K1e).

> **Why this arrives last, on purpose.** The 2026-08-09 decision of record (`docs/PRD.md:128`) places the full achievements system as *the very last increment before production deployment*, so that it is **designed once against the finished feature set** rather than retrofitted per feature. Everything it scores already exists; nothing here asks another vertical to change.

---

## 1. Scope, and the line against J5

**In scope (this document):** points, badges, streaks, team progress, leaderboards, notification integration, and a per-tenant opt-out.

**Not in scope — J5 owns it:** the getting-started checklist. ⚠️ The two are easy to conflate and must not be. J5's `GettingStartedChecklist` is **100% derived and stateless** — it recomputes done-ness per request from live KPI counts, and the entire feature persists **one byte** (`tenant_users.onboarding_dismissed_at`). It records that a step *is currently true*, never *when it became true*, so an "earned on 12 Jul" date is unrecoverable from it and this engine cannot be built on top of it.

**What this engine reuses from J5 rather than re-deriving** (a second copy is the drift):

| Reuse | Where |
|---|---|
| `MdsChecklist` / `MdsProgress`, and the **"N of M, never a percentage"** rule | `packages/design-system/src/components/Checklist/` |
| `DashboardMetricsService::visibleFormsQuery()` / `publishedFormsCount()` — the single definition of "forms this user's dashboard counts" | `app/Services/Dashboard/DashboardMetricsService.php` |
| `ShellAbilities::for()` and its two degradation rules (drop an instruction the reader cannot carry out; keep a fact but strip its href) | `app/Support/Authorization/ShellAbilities.php` |
| The four step keys `create_form` / `publish_form` / `first_response` / `invite_teammate` | `app/Services/Onboarding/GettingStartedChecklist.php` |

**Also out of scope, deliberately:** a scripted tour. `docs/onboarding-template-content-plan.md:20` rejects one on the record — *"no forced tour beyond this one choice point"* — and nothing here reopens it. Gamification is a passive affordance, never a gate in front of the product.

---

## 2. What's already decided (confirmed here, not re-derived)

- **The award substrate** — an append-only ledger written by synchronous listeners, ADR-0020 §D1.
- **No new `DomainEventType` case** — ADR-0020 §D2. The engine consumes the existing eight and adds one *plain* event.
- **Exactly-once by index, honoured with `ON CONFLICT DO NOTHING`** — ADR-0020 §D3.
- **A badge stores its key and its date and nothing else** — ADR-0020 §D9. The key is the identity, so re-thresholding moves future earnings only and the ledger can always re-answer "would they still qualify".
- **`append_only` RLS; weights copied at award time** — ADR-0020 §D4. Re-weighting moves future awards only.
- **Streaks derived, history backfilled from `audits`** — ADR-0020 §D5 (product decision 2026-08-17).
- **Free on every plan tier; the tenant's own toggle is the only control** — ADR-0020 §D6 (product decision 2026-08-17).
- **Leaderboard reuses `dashboard.org.view`; no thirtieth permission key** — ADR-0020 §D7 (product decision 2026-08-17).
- **A `guest` submission credits nobody** — ADR-0020 §D8.

---

## 3. The scoring vocabulary (`App\Enums\PointRule`) — BUILT

The weights are a product statement, not a constant table: collection out-earns everything over any real period because it is high-frequency and it *is* the product; publishing out-earns creating because a draft nobody can answer has produced nothing yet; editing is worth least because it is correction rather than new evidence.

| Rule | Points | Source signal | Credited to | Subject (the idempotency key) |
|---|---|---|---|---|
| `form.published` | 25 | `FormPublished` | publisher | `form_version` — republishing a revision earns again; versions are immutable (ADR-0013) so one publication cannot be farmed |
| `member.invited` | 15 | `MemberInvited` | inviter | `invite` — SHA-256 of the normalized email |
| `form.created` | 10 | `FormCreated` *(new, plain)* | creator | `form` |
| `member.joined` | 10 | `MemberJoined` | the joiner | `member` — the only non-repeatable rule |
| `submission.collected` | 5 | `SubmissionCreated`, **only where `respondent_user_id` is set** | the collector | `submission` |
| `submission.reviewed` | 3 | `SubmissionApproved` **and** `SubmissionReturned` | reviewer | `submission` |
| `submission.edited` | 1 | `SubmissionUpdated` | editor | `submission` |

⚠️ **Four of the seven acts are unbounded** — review, edit, invite and publish can each be repeated indefinitely by one person against one object. The index is the only thing between them and an infinite score, which is why no part of the key is nullable (ADR-0020 §D3a) and why the tests repeat every act.

⚠️ **Both review outcomes write the SAME rule against the SAME subject**, so one reviewer scores a given submission once, ever, however many approve/return passes it takes. That is deliberate anti-farming: the alternative makes an unbounded approve/return loop the most efficient way to top the ladder, rewarding churning the queue over clearing it. A *second* reviewer earns their own award — `user_id` is part of the key.

---

## 4. The ledger (`point_awards`) — BUILT

`database/migrations/2026_08_17_000101_create_point_awards_table.php`, data-dictionary §31.

| Column | Notes |
|---|---|
| `id` | `bigIncrements` — an internal aggregation row never addressed externally (the `usage_counters` precedent); the model does **not** use `HasUuidv7` |
| `tenant_id`, `user_id` | FK to `tenants`/`users`, `cascadeOnDelete`. Single-column into `users` because it is a **global** table — ADR-0002 §D5's composite form applies to tenant-scoped parents |
| `rule` | `string(30)`, CHECK generated from `PointRule::values()` so enum and constraint cannot drift |
| `points` | copied from `PointRule::points()` **at award time**, never joined at read time; CHECK `> 0` |
| `subject_type`, `subject_id` | `string(20)` / `string(64)`, **both NOT NULL**. The string width is for `member.invited` alone (a 64-char digest); everything else stores a uuid |
| `awarded_at` | when the **act** happened — distinct from `created_at`, which is when the row was written. The backfill depends on the difference |

**Indexes.** `unique(tenant_id, user_id, rule, subject_type, subject_id)` — the idempotency guard, treated by every writer as "already awarded" rather than as an error. `index(tenant_id, user_id, awarded_at)` — totals, the ladder and the streak walk all read (tenant, member) ordered by day.

**RLS: `append_only`.** SELECT + INSERT policies only; no UPDATE or DELETE policy exists, so FORCE RLS denies mutation to every role including the table owner. `PointAwardRlsTest` asserts the **absence** of those two policies, because their later appearance would not be a refinement — it would be the ledger becoming rewritable.

---

## 5. The writer (`App\Services\Gamification\PointsRecorder`) — BUILT

One method, `award(PointRule, ?string $userId, string $subjectType, string $subjectId, ?Carbon $awardedAt)`, returning **true only when a row was genuinely created** — the distinction K1b's badge evaluation and K1c's backfill counter both depend on.

Three properties, each of which had to be argued:

1. **It cannot break the act it scores.** The whole body is a swallow-and-log guard on the `UsageMeter` precedent. A respondent's submission or a member's form must persist even if the scoreboard throws.
2. **It cannot poison a caller's transaction.** `ON CONFLICT DO NOTHING` never raises, so there is no exception to catch — see ADR-0020 §D3b for why a caught 23505 would be worse than the duplicate it prevents.
3. **It writes raw SQL and passes `tenant_id` explicitly**, bypassing `BelongsToTenant`. That is safe *because* an RLS **INSERT** refusal raises `42501` rather than silently affecting zero rows the way a filtered UPDATE does — so a mis-scoped write lands in the log rather than disappearing. `PointAwardRlsTest` pins that asymmetry directly, since the recorder's swallow-and-log would hide every lost award if it ever inverted.

`PointsRecorder::emailSubject()` is the shared hash for `member.invited`, deliberately *not* duplicated into K1c's backfill: two copies of a hash rule is two chances to disagree, and the disagreement would present as duplicate awards rather than as an error.

---

## 6. The listeners — BUILT

Eight classes under `app/Listeners/Gamification/`, one per event, matching the shape all twenty-five existing listeners take.

⚠️ **All synchronous, never `ShouldQueue`.** A queued listener runs on a worker under a NULL tenant GUC where every RLS-scoped read finds nothing and every write is refused. This is stated at `MeterSubmissionUsage.php:17-22` and repeated at every listener in the codebase for a reason.

⚠️ **Emission timing differs by caller, and the difference is structural.** `FormCreated` is raised **post-commit** from `FormService::create()` — the ordinary in-request case, where session-scoped tenant context outlives the commit, so scoring can never roll back a real user's form. `MemberJoined` fires **inside** its transaction, because `joinOpenTenant()` is the one membership write with no ambient context: it uses `applyLocal()`, whose `SET LOCAL` GUC dies at commit, so a post-commit RLS write there would be refused outright. `AwardPointsForMemberJoined` is therefore the sharpest case in the codebase for §5's point 2, and is tested through the **real** join path rather than a hand-fired event.

⚠️ **`FormService::create()` has TWO callers.** `TemplateService::instantiate()` wraps it in its own `DB::transaction`, so the post-commit emission fires while an outer transaction is still open. Both properties above had to hold there, and a test drives that path specifically.

---

## 7. The badge catalog and its evaluator — BUILT

> **A note on this document's shape, so no future increment renumbers it again.** Gating, the forward spec
> and open items are always the **last three** sections. Each increment inserts its BUILT section
> immediately before them. K1b moved §7–§9 down by one to establish that; K1c–K1e should not have to.

`App\Enums\BadgeKey` (10 cases), `badge_awards` (`2026_08_17_000102`), `App\Services\Gamification\BadgeAwarder`, data-dictionary §32.

**Every criterion is the same shape — N awards of one `PointRule`.** That uniformity is not a simplification; it is what makes evaluation free. The idempotency guard on `point_awards` is `unique(tenant_id, user_id, rule, subject_type, subject_id)`, whose **leading prefix is exactly `(tenant_id, user_id, rule)`** — so "how many times has this member done this?" is an index-only scan on an index that already exists. And because evaluation runs only on a genuinely-new award, only badges keyed on the rule that just fired can have changed: **one rule, one count, per award.**

⚠️ **There is deliberately no second criterion kind, though one is coming.** A *total points* criterion would break both halves (a SUM across every rule, on every award) and could not state itself honestly — §D4 makes `points` a per-row historical value, so "500 points" means a different amount of work depending on when you did it. *Streak* badges are real and are **K1c's**, because a streak needs a `DISTINCT awarded_at::date` walk over a day boundary K1c has not yet chosen. A union whose second arm has no service behind it is a case with no emission site — the shape `DomainEventType`'s docblock warns about and `PointRule` refuses for `isRepeatable()`. **K1b's catalog is therefore depth-only: it rewards doing one thing a lot. Breadth-over-time is the orthogonal axis and it is scheduled, not missed.**

| Badge | Rule | Threshold | Why this shape |
|---|---|---|---|
| `welcome` | `member.joined` | 1 | The only bounded rule, so it cannot be farmed or missed. Awarded, **never announced** — see below. |
| `first_form` | `form.created` | 1 | J5's `create_form` step, now with a recoverable date attached. |
| `first_publish` · `publisher` | `form.published` | 1 · 3 | J5's `publish_form` step, then "this workspace is running". |
| `first_response` · `collector` · `field_veteran` | `submission.collected` | 1 · 25 · 250 | Three rungs because it is high-frequency and it **is** the product. |
| `first_review` · `reviewer` | `submission.reviewed` | 1 · 50 | Bounded by collection volume *and* by who holds the reviewer role, so two rungs is the honest depth. |
| `recruiter` | `member.invited` | 5 | Role-gated by `tenant.members.invite`; a team, not a single admin click. |

⚠️ **`submission.edited` earns no badge, and it is the one absence that would be actively harmful to fill.** The rule is already exactly-once per (editor, submission), so a threshold of N cannot be farmed against one row — it requires editing **N distinct submissions**, and the cheapest way to do that is to open N responses, touch a space and save. That writes N audit rows and bumps `updated_at` on N rows of real research data. It is the only rule whose act **mutates collected evidence**, and its weight of 1 is this product's recorded opinion of it. A badge would contradict the weight §3 spends a paragraph justifying. `BadgeCatalogTest` pins the absence, because "we forgot" and "we decided" look identical in an enum.

⚠️ **The `publisher` threshold was written as 10 first and corrected to 3.** A workspace is a handful of forms — the seeded demo tenant has **seven in total** — so a publishing tier of ten is unreachable by any member of a workspace of this product's actual shape. Not a tier; a dead rung.

**`welcome` is awarded but never announced**, and `BadgeKey::announces()` is the branch. Its criterion cannot be failed, so it lands in the same request that creates the membership — a bell row telling a person who just joined that they have joined, in the noisiest minute of a new member's life. It is still awarded, because it carries an earned-on date nothing else in the schema exposes to the member (`tenant_users.joined_at` has no reader) and because it keeps a brand-new achievements surface from being blank. ⚠️ This is **not** the `isRepeatable()` shape: that predicate was refused because nothing would consult it, and this one is consulted by the evaluator and changes behaviour.

**The ledger.** `unique(tenant_id, user_id, badge)`, `append_only` RLS, `awarded_at` copied from the **triggering point award** rather than the clock. **No second index** — every read this engine makes is "this member's badges" or "this workspace's badges", both served by the unique's leading columns, and a member holds at most ten rows. **No `threshold` column** — see ADR-0020 §D9.

**The evaluator.** Called from `PointsRecorder::award()` and only on a genuinely-new award. Three properties, each of which had to be argued:

1. **The `<=` comparison is a repair mechanism, not a style choice.** Under READ COMMITTED two concurrent awards each see the same count, so adjacent tiers can be stepped over by both — nobody observes the number that would have granted the higher one. With `<=` the next award of that rule grants it; with `==` it would be lost forever.
2. **It announces from the write's own result, never from the count.** Evaluation runs on every matching award, so a member's thousandth response re-offers `first_response` for the thousandth time; 999 of those affect zero rows. Announcing on "the threshold was met" would send a thousand notifications for one badge — and **every badge-row assertion would still be green**, which is why the replay test asserts on `notifications`.
3. ⚠️ **It holds its own SAVEPOINT, and that is the part K1a's argument does not cover.** K1a could say "`ON CONFLICT DO NOTHING` never raises, so there is nothing to catch". Three things here can raise — the count, a `42501` RLS refusal, and a `23514` from a `BadgeKey` case added without widening its CHECK — and in PostgreSQL a raise aborts the *whole* transaction, so a bare `catch` swallows the error and hands the caller a transaction that is already dead. `DB::transaction()` issues a SAVEPOINT when nested (the `SavedReportViewService` / `SubmissionReferenceBackfill` precedent, the second of which records that without it its own effect test failed on `25P02`). The **announcement sits outside that savepoint**: inside it, a failed notification would roll the badge row back, and the next award would re-earn and re-fail forever. The earned fact outranks telling somebody about it.

**What the demo shows, so a sparse page is not read as a bug.** `DemoSeeder` drives `FormService::create()` and `PublishService::publish()` for real, so the demo tenant's ledger is **form-shaped**: publishing badges appear, collection and review badges do not, because the seeder hand-rolls its ~518 submissions rather than firing `SubmissionCreated`. K1b seeds **no** badge rows deliberately — a hand-seeded badge with no ledger behind it is a fixture asserting something the engine would never produce, and `append_only` would make a wrong one permanent. Widening the demo's ledger is K1c's decision, alongside the `audits` backfill.

---

## 8. Gating

**One gate, `EntitlementService::feature('gamification')`**, which composes plan grant AND the tenant's module toggle. Because §D6 grants the key on every tier, the plan half can never be what switches it off — only `modules.gamification` can. A tenant with no subscription at all resolves to the seeded `free` plan, which carries the key, so gamification is on by default everywhere.

⚠️ **K1d must NOT mount `feature:gamification` as route middleware.** `RequireFeature` raises `FeatureGateException`, whose copy is *"Your plan doesn't include X. Upgrade your plan to use it."* — and since no plan withholds this key, the only way that gate can fire is a tenant that switched the module off itself, for whom the sentence is simply wrong. Gate the surfaces on the module toggle and give the refusal its own copy. K1a adds the `FeatureGateException::forKey()` label arm anyway, so that no 402 can ever render a raw snake_case key (ADR-0011 §D9's rule); the wrong-copy-on-self-disable shape is pre-existing and shared with all eleven toggleable keys.

---

## 9. Forward spec — the remaining increments

**K1c — streaks, team progress, and the backfill.** Streak = `DISTINCT awarded_at::date` walked backwards; the day boundary must be stated explicitly rather than inherited from the server's timezone. Team progress = workspace totals, which *do* count guest submissions (§D8's other half). The backfill is an operator command fanning out one `TenantAwareJob` per tenant, replaying `audits` through the same `PointRule` map. ⚠️ **It must not run inside a migration** — a migration executes with FORCE RLS and no tenant GUC, so `INSERT…SELECT` there writes zero rows silently.

  ⚠️⚠️ **AND K1b HAS ALREADY BUILT THE HALF OF THIS THAT WOULD OTHERWISE BITE, SO USE IT.** The backfill writes through `PointsRecorder::award()`, which returns true on a genuinely-new award — and badge evaluation hangs off exactly that. **A replay therefore earns badges for real, which is wanted, and would announce every one of them, which is not:** a long-standing member would be told about most of the catalog at once for things they did last year. `award()` takes **`announceBadges: false`** for this, and K1b's tests pin both the suppression and the default. ⚠️ Replay in **chronological order**: `awarded_at` is copied from the triggering award, so an out-of-order replay stamps a badge with whichever qualifying act happened to arrive first. It self-heals in membership but not in date.

  ⚠️ **The demo fixture is a K1c decision, recorded here so it is not rediscovered.** `DemoSeeder` hand-rolls its ~518 submissions rather than firing `SubmissionCreated`, and its audit tail is a hand-authored garnish rather than one row per submission — so replaying `audits` will **not** by itself give the demo tenant collection or review badges. Widen the audit tail, seed the ledger, or accept that the demo's badges stay form-shaped; all three are defensible, and none of them is K1b's to choose.

**K1d — the leaderboard and its API.** Own standing for everyone; the named ranked list gated on `dashboard.org.view` (§D7). `/api/v1` endpoint with a **PHPDoc summary first line** (Redocly `operation-summary`), `openapi.json` regenerated via `scramble:export`, diffed, and re-verified stable on a second run.

**K1e — the UI.** An achievements surface, a dashboard tile and a nav affordance. ⚠️ **Runs LAST, after J5 merges** — `Dashboard.vue`, `DashboardController`, `DashboardMetricsService` and `packages/design-system/` are all Lane A's live J5 claim. The count badge must be a **JSON sidecar route + composable**, never a shared Inertia prop: `routes/tenant.php:157-166` records that an Inertia partial reload re-runs the current page's controller, so a shared prop pays a query on every page in the app.

---

## 10. Open items and things found along the way

- **`exceptions-log` #16 is NOT spent.** K1a–K1d ship no design-system deviation. K1e may need it.
- **The media-resumability narrowing is still unfiled.** `docs/architecture/technical-architecture.md` §4.2 promises *resumable* media sync; G8b shipped whole-file upload with per-file retry. It belongs in `docs/feature-backlog.md`, which Lane A has held since J4b and still holds for J5 — P3a could not file it and neither can this increment. The first Lane B row after that file is released should.
- **A test-environment boundary worth knowing before writing the K1c backfill tests.** `TenantMembershipService::invite()` resolves the global identity through `resolveUserByEmail()` on the **separate `pgsql_auth` connection**, which cannot see rows written inside `RefreshDatabase`'s uncommitted outer transaction. Inviting an address with no *committed* `users` row therefore succeeds once and dies on `users_email_unique` the second time. Nothing about that is reachable in production; a test must use `committedTenantIdentity()`. Recorded because it cost a wrong diagnosis first — the failure looks exactly like a case-sensitivity defect in the invite path, and it is not one.
