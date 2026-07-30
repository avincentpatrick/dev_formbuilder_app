<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Rules\ValidTemplate;
use App\Services\Forms\FormService;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Set (or clear) a form's confirmation message (Increment H6a,
 * `docs/piping-output-encoding-design.md` §6.2).
 *
 * Its own request — deliberately NOT folded into {@see FormMetadataRequest} — mirroring the
 * {@see UpdateSaveResumeRequest} precedent: the write is a dedicated
 * {@see FormService::setConfirmationMessage}, never mass-assignment. Ungated by plan: confirmation copy
 * carries no tier feature, so the route stacks only `can:update,form`.
 *
 * This is the FIRST FormRequest in the repo to validate a `*_translations` column at all. Doc #26 §4
 * records that no such column had a rule anywhere; H6a closes that gap only for the column it adds. The
 * other four (`label_translations`, `hint_translations`, and the two on `form_sections`) have no
 * FormRequest ingress to attach a rule to — the builder cannot author translations, and their only
 * writers are XLSForm import, the schema-blueprint materializer and the field library, all of which go
 * through `Model::create`. Their guarantee is the publish gate, not a request rule.
 *
 * {@see ValidTemplate} checks GRAMMAR only. References resolve at publish, against the version being
 * published — there is no version to resolve against here (see the rule's docblock).
 */
final class UpdateConfirmationMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (can:update,form) owns authorization
    }

    /**
     * @return array<string, array<int, string|ValidationRule>>
     */
    public function rules(): array
    {
        return [
            // `max:2000` counts CHARACTERS; the template parser's own cap is 2000 BYTES, and it binds only
            // a hole-bearing value (Doc #26 §2.1 as amended by H6a). A multibyte message can therefore pass
            // here and still be refused at publish — the request rule is the cheap early check, not the
            // authority.
            'confirmation_message' => ['nullable', 'string', 'max:2000', new ValidTemplate],
            'confirmation_message_translations' => ['nullable', 'array'],
            'confirmation_message_translations.*' => ['nullable', 'string', 'max:2000', new ValidTemplate],
        ];
    }
}
