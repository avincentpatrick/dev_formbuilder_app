<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Enums\UsageMetric;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Services\Entitlements\UsageMeter;
use App\Support\Export\SpreadsheetCell;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\WriterInterface;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streamed CSV/XLSX export of a form's submissions (Increment F7), keyed on the stable `form_fields.key`.
 *
 * Column resolution is **union-by-key across every version present** in the filtered set: submissions FK to
 * the immutable `form_version_id` they were collected against, so a republished form can have differing field
 * sets. We walk each present version's `form_versions.schema_snapshot` (newest version first) and union the
 * data-field keys — a stable key defines one column no matter which versions carry it, and no version's data
 * is dropped. Each submission's cells are then resolved through {@see SchemaValueFormatter} using that
 * submission's OWN version definition (so choice values map to the labels that version defined).
 *
 * The output is streamed row-by-row through openspout with a `->lazy()` chunked, eager-loaded query so memory
 * stays flat regardless of volume (the async/chunked export JOB for very large datasets is Phase 2). The row
 * reads run inside a transaction that re-asserts tenant context ({@see TenantContext::applyLocal()}) so RLS
 * scoping is deterministic even though the stream closure fires during `Response::send()`.
 *
 * H16a MOVED THE COLUMN RESOLUTION AND CELL FORMATTING OUT to {@see SubmissionRowProjector}, unchanged, so the
 * Google Sheets connector writes the same values this file downloads. What stays here is everything that is
 * genuinely about *this* channel: metering, the streamed openspout writer, the RLS-re-asserting transaction,
 * and the fixed metadata column block. Nothing about which columns exist or how a cell renders lives here any
 * more — that was the whole point of the extraction, and duplicating it back would recreate the divergence.
 */
final class SubmissionExporter
{
    public function __construct(
        private readonly UsageMeter $meter,
        private readonly SubmissionRowProjector $projector,
    ) {}

    /**
     * @param  array{status?: ?string, source?: ?string, q?: ?SearchTerms}  $filters
     * @param  'csv'|'xlsx'  $format
     */
    public function stream(Form $form, array $filters, string $format): StreamedResponse
    {
        // Meter exports_count (H5b) once per export request, before the stream — a streamed response can't
        // reliably increment on completion. Metered for reporting only; exports are not in the hard-block set.
        $this->meter->increment(UsageMetric::ExportsCount);

        $locale = $form->default_locale ?? 'en';

        // Resolve columns + per-version field maps up front (request scope, tenant context still set).
        $versions = FormVersion::query()
            ->whereIn('id', $this->baseQuery($form, $filters)->distinct()->pluck('form_version_id'))
            ->get()
            ->sortByDesc('version_number');

        [$columns, $fieldMeta] = $this->projector->resolveColumns($versions, $locale);
        $headerRow = array_merge(array_values(SubmissionRowProjector::metaLabels()), array_values($columns));
        $keys = array_keys($columns);

        $tenantId = TenantContext::currentTenantId();
        $userId = TenantContext::currentUserId();
        $filename = Str::slug($form->title ?: 'form').'-submissions-'.now()->format('Ymd-His').'.'.$format;

        return response()->streamDownload(function () use ($form, $filters, $format, $headerRow, $keys, $fieldMeta, $tenantId, $userId, $locale): void {
            $writer = $this->writer($format);
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues(SpreadsheetCell::row($headerRow)));

            DB::transaction(function () use ($form, $filters, $keys, $fieldMeta, $writer, $tenantId, $userId, $locale): void {
                TenantContext::applyLocal($tenantId, $userId);

                $this->baseQuery($form, $filters)
                    ->with(['answers', 'respondent:id,name'])
                    ->orderBy('id')
                    ->lazy()
                    ->each(function (Submission $submission) use ($keys, $fieldMeta, $writer, $locale): void {
                        $writer->addRow(Row::fromValues(SpreadsheetCell::row($this->row($submission, $keys, $fieldMeta, $locale))));
                    });
            });

            $writer->close();
        }, $filename, ['Content-Type' => $this->contentType($format)]);
    }

    /**
     * The RLS-scoped, filtered submission query for this form. Rebuilt (not cloned) each call so the
     * count/version-pluck pass and the streaming pass are independent statements.
     *
     * @param  array{status?: ?string, source?: ?string, q?: ?SearchTerms}  $filters
     * @return Builder<Submission>
     */
    private function baseQuery(Form $form, array $filters): Builder
    {
        return Submission::query()
            ->where('form_id', $form->id)
            // The inbox's own predicate (J1e), so a keyword-filtered view and its Export button agree.
            // `matchingKeyword` is a no-op on empty terms, so an export launched from anywhere else is
            // byte-identical to before.
            ->matchingKeyword($filters['q'] ?? SearchTerms::parse(null))
            ->when($filters['status'] ?? null, fn (Builder $q, string $v): Builder => $q->where('status', $v))
            ->when($filters['source'] ?? null, fn (Builder $q, string $v): Builder => $q->where('source', $v));
    }

    /**
     * One submission's cells: the fixed metadata block, then one cell per resolved column.
     *
     * The `?? ''` fallback is not decoration — it is the case where a column exists because ANOTHER version in
     * the filtered set defines that key and this submission's version does not, which is precisely what
     * union-by-key across versions creates. {@see SubmissionRowProjector::answerValues()} omits such a key
     * rather than guessing at a value for a field this submission never had.
     *
     * @param  list<string>  $keys
     * @param  array<string, array<string, array{type: FieldType, config: array<string, mixed>}>>  $fieldMeta
     * @return list<string>
     */
    private function row(Submission $submission, array $keys, array $fieldMeta, string $locale): array
    {
        $values = $this->projector->answerValues($submission, $fieldMeta, $locale);
        $row = array_values($this->projector->metaValues($submission));

        foreach ($keys as $key) {
            $row[] = $values[$key] ?? '';
        }

        return $row;
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
