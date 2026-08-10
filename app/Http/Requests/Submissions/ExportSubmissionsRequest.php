<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Support\Audit\AuditFilterQuery;
use App\Support\Search\SearchTerms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * The streamed-export request (Increment F7). Authorization is the `can:export,<Submission>,form` route
 * middleware; this only validates the output format and the optional status/source filters carried over from
 * the inbox. Columns come from the form's schema, not the request.
 */
final class ExportSubmissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'format' => ['nullable', Rule::in(['csv', 'xlsx'])],
            'status' => ['nullable', new Enum(SubmissionStatus::class)],
            'source' => ['nullable', new Enum(SubmissionSource::class)],
            // No `max:` — every bound in this feature lives in `SearchTerms::parse()`, where it clamps
            // instead of refusing. See `SearchRequest::rules()`.
            'q' => ['sometimes', 'nullable', 'string'],
        ];
    }

    /** @return 'csv'|'xlsx' */
    public function exportFormat(): string
    {
        return $this->input('format') === 'xlsx' ? 'xlsx' : 'csv';
    }

    /**
     * ⚠️ `q` IS CARRIED (J1e) BECAUSE THE INBOX'S EXPORT BUTTON BUILDS ITS URL FROM THE FILTERS ON SCREEN.
     * A keyword the page applied and this request ignored would stream rows the reviewer is not looking at,
     * which is the same defect {@see AuditFilterQuery} exists to prevent one surface over.
     *
     * The honest caveat, stated because it is pre-existing and this parameter does not fix it: the export
     * has never applied `visibleTo()` or the inbox's `countable()` display default — it is gated per-form by
     * `can:export` instead — so an export is still a wider slice than the inbox view in ways `q` is not
     * responsible for.
     *
     * @return array{status: ?string, source: ?string, q: SearchTerms}
     */
    public function filters(): array
    {
        return [
            'status' => $this->stringOrNull('status'),
            'source' => $this->stringOrNull('source'),
            'q' => SearchTerms::parse($this->stringOrNull('q')),
        ];
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
