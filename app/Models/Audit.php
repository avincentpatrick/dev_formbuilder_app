<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditEvent;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Support\Audit\AuditLogger;
use Database\Factories\AuditFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One row of the append-only audit ledger (data-dictionary §13, audit-compliance-logging-spec.md). Written
 * only through {@see AuditLogger} — never mass-assigned from a request, and never updated
 * or deleted (the `append_only` RLS shape denies both at the database).
 *
 * `auditable` is a polymorphic pointer whose type is one of the spec §1 alias strings; it is deliberately
 * NOT resolved through the global Relation::morphMap (adding User/Tenant there would split Sanctum/Spatie
 * morph rows), so the read API surfaces `auditable_type`/`auditable_id` as opaque identifiers rather than
 * eager-loading the target. The relation is provided for the rare in-app resolve, unused by the H4 API.
 *
 * @property string $id
 * @property ?string $tenant_id
 * @property string $auditable_type
 * @property string $auditable_id
 * @property AuditEvent $event
 * @property ?array<string, mixed> $old_values
 * @property ?array<string, mixed> $new_values
 * @property ?list<string> $redacted_fields
 * @property ?string $user_id
 * @property bool $is_system_action
 * @property ?string $ip_address
 * @property ?string $user_agent
 * @property Carbon|null $created_at
 */
class Audit extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<AuditFactory> */
    use HasFactory;

    use HasUuidv7;

    /** Append-only: rows have a creation time but are never updated (§13). */
    public const ?string UPDATED_AT = null;

    /** Everything is written via AuditLogger::forceFill — nothing is mass-assignable from a request. */
    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event' => AuditEvent::class,
            'old_values' => 'array',
            'new_values' => 'array',
            'redacted_fields' => 'array',
            'is_system_action' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
