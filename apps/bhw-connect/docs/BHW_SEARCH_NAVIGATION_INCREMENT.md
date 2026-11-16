# BHW analytics — search navigation response

Phase 1 performance follow-up: submitting a BHW search keeps the hydrated page
shell instead of loading a second full document. It reuses Next's Form navigation
and the existing server search, resolver, native GET fields and validated map
snapshot. No source, query grammar or disclosure rule changes.

The local phone regression sample before this change included a 1,907 ms search
response against the 1,500 ms target. Final observations belong in the Phase 1
acceptance record; this change alone is not proof of representative performance.

Acceptance covered: place/indicator search, explicit population selection,
comparison intent, browser Back, prefix and original map context. Browser checks
also verify that the hydrated document survives submission and native GET keeps
the prefixed URL/query/map snapshot with JavaScript disabled. The pre-existing
search loading boundary streams its results and requires JavaScript to reveal
them; native URL navigation is not full no-JavaScript support.

Validation: npm ci, lint (one existing census-fixture warning), types, 1,999 unit
tests passed / 55 database-dependent tests skipped, migration lint, prefixed
no-data build and extractor checks. Nine relevant seeded-database browser cases
passed across the initial run and targeted correction of the native-navigation
assertion. CI runs the database-dependent checks. Physical phone/desktop reader
evaluation and representative production timing remain separate gates.
