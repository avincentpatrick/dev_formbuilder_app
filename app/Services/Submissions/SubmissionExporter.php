<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Enums\SubmissionSource;
use App\Enums\UsageMetric;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Services\Entitlements\UsageMeter;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSources;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
 */
final class SubmissionExporter
{
    /** Fixed leading metadata columns, before the per-field answer columns. */
    private const META_HEADERS = ['Submission ID', 'Status', 'Source', 'Respondent', 'Submitted at', 'Locale'];

    public function __construct(
        private readonly SchemaValueFormatter $formatter,
        private readonly UsageMeter $meter,
        private readonly TemplateRenderer $templates,
    ) {}

    /**
     * @param  array{status?: ?string, source?: ?string}  $filters
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

        [$columns, $fieldMeta] = $this->resolveColumns($versions, $locale);
        $headerRow = array_merge(self::META_HEADERS, array_values($columns));
        $keys = array_keys($columns);

        $tenantId = TenantContext::currentTenantId();
        $userId = TenantContext::currentUserId();
        $filename = Str::slug($form->title ?: 'form').'-submissions-'.now()->format('Ymd-His').'.'.$format;

        return response()->streamDownload(function () use ($form, $filters, $format, $headerRow, $keys, $fieldMeta, $tenantId, $userId): void {
            $writer = $this->writer($format);
            $writer->openToFile('php://output');
            $writer->addRow(Row::fromValues($headerRow));

            DB::transaction(function () use ($form, $filters, $keys, $fieldMeta, $writer, $tenantId, $userId): void {
                TenantContext::applyLocal($tenantId, $userId);

                $this->baseQuery($form, $filters)
                    ->with(['answers', 'respondent:id,name'])
                    ->orderBy('id')
                    ->lazy()
                    ->each(function (Submission $submission) use ($keys, $fieldMeta, $writer): void {
                        $writer->addRow(Row::fromValues($this->row($submission, $keys, $fieldMeta)));
                    });
            });

            $writer->close();
        }, $filename, ['Content-Type' => $this->contentType($format)]);
    }

    /**
     * The RLS-scoped, filtered submission query for this form. Rebuilt (not cloned) each call so the
     * count/version-pluck pass and the streaming pass are independent statements.
     *
     * @param  array{status?: ?string, source?: ?string}  $filters
     * @return Builder<Submission>
     */
    private function baseQuery(Form $form, array $filters): Builder
    {
        return Submission::query()
            ->where('form_id', $form->id)
            ->when($filters['status'] ?? null, fn (Builder $q, string $v): Builder => $q->where('status', $v))
            ->when($filters['source'] ?? null, fn (Builder $q, string $v): Builder => $q->where('source', $v));
    }

    /**
     * Union the data-field keys across every present version (newest first). Returns the ordered
     * `key ⇒ header` column map and a `versionId ⇒ key ⇒ {type,config}` resolution map.
     *
     * @param  Collection<int, FormVersion>  $versions
     * @return array{0: array<string, string>, 1: array<string, array<string, array{type: FieldType, config: array<string, mixed>}>>}
     */
    private function resolveColumns($versions, string $locale): array
    {
        $columns = [];
        $fieldMeta = [];

        foreach ($versions as $version) {
            $fields = $version->schema_snapshot['fields'] ?? [];
            usort($fields, fn (array $a, array $b): int => ($a['sequence'] ?? 0) <=> ($b['sequence'] ?? 0));

            // Piping sources for this version's headers (H6a) — every field, since `hidden`/`calculated`
            // are pipeable sources without being data columns of their own.
            $sources = TemplateSources::fromSnapshot($fields);

            foreach ($fields as $field) {
                $type = FieldType::tryFrom((string) ($field['field_type'] ?? ''));
                if ($type === null || ! $this->formatter->isDataField($type)) {
                    continue;
                }

                $key = (string) $field['key'];
                $config = is_array($field['config'] ?? null) ? $field['config'] : [];
                $fieldMeta[$version->id][$key] = ['type' => $type, 'config' => $config];

                if (! array_key_exists($key, $columns)) {
                    $columns[$key] = $this->header($field, $locale, $key, $sources);
                }
            }
        }

        return [$columns, $fieldMeta];
    }

    /**
     * One column header: the label resolved into `$locale`, then rendered as a template (H6a).
     *
     * The order is normative (Doc #26 §4): resolve the locale, THEN fill the holes — rendering first would
     * fill holes into a string nobody is going to read.
     *
     * The answer map is deliberately EMPTY. One header row serves every submission in the export, so a
     * piped header has no single answer to fill from — it is structurally unfillable, and §3.4 requires the
     * gap rather than the raw `${key}` token. A label of "Age of ${child_name}" therefore heads its column
     * as "Age of ". Only the header is templated; the answer CELLS are untouched, and the separate
     * spreadsheet formula-injection prefix §5 calls for is a different increment's row.
     *
     * @param  array<string, mixed>  $field
     * @param  array<string, array{type: FieldType, config: array<string, mixed>}>  $sources
     */
    private function header(array $field, string $locale, string $key, array $sources): string
    {
        $translations = is_array($field['label_translations'] ?? null) ? $field['label_translations'] : [];

        $label = (string) ($translations[$locale] ?? $field['label'] ?? $key);

        return $this->templates->render($label, $sources, []);
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, array<string, array{type: FieldType, config: array<string, mixed>}>>  $fieldMeta
     * @return list<string>
     */
    private function row(Submission $submission, array $keys, array $fieldMeta): array
    {
        $rawAnswers = data_get($submission, 'answers.answers');
        /** @var array<string, mixed> $answers */
        $answers = is_array($rawAnswers) ? $rawAnswers : [];
        $versionMap = $fieldMeta[$submission->form_version_id] ?? [];

        $row = [
            $submission->id,
            $submission->status->label(),
            $submission->source->label(),
            $this->respondentLabel($submission),
            $submission->submitted_at?->toIso8601String() ?? '',
            $submission->locale ?? '',
        ];

        foreach ($keys as $key) {
            $meta = $versionMap[$key] ?? null;
            $row[] = $meta === null
                ? ''
                : $this->formatter->displayValue($meta['type'], $answers[$key] ?? null, $meta['config']);
        }

        return $row;
    }

    private function respondentLabel(Submission $submission): string
    {
        if ($submission->respondent !== null) {
            return $submission->respondent->name;
        }

        return $submission->source === SubmissionSource::Guest ? 'Guest' : '';
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
