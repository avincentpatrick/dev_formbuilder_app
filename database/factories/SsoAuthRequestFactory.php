<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\SsoAuthIntent;
use App\Models\SsoAuthRequest;
use App\Models\SsoConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SsoAuthRequest>
 *
 * `tenant_id` is filled by `BelongsToTenant`'s creating hook from the ambient context, so a test must be
 * inside `enterTenant()` — the {@see SsoConnectionFactory} situation exactly. Under the strict FORCE RLS on
 * this table, forgetting that does not error: the INSERT matches no policy and writes zero rows.
 *
 * ⚠️ `forConnection()` IS EFFECTIVELY MANDATORY. The composite FK `(tenant_id, sso_connection_id)` is what
 * confines a request to the tenant that minted it (ADR-0002 §D5 — a single-column FK's referential action
 * bypasses RLS), so the connection must be one that already exists in the SAME tenant context.
 */
final class SsoAuthRequestFactory extends Factory
{
    protected $model = SsoAuthRequest::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'sso_connection_id' => SsoConnection::factory(),
            'request_id' => SsoAuthRequest::mintRequestId(),
            'intent' => SsoAuthIntent::Login,
            // Null, and the CHECK constraint agrees: a login request carrying a subject would let a
            // consumed login assertion masquerade as a step-up.
            'user_id' => null,
            'return_to' => null,
            'force_authn' => false,
            'issued_at' => now(),
            'expires_at' => now()->addSeconds((int) config('saml.authn_request_ttl_seconds')),
            'ip_address' => '203.0.113.10',
        ];
    }

    public function forConnection(SsoConnection $connection): self
    {
        return $this->state(fn (): array => ['sso_connection_id' => (string) $connection->getKey()]);
    }

    /** A request whose window has closed — indistinguishable from "never existed" at the ACS, by design. */
    public function expired(): self
    {
        return $this->state(fn (): array => [
            'issued_at' => now()->subHour(),
            'expires_at' => now()->subMinute(),
        ]);
    }

    /**
     * Already used.
     *
     * Stamped AFTER creation with a direct UPDATE rather than through the attribute array, because
     * `consumed_at` is deliberately not fillable on the model — see its `$fillable` docblock. A factory
     * that needed the column opened up would be the first step toward the read-then-write race the whole
     * design exists to prevent.
     */
    public function consumed(): self
    {
        return $this->afterCreating(function (SsoAuthRequest $request): void {
            SsoAuthRequest::query()->whereKey($request->getKey())->update(['consumed_at' => now()]);
            $request->refresh();
        });
    }
}
