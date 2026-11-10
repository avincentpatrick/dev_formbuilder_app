# Combined vaginal–caesarean deliveries — HOLD for consolidated load

One additional maternal indicator contributes 568 facts: total and three source age-group counts
at 142 reporting areas. It is the source's combined delivery category, not the sum of vaginal and
caesarean counts. Its total rate uses total deliveries; age breakdowns retain counts only.

In `Annual_b`, Q is the total, N:P are ages 10–14/15–19/20–49, B is total deliveries and R is
an Excel fraction formatted `0.00%`. The preparer requires that exact reviewed format and multiplies
the source fraction by 100 using decimal arithmetic. It retains the raw rate and format in the
private payload. This converts units, rather than recalculating a published rate from counts.
The source audit reconciles all 142 total rates, age sums and the three delivery categories.

```sh
python ingestion/prepare_fhsis_expansion.py <private-directory> \
  --reference <original-private-fhsis-directory> --retrieved YYYY-MM-DD \
  --programme-set combined-delivery
```

The append loader requires exactly the committed definition. Expected dry run: 568 indicator
rows, one definition and one national checkpoint. Step 29 follows the earlier 28 bundles,
producing planned totals of 631 definitions, 2,596,086 indicator facts and unchanged 13,952
workforce facts. These are future totals, not current live content. No migration or grant is needed.

Keep all other source holds excluded. Older core QA notes describe this rate as held; this separate
reviewed batch resolves that one exception without modifying or reloading the core payload.
No server load is authorized until the owner explicitly releases the consolidated package.
