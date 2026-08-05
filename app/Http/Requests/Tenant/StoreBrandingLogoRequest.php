<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Services\Attachments\AttachmentStorageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * The tenant brand-logo upload (Increment H23a2).
 *
 * **The rules here are the FIRST of two gates, never the only one.**
 * {@see AttachmentStorageService::storeBrandingLogo()} re-checks the size and CONTENT-SNIFFS the MIME
 * from the file's bytes before storing anything, because `mimetypes:` validation trusts a header a client
 * controls. The duplication is deliberate: this layer exists to give a friendly, field-attached error, and
 * the service layer exists to be correct.
 *
 * Both read the same `config('attachments.branding_logo.*')` so the two can never disagree about what is
 * allowed — a hard-coded list here would drift from the allowlist that actually enforces.
 *
 * **SVG is absent on purpose.** It is an XML document that can carry `<script>`, and a brand logo is
 * served same-origin to every respondent of every branded form — stored XSS (threat-model §6). The
 * exclusion is enforced in config and explained there; this note exists so nobody "helpfully" adds it to
 * the rule below.
 */
final class StoreBrandingLogoRequest extends FormRequest
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
        $accepted = config('attachments.branding_logo.accepted_types');
        $maxKb = (int) (config('attachments.branding_logo.max_bytes') / 1024);

        return [
            'logo' => ['required', 'file', 'mimetypes:'.implode(',', $accepted), 'max:'.$maxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'logo.mimetypes' => 'A logo must be a PNG, JPEG or WebP image. SVG is not accepted.',
            'logo.max' => 'A logo must be 1 MB or smaller.',
        ];
    }

    public function logo(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('logo');

        return $file;
    }
}
