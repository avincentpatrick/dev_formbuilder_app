# FHSIS live-data diagnosis — 2026-11-07

The deployed section reads the indicator dictionary, but none of its queries returned figures
for the national page, Region I, Ilocos Norte or Adams. Both indicator and workforce reads
were empty. The source metadata resolves `fhsis-2025`; the app filters indicators by its ID,
geography, geography level and `breakdown = 'total'`.

The baseline's policies grant SELECT to anon on both fact tables and the dictionary. This is
historical evidence, not proof of current privileges or contents. The old repository contains
the plan but no FHSIS cleaner or loader. `db/make-seed.mjs` invents fact rows even when its source
table is empty; synthetic rows therefore never established that production FHSIS was populated.

## Operator check

The server's database is not reachable from the Codex workspace. After this change deploys,
avincentpatrick can run the following from PowerShell. It reads the existing BHW configuration
without displaying it. The code uses a read-only transaction and rolls it back, queries only
FHSIS tables and public metadata, and prints counts and keys rather than source records.

```powershell
Set-Location C:\meridian\app\apps\bhw-connect
node --env-file=C:\meridian\bhw\.env db/inspect-fhsis.mjs
```

Share the JSON output, not the `.env` file. An `anon_role_switch` permission error simply means
the connection role cannot impersonate anon: rerun with the operator's existing authorised
connection if available; do not grant extra permissions just to run this check.

## Interpret the output before changing anything

- `table_totals` has zero fact rows: the source load is absent. Recover the original cleaning
  provenance and prepare a private verified load; do not load the development seed.
- Fact rows exist under a different dataset slug: reconcile dataset registration with the
  original provenance before changing foreign keys or broadening the query.
- The expected dataset exists but `total_breakdown_rows` is zero: inspect `indicator_keys`;
  establish the source's breakdown meaning before changing `total` to another label.
- Owner sees rows but anon does not: examine the returned live policies and role grants.
- Dataset and breakdown match but national rows do not: examine `national_geo_keys` and
  `geography_mismatches`. Do not remove the geography filters to make a number appear.
- Owner and anon both see the expected national rows: check the running app's database target
  and PostgREST logs on the server. Never paste connection URLs or tokens into chat.

Until this evidence is available, the cause remains unconfirmed. The UI now says the dataset
is unavailable when no total reports are accessible, rather than making a nationwide claim
about DOH reporting. No schema, grants, existing records or source figures have been changed.
