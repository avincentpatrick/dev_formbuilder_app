<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AuditEvent;
use App\Models\Audit;
use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Factories\Factory;
use Ramsey\Uuid\Uuid;

/**
 * @extends Factory<Audit>
 *
 * `tenant_id` auto-fills from the active TenantContext (or set a state for the super-admin platform-row case).
 * Production audit rows are written via {@see AuditLogger}, never a factory — this exists
 * for read-API and RLS tests. `Factory::makeInstance` wraps construction in `Model::unguarded`.
 */
class AuditFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'auditable_type' => 'form',
            'auditable_id' => Uuid::uuid7()->toString(),
            'event' => AuditEvent::Updated,
            'old_values' => null,
            'new_values' => ['status' => 'active'],
            'redacted_fields' => null,
            'is_system_action' => false,
        ];
    }

    public function event(AuditEvent $event): static
    {
        return $this->state(fn (): array => ['event' => $event]);
    }

    /** A platform action with an explicit tenant + acting super-admin (the suspend/reactivate shape). */
    public function forTenant(string $tenantId): static
    {
        return $this->state(fn (): array => ['tenant_id' => $tenantId, 'auditable_type' => 'tenant', 'auditable_id' => $tenantId]);
    }
}
