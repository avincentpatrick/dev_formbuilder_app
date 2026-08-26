<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SsoVerifiedDomain;
use App\Services\Sso\SsoDomainService;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SsoVerifiedDomain>
 *
 * `tenant_id` is filled by `BelongsToTenant`'s creating hook from the ambient context, so a test must be
 * inside `enterTenant()` — {@see SsoConnectionFactory}'s situation exactly. Under strict FORCE RLS,
 * forgetting that does not error: the INSERT matches no policy and writes zero rows.
 *
 * ⚠️ **THE DEFAULT IS PENDING, AND `verified()` IS AN EXPLICIT STATE — THAT ASYMMETRY IS DELIBERATE.**
 * A factory whose default was verified would let a case obtain the fact this whole control exists to make a
 * workspace earn, by typing nothing. Every test that grants an assertion authority over a domain has to say
 * so out loud, which is what keeps the refusal cases honest about what they are refusing.
 *
 * ⚠️ **AND `verified()` IS THE ONLY WAY IN THIS REPOSITORY TO SET `verified_at` WITHOUT A DNS LOOKUP.**
 * {@see SsoDomainService} writes it through `forceFill` and the model's `$fillable` omits it, so a test that
 * wanted to skip the round trip had exactly two options: a fake resolver, or this. The fake is the right
 * tool for testing the lifecycle and this is the right tool for the forty-odd cases whose subject is
 * something else entirely.
 *
 * ⚠️ **DOMAINS ARE FIXED, NEVER `fake()->domainName()`.** M9's post-mortem records a faker-generated fixture
 * reddening a test on a dice roll, where re-running would have hidden it forever. The domain is the exact
 * thing under comparison here, so a random one would make a case that passes or fails by luck.
 */
final class SsoVerifiedDomainFactory extends Factory
{
    protected $model = SsoVerifiedDomain::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'domain' => 'acme.test',
            // Not random: nothing looks a row up by token, and a deterministic fixture is one less thing
            // that can differ between two runs of the same case.
            'verification_token' => str_repeat('a', 64),
            'token_issued_at' => now(),
            'verified_at' => null,
            'verification_checked_at' => null,
            'verification_failure_reason' => null,
            'created_by' => null,
        ];
    }

    /** The state that actually authorises an assertion. Say it out loud — see the class docblock. */
    public function verified(): self
    {
        return $this->state(fn (): array => [
            'verified_at' => now(),
            'verification_checked_at' => now(),
            'verification_failure_reason' => null,
        ]);
    }

    public function forDomain(string $domain): self
    {
        return $this->state(fn (): array => [
            'domain' => $domain,
            // Distinct per domain so two rows in one tenant cannot collide on a token, which would otherwise
            // make the fixture depend on there only ever being one.
            'verification_token' => substr(hash('sha256', $domain), 0, 64),
        ]);
    }
}
