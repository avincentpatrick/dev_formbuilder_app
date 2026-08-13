<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SsoFailureReason;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Sso\SsoAuthFailureRecorder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One refused SAML sign-in, kept so a tenant's own admin can see why (P1c — ADR-0016 §D26).
 *
 * ⚠️ THIS TABLE IS TRIMMED, AND NOTHING MAY BE BUILT ON THE ASSUMPTION THAT A ROW SURVIVES.
 * {@see SsoAuthFailureRecorder} enforces a per-tenant row cap and a retention window on every write, which
 * is what makes an unauthenticated writer safe. It is a diagnostic view, deliberately not a ledger — the
 * ledger is `audits`, and the reason this is a separate table is precisely that `audits` may never be
 * trimmed. Do not reach for these rows to answer a compliance question.
 *
 * ⚠️ NO FACTORY, AND NO SETTER OUTSIDE THE RECORDER. Every row is written on an unauthenticated path with
 * no `app.current_user_id`, so a second writer that assumed an actor would fail closed in a way nothing
 * would notice until an admin found an empty panel.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sso_connection_id
 * @property SsoFailureReason $reason
 * @property ?string $subject_email
 * @property ?string $request_id
 * @property ?string $ip_address
 * @property Carbon $occurred_at
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SsoAuthFailure extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    /** @var list<string> */
    protected $fillable = [
        'tenant_id',
        'sso_connection_id',
        'reason',
        'subject_email',
        'request_id',
        'ip_address',
        'occurred_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => SsoFailureReason::class,
            'occurred_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SsoConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SsoConnection::class, 'sso_connection_id');
    }
}
