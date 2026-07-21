<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Enums\AccentToken;
use App\Enums\FontSizeScale;
use App\Enums\ThemeMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Appearance preferences (PRD Feature #9, data-dictionary §19, Increment G11).
 *
 * EVERY field is `sometimes`, and that is the load-bearing decision in this class. The top-nav
 * ThemeQuickToggle PATCHes only `theme_mode`, and each control in Settings → Appearance PATCHes only
 * its own axis — so a partial payload must never clobber the axes it did not mention. An absent key is
 * never validated, never appears in {@see self::toColumns()}, and therefore never reaches
 * updateOrCreate. The guarantee is structural and server-side: even a stale client cannot overwrite an
 * axis it doesn't know about. Pinned by PersonalizationPreferenceTest's partial-PATCH test.
 *
 * `accent_token` is whitelisted at the APPLICATION layer by explicit data-dictionary §19 decision (a DB
 * CHECK enumerating design-system tokens would force a migration every time §2.2 adds an accent), which
 * is why {@see AccentToken} is validated with Rule::enum() rather than a loose string rule.
 *
 * The wire speaks the enum ('blueprint'), the column stores NULL for the default: toColumns() maps
 * between them. That keeps "NULL = product default" true in the database while the wire and the UI both
 * carry a real, non-null value — MdsSegmentedControl's modelValue is a non-nullable string, so a null
 * round-trip would need coalescing at every read site.
 */
final class UpdateAppearanceRequest extends FormRequest
{
    /**
     * Authorization is the route's `auth` middleware plus belongs-to-user RLS: a user can only ever
     * read or write their own row, so there is no policy to consult.
     */
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
            'theme_mode' => ['sometimes', 'required', Rule::enum(ThemeMode::class)],
            'accent_token' => ['sometimes', 'required', Rule::enum(AccentToken::class)],
            'font_size_scale' => ['sometimes', 'required', Rule::enum(FontSizeScale::class)],
            'use_dyslexia_friendly_font' => ['sometimes', 'required', 'boolean'],
        ];
    }

    /**
     * Only the keys this request actually sent, mapped to their column values.
     *
     * Deliberately NOT named attributes() — that is a real FormRequest hook for validation-message
     * attribute names, and overriding it here would break error messages.
     *
     * @return array<string, string|bool|null>
     */
    public function toColumns(): array
    {
        $validated = $this->validated();
        $columns = [];

        if (array_key_exists('theme_mode', $validated)) {
            $columns['theme_mode'] = $validated['theme_mode'];
        }

        if (array_key_exists('accent_token', $validated)) {
            // Blueprint is the product default and is stored as NULL (data-dictionary §19).
            $columns['accent_token'] = AccentToken::from($validated['accent_token'])->toColumn();
        }

        if (array_key_exists('font_size_scale', $validated)) {
            $columns['font_size_scale'] = $validated['font_size_scale'];
        }

        if (array_key_exists('use_dyslexia_friendly_font', $validated)) {
            // boolean() coerces the "1"/"true"/"on" forms an HTML form or an Inertia visit may send.
            $columns['use_dyslexia_friendly_font'] = $this->boolean('use_dyslexia_friendly_font');
        }

        return $columns;
    }
}
