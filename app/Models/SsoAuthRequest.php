<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SsoAuthIntent;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Services\Sso\SsoAuthRequestService;
use App\Services\Sso\SsoStepUpService;
use Database\Factories\SsoAuthRequestFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The SP's memory of an AuthnRequest it minted (Phase 4, P1a — ADR-0016). The ACS's `InResponseTo` check is
 * a lookup against this table, and {@see $consumed_at} is what makes a request single-use.
 *
 * ⚠️ NOTHING HERE MAY BE CONSUMED BY READING THE MODEL AND THEN SAVING IT. The consume step must be a
 * conditional UPDATE whose affected-row count is the check — a read-then-write leaves a window in which two
 * concurrent replays both see `consumed_at IS NULL` and both proceed. See `SsoAcsController`.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $sso_connection_id
 * @property string $request_id
 * @property SsoAuthIntent $intent
 * @property ?string $user_id
 * @property ?string $return_to
 * @property bool $force_authn
 * @property Carbon $issued_at
 * @property Carbon $expires_at
 * @property ?Carbon $consumed_at
 * @property ?Carbon $verified_at
 * @property ?Carbon $completed_at
 * @property ?string $ip_address
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 */
class SsoAuthRequest extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<SsoAuthRequestFactory> */
    use HasFactory;

    use HasUuidv7;

    /**
     * ⚠️ `consumed_at`, `verified_at` AND `completed_at` ARE ALL DELIBERATELY ABSENT. Single-use is
     * established by the conditional UPDATEs in {@see SsoAuthRequestService::consume()} and
     * {@see SsoStepUpService::redeem()}, whose affected-row counts are the check, and leaving the columns
     * unfillable is what stops a well-meaning `fill(['consumed_at' => now()])` from quietly reintroducing
     * the read-then-write race the class docblock above forbids.
     *
     * @var list<string>
     */
    protected $fillable = [
        'tenant_id',
        'sso_connection_id',
        'request_id',
        'intent',
        'user_id',
        'return_to',
        'force_authn',
        'issued_at',
        'expires_at',
        'ip_address',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'intent' => SsoAuthIntent::class,
            'force_authn' => 'boolean',
            'issued_at' => 'datetime',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'verified_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<SsoConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(SsoConnection::class, 'sso_connection_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Mint a request id valid as an `xs:ID`.
     *
     * The leading underscore is required, not cosmetic: `xs:ID` derives from `xs:NCName`, which may not begin
     * with a digit, and a bare hex string does so half the time. Some IdPs reject such a request outright;
     * worse, others accept it and echo back a mangled `InResponseTo` that then fails to match.
     */
    public static function mintRequestId(): string
    {
        return '_'.bin2hex(random_bytes(16));
    }

    /**
     * Whether a string is shaped like an id {@see mintRequestId()} could have produced.
     *
     * ⚠️ NOT AN EXISTENCE CHECK, AND NEVER A SUBSTITUTE FOR ONE. Its only caller is the failure recorder,
     * which wants to store the `InResponseTo` an UNVALIDATED document claimed so an admin can correlate a
     * panel row with a log line. That value is attacker-controlled text on its way into a `char(33)`
     * column: a longer one makes the INSERT throw, the recorder swallow it, and the whole panel go quiet
     * for as long as somebody keeps sending them. Storing it only when it matches the shape we mint turns
     * that from a suppression primitive into a null.
     *
     * The lesson is `SsoIdentityResolver`'s, one layer out: a value being authentic is not the same as it
     * being well-formed, and here it is not even authentic.
     */
    public static function isMintedShape(string $candidate): bool
    {
        return preg_match('/^_[0-9a-f]{32}$/', $candidate) === 1;
    }

    /**
     * Whether this request may still be consumed, ignoring single-use (which only the atomic UPDATE can
     * answer). Expiry is evaluated WITHOUT clock skew: the skew allowance exists for disagreement between
     * the IdP's clock and ours over timestamps THE IDP wrote, whereas `expires_at` is a value this host
     * wrote and reads back — there is no second clock to disagree with.
     */
    public function isLive(?Carbon $now = null): bool
    {
        return $this->consumed_at === null && $this->expires_at->isAfter($now ?? Carbon::now());
    }
}
