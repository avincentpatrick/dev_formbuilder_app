# BHW deployment blocker — extractor executable entry

Deployment 43719004397 (2026-11-15) passed the Meridian deployment step, then
failed BHW's post-build standalone extractor verification. The CSV child process
exited successfully without creating its output JSON. The release was not shown
as successfully deployed; this does not establish the currently served version.

The bundled extractor previously guarded CLI execution by comparing
`import.meta.url` with a URL made from a canonicalized argv path. Windows service
temporary directories can use short-path spellings, and deployment uses a current
junction. That comparison can skip execution without signaling an error. The log
establishes the missing output; the exact path spelling mismatch cannot be
reproduced on this Linux workspace.

PR #523 documents delivery of the earlier #522 extractor fix and the then-blocking
Composer audit. This is a separate failure observed after BHW reached post-build
verification on Windows; that earlier handoff is not proof of this deployment.

A dedicated executable entry now invokes the reusable extraction library directly,
without path-identity detection. The worker still executes the same bundled
`analysis-extractor.mjs` and receives the same JSON contract. Missing mandatory
arguments fail with nonzero exit. Importing the library still performs no CLI work.

The standalone post-build verification remains mandatory: it runs
CSV, XLSX, PDF and GeoJSON from a detached release behind a directory junction, now with
`--preserve-symlinks-main` to exercise differing entry-path spellings.
A focused child-process test exercises a junction/path with spaces and missing
arguments. The PR records Linux check results; Windows deployment confirmation
remains required. No ops, workflows, source access, data or deployment bypasses
are changed.

This repair precedes further analytics work because BHW's deployment is blocked.
PR #536 remains independently reviewable. The working agreement requires notifying
avincentpatrick before any merge while the latest production Deploy run is red.
Value filtering/classification and the remaining Phase 1 items are still pending.
