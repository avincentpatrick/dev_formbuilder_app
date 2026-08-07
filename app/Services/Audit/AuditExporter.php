<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\Enums\AuditEvent;
use App\Enums\UsageMetric;
use App\Models\Audit;
use App\Models\User;
use App\Services\Analytics\AnalyticsExporter;
use App\Services\Entitlements\UsageMeter;
use App\Services\Submissions\SubmissionExporter;
use App\Support\Audit\AuditableTypes;
use App\Support\Audit\AuditDiff;
use App\Support\Audit\AuditLogger;
use App\Support\Export\SpreadsheetCell;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed CSV/XLSX export of the tenant's audit ledger (Increment I2, audit-compliance-logging-spec §3).
 *
 * Cloned from {@see SubmissionExporter}'s lazy-chunked shape rather than
 * {@see AnalyticsExporter}'s materialised one: analytics rows are
 * fixed-cardinality aggregates, audit rows are unbounded and never pruned.
 *
 * ── The three normative rules of a streamed export in this app ────────────────────────────────────────
 *  1. METER BEFORE THE STREAM — a streamed response cannot reliably increment on completion.
 *  2. CAPTURE the tenant/user ids BEFORE the closure.
 *  3. `TenantContext::applyLocal()` INSIDE it, because the closure fires during `Response::send()`, after
 *     `EstablishTenantDatabaseContext::terminate()` has torn the GUC down. Omit it and RLS hides every row
 *     and the download is a header and nothing else — AT HTTP 200, which every status-only test passes.
 *
 * Rule 3 is MUTATION-VERIFIED, not assumed: deleting the `applyLocal()` call reddens
 * `AuditExportTest`'s first test with a header-only file, exactly as the trap predicts.
 *
 * **On the second argument, honestly.** `applyLocal($tenantId, $userId)` mirrors the request's full
 * context so reads inside the closure resolve as they would in request scope, and it matches
 * `AnalyticsExporter`. It is NOT what makes the Actor column work: the `users` policy is
 * `id = app.current_user_id OR EXISTS(active co-tenant membership)`, so every actor this query can name is
 * already visible through the MEMBERSHIP branch, and the user GUC only feeds the self-branch, which
 * nothing here reads. Keep passing it for context fidelity; do not conclude from its inertness that the
 * whole `applyLocal()` line is optional — deleting THAT empties the file.
 *
 * **`->with('user:id,name')` is an N+1 GUARD, and no test enforces it.** `data_get($audit, 'user.name')`
 * lazy-loads, so removing the eager load leaves every assertion green and quietly turns one query into one
 * per exported row — on a stream whose whole point is flat memory and bounded cost over an unbounded
 * table. Recorded here because a green suite will not defend it.
 *
 * There is deliberately NO `PermissionRegistrar::setPermissionsTeamId()` call, unlike `AnalyticsExporter` —
 * nothing in this closure calls `$user->can()`, so row visibility is pure RLS on `tenant_id`, and copying
 * the line would be cargo-culting the one part of that class its own docblock admits is defence-in-depth.
 */
final class AuditExporter
{
    private const HEADERS = ['Timestamp', 'Event', 'Type', 'Target ID', 'Actor', 'IP address', 'Changes', 'Redacted fields'];

    /**
     * XLSX's hard per-cell limit is 32,767 characters: openspout writes past it happily and Excel then
     * refuses to open the file. Applied to BOTH formats so the two produce identical bytes — a shape that
     * differed between them would make "the same export" untrue for one of them.
     */
    private const CELL_MAX = 32_000;

    public function __construct(
        private readonly UsageMeter $meter,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @param  array{auditable_type?: ?string, event?: ?string, user_id?: ?string, from?: ?CarbonInterface, to?: ?CarbonInterface, from_raw?: ?string, to_raw?: ?string}  $filters
     * @param  'csv'|'xlsx'  $format
     */
    public function stream(User $user, array $filters, string $format): StreamedResponse
    {
        $this->meter->increment(UsageMetric::ExportsCount);

        $tenantId = TenantContext::currentTenantId();
        $userId = TenantContext::currentUserId();

        $this->recordSelfReferentialExport($tenantId, $filters, $format, $user);

        // A wall-clock stamp, not the filtered range: from/to are optional, so a range-based name needs
        // null branches and produces nonsense on an unfiltered pull. "When I pulled it" is the fact a
        // compliance export needs — and the FILTERS are recorded in the audit row above, which is a better
        // and tamper-evident place for them than a filename anyone can rename.
        $filename = 'audit-log-'.now()->format('Ymd-His').'.'.$format;

        return response()->streamDownload(function () use ($filters, $format, $tenantId, $userId): void {
            $writer = $this->writer($format);
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(SpreadsheetCell::row(self::HEADERS)));

            DB::transaction(function () use ($filters, $writer, $tenantId, $userId): void {
                // BOTH arguments — see the class docblock on the blank-Actor-column trap.
                TenantContext::applyLocal($tenantId, $userId);

                $this->baseQuery($filters)
                    ->with('user:id,name')
                    // ASC, unlike the screen's newest-first: a ledger reads chronologically in a file.
                    ->orderBy('id')
                    ->lazy()
                    ->each(function (Audit $audit) use ($writer): void {
                        $writer->addRow(Row::fromValues(SpreadsheetCell::row($this->row($audit))));
                    });
            });

            $writer->close();
        }, $filename, ['Content-Type' => $this->contentType($format)]);
    }

