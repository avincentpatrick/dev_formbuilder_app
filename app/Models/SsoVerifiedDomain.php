<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainVerificationFailure;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Sso\SsoDomainService;
use App\Services\Sso\SsoUserProvisioner;
use Database\Factories\SsoVerifiedDomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An email domain this workspace has proven it controls, or is in the middle of proving (M18 — §D34).
 *
 * ⚠️ A PENDING ROW GRANTS NOTHING, AND THE ONLY QUESTION ANYONE MAY ASK IS {@see self::isVerified()}.
 * The row exists from the moment a claim is made so the token has somewhere to live between minting and
 * publication; `verified_at` is the whole of its authority. {@see SsoUserProvisioner} therefore asks the
 * question through {@see SsoDomainService::isVerifiedFor()} rather than by loading a row and reading a
 * column, so "exists" can never be mistaken for "verified" at a call site.
 *
 * ⚠️ `$fillable` IS DELIBERATELY EMPTY OF THE STATE COLUMNS. `verified_at`, `verification_checked_at`,
 * `verification_failure_reason` and `verification_token` are written by {@see SsoDomainService} through
 * `forceFill()` and by nothing else. This is the `CustomDomainService` posture, and the reason is the one
 * that class records: a mass-assigned create carrying `verified_at` would hand a workspace the fact the
 * whole control exists to make it earn.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $domain
 * @property string $verification_token
 * @property Carbon $token_issued_at
 * @property ?Carbon $verified_at
 * @property ?Carbon $verification_checked_at
 * @property ?DomainVerificationFailure $verification_failure_reason
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SsoVerifiedDomain extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<SsoVerifiedDomainFactory> */
    use HasFactory;

    use HasUuidv7;

    /**
     * ⚠️ THE STATE COLUMNS ARE ABSENT ON PURPOSE — see the class docblock. Adding `verified_at` here would
     * make the proof of domain control mass-assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'domain',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'token_issued_at' => 'datetime',
            'verified_at' => 'datetime',
            'verification_checked_at' => 'datetime',
            'verification_failure_reason' => DomainVerificationFailure::class,
        ];
    }

    /** The one question this model answers for the authentication path. */
    public function isVerified(): bool
    {
        return $this->verified_at !== null;
    }

    /** @return BelongsTo<User, $this> */
    public function claimant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
