<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Services\Attachments\AttachmentStorageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * One reference file for a form (M132, `R-bf49e4c1`): a PDF or an image respondents can open. Authorization is the
 * route's `can:update,form` — whoever may edit the form may attach to it.
 *
 * **These rules are the FIRST of two gates**, as for a note's image: a field-attached 422 the settings panel can
 * show, while {@see AttachmentStorageService::storeFormReferenceFile()} re-checks the type and the size as the write
 * path's own guard. Both read `config('attachments.form_reference_file.*')`. `mimetypes:` asks `finfo` about the
 * BYTES, so a renamed file is judged by what it is.
 *
 * The accessor is `uploadedReferenceFile()`, never `file()` or `image()`: both name methods `Request` already has.
 */
final class StoreFormReferenceFileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var list<string> $accepted */
        $accepted = config('attachments.form_reference_file.accepted_types');
        $maxKb = (int) (config('attachments.form_reference_file.max_bytes') / 1024);

        return [
            'file' => ['required', 'file', 'mimetypes:'.implode(',', $accepted), 'max:'.$maxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Choose a file to attach.',
            'file.mimetypes' => 'A reference file must be a PDF, or a PNG, JPEG or WebP image.',
            'file.max' => 'A reference file must be 10 MB or smaller.',
        ];
    }

    public function uploadedReferenceFile(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
