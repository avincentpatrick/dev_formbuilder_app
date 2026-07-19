<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\FieldType;
use App\Models\FieldLibrary;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FieldLibrary>
 *
 * Like {@see FormTemplateFactory}, tenant_id is set EXPLICITLY (the model omits BelongsToTenant so nothing
 * auto-fills it). The default is a tenant-owned question pinned to the active TenantContext, satisfying the
 * strict INSERT policy. The `platform()` state produces a NULL-tenant row — forbidden from a tenant
 * connection by the strict write policy, so platform rows must be created on `DB::connection('pgsql_privileged')`
 * (the seeder / RLS-test path), not via this factory under a tenant connection.
 */
class FieldLibraryFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => TenantContext::currentTenantId(),
            'name' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'category' => fake()->randomElement(['Demographics', 'Contact', 'Consent']),
            'field_type' => FieldType::ShortText->value,
            'default_label' => fake()->sentence(3),
            'default_label_translations' => null,
            'default_hint' => null,
            'default_config' => [],
            'default_validations' => [],
            'is_active' => true,
            'usage_count' => 0,
        ];
    }

    /** A platform/system question (NULL tenant) — create it on the privileged connection. */
    public function platform(): static
    {
        return $this->state(fn (): array => ['tenant_id' => null]);
    }

    /** A specific tenant's own private question. */
    public function forTenant(string $tenantId): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenantId]);
    }
}
