# FHSIS 2025 child-health batch — prepared, hold for consolidated load

No live load is requested. This second expansion is prepared independently of the 69-indicator
core batch; both remain on hold until the user finishes choosing and preparing additional data.
No schema migration or grant change is needed. Deploying this code does not load the facts.

## Reviewed content

The five annual workbooks produce 12 indicators and 4,260 facts across 142 named reporting areas:

- Grade 1 and Grade 7 Td vaccination and MR vaccination (four indicators), with source male/female
  counts. Denominators are enrolled learners; sex-specific enrolment is not supplied here.
- HPV doses 1 and 2 among 9-year-old females (two indicators), using the projected female population.
- Vitamin A for high-risk sick children aged 6–11 and 12–59 months (two indicators), using sick
  children seen, with source sex-specific numerators and denominators. These are additional to
  routine supplementation; the source describes measles and/or persistent diarrhea.
- Diarrhea treated with ORS, ORS with zinc, the source's combined diarrhea treatment total, and
  pneumonia treated with antibiotics, all aged 0–59 months (four indicators). The three component
  treatment indicators have sex counts and denominators. Do not add the combined diarrhea total
  to its components. Sex rates are absent in the source and are not invented.

Each annual sheet has national, region, province/HUC and a few separately listed municipality
records. Missing municipality observations stay missing. The dashboard uses its limited-geography
explanation for these indicators. HPV is already a female-only cohort in its label.

Workbook headers and national rates were checked against their numerator and denominator;
all 12 agree within 0.01 percentage point. Source DQC flags are excluded. The MR workbook's DQC
labels say Td, but its title and actual indicator headers say MR; mappings use the latter. The HPV
DQC header says over 200%; the dashboard consistently flags published coverage above 100% and
never caps or changes it. Rates and source numeric precision are preserved.

## Reproduce privately

```sh
python ingestion/prepare_fhsis_expansion.py ingestion/data/fhsis_child_health \
  --reference ingestion/data/fhsis_2025 --retrieved YYYY-MM-DD --programme-set child-health
python -m unittest discover -s ingestion -p 'test_prepare_fhsis*.py'
```

Source files, facts, manifest and validation receipts stay outside git. The approved public metadata
is `ingestion/fhsis-child-health-definitions.json`. The loader selects this exact mapping only for
`programme_set: child-health`; absent programme_set retains compatibility with the original core
package. Unknown programmes, altered definitions, duplicate sources/facts and repeat loads fail.

Local PostgreSQL 17 validation: dry run rolled back; a local-only apply inserted all 4,260 facts and
12 definitions; prior facts were unchanged; a repeated apply was rejected; anon could read every
added fact. This does not replace the eventual server PostgreSQL 15 dry run.

For the eventual handoff, use `db/load-fhsis-expansion.mjs` with this private JSON and its SHA256,
first without `--apply`. Expected addition: 4,260 indicator rows, 12 definitions, 12 national
checkpoints. After both prepared batches and the original live load, expected totals are 264,432
indicator rows, 101 definitions and unchanged 13,952 workforce rows. Those are planned totals,
not a statement about current live content. Further batches will change the consolidated totals.
