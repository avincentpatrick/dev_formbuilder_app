<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Enums\UsageMetric;
use App\Models\User;
use App\Services\Entitlements\UsageMeter;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Export\SpreadsheetCell;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed CSV/XLSX export of an analytics report (`docs/PRD.md:206`, ADR-0011 §D13, Increment H24a).
 *
 * ── The three lines that make a streamed export correct ─────────────────────────────────────────────────
 * §D13 names them rather than the outer shape, because the outer shape is easy and these are not:
 *
 *  1. **Meter BEFORE the stream.** A streamed response cannot reliably increment on completion — the client
 *     may disconnect mid-body and the callback never finishes.
 *  2. **Capture the tenant and user ids BEFORE the closure.**
 *  3. **Restore the context INSIDE it.** The closure fires during `Response::send()`, after
 *     `EstablishTenantDatabaseContext::terminate()` has already torn context down. Omitting this ships an
 *     **empty file with HTTP 200** — a defect every status-only test passes, which is why the test for this
 *     asserts on rows.
 *
 * **The aggregation runs INSIDE the closure, deliberately.** Materializing the rows in request scope and
 * letting the closure only write them would work — the result is fixed-cardinality by §D7 — but it would
 * make all three lines above ceremony, and a later edit that moved a query back inside would find no
 * protection. This was caught by mutation: with the rows pre-computed, DELETING the `applyLocal()` call
 * reddened nothing at all.
 *
 * §D13 names only `applyLocal()`, but the tenant GUC is half the story: the permissions TEAM id is torn
 * down too, and `AnalyticsFormSet` consults `$user->can()`. Both are restored below.
 *
 * ── What it exports, and why it is not what the chart shows ─────────────────────────────────────────────
 * The breakdown is emitted **un-collapsed**: no top-N, no "Other (N)" bucket. §D11's argument for the
 * overflow bucket is that nothing is hidden, only un-plotted — the full set lives in the paired data table
 * and in this export. Collapsing here would make that false.
 *
 * ── Double metering is intentional ──────────────────────────────────────────────────────────────────────
 * The route sits in the `/api/v1` Group B stack, so `MeterApiUsage` records `api_requests` as well.
 * Different metrics, both wanted; recorded because it looks like a bug.
 */
final class AnalyticsExporter
{
    public function __construct(
        private readonly AnalyticsMetricsService $metrics,
        private readonly UsageMeter $meter,
    ) {}

    /** @param  'csv'|'xlsx'  $format */
    public function stream(AnalyticsQuery $query, User $user, string $format): StreamedResponse
    {
        // (1) Before the stream. See the class docblock.
        $this->meter->increment(UsageMetric::ExportsCount);

        $filename = 'analytics-'.$query->from->toDateString().'-to-'.$query->to->toDateString().'.'.$format;

        // (2) Captured before the closure.
        $tenantId = TenantContext::currentTenantId();
        $userId = TenantContext::currentUserId();

        return response()->streamDownload(function () use ($query, $user, $format, $tenantId, $userId): void {
            $writer = $this->writer($format);
            $writer->openToFile('php://output');

            DB::transaction(function () use ($query, $user, $writer, $tenantId, $userId): void {
                // (3) Inside the closure. Without this every aggregate below runs with no tenant GUC, RLS
                // hides every row, and the file is a header and nothing else — with HTTP 200.
                TenantContext::applyLocal($tenantId, $userId);

                // The GUC is only half of the torn-down context. `AnalyticsFormSet` asks
                // `$user->can('dashboard.org.view')`, and Spatie resolves that against the permissions TEAM
                // id, which `EstablishTenantDatabaseContext` set and terminate() did not restore. Without it
                // an org-wide reader could silently degrade to their granted-forms subset — a SMALLER number,
                // in a file, with no error.
                //
                // HONESTY NOTE: this line is defence-in-depth and a mutation leaves it GREEN. Every analytics
                // route carries a `can:` gate that calls `$user->can()` on this same User instance before the
                // closure runs, so Spatie's role memo is already warm and the lookup would succeed anyway —
                // which means the unwarmed case cannot be constructed on any route that exists today. It is
                // kept rather than deleted because the alternative is depending on an invariant enforced in
                // another file: a future route that reaches this exporter without a policy gate would export
                // the wrong number and pass every test.
                app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

                foreach ($this->rows($query, $user) as $row) {
                    $writer->addRow(Row::fromValues(SpreadsheetCell::row($row)));
                }
            });

            $writer->close();
        }, $filename, ['Content-Type' => $this->contentType($format)]);
    }

    /**
     * The export's flat shape: one long table carrying every section of the report, tagged by section.
     *
     * A single sheet rather than one per section, deliberately — CSV has no sheets, and a shape that differs
     * between the two formats would make "the same export pattern as submission exports" untrue for one of
     * them.
     *
     * @return list<list<string|int|float|null>>
     */
    private function rows(AnalyticsQuery $query, User $user): array
    {
        $rows = [['Section', 'Key', 'Value']];

        $total = $this->metrics->total($query, $user);
        $rows[] = ['Total', 'Current period', $total['current']];
        $rows[] = ['Total', 'Prior period', $total['prior']];
        $rows[] = ['Total', 'Change %', $total['change']];

        foreach ($this->metrics->series($query, $user) as $point) {
            $rows[] = ['Series', $point['bucket'], $point['count']];
        }

        // UN-COLLAPSED: the axis rows, then the remainder EXPANDED back out rather than left as "Other (N)".
        // The chart's overflow bucket is a plotting concession; the export is where the full set lives.
        $breakdown = $this->metrics->breakdown($query, $user);

        foreach ($breakdown['rows'] as $row) {
            $rows[] = ['Breakdown', $row['key'], $row['count']];
        }

        if ($breakdown['other'] !== null) {
            $rows[] = ['Breakdown', 'Other ('.$breakdown['other']['categories'].' categories)', $breakdown['other']['count']];
        }

        // Always emitted, even at zero — §D6 requires the bucket to be explicit, and an export that omitted
        // it would not reconcile against the totals above.
        $rows[] = ['Breakdown', 'Unassigned', $breakdown['unassigned']];

        $drafts = $this->metrics->draftMetrics($query, $user);
        $rows[] = ['Drafts', 'Denominator', $drafts['denominator']];
        // Suppression is EXPORTED as suppression, never as a blank cell that reads like zero (§D5).
        $rows[] = ['Drafts', 'Conversion %', $drafts['suppressed'] ? 'suppressed: '.$drafts['reason'] : $drafts['conversion_rate']];
        $rows[] = ['Drafts', 'Median first save to submit (seconds)', $drafts['suppressed'] ? 'suppressed: '.$drafts['reason'] : $drafts['median_seconds']];

        return $rows;
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
