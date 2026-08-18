# Gamification Design Doc

**Project:** Form-Builder SaaS (`dev_formbuilder_app`)
**Doc #28.** The substrate decisions live in **ADR-0020** (`docs/adr/0020-gamification-awarding-substrate.md`); this document is the engine's specification and its as-built record.
**Status:** Draft v1.1 — **K1a BUILT (2026-08-17)**: the `point_awards` ledger, the `PointRule` vocabulary, `PointsRecorder`, the eight listeners, the plain `FormCreated` event, and the every-tier `gamification` module toggle. **K1b BUILT (2026-08-17)**: the `BadgeKey` catalog, the `badge_awards` ledger, `BadgeAwarder`, and `NotificationType::BadgeEarned`. **K1c BUILT (2026-08-18)**: the derived streak, workspace team progress, the two-source backfill and its operator command — plus the live `accept()` defect it uncovered and the seeded-ledger decision §10 had left open. **K1d BUILT (2026-08-18)**: the leaderboard, its two `/api/v1` routes, `RequireModule` and the `module_disabled` refusal. **K1e BUILT (2026-08-18)**: the achievements surface, the dashboard card, the sidebar affordance and its streak sidecar, plus `BadgeShelfService` — the one reader the engine still lacked. **THE ENGINE IS COMPLETE; nothing below is forward-spec.**

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

One method, `award(PointRule, ?string $userId, string $subjectType, string $subjectId, ?Carbon $awardedAt, bool $announceBadges = true)`, returning **true only when a row was genuinely created** — the distinction K1b's badge evaluation and K1c's backfill counter both depend on.

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

## 8. Streaks, team progress, and the backfill — BUILT

`App\Services\Gamification\{StreakCalculator,MemberStreak,TeamProgressService,TeamProgress}`, the replay
(`AuditReplayMap`, `GamificationBackfill`, `ReplayTenantHistoryJob`, `BackfillGamificationCommand`), and
`Form::scopePublished()`. ADR-0020 **§D10** records the two decisions this section had to take.

### 8.1 The streak

`DISTINCT (awarded_at AT TIME ZONE :zone)::date`, walked backwards, served by the
`(tenant_id, user_id, awarded_at)` index K1a already built for it. Nothing is stored: §D5's argument holds
unchanged, and Context §6's "the production box runs no scheduler" makes a maintained streak a thing that
would silently never update.

⚠️ **THE DAY BOUNDARY IS A STATED LITERAL, NOT `config('app.timezone')` — AND THE TWO AGREEING TODAY IS
EXACTLY WHAT MAKES READING THE CONFIG THE TEMPTING MISTAKE.** `StreakCalculator::DAY_BOUNDARY = 'UTC'`.
Reading the config would make the meaning of *a day* in every streak in the product depend on a setting
nobody thinks of as a gamification setting, and moving it would re-cut every member's history at once —
no migration, no announcement, nothing to notice. Nothing in the schema carries a per-tenant or per-user
zone (the only `timezone` column anywhere is `forms.timezone`, whose own migration says it is
authoring/display metadata never consulted server-side), so the boundary is a **parameter** with that
constant as its default: the day tenants gain a zone, the caller supplies it and this class does not change.

⚠️ **A STREAK SURVIVES "NOTHING YET TODAY" AND BREAKS AFTER A FULL MISSED DAY.** Requiring today's date
would zero every member in the product between midnight and whenever they next act, so the number somebody
sees at breakfast would not be the one they saw the night before.

⚠️ **ADJACENCY IS `addDay()->isSameDay()`, NEVER `diffInDays() === 1`.** Carbon 3 returns a **signed float**
there, so the strict comparison is false against `1.0` and every streak in the product would silently be
one day long. It is also DST-proof, which the parameterised zone above will eventually need.

`MemberStreak` carries `current`, `longest` and `lastActiveOn` from one walk. `current` decays and `longest`
only ever rises; a surface that shows one and calls it the other tells a member they have lost an
achievement they still hold.

