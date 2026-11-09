# FHSIS demographic summaries — HOLD for consolidated load

Eight reviewed indicators add 4,340 facts from `Demographic_2025_EB_Final.xlsx`:

- Population and household estimates from `Health Workers`, 1,744 source areas each.
- Barangay, rural health unit and barangay health station counts from `BGY & BHS`, 142 areas each.
- The source population-per-unit ratio for each of those three groups, also 142 areas each.

Population and household estimates are FHSIS context, not replacements for StepZero/PSA population,
the facility registry or the BHW census. The FHSIS BHW filing tally remains excluded. Existing eight
non-BHW workforce cadres are unchanged. Province/HUC summary counts are not allocated to municipalities.

All 415 ratios with positive denominators match source population divided by source units within
0.000001. Eleven ratios have a zero unit count; preserve the supplied raw ratio but display
“Not defined (zero units)” rather than suggesting zero population per facility. These ratios are not
percentages, coverage, staffing targets or a measure of access. All shared population figures in the
two source sheets agree. No ratio is recomputed for publication.

Prepare privately:

```sh
python ingestion/prepare_fhsis_expansion.py <private-directory> \
  --reference <original-private-fhsis-directory> --retrieved YYYY-MM-DD \
  --programme-set demographics
```

The existing append loader accepts exactly the reviewed demographic definitions. Expected dry run:
4,340 added indicator facts, eight definitions and eight national checkpoints. Apply only after the
owner authorizes the consolidated server load. No new migration or grant is needed. The default is
rollback; repeat loads are rejected and server geography must match the bundle.

Appended after the 27 earlier prepared bundles, this is step 28. Planned final totals are 630
definitions, 2,595,518 indicator facts and unchanged 13,952 workforce facts. Those are not live totals.
The private consolidated manifest and runbook supersede the earlier 27-bundle readiness package.
ZOD, senior NCD, ambiguous oral cohorts and other documented source holds remain excluded.
