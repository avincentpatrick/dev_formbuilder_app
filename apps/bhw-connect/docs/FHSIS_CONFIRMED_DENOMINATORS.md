# Confirmed cervical-lesion and schistosomiasis treatment denominators

The FHSIS 2025 Annual Report explicitly confirms both definitions:

- Section 1.E.3, printed p. 88 / PDF p. 99: women aged 30–65 screened by VIA, Pap smear or HPV DNA.
  In `4a. CA_nofml.xlsx`, `Annual!D + E + F` supplies the denominator for the existing
  `ncd_cervical_lesions` total row. Exclude assessed-only women (G). Retain the published rate in S.
- Section 1.F.A.1, printed p. 96 / PDF p. 107: confirmed acute plus chronic cases.
  In `Schisto_2025_EB_nofml.xlsx`, `Annual!M + Annual1!H` supplies the denominator for the existing
  `infect_schisto_treated` total row. Join by resolved geography, not row position or an ambiguous
  place name. Retain the published treatment rate in `Annual1!M`.

[Official annual report](https://drive.google.com/file/d/15xLfMp8TjdSX2RIbXoWkf445mr-V5kLX/view).
The definition is a reported screening/service proportion, not population prevalence or proof of
person-level deduplication. The separate chronic-diagnosis rate remains withheld.

Preparation preserves each denominator component's file, sheet, row, column and geography in the
private payload. Missing components produce a missing denominator, never a partial sum. Zero
components remain zero. The published source rate is never recalculated. The page shows zero or
missing denominators as undefined, preserving the reported numerator, including treatment counts
reported alongside zero confirmed cases. Sex breakdowns remain counts only.

This revises the held infectious and NCD batches in place, with new checksums and reviewed
metadata. It adds no measures or count facts: 142 total rows in each batch gain denominator/rate
fields. All 141 positive cervical and 39 positive schistosomiasis denominators reconcile to the
published rates. There are respectively 1 and 103 zero-denominator total rows.

Use only the revised consolidated package, not a mixture with older batches. The loader's exact
metadata allowlist rejects the superseded count-only bundles. This is preparation for the initial
server append: it does not update already-loaded data. If those batches have been loaded before
operator handoff, stop and reconcile; do not delete or reload server data. No migration or grant
change is needed. The 29-bundle totals stay at 631 definitions, 2,596,086 indicator rows and 13,952
workforce rows. Server loading remains on HOLD until explicitly authorized.