### 8.2 Team progress

Workspace totals: points, responses, published forms, active members, badges, contributors.

⚠️ **`responses` AND THE SUM OF MEMBERS' `submission.collected` AWARDS DELIBERATELY DISAGREE, AND THE
DIFFERENCE IS EXACTLY THE GUEST SUBMISSIONS.** That is §D8's other half made concrete: the ladder credits
only the member on `submissions.respondent_user_id`, because crediting a form's owner for a public link
would decide "who collected the most data" by whoever published the busiest form — but a guest response is
still a response the workspace collected. On a tenant collecting mainly through public links the gap is most
of the total. **This is the single most likely thing here to be reported as a bug**, so it is stated on the
type itself, and the test asserts the DIFFERENCE rather than the total (a total agrees by accident on any
tenant with no guest rows).

`Submission::scopeCountable()` is reused rather than re-derived — ADR-0011 §D2's one definition of "a
response" — and `Form::scopePublished()` was **extracted** in this increment so that
`DashboardMetricsService::publishedFormsCount()` and team progress cannot drift about what "published"
means. There is no visibility conjunct: team progress is what the WORKSPACE did, and scoping it to grants
would give two members on one screen two different totals for one workspace. Who may *see* it is K1d/K1e's
gate, one layer up.

### 8.3 The backfill — and the row's own premise was false

⚠️⚠️ **THE ROW, §9 AND §D5 ALL SAID "REPLAY `audits` THROUGH THE SAME `PointRule` MAP". THERE IS NO SUCH
MAP, AND THREE OF THE SEVEN RULES ARE NOT IN THAT LEDGER AT ALL.** Verified against the code before a line
was written:

| Rule | In `audits`? | |
|---|---|---|
| `form.created` | ✅ | `('form','created')` — `FormService::create()` |
| `form.published` | ✅ | `('form_version','published')`; `auditable_id` **is** the version id the listener keys on |
| `submission.collected` | ⚠️ | `('submission','created')`, but the credited member is read from `submissions.respondent_user_id`, not from the audit's actor |
| `submission.reviewed` | 🔴 | `('submission','updated')` — **the same tuple as the next row** |
| `submission.edited` | 🔴 | `('submission','updated')` — written by a *second* service |
| `member.joined` | 🔴 | only the three self-serve doors; `accept()` writes no audit row |
| `member.invited` | 🔴 | **`invite()` writes no audit row whatsoever** |

So the backfill reads **`audits` for the five act rules and `tenant_users` for the two membership rules**
(`invited_by` / `invited_at` / `joined_at` — the authority on membership, complete for every door).

**Review versus edit is discriminated on the SHAPE of `new_values`.** `SubmissionReviewService::snapshot()`
always emits `remarks` and `returned_reason` and never an answer key; `SubmissionAnswerEditService` emits
four status keys plus one flattened `answers.<key>` per changed answer, and its own docblock records that
the prefix sits at the top level so `AuditRedactor` can match it. The two key sets are disjoint on exactly
those markers, and redaction cannot erase the signal — `AuditRedactor::apply()` replaces a sensitive value
in place and never removes its key. ⚠️ **Anything matching neither marker is counted as UNMAPPED, not
guessed**: an edit that changed no answers emits the status keys alone, and there is no honest way to read
that as a review. That count is what makes a future third writer of the tuple visible instead of silently
mis-scored.

⚠️ **`member.joined`'s subject is the USER id; the audit row's `auditable_id` is the MEMBERSHIP uuid.**
Keying on the wrong one does not collide with the live award — the subject is part of the idempotency index
— so it writes a SECOND row. A doubled score, with no error anywhere.

