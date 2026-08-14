<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One in-flight Google sign-in (Increment J3c2, ADR-0019 §D7).
 *
 * Its life is three writes on two hosts:
 *   1. MINT   — tenant host, unauthenticated. A row with a `state_id` and nothing else asserted.
 *   2. ATTACH — central host, after Google's answer verifies. Stamps `consumed_at`, the four `google_*`
 *      columns and a `handoff_hash`.
 *   3. REDEEM — tenant host again. Stamps `completed_at`; the session is created only here.
 *
 * ⚠️ `consumed_at`, `handoff_hash`, `handoff_expires_at`, `completed_at` AND EVERY `google_*` COLUMN ARE
 * DELIBERATELY ABSENT FROM `$fillable`. Single-use is established by conditional UPDATEs whose
 * affected-row count IS the check; leaving these unfillable is what stops a well-meaning
 * `fill(['completed_at' => now()])` from quietly reintroducing the read-then-write race those UPDATEs
 * exist to avoid. This is `SsoAuthRequest`'s rule and it is here for the same reason.
 *
 * ⚠️ THE `google_*` COLUMNS ARE ALSO POST-VALIDATION-ONLY, which is a disclosure rule rather than a
 * tidiness one. They are written only after Google's answer has verified, so nothing an anonymous caller
 * supplies can reach a tenant's database or an operator's screen through them.
 *
 * ⚠️ `implements TenantScoped` IS LOAD-BEARING AND ITS ABSENCE FAILED SILENTLY IN EXACTLY ONE DIRECTION.
 * {@see BelongsToTenant} adds its read scope unconditionally, but gates the `creating` auto-fill of
 * `tenant_id` on `$model instanceof TenantScoped` — so a model carrying the trait WITHOUT the interface
 * reads as though it were tenant-scoped and only its WRITES are broken. This class shipped that way for
 * one commit: every INSERT left `tenant_id` null and PostgreSQL refused it as an RLS violation (42501)
 * rather than as a null violation, which points the reader at the policy instead of at the model.
 * `tests/Unit/Models/TenantScopedContractTest.php` now asserts the pairing across `app/Models` so the next
 * one fails in milliseconds rather than inside an HTTP round trip.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $state_id
 * @property ?string $return_to A PATH, never a URL — sanitised in and re-validated out.
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property ?Carbon $consumed_at Stamped by the central callback. NULL = the state is unspent.
 * @property ?string $handoff_hash SHA-256 of the token that travels in the URL, never the token.
 * @property ?Carbon $handoff_expires_at
 * @property ?string $google_sub The stable identity (ADR-0019 §D2). The email is NOT the identity.
 * @property ?string $google_email
 * @property ?bool $google_email_verified This flow's signature check (§D3).
 * @property ?string $google_name
 * @property ?Carbon $completed_at Stamped by the tenant hop. Its presence makes the handoff single-use.
 * @property ?string $ip_address
 */
class GoogleAuthRequest extends Model implements TenantScoped
{
    use BelongsToTenant;
    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'state_id',
        'return_to',
        'issued_at',
        'expires_at',
        'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'handoff_expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'google_email_verified' => 'boolean',
        ];
    }

    /**
     * A fresh state id: 32 hex characters, 128 bits.
     *
     * `SsoAuthRequest::mintRequestId()`'s budget without its leading `_`, which exists there to satisfy
     * SAML's `xs:ID` (a value may not begin with a digit) and has no analogue in an OAuth `state`.
     */
    public static function mintStateId(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * A fresh handoff token, returned in PLAINTEXT to the caller exactly once.
     *
     * Only its SHA-256 is stored. The token travels in a URL path, so it lands in browser history, any
     * proxy log and any `Referer` — the `impersonation_tokens` precedent, for the same reason.
     */
    public static function mintHandoffToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public static function hashHandoffToken(string $plain): string
    {
        return hash('sha256', $plain);
    }
}
