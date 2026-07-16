<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Http\Controllers\Api\V1\FormXlsformApiController;
use App\Http\Controllers\Tenant\FormXlsformController;
use App\Services\Xlsform\XlsformWorkbookReader;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Validates the uploaded `.xlsx` for XLSForm import (Increment G7b), for both the Inertia builder
 * ({@see FormXlsformController}) and the /api/v1 ({@see FormXlsformApiController}) channels. Authorization is
 * the route middleware's concern (`can:update,form` / `WRITE_FORMS` — import mutates the draft), so this only
 * shape-validates. A form definition is tiny, so the ceiling is a hard 5 MB.
 *
 * A `.xlsx` is a zip container, so fileinfo commonly content-sniffs it as `application/zip`; `mimes:xlsx,zip`
 * accepts both so a genuine workbook is not rejected. The real structural check is
 * {@see XlsformWorkbookReader} opening it.
 */
final class XlsformImportRequest extends FormRequest
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
            // `xlsx,zip`: an .xlsx is a zip container, so fileinfo commonly guesses `application/zip` — allowing
            // both keeps a genuine workbook from being rejected. The real structural check is openspout opening it.
            'file' => ['required', 'file', 'mimes:xlsx,zip', 'max:5120'],
        ];
    }

    public function uploadedFile(): UploadedFile
    {
        $file = $this->file('file');
        assert($file instanceof UploadedFile);

        return $file;
    }
}
