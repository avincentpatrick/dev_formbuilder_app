<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Enums\AfterSubmit;
use App\Services\Forms\FormService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Set a form's "After submitting" setting (M248, `D232`): another response, "Change my answers" until the form closes,
 * or one response.
 *
 * Its own request, like {@see UpdatePageModeRequest}: the write is a dedicated {@see FormService::setAfterSubmit()},
 * never mass-assignment. `can:update,form` alone gates the route — the entitlement catalog holds no key for it, and
 * minting one would be a pricing decision rather than the enforcement of one.
 */
final class UpdateAfterSubmitRequest extends FormRequest
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
        return [
            'after_submit' => ['required', 'string', Rule::enum(AfterSubmit::class)],
        ];
    }

    public function afterSubmit(): AfterSubmit
    {
        return AfterSubmit::from((string) $this->validated('after_submit'));
    }
}
