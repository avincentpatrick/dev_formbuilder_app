<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Models\Form;
use App\Rules\RedirectUrl;
use App\Rules\ValidTemplate;
use App\Services\Forms\FormService;
use App\Support\Forms\RedirectTarget;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;

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
 *
 * ── THE DESTINATION AFTER THE THANK-YOU (M130, `R-db169c29`, `D76`) ──────────────────────────────────
 * The same section, route and audit row: an author sets the message and where a respondent goes next in one
 * save. `redirect_kind` is optional, and when it is absent the destination is left as it is — "Reset to
 * default" sends the message alone and must not clear a destination by omission. A form destination must be
 * another form of this workspace the author may open; a typed address goes through {@see RedirectUrl}.
 */
final class UpdateConfirmationMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (can:update,form) owns authorization
    }

    /**
     * @return array<string, array<int, string|ValidationRule|In|Closure>>
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
            'redirect_kind' => ['sometimes', 'string', Rule::in(['none', 'form', 'url'])],
            'redirect_form_id' => ['exclude_unless:redirect_kind,form', 'required', 'string', 'uuid', $this->anotherFormTheAuthorMayOpen()],
            'redirect_url' => ['exclude_unless:redirect_kind,url', 'required', 'string', 'max:'.RedirectUrl::MAX_LENGTH, new RedirectUrl],
        ];
    }

    /**
     * The destination this save asks for, or null when it does not mention one — which leaves the stored one as
     * it is (the class docblock says why "Reset to default" depends on that).
     */
    public function redirectTarget(): ?RedirectTarget
    {
        return match ($this->validated('redirect_kind')) {
            'form' => RedirectTarget::form((string) $this->validated('redirect_form_id')),
            'url' => RedirectTarget::url((string) $this->validated('redirect_url')),
            'none' => RedirectTarget::none(),
            default => null,
        };
    }

    /**
     * Another form of this workspace the author may open — never this one. Row-level security already hides
     * every other workspace's forms, so a foreign id reads as missing; the policy check keeps out a form the
     * author cannot open, whose title the settings page would otherwise show back to them.
     */
    private function anotherFormTheAuthorMayOpen(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $current = $this->route('form');
            $target = is_string($value) && Str::isUuid($value) ? Form::query()->whereKey($value)->first() : null;

            if ($target === null
                || ($current instanceof Form && $target->is($current))
                || $this->user()?->can('view', $target) !== true) {
                $fail('Choose another form from this workspace.');
            }
        };
    }
}
