# Ask the Data projects and worker operations

This document describes the Phase 2 foundation. Phase 3 extends it with files and research; see [source operations](ASK_THE_DATA_SOURCES_OPERATIONS.md).

Phase 2 adds private saved projects, owner-managed viewer/editor sharing, durable analysis jobs, attempt-level usage records, and protected JSON analysis records. It does not add uploads, online research, new statistical methods, or publication exports.

## Storage and authorization

The new additive migration is `20261112220000_analysis_projects.sql`. Projects, memberships, jobs, result/evidence JSON and provider-usage records live in BHW's PostgreSQL database. Tables and RPCs deny anonymous/authenticated data-plane roles; service-role handlers derive the actor from the approved Meridian session. Administrator status does not grant access to another user's private project.

Questions are saved when submitted. The worker stores the answer, sources, figures, retrieved evidence, geographic context and provider/model usage. Opening a saved project restores its runs; recent runs load first and older runs can be requested. Browser drafts that have not been submitted are not stored. Concurrent edits use project revisions; a repeated submission key returns the existing job. One active analysis per project preserves conversation order.

Owners manage sharing and deletion, editors add analyses and rename, and viewers read/download. Sharing is limited to approved users by their sharing ID. Revocation/unsharing is checked again by the database before claiming a job, on worker heartbeats, on publication, and on each API/download request. Cancellation discards results; an already-started provider request may finish and incur charges. Old worker leases cannot publish after replacement, cancellation or deletion.

Deleting a project immediately denies all further project/result access and removes active conversation text, state, evidence, results, memberships and usage records. A minimal deleted-project/job tombstone remains for durable-job fencing and audit integrity. The Phase 2 implementation had no private disk artifacts. Phase 3 now connects attachment/index deletion and private original cleanup to this lifecycle; see source operations. Existing database backup retention still applies: deletion does not rewrite prior backups. The documented server default is 14 nightly backups; the operator must confirm the active retention configuration. Restoration must respect deleted-project tombstones and must not reopen deleted content to users.

## Run the separate worker service

The web application does not start a worker automatically. Before enabling this feature in a deployed environment, the server operator must run the bundled `scripts/analysis-worker.mjs` as a separate, automatically restarted Windows service using Node 24 and the same BHW environment file. The script is explicitly included in standalone output at build time.

Example command, using the existing release junction and server settings file:

```
node --env-file=C:\meridian\bhw\.env C:\meridian\bhw\current\scripts\analysis-worker.mjs
```

Use the actual installed Node executable path in the service configuration. Load `CRON_SECRET` from the existing environment, never a command-line literal or a log. The worker sends bearer-authenticated POSTs to a loopback-only endpoint. `BHW_ANALYSIS_WORKER_URL` defaults to `http://127.0.0.1:3100/bhw-connect/api/analysis/worker`; adjust it for a different local port/base path. Redirects are refused. Do not expose credentials or forward session cookies to the worker.

A single process is sufficient initially; multiple worker processes use database leases and per-project locking. Idle workers poll every two seconds; connection errors back off for five seconds. A job lease lasts 60 seconds and is renewed every ten seconds while processing. An expired lease is reclaimed on a subsequent poll, up to three automatic execution attempts. The third expired attempt becomes a failed job; approved editors can explicitly retry failed/cancelled jobs. An interrupted provider call may have been billed even when no result was saved; the attempt log records unavailable usage rather than inventing a cost. Running requests are bounded by the worker client's five-minute timeout; future CPU-heavy statistics/file processing need their own execution isolation in the later phases.

The runner dispatches the current asynchronous analysis engine through the local app's dedicated worker endpoint, independently of the user's browser request. It does not execute user-authored code. Web-process restarts lose in-flight execution but retain the queue; a new worker request reclaims the expired lease. This phase does not introduce a Python runtime or a new external queue service.

Windows service installation, environment configuration, backup confirmation and production restart/recovery testing are operator work on the server. This PR does not change `ops/` or `.github/`, install a service, or claim production verification. If shared deploy/service scripts are later changed, follow the working agreement's ownership review.

## Usage and cost estimates

Each lease/attempt gets a separate usage record, including interrupted attempts. Successful provider responses report model IDs and token counts when supplied by the API; configured-provider failures retain unknown usage. Usage is isolated per analysis with AsyncLocalStorage, including routing/classification calls. No per-user message quota or spending cap is added.

Optional server-owned `BHW_AI_PRICES_JSON` maps exact `provider/model` IDs to USD prices per million tokens, for example:

```json
{"provider/exact-model-id":{"inputPerMillion":1,"outputPerMillion":2}}
```

These example prices are not real provider prices. Set rates only from the chosen plan's current prices. If the model/rate/token counts are unavailable or invalid, the estimate is null and the UI says cost unavailable. The download contains all attempt records. Estimates do not replace provider invoices or include unrecorded external-service fees; the monthly operating budget remains undecided.

## Release verification

Run repository lint, types, unit/database tests, migration lint and both required builds. Verify the standalone worker script exists and is syntactically valid. Test private project reopen, viewer/editor/owner actions, approval revocation, cancellation/retry, expired-lease recovery, stale-result rejection, source/result isolation, download permissions and deletion. Use the synthetic development copy and a synthetic whoami fixture for local browser tests; do not copy live data into tests.

After deploying the schema, refresh the committed development copy through the existing BHW dev-copy workflow as required by `CLAUDE.md`. Before calling Phase 2 operationally complete, confirm that the server worker service is running and that a job finishes after the browser closes. Exercise restart recovery on the target Windows host; local PostgreSQL/Node/browser tests do not prove that deployment step.