**Shape.** An operator command fanning out one `TenantAwareJob` per active tenant, per §D5. Each job takes
one **chunk** and re-dispatches itself: `TenantAwareJob` wraps everything in one transaction with a 60-second
timeout that an invariant holds below the queue's `retry_after`, so a single-shot replay of a large workspace
would be killed and roll back everything it had done — the tenant would never advance however often it
retried. `audits.id` is a **uuidv7**, so `ORDER BY id` is both chronological and covered by
`audits_tenant_recent_idx`; no index was added.

⚠️ **CHRONOLOGICAL WITHIN EACH RULE IS ALL THAT IS REQUIRED**, which is what makes two sources safe: every
badge counts exactly one `PointRule`, so the crossing row is the chronologically-Nth award of that rule
whatever order the rules ran in. Membership runs first, the audit walk second, with no merge and no sort
across the two. **Do not "fix" this into a merge sort.**

⚠️ **IT MUST NOT RUN IN A MIGRATION**, and the reason is sharper than "policy": a migration executes as
`meridian_app` with no tenant GUC, where the strict predicate resolves to `tenant_id = NULL` and matches
nothing — it would read zero rows, write zero rows, raise nothing and report success. The five backfills
under `app/Support/Migrations/` get away with it on the BYPASSRLS connection, and that route is closed here
on purpose: the whole safety argument for `PointsRecorder`'s raw INSERT is that the strict `WITH CHECK`
proves it wrote where it thought it did.

**`--dry-run` is the real replay inside a rolled-back transaction**, deliberately not a second counting
query — that would be a second authority on the same question, agreeing with the writer only by inspection.

**Every refusal is counted and reported**, because a silent zero is this feature's failure mode: `scanned`
must equal `created + existing + unmapped + uncredited`, the module being off is named rather than reported
as a long list of rows already held, and an invitation whose invitee this process may not read (the `users`
policy admits only *you* or an **active** co-member, and a tenant job runs with `current_user_id` unset) is
reported as uncredited rather than dropped by an inner join.

### 8.4 🔴 A live defect this increment found and fixed

**An invited member who accepted earned nothing.** `MemberJoined` was raised only from the private
`attachMember()`, which serves self-registration, SAML and Google. `accept()` — the commonest door — raised
no event, so an accepted invitation produced no `member.joined` points, **no `welcome` badge**, and no
notification to the Owner who had sent the invitation. Fixed rather than filed, because the alternative is a
scoreboard that permanently disagrees with itself: the backfill grants every historical invited member their
join points from `joined_at`, and the very next acceptance would grant none.

It emits **post-commit**, the opposite of `attachMember()`'s choice, and the difference is structural rather
than stylistic: only that method runs with no ambient tenant context, so only it must raise inside its own
transaction.

### 8.5 The demo fixture — the decision §9 left to K1c

Both seeders hand-roll their submissions and never raise `SubmissionCreated`, so every seeded workspace was
**form-shaped**: a product owner opening the achievements page would have found the main surface empty.
Of the three options on the record, **widening the audit tail to one row per submission is what §D1
refuses** — it makes what gets audited a scoring decision, taken by somebody not thinking about scoring, in
a compliance ledger. So the seeders award the acts they fabricate through `PointsRecorder` itself, which is
the posture they already have toward `FormService`, and call the backfill's own `replayMemberships()` for
the membership half rather than writing a third copy of those keys.

⚠️ **This is NOT the hand-seeded badge K1b refused.** That refusal was about a `badge_awards` row with no
ledger behind it. Every row here goes through the real writer and every badge is earned by the real
evaluator crossing a real threshold.

⚠️ **`announceBadges: false`, which diverges from the form badges in the same fixture — deliberately.**
These are back-dated acts, and the product's rule for back-dated acts is the one the backfill follows.
Announcing would also move the E2E fixture's `unread_count`, which several Playwright specs assert on and
which has nothing to do with gamification.

**Measured on a real `migrate:fresh --seed`, not predicted** (the K1b lesson applied): demo — 6
`form.created`, 5 `form.published`, 6 `member.joined`, 145 `submission.collected`, 152
`submission.reviewed`, **15 badges**; northwind — 6 badges. Badge notifications **unchanged** at 7 and 2.
Not one seeder fixture assertion moved, which was the point. `member.invited` is absent from both because
neither seeder records who invited whom, and `submission.edited` because no seeded submission is edited.

