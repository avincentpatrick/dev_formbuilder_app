<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Services\Sso\SsoMetadataParser;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Import a tenant's identity-provider metadata (P1a — ADR-0016 §D10).
 *
 * `metadata_xml` is the only accepted field, and that is a security property rather than tidiness: every
 * other column on `sso_connections` is either derived from this document by {@see SsoMetadataParser} or is
 * policy that belongs to {@see UpdateSsoConnectionRequest}. A request permitted to carry
 * `idp_certificates_fingerprint` directly would let a tenant claim a fingerprint that does not match the
 * keys it stored.
 *
 * ⚠️ THE DOCUMENT ARRIVES AS A STRING, NEVER AS AN UPLOADED FILE, AND THE ROUTE'S VERB DEPENDS ON IT.
 * The page's "Load from file" control reads the file client-side and puts its text in this same field, so
 * the wire is always JSON. That is what makes `PUT` correct: `@inertiajs/core` does **not** method-spoof
 * (there is no `_method` in the bundle), it converts a payload containing a `File` to `FormData` and keeps
 * the verb — and PHP populates `$_POST`/`$_FILES` only for a `POST` with a multipart body, so a `PUT`
 * carrying a file arrives with an EMPTY request and every field 422s "required" with no visible cause.
 * **If a real file input is ever added here, the route must become a `POST`** (the
 * `POST /settings/branding/logo` precedent, which is a separate route from `PATCH /settings/branding` for
 * exactly this reason).
 *
 * Authorization is the route's `can:tenant.settings.manage` + `feature:sso_saml` + `step-up` gates.
 */
final class ImportSsoMetadataRequest extends FormRequest
{
    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        // The friendly half of the two size gates; SsoMetadataParser::parseHardened() re-checks bytes before
        // loadXML() and is the half that is correct. `max:` counts CHARACTERS, so this is a slightly loose
        // bound on a UTF-8 document — deliberately, because a field error that fires early is the point and
        // the exact ceiling is the parser's job.
        $maxCharacters = (int) config('saml.max_metadata_bytes');

        return [
            'metadata_xml' => ['required', 'string', 'max:'.$maxCharacters],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['metadata_xml' => 'identity provider metadata'];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'metadata_xml.required' => 'Paste the XML metadata document from your identity provider.',
            'metadata_xml.max' => 'That document is far larger than identity-provider metadata should be. Check you pasted the metadata and not something else.',
        ];
    }

    /** The pasted document. */
    public function metadataXml(): string
    {
        /** @var array{metadata_xml: string} $validated */
        $validated = $this->validated();

        return $validated['metadata_xml'];
    }
}
