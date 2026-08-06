<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Models\Form;
use App\Services\Forms\FormService;
use App\Support\Forms\FormSlug;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The share surface's write (Increment I1) — a form's public slug and its guest-access toggle, the two
 * columns PRD Feature #3 depends on and that no route had ever written.
 *
 * Its own request rather than an extension of {@see FormMetadataRequest}, per the {@see AssignFormScopeRequest}
 * / {@see UpdateSaveResumeRequest} precedent: the write is a dedicated {@see FormService::setShareSettings}
 * and never mass-assignment, which matters more here than anywhere else — both columns ARE in
 * `Form::$fillable`, so a `$form->update($validated)` behind the `can:update,form` gate would hand every form
 * editor the ability to publish a form to the open internet as a side effect of some unrelated edit.
 *
 * `public_slug` is `present` + `nullable` rather than `required`, the AssignFormScopeRequest reading: null is
 * a meaningful value (un-publish the link) and `required` rejects it.
 *
 * The uniqueness rule is deliberately the plain table form with no `tenant_id` predicate, matching every
 * other `Rule::unique` in this repo. Two properties make that correct rather than lucky:
 *  - RLS on the connection (EstablishTenantDatabaseContext) scopes the validator's own query to the current
 *    tenant, which is the leading half of `forms_tenant_id_public_slug_unique`.
 *  - `Rule::unique` queries the TABLE, not the model, so it sees soft-deleted rows — which is what the index
 *    does too. A model-scoped check would call a trashed form's slug free and then eat a 23505.
 */
final class UpdateFormShareRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (can:update,form) owns authorization
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Form $form */
        $form = $this->route('form');

        return [
            'allow_guest_submissions' => ['required', 'boolean'],
            'public_slug' => [
                'present',
                'nullable',
                'string',
                'min:'.FormSlug::MIN_LENGTH,
                'max:'.FormSlug::MAX_LENGTH,
                // Lowercase alphanumerics in hyphen-separated groups: no leading/trailing/doubled hyphen, no
                // uppercase (the runtime lookup is a case-sensitive equality), no path or query characters.
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::notIn(FormSlug::RESERVED),
                Rule::unique('forms', 'public_slug')->ignore($form->id),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'public_slug.regex' => 'Use lowercase letters, numbers and single hyphens — for example "clinic-intake".',
            'public_slug.not_in' => 'That link name is reserved. Choose another.',
            'public_slug.unique' => 'Another form in this workspace already uses that link.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Guest access with no slug is not a half-configured form, it is an unreachable one: the runtime
            // resolves `where('public_slug', $slug)`, so the flag would be on and every visitor would still
            // get the 404 that GuestFormController gives an unknown slug. Refuse the combination outright
            // rather than persisting a state whose only symptom is a link that does not exist.
            if ($this->boolean('allow_guest_submissions') && $this->input('public_slug') === null) {
                $validator->errors()->add(
                    'public_slug',
                    'A link name is required before this form can accept guest responses.',
                );
            }
        });
    }
}
