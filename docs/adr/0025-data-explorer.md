# ADR-0025: The Data Explorer (a member's own charts, as declarations checked against a catalogue; answers read from where they are stored; no charting library)

## Status

**Accepted — 2026-11-11.** Built by `M236`, from the user's request of the same day: *"can you build a module in here separate from the built in cards where i can utilize all available data sources to do my own visual analysis like a powerbi or a tableau"*. `/analytics` (ADR `0011-analytics-substrate.md`) answers a fixed set of questions with fixed cards; a reader chooses one of five axes and nothing else. This ADR adds a second page, `/explore`, where a member picks a data source, says what to group and what to count, and keeps the chart. **Decision: a chart is a declaration, never a query.** The page sends the names of a source and its fields; the server holds the only list of what those names mean, runs the declaration as bounded live SQL under the request's own tenant context, and returns numbers. Nothing a reader types reaches a statement except as a bound value. Three of the choices below were the user's, filed as `D217`, `D218` and `D219` in `docs/claims/decisions.md`; each was built on its recommendation, and the user chose that recommendation for all three on 2026-11-12.

- **Deciders:** the user (the request), and the session that built it.
- **Related ADRs:** `0011-analytics-substrate.md` — §D2's countable predicate, §D7's bounded live SQL and §D10–§D12's chart rules are kept as written; §D3 and §D10 are extended, and each carries a dated note pointing here. `0002-multi-tenancy-shared-db-rls.md` — every explorer query runs under the tenant GUC as `meridian_app`; nothing elevates. `0008-entitlement-and-metering.md` — the `advanced_analytics` key is reused, not joined by a second one. `0020-gamification-awarding-substrate.md` — the points source follows its leaderboard's gate.
- **Related docs:** `docs/data-dictionary.md` (`explorer_charts`), `docs/ACCESS-MATRIX.md` (the `/explore` rows), `docs/TESTING-GUIDE.md` (the walk-through), `docs/ux/design-system-reference.md` §3.11 (the two new chart primitives).

---

## Context

1. **`/analytics` cannot be asked a new question.** Its report is one `AnalyticsQuery` — a range, a form selection, one of five axes — rendered by three fixed cards. Two group-bys, a value other than a count, a filter on anything but status, channel and language, or any data that is not a response are all outside it by construction, and ADR-0011 §D9's amendment lists the mechanisms that keep them outside.

2. **Most of what a workspace stores is not reachable from any chart.** A member can read forms, the roster, the audit trail, webhook and integration deliveries, the points ledger and feedback reports, each on its own page, each as a list. None can be counted, grouped or compared.

3. **Answers are chartable only where an author planned for it.** ADR-0011 §D3 offered answer-value aggregation over `submission_answer_index`, which holds a question only if its author ticked "Indexed for reporting" before the response arrived, is never backfilled, and skips multiple-choice questions entirely. For nearly every question in a workspace that index is empty. The answers themselves are all in `submission_answers.answers`, one jsonb document per response, keyed by the question's stable key.

4. **A generic query surface is the classic way to lose tenant isolation or authorization.** A page that accepts a table name, a column or an expression has to prove, for every input, that it cannot read what its caller could not otherwise open. Row-level security answers the tenant half for free. It does not answer "may a Reviewer read the audit trail", "which forms does this Editor hold a grant on", or "is this question marked personal".

5. **The testing server runs PostgreSQL 15** (`D43`), two major versions behind development and CI. `pg_input_is_valid`, the obvious guard for casting stored text to a date, arrived in 16.

6. **Static analysis already forbids the easy mistake.** Larastan types every raw-SQL method's argument as `literal-string` at level 8, so a statement assembled from a runtime value does not pass the merge gate.

**The core tension:** the request is for freedom — any source, any grouping — and every unit of freedom given to the page is a unit of proof owed about what it cannot reach. The opposite failure is a "builder" with three hard-coded charts. This ADR spends its decisions on where the line goes: the reader is free over a CATALOGUE, and the catalogue is code.

---

## Decision

### The eight sub-decisions

- **D1 — A separate page, behind the key that already exists.** `/explore` is its own nav item, routes file, policy and pages; it shares nothing with `/analytics` at runtime except ADR-0011 §D2's predicate. It sits behind `feature:advanced_analytics`, not a new key. The reason is operational and specific: a deploy does not re-run the plan seeder, so a key coined today is absent from every plan row already in a database, and the page would be hidden on the testing server by its own gate. One consequence is accepted: the Settings → Modules switch for advanced analytics hides both pages. *(The user's call: `D217`, answered A, as built.)*

