# `ingestion/data/` — kept out of git

This folder holds BHW Connect's **source data**: the BHW dataset (`dataset.parquet`, 270,917
individual records — personal data), the NHFR, UUC-PHC, PSA and STEP Zero workbooks, the cleaned
CSV extracts the ingestion scripts write, and the knowledge-base extraction transcripts.

None of it is committed. The app now lives in Meridian's repository, which is **public**. Its
`.gitignore` keeps everything here except this README out of git. The folder's contents (414
files, about 56 MB at the import) remain in the archived repository
<https://github.com/jongsky25/BHW-Connect-Dashboard> as of commit `e4237373`.

**Where the data lives now:**

- With the BHW developer (`jongsky25`), in their private storage — the working copy the
  ingestion scripts run against.
- On the testing box, at `C:\meridian\bhw\data\`, once the database moves there (phase P2 of the
  merge). Only the operator account can read it.

**How a new load reaches the testing box** (decision `D181` in Meridian's
`docs/claims/decisions.md`): the BHW developer prepares the load file — SQL or CSV — and sends it
**privately**, never through git or a pull request. The box's operator then loads it with one
PowerShell block (`psql \copy` against the `bhw_connect` database). An admin upload page in BHW
is queued for later.

One committed test reads a file from here, `lib/kb/extraction-transcript.test.ts` (the
transcript's invariants). Its transcript cases are skipped, not failed, where the folder is empty.
The ingestion scripts' own defaults (`--src ingestion/data/…`) are unchanged.
