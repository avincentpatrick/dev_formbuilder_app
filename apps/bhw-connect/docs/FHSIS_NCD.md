# FHSIS 2025 NCD batch — hold for consolidated loading

Prepared only. No server load is requested until the user finishes preparing the additions. Code
deployment does not insert this batch. No migration or grant change is needed.

## Content, periods and geography

Nine workbooks supply 64 indicators and 33,730 facts:

- Three adult annual PhilPEN measures: risk assessment, identified hypertension and identified type
  2 diabetes, ages 20–59. These use the explicit Annual columns 196–207 on `RA`, not the quarterly
  or monthly repeats, and cover 1,744 areas including municipalities.
- Twelve **December 2025** medicine-access measures: antihypertensive and antidiabetic medicines,
  each with facility-provided, out-of-pocket and total access, for adults 20–59 and seniors 60+.
  Only source total-access rates have denominators; component and sex counts have no invented rates.
- Twelve annual risk-factor measures: smoking history, binge drinking, insufficient activity,
  unhealthy diet, overweight and obesity, separately for adults 20–59 and seniors 60+.
- Three senior vision measures: screened, eye disease identified and referred for eye care.
- Two senior vaccination measures: PPV and influenza, with their different source denominators.
- Thirteen cervical screening/assessment and subsequent suspicious-finding, precancerous-lesion,
  treatment and referral measures for women aged 30–65.
- Fourteen breast-screening measures, preserving the two overlapping source cohorts: high-risk
  asymptomatic/symptomatic women aged 30–69 and asymptomatic women aged 50–69. Screening methods,
  significant findings and linkage to care have their own source denominators.
- Five mhGAP screening counts: ages 0–9, 10–19, 20–59, 60+, and the source all-age total. Each age
  indicator retains source male/female counts. They must not be added to the all-age total.

Except the three adult annual PhilPEN measures, the tables cover 142 national, regional,
province/HUC and selected city/municipality reporting areas. Missing municipality figures remain
missing. Risk factors, methods and pathways can overlap. Reported findings are not confirmed
cancer diagnoses, and service counts should not be presented as unique people or population
prevalence. December medicine access is not summed across months into an annual measure.

## Held measures and source notes

- Senior annual risk assessment/hypertension/diabetes are now prepared separately as `ncd-senior`.
  The official 2025 report corroborates the annual RA block despite the Q4 Annual 2024 heading.
  See [senior annual preparation](FHSIS_SENIOR_NCD_LOAD.md) for geography and quarter-sum caveats.
- Cervical precancerous-lesion rates now use the confirmed VIA + Pap + HPV screening denominator;
  see [confirmed denominators](FHSIS_CONFIRMED_DENOMINATORS.md). Zero denominators are undefined.
- The cervical screening target is projected women aged 30–65 minus those screened in the prior
  two years, per the workbook footnote. Suspicious findings use assessed-only women as the source
  denominator. The source also warns that collection for this exact age group began in the second
  semester of 2024 and earlier data may not fully align.
- The `RA 20+`, monthly/quarterly duplicates, Population/helper sheets and annotation rows are not
  added as separate indicators. No rates are averaged or rebuilt from geographic children.
- NCD coded geography uses both the original reference names and the existing geography dictionary,
  excluding barangays. Reviewed naming equivalents are City of Carmona → CARMONA (0402104), and
  Dr. Jose P. Rizal → RIZAL, Palawan (1705323). Original source names/PSGCs remain in the package;
  code corrections are recorded in QA. Duplicate or unresolved geographic records still stop parsing.

All 10,469 rates with available counts and nonzero denominators reconcile within 0.01 percentage
point. This checks mappings, not clinical reporting completeness. Source overshoots and missing
markers are preserved, not capped or converted to zeros.

## Private preparation and eventual validation

```sh
python ingestion/prepare_fhsis_expansion.py ingestion/data/fhsis_ncd \
  --reference ingestion/data/fhsis_2025 --retrieved YYYY-MM-DD --programme-set ncd
```

The loader accepts exactly `ingestion/fhsis-ncd-definitions.json` for programme set `ncd`. All earlier
packages retain their approved mappings. Sources, facts, checksums, manifest and receipts stay
outside git. The package includes the source-year exception for later review.

Local PostgreSQL 17 validation confirmed rollback, append, unchanged earlier facts, repeat-load
rejection and anonymous access to all added rows. For the eventual PostgreSQL 15 server dry run,
expect **33,730 added rows, 64 definitions and 64 national checkpoints**. Use the expansion loader
with the private JSON and SHA256, without `--apply` for a dry run.

After the original live load and all six prepared loadable batches, planned totals are 362,630
indicator rows, 284 definitions and unchanged 13,952 workforce rows. These are future totals, not
current live content. ZOD remains held: its Q4 figures match the 2025 report, but certified versus uncertified counts conflict.