---

## 9. Gating

**One gate, `EntitlementService::feature('gamification')`**, which composes plan grant AND the tenant's module toggle. Because §D6 grants the key on every tier, the plan half can never be what switches it off — only `modules.gamification` can. A tenant with no subscription at all resolves to the seeded `free` plan, which carries the key, so gamification is on by default everywhere.

✅ **K1d did NOT mount `feature:gamification` as route middleware, and §9A.3 records what it mounted instead.** `RequireFeature` raises `FeatureGateException`, whose copy is *"Your plan doesn't include X. Upgrade your plan to use it."* — and since no plan withholds this key, the only way that gate can fire is a tenant that switched the module off itself, for whom the sentence is simply wrong. Gate the surfaces on the module toggle and give the refusal its own copy. K1a adds the `FeatureGateException::forKey()` label arm anyway, so that no 402 can ever render a raw snake_case key (ADR-0011 §D9's rule); the wrong-copy-on-self-disable shape is pre-existing and shared with all eleven toggleable keys.

---

## 9A. The leaderboard and its API — BUILT (K1d)

Two `/api/v1` routes, one read service, and no new table. `openapi.json` moved for the first time in this
engine (K1a–K1c added no route), regenerated with `scramble:export`, diffed, and verified byte-identical on
a **second** export.

```
GET /api/v1/gamification/me           ability:read:gamification                            module:gamification
GET /api/v1/gamification/leaderboard  ability:read:gamification  can:viewAny,PointAward     module:gamification
```

### 9A.1 The split, which is §D7 made structural rather than a matter of controller discipline

`me` carries **no `can:` gate** — every member sees their own points, badges, streak and standing, and the
payload names nobody but the caller. `leaderboard` carries `can:viewAny,PointAward`, a new
`PointAwardPolicy` whose single arm is the existing `dashboard.org.view`. **No thirtieth permission key is
minted** (§D7, a product decision of record).

The types enforce it rather than the controller remembering to: `MemberProgress` cannot hold a colleague's
name and `Scoreboard` is the only thing that can, so the ungated route has nothing to leak. `GamificationApiTest`
asserts the negative directly — a Form Editor's `me` body contains neither the owner's name nor their id.

⚠️ **`ApiAbilities::MANAGE_SCOPES` records a standing rule that a Group-B route without a `can:` gate breaks
the token-scope argument, so `me` is an argued exception rather than an unnoticed one.** That rule exists
because an any-of ability can be minted by a principal the route's real authorization would refuse — the
`can:` gate is what re-checks the acting user. There is no such gap here: the resource *is* the caller, a
token can never exceed its issuer, and no permission in the catalog would let one member read another's
standing on this route anyway.

### 9A.2 A fourteenth ability, and no new permission

`read:gamification` is **new**, never a widening of `read:analytics` — the fifth instance of the rule
`ApiAbilities` states four times already: folding the ladder in would retroactively hand every
already-minted analytics token a **named, per-person productivity ranking of the tenant's staff**, which no
issuer of those tokens agreed to. Like `read:analytics` it needs no RBAC key: it maps to
`dashboard.org.view ∨ dashboard.form.view`, so all five roles can mint it and read their own card, while the
policy withholds the named list from a Form Editor or Reviewer.

### 9A.3 The gate, and the sentence it must not say

`RequireModule` (alias `module:`) reads **only** `TenantSettingRegistry::moduleEnabled()`, never
`EntitlementService::feature()`. Refusal is `ModuleDisabledException` → **403 `module_disabled`**, not the
entitlement family's 402: nothing is owed, and the state is undoable from inside the workspace. It throws on
a key outside `ToggleableModules::KEYS`, because such a gate would pass forever while looking like it
guarded something. Off-tenant it passes, matching `RequireFeature`'s "no context to enforce against ⇒ no
enforcement" stance — every route it can be mounted on already sits behind tenant context.

⚠️ **THE ONLY TEST THAT CAN CATCH A WRONGLY-MOUNTED `feature:gamification` IS THE SELF-DISABLE CASE, AND
THAT IS A PROPERTY OF THE PLAN CATALOG RATHER THAN OF THE TESTS.** Every tier granting `api_access` also
grants `gamification` (Starter upward spreads the every-tier list), so **no plan fixture separates the two
keys**. The one state where the wrong gate fires is a tenant that switched the module off — and it fires
with the wrong sentence. `GamificationApiTest` therefore asserts on the string that must be **absent**.

⚠️ **AND A FREE TENANT HAS GAMIFICATION AND CANNOT REACH IT OVER THE API AT ALL**, because the whole
`/api/v1` group sits behind `feature:api_access`, which Free does not carry. Measured, not assumed — every
request 402s with `api_access` before any gamification code runs. That is a pre-existing property of the API
group rather than anything this row chose, and it means **K1e's web surface is the only door a Free tenant
has to this feature** — exactly the audience §D6 refused to put a tier ladder in front of.

### 9A.4 Who is on the ladder, and the three numbers that do not reconcile

The roster is `tenant_users` at `status = 'active'`, **not** the ledger — full reasoning in ADR-0020 §D11.
Members who have earned nothing appear, ranked last; members who have left do not appear and keep their
history. Rank is competition ranking, so a tie for 2nd is followed by 4th, and `of` counts the team rather
than the scorers.

⚠️ Points and badges are read as **two grouped statements, never joined**. One joined read multiplies a
member's award rows by their badge rows: with two awards and three badges, `SUM(points)` triples, silently
and plausibly. The test that pins it needs at least two of each — one of each returns the same number under
both shapes and would let the bug through.

The three deliberate disagreements between `team` and `entries` — guest submissions, and departed members in
two forms — are tabulated in §D11(c) and repeated in the endpoint's own OpenAPI description, because the
reader who most needs them is an integrator who will never open this file.

---

## 10. The increments, as built

~~**K1c — streaks, team progress, and the backfill.**~~ **BUILT — see §8, which records where this specification turned out to be wrong.** Streak = `DISTINCT awarded_at::date` walked backwards; the day boundary must be stated explicitly rather than inherited from the server's timezone. Team progress = workspace totals, which *do* count guest submissions (§D8's other half). The backfill is an operator command fanning out one `TenantAwareJob` per tenant, replaying `audits` through the same `PointRule` map. ⚠️ **It must not run inside a migration** — a migration executes with FORCE RLS and no tenant GUC, so `INSERT…SELECT` there writes zero rows silently.

  ⚠️⚠️ **AND K1b HAS ALREADY BUILT THE HALF OF THIS THAT WOULD OTHERWISE BITE, SO USE IT.** The backfill writes through `PointsRecorder::award()`, which returns true on a genuinely-new award — and badge evaluation hangs off exactly that. **A replay therefore earns badges for real, which is wanted, and would announce every one of them, which is not:** a long-standing member would be told about most of the catalog at once for things they did last year. `award()` takes **`announceBadges: false`** for this, and K1b's tests pin both the suppression and the default. ⚠️ Replay in **chronological order**: `awarded_at` is copied from the triggering award, so an out-of-order replay stamps a badge with whichever qualifying act happened to arrive first. It self-heals in membership but not in date.

  ⚠️ **The demo fixture is a K1c decision, recorded here so it is not rediscovered.** `DemoSeeder` hand-rolls its ~518 submissions rather than firing `SubmissionCreated`, and its audit tail is a hand-authored garnish rather than one row per submission — so replaying `audits` will **not** by itself give the demo tenant collection or review badges. Widen the audit tail, seed the ledger, or accept that the demo's badges stay form-shaped; all three are defensible, and none of them is K1b's to choose.

