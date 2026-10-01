<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Enums\FieldType;
use App\Services\Forms\FormBuilderService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change a draft field's type in place (Increment M122, `B5a`'s HTTP half).
 *
 * ⛔ ITS OWN REQUEST AND ITS OWN ROUTE, NEVER A `field_type` KEY ON {@see UpdateFieldRequest}. That request's
 * `configRules()` dispatches on the route model's CURRENT type, so a type-changing PATCH would validate the
 * new type's config against the old type's rules; and the PATCH is the autosave channel, where a dropped
 * keystroke must never change what a question is.
 *
 * ⚠️ THE TOKEN IS REQUIRED HERE, UNLIKE THE PATCH. {@see FormBuilderService::convertField()} refuses to land a
 * type change on a row the author did not see, so `version` is not optional. `fingerprint` is the plan the
 * author confirmed, as `GET …/conversions` returned it; it may be absent only for a plan that needs no
 * confirmation, and the engine — not this request — decides which plans those are.
 */
final class ConvertFieldRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (can:update,form) owns authorization
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'to' => ['required', 'string', Rule::enum(FieldType::class)],
            'version' => ['required', 'string'],
            'fingerprint' => ['nullable', 'string', 'regex:/^[0-9a-f]{64}$/'],
        ];
    }

    public function target(): FieldType
    {
        return FieldType::from($this->string('to')->toString());
    }

    public function fingerprint(): ?string
    {
        $fingerprint = $this->input('fingerprint');

        return is_string($fingerprint) ? $fingerprint : null;
    }
}