    /**
     * Exporting the audit log is itself an auditable act (spec §3) — one entry, not one per exported row.
     *
     * `auditable_type = 'audit_log'` with `auditable_id` = THE TENANT'S UUID. The column is `uuid NOT NULL`
     * and there is no audit_log row to point at, so this is spec §1's role-grant device again: key on the
     * owning entity. Not the actor's id — that is what `user_id` is for, and it would make the row's TARGET
     * the person who pulled it.
     *
     * ⚠️ **WRITTEN IN REQUEST SCOPE, BEFORE `streamDownload()`, AND THAT IS THE ONLY CORRECT PLACE.** The
     * stream closure fires during `Response::send()`, after context teardown: `Auth::id()` is gone (and
     * {@see AuditLogger} falls back to it) and the request's ip/user-agent reads return null. This is the
     * identical rule `SubmissionPdfRequestService` states for the identical reason.
     *
     * **NO TRANSACTION**, deliberately. AuditLogger's "inside the caller's transaction" contract exists so
     * that a rolled-back change leaves no phantom ledger row — and an export HAS NO CHANGE TO ROLL BACK, so
     * the requirement is vacuous here. Wrapping one statement in BEGIN/COMMIT would only leave the next
     * reader wondering what it was protecting.
     *
     * **NO ROW COUNT**, and someone will want to add one. Two reasons not to: it needs a `count(*)` over
     * the filtered set of an unbounded table before the stream even starts, and it is not honestly knowable
     * anyway — the client can abandon the download mid-body, so recording an intended count they never
     * received would be a false entry in the one table that exists to not contain those.
     *
     * An observed and accepted consequence, pinned by a test so nobody "fixes" it: this row commits before
     * the stream query runs, so **the exported file contains its own export row**. That is arguably correct
     * — the file honestly records that it was produced — and excluding `audit_log` rows from the query to
     * hide it would make the export lie about the ledger's contents.
     *
     * @param  array<string, mixed>  $filters
     */
    private function recordSelfReferentialExport(?string $tenantId, array $filters, string $format, User $user): void
    {
        if ($tenantId === null) {
            return;
        }

        $this->audit->record(
            AuditEvent::Exported,
            'audit_log',
            $tenantId,
            new: [
                'format' => $format,
                'auditable_type' => $filters['auditable_type'] ?? null,
                'event' => $filters['event'] ?? null,
                'user_id' => $filters['user_id'] ?? null,
                'from' => $filters['from_raw'] ?? null,
                'to' => $filters['to_raw'] ?? null,
            ],
            actorId: (string) $user->getKey(),
        );
    }

    /**
     * ONE FILE ROW PER AUDIT ROW, with the diff pre-rendered into a single readable cell.
     *
     * Rejected: two raw-JSON columns — that reproduces exactly the unreadability this increment exists to
     * fix, forces the reader to diff by eye across two cells, and is precisely the shape that trips the
     * XLSX cell limit. Rejected: one file row per changed key (the AnalyticsExporter long shape) — that
     * precedent is a report of AGGREGATES and is naturally long; this is a chronological log, and exploding
     * one act into twenty rows duplicates its timestamp and actor twenty times and makes "how many things
     * happened" uncountable.
     *
     * @return list<string>
     */
    private function row(Audit $audit): array
    {
        $changes = AuditDiff::rows($audit->old_values, $audit->new_values, $audit->redacted_fields, cap: null);

        $rendered = implode('; ', array_map(
            static fn (array $c): string => $c['key'].': '.($c['old'] ?? '(absent)').' → '.($c['new'] ?? '(absent)'),
            $changes,
        ));

        return [
            (string) $audit->created_at?->toIso8601String(),
            $audit->event->label(),
            AuditableTypes::label($audit->auditable_type),
            (string) $audit->auditable_id,
            // Same three-state resolution the viewer uses — see AuditLogPresenter::actorLabel(). A
            // non-null user_id whose row is invisible under the users join-shape policy is a departed
            // member, not the platform.
            $audit->user_id === null ? 'System' : (is_string($name = data_get($audit, 'user.name')) ? $name : 'Unknown user'),
            (string) $audit->ip_address,
            $this->cap($rendered),
            implode(', ', $audit->redacted_fields ?? []),
        ];
    }

    private function cap(string $value): string
    {
        return mb_strlen($value) <= self::CELL_MAX
            ? $value
            : mb_substr($value, 0, self::CELL_MAX).'… (truncated)';
    }

    /**
     * The RLS-scoped, filtered ledger query.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<Audit>
     */
    private function baseQuery(array $filters): Builder
    {
        return Audit::query()
            ->when($filters['auditable_type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v));
    }

    private function writer(string $format): WriterInterface
    {
        return $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
    }

    private function contentType(string $format): string
    {
        return $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv';
    }
}
