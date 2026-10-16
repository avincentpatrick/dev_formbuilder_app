<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasUuidv7;
use App\Services\Admin\PlatformSupportTokenService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A platform support token (M175, `R-78f43f26`, `D139`, `D141`; data-dictionary §39, ADR-0023) — the credential
 * the dev session's feedback pull presents to `/api/platform/v1/…`.
 *
 * Platform-owned, so deliberately NOT `BelongsToTenant` and not a Sanctum token: see the create migration.
 * Issued and revoked only through {@see PlatformSupportTokenService}, which is why nothing here is
 * mass-assignable — every column is written with `forceFill()` by the one service that owns the rules.
 *
 * @property string $id
 * @property string $user_id
 * @property string $name
 * @property string $token_hash
 * @property string $ability
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class PlatformApiToken extends Model
{
    use HasUuidv7;

    /** The one ability a support token carries today: read every workspace's feedback, move a report. */
    public const ABILITY_FEEDBACK = 'feedback:support';

    /** The plaintext's prefix — recognisable to a secret scanner and to a person reading a config file. */
    public const PREFIX = 'msp_';

    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /**
     * The super-admin who issued it.
     *
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Usable right now: not revoked and not past its expiry. The owner's standing is checked separately. */
    public function isUsable(?Carbon $now = null): bool
    {
        $now ??= Carbon::now();

        return $this->revoked_at === null && $this->expires_at->isAfter($now);
    }

    /** `active`, `expired` or `revoked` — revoked wins, since it is the operator's own act. */
    public function state(?Carbon $now = null): string
    {
        return match (true) {
            $this->revoked_at !== null => 'revoked',
            ! $this->expires_at->isAfter($now ?? Carbon::now()) => 'expired',
            default => 'active',
        };
    }
}
