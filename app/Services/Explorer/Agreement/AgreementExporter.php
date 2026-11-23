<?php

declare(strict_types=1);

namespace App\Services\Explorer\Agreement;

use App\Enums\UsageMetric;
use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Models\ExplorerChart;
use App\Models\User;
use App\Services\Analytics\AnalyticsExporter;
use App\Services\Entitlements\UsageMeter;
use App\Services\Explorer\ExplorerQueryRunner;
use App\Support\Explorer\ExplorerQuery;
use App\Support\Export\SpreadsheetCell;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * A saved agreement list as CSV or Excel (M250, `D234` answer 2; ADR-0025 §D10).
 *
 * ── The three lines that make a streamed export correct ─────────────────────────────────────────────────
 * Exactly {@see AnalyticsExporter}'s, for its reasons: meter BEFORE the stream (a client that disconnects never
 * finishes the callback); capture the tenant and user ids BEFORE the closure; restore both, and the permissions
 * team, INSIDE it — the closure runs after the request's database context has been torn down, and without them every
 * count below is zero in a file served with HTTP 200. The list is counted inside the closure, so deleting the
 * `applyLocal()` call reddens the test that reads the rows.
 *
 * ── One sheet, one line per item, in the list's own order ───────────────────────────────────────────────
 * The short list first, then the items to discuss most-disagreed first, then those with too few views — the order
 * the page shows and a meeting reads. Every answer's count is in one cell rather than a column per answer: across
 * NHETE's lists that would be sixty columns, most of them empty on any one line.
 */
final class AgreementExporter
{
    private const array STATUS = [
        AgreementShaper::SHORT_LIST => 'On the short list',
        AgreementShaper::DISCUSS => 'To discuss',
        AgreementShaper::TOO_FEW => 'Too few views',
    ];

    public function __construct(
        private readonly ExplorerQueryRunner $runner,
        private readonly UsageMeter $meter,
    ) {}

    /** @param  'csv'|'xlsx'  $format */
    public function stream(ExplorerChart $chart, ExplorerQuery $query, User $user, string $format): StreamedResponse
    {
        // (1) Before the stream.
        $this->meter->increment(UsageMetric::ExportsCount);

        $name = Str::slug($chart->name);
        $filename = ($name !== '' ? $name : 'agreement-report').'-'.$query->from->toDateString().'-to-'.$query->to->toDateString().'.'.$format;

        // (2) Captured before the closure.
        $tenantId = TenantContext::currentTenantId();
        $userId = TenantContext::currentUserId();

        return response()->streamDownload(function () use ($query, $user, $format, $tenantId, $userId): void {
            $writer = $this->writer($format);
            $writer->openToFile('php://output');

            DB::transaction(function () use ($query, $user, $writer, $tenantId, $userId): void {
                // (3) Inside the closure: without it RLS hides every response and the file is a header alone.
                TenantContext::applyLocal($tenantId, $userId);
                app(PermissionRegistrar::class)->setPermissionsTeamId($tenantId);

                foreach ($this->rows($query, $user) as $row) {
                    $writer->addRow(Row::fromValues(SpreadsheetCell::row($row)));
                }
            });

            $writer->close();
        }, $filename, ['Content-Type' => $this->contentType($format)]);
    }

    /**
     * @return list<list<string|int|float|null>>
     */
    private function rows(ExplorerQuery $query, User $user): array
    {
        try {
            $result = $this->runner->run($query, $user);
        } catch (InvalidExplorerQueryException $e) {
            // Checked before the stream began; a form changed in between gets its sentence, not a broken file.
            return [['This agreement list cannot be counted: '.$e->getMessage()]];
        }

        /** @var array{threshold: int, min_views: int, items: list<array<string, mixed>>} $list */
        $list = $result['agreement'];

        $rows = [[
            'Order',
            "Status (at least {$list['threshold']}% agreeing of at least {$list['min_views']} views)",
            'Section',
            'Question',
            'Column',
            'Row',
            'Key',
            'Responses',
            'Gave a view',
            'Left out',
            'Counted answer',
            'Agreeing',
            'Share agreeing (%)',
            'Answers',
        ]];

        foreach ($list['items'] as $index => $item) {
            $isRow = $item['label'] !== $item['question'] || $item['column'] !== null;

            $rows[] = [
                $index + 1,
                self::STATUS[$item['status']] ?? (string) $item['status'],
                (string) $item['section'],
                (string) $item['question'],
                $item['column'],
                $isRow ? (string) $item['label'] : null,
                (string) $item['key'],
                (int) $item['responses'],
                (int) $item['views'],
                (int) $item['left_out'],
                implode(' + ', array_map(static fn (array $answer): string => (string) $answer['label'], $item['counted'])),
                (int) $item['agreeing'],
                $item['share'],
                implode('; ', array_map(
                    static fn (array $answer): string => $answer['label'].' '.$answer['responses'].($answer['left_out'] ? ' (left out)' : ''),
                    array_filter($item['answers'], static fn (array $answer): bool => $answer['responses'] > 0),
                )),
            ];
        }

        return $rows;
    }

    private function contentType(string $format): string
    {
        return $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'text/csv';
    }

    private function writer(string $format): WriterInterface
    {
        return $format === 'xlsx' ? new XlsxWriter : new CsvWriter;
    }
}
