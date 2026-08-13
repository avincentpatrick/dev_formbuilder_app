<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SsoConnectionStatus;
use App\Enums\SsoProtocol;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A tenant's federated-identity trust anchor (Phase 4, P1a — ADR-0016). One row per tenant.
 *
 * `idp_certificates` is `encrypted:array` — an INTEGRITY claim, not a confidentiality one. A signing
 * certificate is public by construction, but anything that can silently rewrite this column can make the
 * application trust assertions minted by a key of the attacker's choosing, which is a total authentication
 * bypass for that tenant. Laravel's cast is authenticated, so tampering fails loudly.
 *
 * @property string $id
 * @property string $tenant_id
 * @property SsoProtocol $protocol
 * @property SsoConnectionStatus $status
 * @property string $idp_entity_id
 * @property string $idp_sso_url
 * @property list<string> $idp_certificates
 * @property string $idp_certificates_fingerprint
 * @property ?string $idp_metadata_sha256
 * @property ?Carbon $idp_metadata_imported_at
 * @property string $name_id_format
 * @property array<string, string> $attribute_map
 * @property bool $jit_provisioning_enabled
 * @property string $default_role_name
 * @property ?Carbon $last_login_at
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SsoConnection extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'protocol',
        'status',
        'idp_entity_id',
        'idp_sso_url',
        'idp_certificates',
        'idp_certificates_fingerprint',
        'idp_metadata_sha256',
        'idp_metadata_imported_at',
        'name_id_format',
        'attribute_map',
        'jit_provisioning_enabled',
        'default_role_name',
        'created_by',
    ];

    /**
     * The trust anchor never appears in the array/JSON form of the model — not in an Inertia prop, not in a
     * log, not in a default resource. The settings screen shows {@see certificateFingerprintShort()} and the
     * parsed subject/expiry instead, which is everything an admin needs to confirm the right key is loaded
     * without shipping the key itself through a view layer.
     *
     * @var list<string>
     */
    protected $hidden = ['idp_certificates'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'protocol' => SsoProtocol::class,
            'status' => SsoConnectionStatus::class,
            'idp_certificates' => 'encrypted:array',
            'idp_metadata_imported_at' => 'datetime',
            'attribute_map' => 'array',
            'jit_provisioning_enabled' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** @return HasMany<SsoAuthRequest, $this> */
    public function authRequests(): HasMany
    {
        return $this->hasMany(SsoAuthRequest::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the protocol endpoints may serve for this connection.
     *
     * Deliberately does NOT consult the entitlement — that is a per-request question about the tenant's plan
     * and belongs to `SsoGate`, which composes the two. Keeping them apart means this method stays a pure
     * statement about the row and can be asserted in isolation.
     */
    public function servesProtocol(): bool
    {
        return $this->status->servesProtocol() && $this->idp_certificates !== [];
    }

    /**
     * A short, safe display of the trust anchor's identity — the first 12 hex characters of the SHA-256 over
     * the sorted certificate set. Enough for an admin to compare against what their IdP shows; useless to
     * anyone trying to reconstruct the key.
     */
    public function certificateFingerprintShort(): string
    {
        return mb_substr($this->idp_certificates_fingerprint, 0, 12);
    }
}
