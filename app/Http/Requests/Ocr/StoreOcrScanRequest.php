<?php

declare(strict_types=1);

namespace App\Http\Requests\Ocr;

use App\Services\Ocr\OcrScanService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Shape-checks one scan upload (M128 — single-form OCR groundwork 1): one to `ocr.upload.max_pages` files,
 * each an image or a PDF of the allowed size. The rest — a PDF on its own, the scan's total, the storage
 * quota, and whether this FORM may be scanned at all — is {@see OcrScanService}'s, because it needs the
 * form and all the files together. Authorization is the route's (`can:create` on a submission for this
 * form, then the module and plan gates), so this only shapes.
 *
 * `mimetypes` reads the CONTENT-SNIFFED type, as the write path does, so a renamed file is refused here
 * with a 422 that names the page.
 */
final class StoreOcrScanRequest extends FormRequest
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
        /** @var list<string> $types */
        $types = config('ocr.upload.accepted_types');

        return [
            'pages' => ['required', 'array', 'min:1', 'max:'.(int) config('ocr.upload.max_pages')],
            'pages.*' => [
                'required',
                'file',
                'mimetypes:'.implode(',', $types),
                'max:'.intdiv((int) config('ocr.upload.max_bytes_per_file'), 1024),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pages.*.mimetypes' => 'Each page must be a JPEG, PNG or WEBP photo, or a PDF.',
            'pages.*.max' => 'Each page must be under 7 MB.',
        ];
    }

    /**
     * The validated pages, in upload order.
     *
     * @return list<UploadedFile>
     */
    public function pages(): array
    {
        $pages = [];
        foreach ((array) $this->file('pages') as $file) {
            if ($file instanceof UploadedFile) {
                $pages[] = $file;
            }
        }

        return $pages;
    }
}