- **D2 — A chart is a declaration checked against a server-side catalogue.** `ExplorerQuery` holds a source key, an optional form id, a chart type, up to two group-bys, up to three values, up to eight filters, a date range, a time zone, a sort and a limit. It names fields by KEY. `ExplorerCatalog` is the one door from those keys to anything queryable: it resolves the source, asks the source whether this reader may use it, and resolves the form among the forms this reader can reach. Each `ExplorerField` carries the SQL for its key, typed `literal-string`, so a source can only build an expression out of text written in its own file. **One runtime value becomes SQL text in the whole feature** — an answer's key, in `AnswersSource` — and it does so after matching `^[A-Za-z0-9_.\-]{1,150}$` and through the driver's own quoting, at a line marked as the exception. It is quoted rather than bound because PostgreSQL will not match a `GROUP BY` expression to a select expression when both hold placeholders. A filter's values and the time zone are always bound.

- **D3 — Bounded live SQL, in ADR-0011 §D7's three senses, with no cache and no rollup.** TIME: the range is mandatory and at most 1,096 days, enforced in `ExplorerQuery`'s constructor so a request, a stored chart and a test pass the same check; it is applied to the source's range column as a bare comparison so the `(tenant_id, …)` indexes can serve it. ROWS: the source narrows to the rows this reader may see before anything else is added. CARDINALITY: one grouped statement returns at most 5,000 groups, largest first, and says so in its result when the cap is hit; a timeline over the range's own date is refused before it runs if it would hold more than 400 periods; the result folds everything past the chart's limit into one bucket. At most two statements per chart — the grouped one, and an ungrouped one over the same rows for totals and coverage. `POST /explore/query` is throttled at 120 a minute per member. Materialization stays what ADR-0011 made it: a fallback triggered by a measured breach, never chosen up front.

- **D4 — What a value, a group and a gap mean.** A value is a count, a distinct count, a sum, an average, a minimum, a maximum or a median. Past the limit the remaining groups fold into ONE "Other (N)" bucket, and it carries a value only where the value is additive: the sum of the rest is the sum of the rest, and there is no arithmetic that turns forty averages into the average of the rest, so there the value is null and the page prints a dash. A calendar group-by is FILLED — a day with no rows appears with 0 for a count or a sum and null for an average — because a line drawn straight across a missing day claims values that were never there. An instant is grouped in the reader's time zone by the construction `AnalyticsMetricsService` uses; a date an answer holds has no zone and is grouped as written. "Is none of" keeps rows with no value, which SQL's three-valued `NOT IN` would drop. Group labels are resolved once, on the server, so a chart, its table and anything later exported print the same word for the same group.

- **D5 — Each source repeats the rule of its own page.** The gate on the routes (`ExplorerChartPolicy::viewAny`, the `/analytics` predicate) opens the PAGE and grants no row. Then: **Responses** and **Forms** — every member who passes the page gate, narrowed to all forms for a holder of `dashboard.org.view` and to granted forms for anyone else, with a member holding no grant seeing nothing, never everything; **Answers to a form** — `submissions.view`, one reachable form; **Members** — `tenant.members.invite`; **Activity** — `audit_log.view`; **Deliveries** — the webhook half with `webhooks.manage` and the webhooks feature, the integration half with `integrations.manage` and the connectors feature; **Points** — `PointAwardPolicy::viewAny` and the gamification switch; **Feedback** — `feedback.view`. A source also declares what it will never expose: an audit entry's before and after values, a delivery's payload and response body, a feedback report's text. The explorer is a second way to read what a member can already read and must never be a first way to read what they cannot; `ExplorerPageTest` holds each role to its list.

- **D6 — Answers are read from `submission_answers.answers`, one form and one range at a time.** This extends ADR-0011 §D3, which stays true for `/analytics`: every question of a form becomes chartable for every response it ever collected, with no author action and no backfill. What makes it affordable is the bound — one form per chart, which `submissions_form_finalized_idx` exists to serve, never a scan across forms — and what makes it honest is three rules. **A value that does not parse is no value:** every numeric and date expression is guarded, so text that is not a number contributes nothing to a sum and is never a cast error that fails the chart, and the result carries how many rows did have a value (the date guard is a pattern plus a days-in-month check, because of Context 5). **A multiple-choice answer counts once per chosen option**, and the result says so. **A question marked personal or sensitive is never offered** — in any published version, because a flag added in version 3 describes version 1's answers too — and a chart that names one is refused as an unknown field. *(The user's call: `D218`, answered A, as built.)* Not offered yet, and counted on the page: questions inside a repeating section, grids, cascading choices, locations, files and long text.

