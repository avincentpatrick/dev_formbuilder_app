<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Support\Branding\BrandRampGenerator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The tenant brand-colour write (Increment H23a2, ADR-0014). Mirrors {@see UpdateDraftSettingsRequest}
 * and {@see UpdateAppearanceRequest}'s partial-write shape so the branding surface can grow without a
 * full-payload clobber.
 *
 * Authorization is the route's `can:tenant.settings.manage` gate (Owner/Admin) plus a `feature:branding`
 * gate; the controller writes the subdomain-resolved current tenant only, never a tenant id from the
 * request body — `tenants` is RLS-exempt, so that scoping is the controller's job and not the database's.
 *
 * **The regex is `#RRGGBB` and nothing else, and the strictness is deliberate.** Three-digit shorthand
 * (`#FFF`), named colours, `rgb()` and 8-digit `#RRGGBBAA` are all refused rather than normalised. Alpha
 * in particular has no meaning here: {@see BrandRampGenerator} derives twelve OPAQUE tokens against fixed
 * grounds, so accepting a transparency the engine then silently discards would be a worse answer than
 * refusing it. The uppercase normalisation happens in the generator, which is the single place that
 * decides what a stored `primary_color` looks like.
 */
final class UpdateBrandingRequest extends FormRequest
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
        return [
            'primary_color' => ['sometimes', 'required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'primary_color.regex' => 'A brand colour must be a six-digit hex value such as #1B5E5E.',
        ];
    }

    /** The submitted brand colour, or null when this request did not carry one. */
    public function brandColor(): ?string
    {
        /** @var array{primary_color?: string} $validated */
        $validated = $this->validated();

        return $validated['primary_color'] ?? null;
    }
}
