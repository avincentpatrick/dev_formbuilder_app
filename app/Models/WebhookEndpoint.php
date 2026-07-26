<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DomainEventType;
use App\Enums\WebhookEndpointStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A tenant-configured webhook delivery target (data-dictionary §14, webhook-integration-design.md, H13a).
 * Mutable tenant-owned record → the plain `strict` RLS shape. `form_id` NULL = a tenant-wide endpoint
 * subscribing across all forms; non-null = scoped to one form.
 *
 * `secret` is `encrypted` at rest (the repo's first app-owned `encrypted` cast) and never returned in full
 * by the API after creation — {@see maskedSecret()} exposes only a suffix. `event_types` is a list of
 * {@see DomainEventType} string values the endpoint subscribes to.
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $form_id
 * @property string $name
 * @property string $url
 * @property string $secret
 * @property list<string> $event_types
 * @property WebhookEndpointStatus $status
 * @property ?string $disabled_reason
 * @property int $consecutive_failure_count
 * @property ?Carbon $last_success_at
 * @property ?Carbon $last_failure_at
 * @property string $signing_algorithm
 * @property ?string $created_by
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class WebhookEndpoint extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<WebhookEndpointFactory> */
    use HasFactory;

    use HasUuidv7;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'form_id',
        'name',
        'url',
        'secret',
        'event_types',
        'status',
        'disabled_reason',
        'signing_algorithm',
        'created_by',
    ];

    /**
     * `secret` is never in the array/JSON form of the model (it must not leak into logs, Inertia props, or a
     * default resource) — the API returns the plaintext once at creation from the raw value and a masked
     * suffix thereafter, via {@see maskedSecret()}.
     *
     * @var list<string>
     */
    protected $hidden = ['secret'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'event_types' => 'array',
            'status' => WebhookEndpointStatus::class,
            'consecutive_failure_count' => 'integer',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
        ];
    }

    /** A never-full display of the signing secret: an ellipsis + the last four characters. */
    public function maskedSecret(): string
    {
        return '…'.mb_substr($this->secret, -4);
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
