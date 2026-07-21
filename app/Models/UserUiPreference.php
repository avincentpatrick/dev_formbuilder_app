<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccentToken;
use App\Enums\FontSizeScale;
use App\Enums\ThemeMode;
use App\Models\Concerns\HasUuidv7;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-user UI preferences (data-dictionary §19, PRD Feature #9). Belongs to a PERSON, not a tenant —
 * so it has NO tenant_id and uses the "belongs to me" RLS shape (keyed on app.current_user_id).
 * Deliberately does NOT use BelongsToTenant. Minimal in B1 (theme_mode only); the theme UI landed in
 * Increment C and the accent/text-size/dyslexia axes in G11.
 *
 * @property-read ThemeMode $theme_mode
 * @property-read ?AccentToken $accent_token
 * @property-read FontSizeScale $font_size_scale
 * @property-read bool $use_dyslexia_friendly_font
 */
class UserUiPreference extends Model
{
    use HasUuidv7;

    protected $fillable = [
        'user_id',
        'theme_mode',
        'accent_token',
        'font_size_scale',
        'use_dyslexia_friendly_font',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'theme_mode' => ThemeMode::class,
            // Nullable by design: NULL = Blueprint, the product default (data-dictionary §19).
            // Laravel casts null → null for backed enums, so the nullable column is safe here.
            'accent_token' => AccentToken::class,
            'font_size_scale' => FontSizeScale::class,
            'use_dyslexia_friendly_font' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
