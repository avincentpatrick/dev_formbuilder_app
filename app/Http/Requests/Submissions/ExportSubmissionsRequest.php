<?php

declare(strict_types=1);

namespace App\Http\Requests\Submissions;

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
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
        ];
    }

    /** @return 'csv'|'xlsx' */
    public function exportFormat(): string
    {
        return $this->input('format') === 'xlsx' ? 'xlsx' : 'csv';
    }

    /**
     * @return array{status: ?string, source: ?string}
     */
    public function filters(): array
    {
        return [
            'status' => $this->stringOrNull('status'),
            'source' => $this->stringOrNull('source'),
        ];
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
