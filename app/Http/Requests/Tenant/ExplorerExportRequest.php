<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A saved agreement list, asked for as a spreadsheet (M250, `D234`). The route's `can:export` middleware owns
 * authorization; this reads only the format, which falls back to CSV as the analytics export's does.
 */
class ExplorerExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'format' => ['sometimes', 'string', 'in:csv,xlsx'],
        ];
    }

    /** @return 'csv'|'xlsx' */
    public function exportFormat(): string
    {
        return $this->string('format', 'csv')->toString() === 'xlsx' ? 'xlsx' : 'csv';
    }
}
