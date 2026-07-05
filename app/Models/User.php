<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidv7;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * The global user identity (multi-tenancy-rbac-design.md §6). One row per person across every tenant.
 *
 * NOT tenant-scoped and deliberately does NOT use BelongsToTenant — visibility is governed by the
 * join-shape `users` RLS policy (self + active co-tenant membership). The connection stays the default
 * (`meridian_app`); the pre-auth read path lives entirely in App\Auth\RlsAwareUserProvider.
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
}
