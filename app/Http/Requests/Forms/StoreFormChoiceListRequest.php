<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Services\Forms\ChoiceListCsvParser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * One choice list for a form, as a CSV file in Kobo's format (M141, `R-f69aab42`, `D95`). Authorization is the route's
 * `can:update,form`.
 *
 * ⚠️ BOTH `text/csv` AND `text/plain` ARE ACCEPTED, AND THAT IS MEASURED. `mimetypes:` asks `finfo` about the bytes, and
 * `finfo` reports a comma file as `text/csv` but a semicolon, tab, ragged or header-only file as `text/plain`. The real
 * check is the parse ({@see ChoiceListCsvParser}), which refuses anything that is not a list, by row; the extension
 * rule keeps a stray text file from being read as one.
 *
 * The accessor is `uploadedList()`, never `file()`: `Request` already has that method.
 */
final class StoreFormChoiceListRequest extends FormRequest
{
    public const int MAX_KB = 10 * 1024;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'extensions:csv', 'mimetypes:text/csv,text/plain,application/csv', 'max:'.self::MAX_KB],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a CSV file to upload.',
            'file.extensions' => 'A choice list must be a .csv file.',
            'file.mimetypes' => 'A choice list must be a .csv file.',
            'file.max' => 'A choice list must be 10 MB or smaller.',
        ];
    }

    public function uploadedList(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
