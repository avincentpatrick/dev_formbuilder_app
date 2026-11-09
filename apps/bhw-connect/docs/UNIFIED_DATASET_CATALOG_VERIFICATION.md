# Unified catalog delivery verification

Increment 7 of [the plan](UNIFIED_DATASET_CATALOG_PLAN.md), 2026-11-07.
Runtime implementation through PR #427 (`c92f143a`); this phase adds regression coverage and
records verification. No production data, shared operations files or Meridian code changed.

## Local checks and evidence

The standalone production build ran at `/bhw-connect` against PostgreSQL 15 and PostgREST 16.4.
The database contains the committed synthetic development copy, not production records.
Temporary test values and district memberships were restored after each browser scenario.

| Scenario | Observed result |
| --- | --- |
| Home → catalog → each available card → return | Shared overview on home; six stable identities; source/release dates and definitions in details; selection retained |
| All versus National | All reports availability without sample national values; National has its own explicit `PH` selection |
| PSGC region → province → municipality → barangay | Actual parent hierarchy followed; refresh/history retains scope; unsupported barangay FHSIS explained |
| Whole municipalities plus split city | Synthetic 10-facility municipality + 1 included barangay = 11; duplicate whole-city/barangay membership counted once |
| Split-city navigation | Only constituent barangays offered; city view retains the included subset; outside barangay rejected; details/reload retain district and partial-city scope |
| Conflicting mapping | All district figures withheld; no guessed membership |
| All six numeric datasets | Temporary exact-city fixtures independently checked against the local database: BHW 100 people, NHFR 0 facilities, UUC 2 listed barangays, FHSIS 81.23% with exact inputs 11.25/13.85, population 12,345, poverty 12.5% with 90% interval. Each card/detail agrees and returns to the same city |
| Zero and empty | Successful zero remains visible on card and detail; synthetic empty fact reads produce no cards and explicit omission reasons |
| Failed reads and retry | A local HTTP proxy injected NHFR 500 responses; other cards remained, NHFR omission was a failure, and retry restored it without changing scope |
| Privacy across scopes | Proxy-injected source suppression withholds demographic groups in All, National and barangay details; no demographic value GET occurs after the privacy gate |
| Mobile and keyboard | 390×844 home, details and split-city view have no page overflow; axe reports zero violations; native select/Tab/Enter works; wide detail table is focusable and scrollable |
| Legacy FHSIS populated path | Synthetic total-breakdown fixture enables the normally skipped test: home dashboard link → region → province → municipality → methodology passes |
| Existing search/deep links | All portal search tests and existing unsupported/redirect browser cases pass; no legacy routes removed |

Additional unit coverage verifies ambiguous duplicates, missing versus failed sources, source
refresh with fixed logical identities, actual-parent navigation, ratio recomputation, complete
membership, finite values, malformed privacy flags, complementary suppression, bounded query
concurrency and public allowlisted projections. Final detail regressions cover every unavailable
state, exact inputs, missing dates, coverage, definitions and district-preserving return links.

## Query and access observations

A local forwarding proxy observed fresh standalone browser requests, including geography and
existing anonymous usage telemetry. No administrator session was supplied.

| Scope | Observed requests | Local elapsed to rendered catalog |
| --- | ---: | ---: |
| All locations | 120 | 2.34 s |
| Selected municipality | 34 | 0.43 s |
| All with injected NHFR failures | 120 | 0.95 s |

Every request carried role `anon`; no raw BHW, admin or ingestion table was read. The existing
anonymous `usage_events` write is telemetry, not catalog input. These are synthetic local timings,
not a production latency guarantee. The data contract describes upper bounds, four concurrent
indicator reads, per-request metadata/table coalescing and ten-unit district batches. Larger
production districts still depend on accepted mapping size and source coverage; row caps fail
closed instead of silently truncating results.

## Required checks

- Increment 6 after incorporating FHSIS PR #426: lint (zero errors, one existing intentional
  fixture warning), typecheck, 110 Vitest files / 1,398 passed / 11 optional skipped, migration
  lint and local migration check (0 pending), no-data and seeded prefixed production builds.
- Full standalone browser suite: 17 passed, one conditional populated-FHSIS case skipped with
  the default empty-report seed. That case passed separately with restored synthetic facts.
- Increment 7: lint, typecheck, 110 Vitest files / 1,403 passed / 11 optional skipped,
  migration lint/check and the prefixed no-data production build passed. No runtime code changes
  after the seeded standalone build; final changes are tests and documentation.

## Live data and operator limits

Synthetic evidence establishes application behavior and calculation rules. It does not establish
that production contains every accepted release, has complete geography, or matches the original
workbooks. Public legacy comparisons corroborate public output only; they are not a direct audit
of production source rows. Production database access is not available in this environment.

FHSIS preparation PR #426 supplies an exact-value migration, private-source preparation and a
transactional first-load runbook. The operator must follow [FHSIS_LOAD.md](FHSIS_LOAD.md), including
the PostgreSQL 15 rollback-by-default dry run and authorized apply, before source facts can appear.
The catalog must explain unavailable FHSIS data until then. No live source load was attempted.

Population/poverty match gaps, incomplete facility attribution, unresolved UUC source rows and
district mapping gaps remain source limitations, not permission to estimate missing figures.
New accepted releases require the metadata and selector review in the data contract. Profiling
Status and ingestion/history remain separate from the six-card catalog.

## Deployed public verification

[Deployment run 42388001630](https://github.com/avincentpatrick/dev_formbuilder_app/actions/runs/42388001630)
completed successfully. Its log confirms release `c92f143aab8b99cee99aac952208a11d9a59bd2a`
is live at 2026-11-07 15:48 UTC, following backup, the additive FHSIS exact-value migration,
service swap and health check. Meridian took its no-maintenance-window path. This is observed
deployment evidence, not an inference from a workflow's event SHA.

Unauthenticated Playwright checks at [staging](https://staging.pitahc.gov.ph/bhw-connect)
at 15:48–15:49 UTC passed:

- Home renders All locations with BHW, NHFR, UUC-PHC, Population and Poverty cards.
  Every available card opens its source/definition table and returns to All without losing scope.
- National, Region I, Ilocos Norte and Adams show supported values. Poverty appears for Adams;
  barangay Adams (Pob.) shows BHW, NHFR and UUC-PHC, with municipal-only sources omitted.
- Abra's at-large district shows BHW, NHFR and UUC-PHC. District selection survives reload;
  mapping provenance/gaps and the source-history link remain accessible.
- FHSIS details explain unavailable data and show no fabricated table. Its source load remains
  the operator step above; the schema deployment alone does not make facts available.
- Mobile home and NHFR details have zero axe violations and no page overflow. The NHFR national
  facility count matches the existing public facilities dashboard. This corroborates the accepted
  public output, not independent source-workbook validation.

Population and poverty are now verified as publicly available in supported selections. Their
production database/source reconciliation remains unverified directly. No login, administrative
read, production write or source load was used for these checks. The final verification PR changes
only tests and this delivery record; the runtime build verified here is unchanged.
