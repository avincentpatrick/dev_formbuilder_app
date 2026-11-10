# FHSIS vital statistics and morbidity — HOLD for consolidated load

Prepare now; **do not load the server yet**. The owner will finish the remaining additions before
requesting one consolidated operator handoff. Deploying this code does not insert real figures.
Workbooks, prepared JSON, checksums and QA reports stay outside this public repository.

## Included

- Vital statistics: 11 definitions and 3,550 facts across 142 source areas. Live births by sex;
  births to mothers under 10, 10–14 and 15–19; direct and indirect maternal deaths by residence
  (with reported maternal age counts); infant deaths.
- Cumulative morbidity: 306 source disease categories across 19 chapters and 142 areas,
  2,216,052 facts. Each disease/area has total, male and female counts plus 16 age groups,
  each with male, female and total counts (51 facts). Preserve distinct disease labels even
  where ICD codes repeat or overlap. These are case reports, not unique patients.
- The existing original FHSIS and earlier prepared programmes remain unchanged. With all earlier
  prepared batches present, this adds 317 definitions and 2,219,602 facts, bringing the local
  preparation total to 622 definitions and 2,591,178 indicator facts; workforce remains 13,952.
  These are **planned totals, not a claim about the live server**.

## Interpretation and checks

The natality/mortality workbook labels rates `%`, but its supplied values use per 1,000 for
adolescent births and infant mortality, and per 100,000 for maternal mortality. All 1,278
comparable source rates match those scales. Adolescent denominator columns say estimated age-group
population without stating sex; metadata retains that limitation instead of asserting a female
population. Live births and deliveries remain separate measures. Maternal age breakdowns have
counts only; the total live-birth denominator must not be presented as age-specific mortality.

Morbidity comes from the January–December cumulative Region and Province companions for all
19 chapters. All source age/sex sums and province-to-region-to-national count totals reconcile.
All 37,638 provincial rates match the supplied population denominators. National/regional rates
are preserved, but their population denominators are absent in these files and are not reverse
engineered. All 380 Top 20 area entries match the cumulative source. Top 20 and month-to-month
summaries are not inserted again.

**Source limitations remain visible:** Region XII May is missing. The source converts blank
submitted cells to zero; a zero does not establish complete reporting or absence of illness.
Overlapping ICD groups must not be summed as distinct cases. These are partial FHSIS submissions,
not a replacement for other disease-surveillance systems. Geography is resolved by region and
source area name, preserving the distinction between a province and its same-named HUC.

The overview shows disease totals and rates. Each disease links to a separate age/sex page,
which reads only its 50 breakdowns. The overview excludes the morbidity age grid so loading
these chapters does not produce a page with more than 15,000 breakdown rows.

## Reproduce privately

Requires `openpyxl`, the original private FHSIS geography reference, and the source workbooks.

```bash
python ingestion/prepare_fhsis_expansion.py <private-directory> \
  --reference <original-private-fhsis-directory> --retrieved YYYY-MM-DD \
  --programme-set vital-statistics
python ingestion/prepare_fhsis_morbidity.py <private-directory> \
  --reference <original-private-fhsis-directory> --retrieved YYYY-MM-DD
```

The first command writes `fhsis-expansion.json` and its checksum. The second writes
`morbidity-01.json` through `morbidity-19.json`, individual checksums and `morbidity-qa.json`.
`drive-sources.json` records the 38 cumulative source file IDs. Raw workbooks and reports are private.
Public definition files contain metadata only, never source facts.

## Eventual operator load — not authorized yet

After the owner requests the consolidated handoff, use the reviewed repository loader for each
checksummed JSON. The original published FHSIS load must already exist. No schema migration,
new grant or role switch is required by this batch.

```powershell
node --env-file=<server-env> db/load-fhsis-expansion.mjs <private-json> <sha256>
# Only at the later authorized load:
node --env-file=<server-env> db/load-fhsis-expansion.mjs <private-json> <sha256> --apply
```

Each JSON has its own transaction, dry-run rollback and repeat-load refusal. Inserts use chunks
of 5,000 inside that transaction to limit database memory. The 19 chapters are **not** one shared
transaction: record each committed receipt, stop on failure, and resume only the uncommitted
chapter after investigation. Never rerun a committed chapter or overwrite existing facts.

The private local-validation report records dry-run rollback, local commit, rejected repeat load,
anonymous reads, and unchanged earlier facts. Local PostgreSQL is 17; the server is PostgreSQL 15,
so retain the operator dry run as a required check in the eventual handoff. After all approved
batches are loaded, inspect database counts, public pages and selected disease breakdowns,
then refresh the synthetic development copy. No real rows enter git.
