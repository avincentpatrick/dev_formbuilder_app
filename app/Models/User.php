<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AccentToken;
use App\Enums\FontSizeScale;
use App\Enums\ThemeMode;
use App\Models\Concerns\HasUuidv7;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * The global user identity (multi-tenancy-rbac-design.md §6). One row per person across every tenant.
 *
 * NOT tenant-scoped and deliberately does NOT use BelongsToTenant — visibility is governed by the
 * join-shape `users` RLS policy (self + active co-tenant membership). The connection stays the default
 * (`meridian_app`); the pre-auth read path lives entirely in App\Auth\RlsAwareUserProvider.
 *
 * @property bool $is_super_admin Global platform-staff flag (RBAC §9); never a Spatie role.
 * @property ?Carbon $two_factor_confirmed_at Set once 2FA enrollment is confirmed.
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'])]
class User extends Authenticatable implements MustVerifyEmail
{
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasRoles;          // Spatie RBAC — teams-mode role resolution against the active tenant (B2a)
    use HasUuidv7;
    use Notifiable;
    use SoftDeletes;
    use TwoFactorAuthenticatable;

    /**
     * Pin Spatie's guard for this model to the RBAC catalog's guard (`web`, per RolePermissionSeeder),
     * independent of the request's runtime default guard (Increment E). The /api/v1 token pipeline calls
     * `Auth::shouldUse('sanctum')`, which mutates `config('auth.defaults.guard')` to `sanctum`; without
     * this, Spatie's Guard::getDefaultName would resolve `sanctum` and every permission check would throw
     * PermissionDoesNotExist against the `web`-guarded catalog. All of this app's roles/permissions are
     * `web`-guarded, so this is always correct (and hardens the session path against the same drift).
     */
    public function guardName(): string
    {
        return 'web';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'two_factor_confirmed_at' => 'datetime',
            'tos_accepted_at' => 'datetime',
            'privacy_policy_accepted_at' => 'datetime',
            'is_super_admin' => 'boolean',
            'password' => 'hashed',
        ];
    }

    /**
     * The user's personal UI preferences (theme, etc.). Belongs to the PERSON, not a tenant —
     * governed by the "belongs to me" RLS shape (keyed on app.current_user_id).
     *
     * @return HasOne<UserUiPreference, $this>
     */
    public function uiPreference(): HasOne
    {
        return $this->hasOne(UserUiPreference::class);
    }

    /**
     * Resolved appearance preferences for server-side <html> attribute emission (Increment C for
     * `mode`; G11 added the other three — PRD Feature #9, design-system-reference.md §2.9).
     *
     * Fail-safe: `user_ui_preferences` is under belongs-to-user RLS, so if app.current_user_id isn't
     * set the read fails closed — degrade to the product defaults, never throw. That rescue is
     * load-bearing and must be preserved: it is what lets any request render even when the tenant
     * database context has not been established.
     *
     * Shape is camelCase because it crosses the wire as an Inertia prop (matching `auth.can.*`); the
     * PATCH request fields stay snake_case to match the column names.
     *
     * @return array{mode: string, accent: string, fontSize: string, dyslexiaFont: bool}
     */
    public function uiTheme(): array
    {
        return rescue(
            function (): array {
                $preference = $this->uiPreference()->first();

                if ($preference === null) {
                    return self::defaultUiTheme();
                }

                return [
                    'mode' => $preference->theme_mode->value,
                    // NULL accent_token = Blueprint, the product default (data-dictionary §19).
                    'accent' => AccentToken::fromColumn($preference->accent_token?->value)->value,
                    'fontSize' => $preference->font_size_scale->value,
                    'dyslexiaFont' => $preference->use_dyslexia_friendly_font,
                ];
            },
            self::defaultUiTheme(),
            report: false,
        );
    }

    /**
     * The product-default appearance, in one place.
     *
     * Both the rescue fallback above and HandleInertiaRequests' guest branch need these, and Increment
     * C duplicated the literal across both — so a fourth axis would have meant editing two lists that
     * nothing forced to agree.
     *
     * @return array{mode: string, accent: string, fontSize: string, dyslexiaFont: bool}
     */
    public static function defaultUiTheme(): array
    {
        return [
            'mode' => ThemeMode::System->value,
            'accent' => AccentToken::Blueprint->value,
            'fontSize' => FontSizeScale::Standard->value,
            'dyslexiaFont' => false,
        ];
    }
}