~~**K1d — the leaderboard and its API.**~~ **BUILT — see §9A**, which records the two things this specification did not settle: who is ON the ladder (§D11(a)) and what the twelve in "4th of 12" counts (§D11(b)). Both routes carry a PHPDoc summary first line for Redocly `operation-summary`; `openapi.json` was regenerated via `scramble:export`, diffed, and verified byte-identical on a second export.

~~**K1e — the UI.**~~ **BUILT — the achievements surface (`/achievements`), the dashboard card and the sidebar affordance.** The count badge is a JSON sidecar (`GET /achievements/streak`) read by `useMemberStreak`, exactly as this section required; `openapi.json` did not move, because both routes are tenant web routes. What the specification did not settle, and what building it decided:

  ⚠️ **THE "NAV AFFORDANCE" IS A FOUR-FILE COUPLING THAT IS MECHANICALLY ENFORCED, AND ONE OF THE FOUR BELONGS TO NEITHER LANE.** J7's `tests/Unit/Navigation/ShellAbilityParityTest.php` asserts that `nav-model.ts` and `app/Support/Search/DestinationCatalog.php` carry the same destination keys, **in the same order, with the same `(ability, feature)` tuple per key** — three cases, each failing with the key named. A sidebar item without a catalog row is a red gate rather than an omission, so global search reaches this page by construction. The item is also the **first** in the nav carrying a `feature` and no `gate`, which made a fourth combination assertable in that parser's anti-vacuity case for the first time.

  ⚠️ **THE NAV GATE IS `feature: 'gamification'` AND IT IS THE MODULE TOGGLE WEARING THE PLAN FIELD.** §D6 grants the key on every tier, so `EntitlementService::feature()`'s plan half can never refuse it and what remains is `modules.gamification` — the same thing `RequireModule` reads. A second client-side `module` axis was considered and refused. The two disagree in exactly one reachable state (an unseeded plan catalog, where `snapshot()` is null and the item hides while the route admits); that is the already-filed fail-open row, shared identically with the five other plan-gated destinations, and is not this row's to fix. ⚠️ **The dashboard card deliberately reads the OTHER axis** — `TenantSettingRegistry::moduleEnabled()`, the exact mirror of the middleware — because a card must not be withheld from a reader whose link would work.

  🔴 **`team` IS BEHIND THE LEADERBOARD GATE, NOT BESIDE IT, AND THIS IS THE ONE GATING CALL THE SPEC LEFT OPEN.** `TeamProgress` carries no colleague's *name*, so it reads as ungated — and serving it that way would hand a Form Editor the workspace-wide totals `DashboardMetricsService` is careful to withhold from them on exactly `dashboard.org.view`. That is a **widening of an existing permission performed by a new page**, which is the shape §D7 minted no thirtieth key in order to avoid. The web surface therefore matches `GamificationController::leaderboard()`: one `scoreboard` payload holding both, null for everyone else.

  ⚠️ **THE COUNT BADGE IS THE STREAK, AND WHAT IT IS NOT IS THE DESIGN** (user decision 2026-08-18). A rank would cost three statements on a route that fires on every navigation, because `standingFor()` ranks the whole tenant to answer for one member. A badge total is monotonic, so it reads as a tally rather than a signal — and the bell already announced each badge as it landed. The streak is the only number in the engine that **decays**, it costs one indexed read, and nothing else in the product shows it. ⚠️ **And the composable holds NO interval**, unlike `useNotificationFeed`: that one needed a timer because a notification arrives from outside the session, and then needed an idle-stop because every poll touches the session and `config/session.php` expires on inactivity. A streak changes only when the member acts, and acting is a navigation — so refetch-on-navigate is not a cheaper approximation of polling, it is strictly more correct and adds no second keep-alive.

  🔴 **AND THE SIDECAR HAD TO BE GATED ON THE DESTINATION'S OWN VISIBILITY, WHICH IS A DEFECT THE BUILD FOUND RATHER THAN A PRECAUTION.** `bootstrap/app.php` renders `ModuleDisabledException` on a non-`api/v1/*` path as `back()->with('toast', …)` — the branch keys on the request **path**, not on the `Accept` header. An unguarded `fetch` from a workspace that had switched gamification off therefore degrades harmlessly *in the client* while **writing a session flash on the server**, so the next Inertia render pops "switched off for this workspace" as a toast the member did nothing to provoke, on a random page, once per navigation forever. Nothing in the composable misbehaves while it happens.

  ⚠️ **THESE ARE THE FIRST `module:` GATES ON A WEB ROUTE** — K1d mounted the middleware on `/api/v1` only, so that `back()` arm had never executed. The refusal is verified not to redirect to `/achievements` itself.

  ⚠️ **`BadgeShelfService` IS THE ONE THING K1e HAD TO BUILD RATHER THAN CONSUME.** `BadgeAwarder` only writes and `LeaderboardService` only counts, so nothing could answer *"which badges do I hold, when did I earn them, and how close am I to the rest"*. Two grouped reads, **never joined** — the §D11(d) fan-out in its other form, where one statement returning "badge, date, and how many awards of its rule" multiplies each badge row by that rule's award rows. ⚠️ **Earned-ness is read from the ledger and is never derived from the count**, which is §D9 made structural: raise a threshold and everyone who earned the badge at the old one still holds it, with `progress` now *below* `threshold`.

  ⚠️ **THE EXCEPTIONS LOG WAS NOT SPENT, AND THE HAND-OFF SAYING IT SHOULD BE WAS WRONG ON THE SPEC.** Adding the `award` glyph is what DSR **Appendix B → Registry growth** asks for in its own words — *"add glyphs to `icons.ts` as features need them… Adding a glyph is a MINOR change"* — and `exceptions-log.md` #2's Disposition says *"extend it as features require"*. I1 added `share`, `link` and `qr` exactly this way and minted no entry. `#16` stays free; the glyph is documented in Appendix B instead, and `badge_earned` is re-pointed off the `trend-up` placeholder K1b left with a note naming K1e as the one to do it.

