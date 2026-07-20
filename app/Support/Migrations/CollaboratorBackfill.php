<?php

declare(strict_types=1);

namespace App\Support\Migrations;

use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

/**
 * Copies `form_collaborators` into `resource_grants` (Increment G10a) and proves the copy landed before
 * the next migration drops the source table.
 *
 * Extracted from the migration so the SQL text, the comparison operator, and the guard messages are
 * assertable with NO database. The alternative — a `RefreshDatabase` Pest test — is unrunnable here:
 * the privileged connection commits OUTSIDE RefreshDatabase's transaction (recorded verbatim at
 * tests/Feature/Forms/FieldLibraryRlsTest.php:20), so recreating `form_collaborators` mid-suite is
 * uncommitted DDL the privileged session cannot see, and a failed assertion would strand the table for
 * every sibling test. The end-to-end proof is a dedicated CI job that migrates a seeded database across
 * the boundary; see the G10a plan's test section.
 *
 * ── Why this runs on `pgsql_privileged` ──────────────────────────────────────────────────────────────
 * Migrations run on the default `pgsql` connection as `meridian_app` — NOSUPERUSER / NOBYPASSRLS
 * (asserted by FormVersionRlsTest) — with NO `app.current_tenant_id` GUC set. Under the strict RLS shape
 * `tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid` therefore evaluates to
 * `tenant_id = NULL`, which matches ZERO rows, and FORCE ROW LEVEL SECURITY binds even the table owner.
 * A naive `INSERT … SELECT` on the app connection would read nothing, write nothing and throw nothing —
 * and the drop migration would then destroy the only remaining copy of every editor's and reviewer's
 * access. A total, silent authorization wipe on a green migration log. The copy is also inherently
 * cross-tenant (each row carries its own `tenant_id`), which no single-tenant GUC could authorize.
 */
final class CollaboratorBackfill
{
    /**
     * Reusing `form_collaborators.id` as the grant PK buys three things: idempotency is free (a re-run
     * conflicts on the PK), the ids stay UUIDv7 so `orderByDesc('id')` still means "most recently
     * granted" (minting `gen_random_uuid()` v4 ids would destroy that ordering permanently), and
     * reconciliation between the two tables is a single join.
     *
     * `capacity` needs no translation — ResourceCapacity's backing values are byte-identical to the
     * retired FormCollaboratorCapacity. `added_by` becomes `granted_by`; `scopeable_type` is the literal
     * morph alias, which the CHECK constraint validates.
     */
    public const COPY_SQL = <<<'SQL'
        INSERT INTO resource_grants
            (id, tenant_id, scopeable_type, scopeable_id, user_id, capacity,
             includes_descendants, granted_by, created_at, updated_at)
        SELECT fc.id, fc.tenant_id, 'form', fc.form_id, fc.user_id, fc.capacity,
               false, fc.added_by, fc.created_at, fc.updated_at
        FROM form_collaborators fc
        ON CONFLICT (id) DO NOTHING
        SQL;

    /** The inverse copy, for a local `migrate:refresh`. Lossy once node grants exist — see the migration. */
    public const RESTORE_SQL = <<<'SQL'
        INSERT INTO form_collaborators
            (id, tenant_id, form_id, user_id, capacity, added_by, created_at, updated_at)
        SELECT g.id, g.tenant_id, g.scopeable_id, g.user_id, g.capacity, g.granted_by, g.created_at, g.updated_at
        FROM resource_grants g
        WHERE g.scopeable_type = 'form'
        ON CONFLICT (id) DO NOTHING
        SQL;

    public function __invoke(ConnectionInterface $privileged): void
    {
        $this->assertReachable($privileged);

        $expected = (int) $privileged->table('form_collaborators')->count();

        $privileged->statement(self::COPY_SQL);

        $copied = (int) $privileged->table('resource_grants')->where('scopeable_type', 'form')->count();
        self::assertCopyComplete($expected, $copied);

        self::assertNoCrossTenant($this->countCrossTenantGrants($privileged));
    }

    /**
     * Turn a missing/misconfigured privileged connection into an actionable error BEFORE the preceding
     * migrations' DDL is stranded half-applied. `phpunit.xml` sets DB_CONNECTION but not the privileged
     * credentials, so this is a genuinely reachable misconfiguration, not a theoretical one.
     */
    private function assertReachable(ConnectionInterface $privileged): void
    {
        try {
            $privileged->select('select 1');
        } catch (Throwable $e) {
            throw new RuntimeException(
                'G10a backfill requires the pgsql_privileged connection (DB_PRIVILEGED_USERNAME / '
                .'DB_PRIVILEGED_PASSWORD). The copy cannot run on the app connection: under FORCE RLS '
                .'with no tenant GUC it would read zero rows and silently wipe every access grant.',
                0,
                $e
            );
        }
    }

    /**
     * `<` rather than `!=` on purpose: a re-run, or a grant created between the copy and the count, must
     * still pass. And `0 < 0` is false, so a `migrate:fresh` (empty source table) passes cleanly.
     */
    public static function assertCopyComplete(int $expected, int $copied): void
    {
        if ($copied < $expected) {
            throw new RuntimeException(
                "G10a backfill under-copied: {$expected} form_collaborators rows, {$copied} form grants. "
                .'Refusing to continue — DO NOT run the drop migration.'
            );
        }
    }

    /**
     * The privileged connection BYPASSED `resourceGrantGuard`'s WITH CHECK, so re-assert its invariant by
     * hand. This is not paranoia: `form_collaborators.form_id` is a SINGLE-column FK with `tenant_id` as
     * an independent column, so a legacy row whose form belongs to another tenant is structurally legal
     * there — and this copy is the one path into `resource_grants` that cannot reject it.
     */
    public static function assertNoCrossTenant(int $crossTenant): void
    {
        if ($crossTenant > 0) {
            throw new RuntimeException(
                "G10a backfill produced {$crossTenant} grant(s) pointing at a missing or cross-tenant form. "
                .'Refusing to continue — DO NOT run the drop migration.'
            );
        }
    }

    private function countCrossTenantGrants(ConnectionInterface $privileged): int
    {
        return (int) $privileged->table('resource_grants as g')
            ->leftJoin('forms as f', function ($join): void {
                $join->on('f.id', '=', 'g.scopeable_id')->on('f.tenant_id', '=', 'g.tenant_id');
            })
            ->where('g.scopeable_type', 'form')
            ->whereNull('f.id')
            ->count();
    }
}
