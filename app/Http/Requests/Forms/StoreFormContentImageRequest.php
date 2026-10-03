<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Services\Attachments\AttachmentStorageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * One image for a note's content (M129, `R-f0c5b682`). Authorization is the route's `can:update,form`: whoever may
 * edit the form may illustrate it.
 *
 * **These rules are the FIRST of two gates.** They exist to answer with a field-attached 422 the builder can show;
 * {@see AttachmentStorageService::storeFormContentImage()} re-checks the type and the size as the write path's own
 * guard. Both read `config('attachments.form_content_image.*')`, so they cannot disagree about what is allowed.
 *
 * `mimetypes:` reads the uploaded file's BYTES — Laravel asks `finfo`, not the client's header — so an SVG renamed
 * `.png` is refused here too. **SVG is absent on purpose**, for the brand logo's reason (see the config).
 */
final class StoreFormContentImageRequest extends FormRequest
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
        $accepted = config('attachments.form_content_image.accepted_types');
        $maxKb = (int) (config('attachments.form_content_image.max_bytes') / 1024);

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
            'file.required' => 'Choose an image to upload.',
            'file.mimetypes' => 'An image must be a PNG, JPEG or WebP file. SVG and GIF are not accepted.',
            'file.max' => 'An image must be 2 MB or smaller.',
        ];
    }

    public function uploadedImage(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
