<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Enums\FormThemePreset;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A form's preset theme (M131, `R-6017d6d8`, `D65`, `D81`): one of `FormThemePreset`, or null for the
 * workspace's own brand. `present` rather than `required`, because null is meaningful.
 *
 * No plan gate, here or on the route: `D81` puts presets on every plan, and the entitlement catalog has no key
 * for them — minting one would be a pricing decision, the reasoning `UpdatePageModeRequest` records for the
 * page-mode setting.
 */
final class UpdateFormThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // `can:update,form` on the route owns authorization
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'preset' => ['present', 'nullable', 'string', Rule::enum(FormThemePreset::class)],
        ];
    }

    public function preset(): ?FormThemePreset
    {
        $value = $this->validated('preset');

        return $value === null ? null : FormThemePreset::from((string) $value);
    }
}
