<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\FormTemplate;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FormTemplate>
 *
 * Unlike the BelongsToTenant-backed factories, tenant_id is set EXPLICITLY here (the model omits the trait
 * so nothing auto-fills it). The default is a tenant-owned template pinned to the active TenantContext, so
 * a plain `FormTemplate::factory()->create()` inside an applyLocal() context satisfies the strict INSERT
 * policy. The `platform()` state produces a NULL-tenant row — but the strict write policy forbids a tenant
 * connection from inserting one, so platform rows must be created on `DB::connection('pgsql_privileged')`
 * (the seeder / CrossTenantIsolationTest path), not via this factory under a tenant connection.
 */
class FormTemplateFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantContext::currentTenantId(),
            'name' => fake()->sentence(3),
            'description' => fake()->optional()->sentence(),
            'category' => fake()->randomElement(['M&E / Research', 'Event Registration', 'Health Survey']),
            'schema_blueprint' => ['sections' => [], 'fields' => []],
            'is_public' => false,
            'usage_count' => 0,
        ];
    }

    /** A platform/system template (NULL tenant, public) — create it on the privileged connection. */
    public function platform(): static
    {
        return $this->state(fn (): array => ['tenant_id' => null, 'is_public' => true]);
    }

    /** A specific tenant's own private template. */
    public function forTenant(string $tenantId): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenantId, 'is_public' => false]);
    }
}
