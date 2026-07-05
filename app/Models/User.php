<?php

declare(strict_types=1);

namespace App\Models;

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
     * Resolved theme preference for server-side <html> attribute emission (Increment C).
     * Fail-safe: `user_ui_preferences` is under belongs-to-user RLS; if app.current_user_id
     * isn't set the read fails closed — degrade to "system", never throw. (accent lands in C2.)
     *
     * @return array{mode: string, accent: string}
     */
    public function uiTheme(): array
    {
        return rescue(
            function (): array {
                $mode = $this->uiPreference()->value('theme_mode');

                return [
                    'mode' => is_string($mode) ? $mode : 'system',
                    'accent' => 'blueprint',
                ];
            },
            ['mode' => 'system', 'accent' => 'blueprint'],
            report: false,
        );
    }
}
