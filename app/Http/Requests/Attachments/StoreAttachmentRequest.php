<?php

declare(strict_types=1);

namespace App\Http\Requests\Attachments;

use App\Http\Controllers\Public\GuestAttachmentController;
use App\Http\Controllers\Tenant\AttachmentController;
use App\Services\Attachments\AttachmentStorageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * Validates a single staged media upload (Increment G6), for both the authenticated
 * ({@see AttachmentController}) and guest
 * ({@see GuestAttachmentController}) channels. Authorization is the route
 * middleware's concern (`can:create,Submission,form` for the tenant channel; the guest-token tenant
 * context + `throttle:guest` for the guest channel), so this request only shape-validates.
 *
 * The `max:` ceiling is the global platform limit; the per-field size cap and the content-sniffed MIME
 * allowlist are enforced in {@see AttachmentStorageService} (they depend on the
 * resolved field's config and on the file's ACTUAL bytes, never the client-declared type — threat-model §6).
 */
final class StoreAttachmentRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:'.(int) config('attachments.max_upload_kb')],
            'field_key' => ['required', 'string', 'max:150'],
        ];
    }

    public function fieldKey(): string
    {
        return (string) $this->validated('field_key');
    }

    public function uploadedFile(): UploadedFile
    {
        $file = $this->file('file');
        assert($file instanceof UploadedFile);

        return $file;
    }
}
