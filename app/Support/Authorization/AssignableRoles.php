<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Support\Audit\AuditableTypes;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Str;

/**
 * The roles a tenant administrator may HAND OUT — the seeded catalog minus `owner`.
 *
 * ── WHY THIS EXISTS, WHICH IS A DRIFT STORY ──────────────────────────────────────────────────────────
 * The same four roles were being derived four different ways before P1a, and a fifth was about to be
 * written:
 *
 *   1. `MemberController::ASSIGNABLE_ROLES` — a private const of hand-written value/label pairs.
 *   2. `MemberController::invite()` — a hand-written `Rule::in([...])` of the same four values.
 *   3. `MemberController::changeRole()` — `array_column(self::ASSIGNABLE_ROLES, 'value')`, a third spelling.
 *   4. `create_sso_connections_table` — `array_filter(RolePermissionSeeder::ROLES, fn ($r) => $r !== 'owner')`,
 *      compiled into the `sso_connections_default_role_check` CHECK constraint.
 *
 * Only (4) is derived from the catalog, and it is the one that is enforced by the DATABASE. A picker that
 * drifts from it does not fail at validation, it fails as a `SQLSTATE 23514` five hundred — so the picker
 * and the constraint have to come from one expression, and this is that expression.
 *
 * ⚠️ {@see values()} MUST stay the same expression the migration compiles into the CHECK. `AssignableRolesTest`
 * compares this class against `pg_get_constraintdef('sso_connections_default_role_check')` — the two are
 * checked against each other in CI rather than merely written to match.
 *
 * ── WHY `owner` IS EXCLUDED ──────────────────────────────────────────────────────────────────────────
 * `docs/multi-tenancy-rbac-design.md` §5/§7: Owner is established ONLY by ownership transfer. It is not
 * invitable, and — the reason this class matters for SSO — an IdP attribute must never be a path to it.
 * A tenant that could set `default_role_name = 'owner'` would hand every JIT-provisioned subject the
 * workspace.
 *
 * Deliberately NOT a PHP enum, for the same reason `RolePermissionSeeder::ROLES` is not one: §3's design
 * note keeps the role catalog as plain data so the seeder stays the single source of truth.
 */
final class AssignableRoles
{
    /**
     * Roles a tenant administrator may never assign, by any mechanism.
     *
     * @var list<string>
     */
    public const array EXCLUDED = ['owner'];

    /**
     * The assignable role names, in catalog order.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_values(array_filter(
            RolePermissionSeeder::ROLES,
            static fn (string $role): bool => ! in_array($role, self::EXCLUDED, true),
        ));
    }

    /**
     * Human-readable, for a picker and for audit-log rendering.
     *
     * Fails OPEN — the {@see AuditableTypes::label()} posture. A role added to the seeder
     * and not to this map renders un-prettified rather than vanishing from a picker while the database CHECK
     * still accepts it, which is the failure that would be silent.
     */
    public static function label(string $role): string
    {
        return match ($role) {
            'admin' => 'Admin',
            'form_editor' => 'Form Editor',
            'reviewer' => 'Reviewer',
            'viewer' => 'Viewer',
            default => Str::of($role)->replace('_', ' ')->title()->toString(),
        };
    }

    /**
     * The picker catalog, in catalog order — least to most privileged is NOT the order, and that is
     * deliberate: the members roster has shipped `admin` first since B2b and reordering it here would
     * silently change which option a keyboard user lands on first.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (string $role): array => ['value' => $role, 'label' => self::label($role)],
            self::values(),
        );
    }
}
