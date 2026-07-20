<?php

declare(strict_types=1);

namespace App\Services\Authorization;

use App\Enums\ResourceCapacity;
use App\Enums\TenantUserStatus;
use App\Exceptions\Authorization\GrantException;
use App\Models\Form;
use App\Models\ResourceGrant;
use App\Models\ScopeNode;
use App\Models\TenantUser;
use App\Models\User;
use App\Policies\ResourceGrantPolicy;
use App\Services\Forms\FormService;
use Illuminate\Support\Facades\DB;

/**
 * The write surface for `resource_grants` (Increment G10b) — the thing that finally makes the Reviewer
 * role assignable. Until this shipped, the only production writer of a grant was
 * {@see FormService::create}, which always grants Editor to the creator, so no
 * administrator could scope a reviewer to anything.
 *
 * Authorization for these writes lives in {@see ResourceGrantPolicy} (the escalation rules:
 * no self-grant, and the actor must hold the `.any` counterpart of what they hand out). What lives HERE
 * is the set of guards that are about the write being COHERENT rather than about who may make it:
 * a same-tenant target, an active recipient, and a capacity change that replaces rather than accumulates.
 *
 * {@see FormService::create} deliberately does NOT route through this service. The creator grant is a
 * system act, not an administrative one — it is exempt from the no-self-grant rule by definition, and
 * subjecting it to the active-membership guard would break the ~20 test files that create forms for users
 * with no `tenant_users` row.
 */
final class ResourceGrantService
{
    public function __construct(private readonly ResourceGrantResolver $grants) {}

    /**
     * Grant `$recipient` a capacity on `$target`, replacing any capacity they already hold there.
     *
     * The replace-not-add behaviour is structural, not a convenience: the `resource_grants` unique key is
     * `(tenant_id, scopeable_type, scopeable_id, user_id)` and deliberately EXCLUDES `capacity`, so a
     * demotion editor→reviewer cannot leave the old edit rights standing beside the new review rights.
     */
    public function grant(
        User $actor,
        User $recipient,
        Form|ScopeNode $target,
        ResourceCapacity $capacity,
        bool $includesDescendants = false,
    ): ResourceGrant {
        if ($includesDescendants && $target instanceof Form) {
            // A form has no descendants. The resource_grants_descendants_check CHECK is the backstop; this
            // is the clean refusal in front of it.
            throw GrantException::descendantsNotApplicable();
        }

        return DB::transaction(function () use ($actor, $recipient, $target, $capacity, $includesDescendants): ResourceGrant {
            // Re-resolve the target through its TENANT-SCOPED model, locked. Two jobs: a foreign target is
            // invisible so `associate()` can never carry a cross-tenant morph pair (the exact hole
            // `$fillable` excludes those columns for, and the RLS guard's last line of defence), and the
            // lock serializes concurrent grants on the same target so the unique key cannot 23505-race.
            $target = $target::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();

            // Likewise through the tenant-scoped User: a foreign id yields not-found rather than leaking
            // that the account exists.
            $recipient = User::query()->whereKey($recipient->getKey())->firstOrFail();

            $this->assertActiveMember($recipient);

            $existing = ResourceGrant::query()
                ->where('scopeable_type', $target->getMorphClass())
                ->where('scopeable_id', $target->getKey())
                ->where('user_id', $recipient->getKey())
                ->first();

            if ($existing !== null) {
                $existing->fill([
                    'capacity' => $capacity,
                    'includes_descendants' => $includesDescendants,
                ]);
                $existing->granted_by = $actor->getKey();
                $existing->save();

                $grant = $existing;
            } else {
                // Never updateOrCreate(): the morph pair is deliberately unfillable, so it must go through
                // associate() with a real, tenant-resolved instance.
                $grant = new ResourceGrant([
                    'user_id' => $recipient->getKey(),
                    'capacity' => $capacity,
                    'includes_descendants' => $includesDescendants,
                ]);
                $grant->scopeable()->associate($target);
                $grant->granted_by = $actor->getKey();
                $grant->save();
            }

            // TODO(audits, Phase 1): record the grant. Blocked — there is no `audits` table until Phase 1.
            $this->grants->forget((string) $recipient->getKey());

            return $grant;
        });
    }

    /** Revoke a grant. De-escalation, so it carries none of grant()'s coherence guards. */
    public function revoke(ResourceGrant $grant): void
    {
        $userId = (string) $grant->user_id;

        DB::transaction(static fn () => $grant->delete());

        // TODO(audits, Phase 1): record the revocation.
        $this->grants->forget($userId);
    }

    /**
     * A grant only resolves while its holder is an ACTIVE member — the resolver loads grants behind a
     * `tenant_users.status = 'active'` EXISTS, which is the standing substitute for the composite FK G10a
     * dropped.
     *
     * So a grant to a non-member is not merely useless, it is actively misleading: the row is written, the
     * UI lists it, and it confers nothing. Refusing here is what keeps "what the table says" and "what the
     * resolver does" the same statement.
     */
    private function assertActiveMember(User $recipient): void
    {
        $isActive = TenantUser::query()
            ->where('user_id', $recipient->getKey())
            ->where('status', TenantUserStatus::Active->value)
            ->exists();

        if (! $isActive) {
            throw GrantException::recipientNotActive();
        }
    }
}