- **D7 — Eight chart kinds, all design-system SVG; ADR-0011 §D10's "no charting library" stands.** Bars, columns, stacked columns, line, area, donut, table and number. §D10 named stacked bars and donuts as not built and said a real need would be a design-system increment weighed again against a library; this is that increment, and the weighing came out the same way for the reason §D10 gave first — tokens are `var()` references that re-paint on a live theme flip. `MdsColumnChart` and `MdsDonutChart` are added under the existing rules: static classes per series, no `:style`, five categorical colours plus a neutral "Other", a surface-coloured dashed outline per series as the non-colour channel, and a real data table behind every plot, pinned by Vitest because axe cannot see its absence. The second group-by is capped at five series for the same reason. One limit is recorded: `MdsTimeSeriesChart` cannot draw a gap, so a period with nothing to average is drawn at zero on a line and the page says so.

- **D8 — A saved chart is a stored declaration, run as whoever opens it.** `explorer_charts` is a `strict` RLS tenant table with a `user_id`, for ADR-0011 §D8's reason. `definition` holds `ExplorerQuery::toArray()` and no data, so a saved chart cannot go stale and a chart naming something since removed degrades to a stated refusal on a page that still opens. A chart is private to its author — from an Owner too — unless shared. **Sharing is read-only and never widens access:** a shared chart is listed on every member's board and run under THAT member's permissions and form reach, so a chart of the audit trail shared with a Reviewer shows the Reviewer a sentence. Only the author changes or deletes it. One member keeps at most fifty, which also bounds the board's fan-out of chart queries. *(The user's call: `D219`, answered A, as built.)*

---

## Consequences

### Positive

- Every tenant data set a member can already open on some page can now be counted, grouped, compared over time and kept as a chart, with no schema change to any of them.
- Every question of every form is chartable for its whole history, where ADR-0011 §D3's index covers only what an author flagged in advance.
- The feature adds one table, no write path into existing data, no new ingress class, no dependency and no new permission.
- Authorization is a list of eight small classes, each stating its own page's rule, and a test that holds each role to its list.

### Negative / accepted trade-offs

- **The first slow chart is a real incident**, as ADR-0011 accepted for `/analytics`: an average over a jsonb key across a very large form is a filtered scan. It is bounded and throttled, and it is unmeasured at scale; filed as `explorer-answers-scale`.
- **Two analytics surfaces now exist**, and a reader has to learn which is which. The board's own introduction says what it is for.
- **A chart's numbers cannot be downloaded**, the board is one list per member with no layout, and several kinds of question and three kinds of data are not offered. Each is a filed row, not an oversight.
- **Saving, sharing and deleting a chart write no audit entry.**
- **The catalogue is code.** A new data source is a class and a line in `ExplorerCatalog::SOURCES`; a tenant cannot add one.

### Risks & Mitigations

| Risk | Mitigation |
|---|---|
| A declaration reads a column it was not offered | D2: fields are named by key against a server-side list; `literal-string` on every expression, enforced by PHPStan; the refusal cases in `ExplorerQueryRunnerTest` |
| The explorer shows a member data their role cannot open elsewhere | D5: each source repeats its page's rule; `ExplorerPageTest` pins the source list per role, and the row scope per source |
| A shared chart leaks its author's reach | D8: run as the viewer; `ExplorerChartWebTest` runs one chart as two members and compares |
| A personal answer is charted | D6: withheld if flagged in any version, and refused by name |
| One bad answer fails a whole chart | D6: guarded casts; the case with `2026-02-30` and `forty` |
| An "Other" bucket shows an invented average | D4: null where not additive |
| A range or a grouping returns an unbounded result | D3: bounds in the value object's constructor, the period check, the row cap |
| A chart passes axe and is unreadable | D7: the table contract, pinned by Vitest per primitive and per page use |

---

## Alternatives Considered

**1. Embed a BI product (Metabase, Superset) — rejected.** It is the fastest route to the most features. It is a second application with its own users, permissions and database connection on a single self-hosted box, and it would read the tenant tables beneath row-level security unless every one of D5's rules were rebuilt in its own model.

**2. A SQL editor for administrators — rejected.** It gives everything D2 exists to withhold, to the role with the most to lose from a mistake, and it is unusable by the members the request is about.

**3. Extend `/analytics` — rejected.** ADR-0011 §D9's amendment lists five mechanisms that keep that page from acquiring capabilities one prop at a time. They are right about that page, and the explorer needs a different shape: a nested declaration, a fetch per change, many charts on one screen.

**4. Read answers through the index — rejected for this surface.** It would make the explorer empty for almost every question, which is the limitation the request is trying to get past.

**5. A charting library — rejected again**, on ADR-0011 §D10's own grounds, with a real requirement in hand this time.

---

## When to Revisit

- **On measurement:** a chart measured above ADR-0011's 1.5-second maximum on real data opens the materialization fallback, or an expression index for a hot question key.
- **When the user revisits `D217`, `D218` or `D219`** (each answered as built on 2026-11-12).
- **When a tenant asks for a source of its own** — a joined view, an external table — which is when "the catalogue is code" stops being enough.
- **When a chart need exceeds five series or the eight kinds**, which is a design-system increment, as before.