---

## 11. Open items and things found along the way

- ✅ **`exceptions-log` #16 is STILL NOT SPENT, and K1e settled that it should not be.** The hand-off expected the award glyph to need one. DSR Appendix B's *Registry growth* rule and `exceptions-log.md` #2's own Disposition both ask for exactly that addition, and I1 set the precedent by adding three glyphs with no entry — so a new glyph is conformance with the spec rather than a deviation from it. It is documented in Appendix B instead.
- ✅ **The media-resumability narrowing was ALREADY FILED — by K1c — and this bullet was STALE for two increments.** It read *"still unfiled… the first Lane B row after that file is released should"*, and K1e set out to discharge it. `docs/feature-backlog.md`'s *Discovered defects* section already carried the entry, signed **"found by P3a, filed by K1c"** — so K1e came within one edit of filing a duplicate of a row that had been there since 2026-08-18. ⚠️ **Recorded rather than quietly deleted, because the failure is the interesting part: a TODO in one document outlived the work that discharged it, and the only thing that caught it was opening the file the TODO pointed at before writing to it.** A specification is not evidence — including a specification's own list of what is left to do.
- **A test-environment boundary worth knowing before writing the K1c backfill tests.** `TenantMembershipService::invite()` resolves the global identity through `resolveUserByEmail()` on the **separate `pgsql_auth` connection**, which cannot see rows written inside `RefreshDatabase`'s uncommitted outer transaction. Inviting an address with no *committed* `users` row therefore succeeds once and dies on `users_email_unique` the second time. Nothing about that is reachable in production; a test must use `committedTenantIdentity()`. Recorded because it cost a wrong diagnosis first — the failure looks exactly like a case-sensitivity defect in the invite path, and it is not one.
