# FHSIS 2025 expansion: deferred server handoff

**HOLD: prepare all additional datasets before a consolidated server load.** The commands below
are reference instructions for that later handoff, not a request to load now. Child-health preparation
is documented in [FHSIS_CHILD_HEALTH.md](FHSIS_CHILD_HEALTH.md), and prenatal/maternal additions
in [FHSIS_MATERNAL.md](FHSIS_MATERNAL.md). Infectious-disease additions are documented in
[FHSIS_INFECTIOUS.md](FHSIS_INFECTIOUS.md). Family planning is documented in
[FHSIS_FAMILY_PLANNING.md](FHSIS_FAMILY_PLANNING.md).

This is an **append**, not a rerun of the original first-load tool. Deploy the release containing
`db/load-fhsis-expansion.mjs` and the age/sex dashboard before loading. The original FHSIS facts
must already be present. No new schema migration or grant is required.

## Operator commands

Download and extract the private package outside the repository. In the current app checkout:

```powershell
$bundle = 'C:\private\fhsis-expansion\fhsis-expansion.json'
$hash = (Get-Content 'C:\private\fhsis-expansion\fhsis-expansion.sha256').Split(' ')[0]
(Get-FileHash $bundle -Algorithm SHA256).Hash.ToLower() -eq $hash
node --env-file=C:\meridian\bhw\.env db/load-fhsis-expansion.mjs $bundle $hash
```

Expect `dry-run-rolled-back`, 176,676 added indicator rows, 69 added definitions, and 69 national
checkpoints. Review `qa-report.json`, `source-manifest.json` and `local-validation.json`. Then:

```powershell
node --env-file=C:\meridian\bhw\.env db/load-fhsis-expansion.mjs $bundle $hash --apply
node --env-file=C:\meridian\bhw\.env db/inspect-fhsis.mjs
```

If the original load is the only prior load, the result is **260,172 indicator rows, 13,952 workforce
rows, 89 definitions and 89 national total rows**. Workforce facts are untouched. A second apply
is refused. Retain the private bundle and receipt; do not commit them. Verify national and province
pages, a vaccine municipality page, age/sex details, and the limited-geography explanation for a
municipality without separately published maternal/nutrition figures.

The existing workflow can refresh the synthetic dev copy after the operator confirms the append,
so its reference dictionary includes the added programmes. This is separate from the live load.

## Included content

- 31 vaccination indicators: pentavalent, OPV and PCV doses 1–3; IPV and MMR doses 1–2, each for
  0–12 and 13–23 months; BCG at 0–28 days and 29 days–1 year; hepatitis B within 24 hours and
  after 24 hours up to 14 days; children protected at birth. Source sex counts are separate and
  have no invented sex-specific denominators. The projected populations differ from FIC's own
  denominator; do not substitute one for another or add dose/cohort rates.
- Two postnatal indicators: at least two postpartum check-ups and at least four postnatal visits,
  with source age groups. These have different source definitions and denominators.
- 16 delivery/newborn indicators: total deliveries; skilled attendance overall and by staff type;
  facility-based delivery overall/public/private; vaginal/caesarean delivery; full-term/preterm/
  fetal-death outcomes; normal/low/unknown birth weight.
- 20 nutrition indicators: early breastfeeding, iron for preterm/low-birth-weight infants,
  vitamin A, micronutrient powder and small-quantity lipid-based supplements by their stated age
  cohorts; MAM/SAM identification, enrolment/admission and treatment outcomes.

The six vaccine sheets each have 1,744 source geographies. Postnatal, delivery/newborn and nutrition
sheets each have 142 named source areas: national, regions, provinces/HUCs and a few separately
listed cities/municipalities. Never allocate provincial counts down to municipalities. Name matches
prefer province/region records and use the current region to resolve city names; SGA maps to the
existing Special Geographic Area lookup. Unresolved, duplicate and out-of-region matches stop
preparation. The source place name and workbook/sheet/row are retained in the private bundle.

## Source issues retained or held back

Rates and exact numeric values remain as supplied; no averaging, capping or reconstruction from
children. National published rates were checked against their numerator/denominator: all included
percentage indicators agree within 0.01 percentage point. This is a column-mapping check, not a
claim that all source subtotals or reporting practices agree. The source has overshoots, including
vitamin A, and postnatal age totals can differ from the supplied overall total.

Two columns are deliberately excluded pending source clarification: combined vaginal-caesarean
rate (its percentage column appears to hold an unscaled fraction), and abortion/miscarriage counts
(which include fractional values). Other source indicators in the same workbook remain available.
DQC columns are not indicators. No BHW tally is added.

## Reproduce and validate privately

```sh
python ingestion/prepare_fhsis_expansion.py ingestion/data/fhsis_expansion \
  --reference ingestion/data/fhsis_2025 --retrieved YYYY-MM-DD
python -m unittest discover -s ingestion -p 'test_prepare_fhsis*.py'
```

The reference directory supplies the original TB geography/name reference and `dim_geo.json`.
The workbook mapping lives in the preparation script; the loader accepts exactly the reviewed
69 definitions in `ingestion/fhsis-expansion-definitions.json`. Downloaded facts stay under ignored
`ingestion/data/`. Source file IDs, modified times and checksums are in the private manifest.

Local validation used PostgreSQL 17: dry run left no new definitions, apply inserted the full
package, a digest of all prior indicator facts was unchanged, repeat apply was rejected, and anon
could read every added row. The server's PostgreSQL 15 dry run remains necessary. Live loading
can only be performed by the operator.
