<?php

declare(strict_types=1);

namespace App\Support\Migrations;

use App\Services\Entitlements\EntitlementService;
use Database\Factories\LegacyOverrideFactory;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

/**
 * Stamps every then-existing tenant with a `legacy_overrides` row grandfathering the five ungated Phase-2
 * features (ADR-0008 §D5, H5c) — the merge-day backfill that fills
 * {@see EntitlementService::legacyOverrides()}. Run once, when H5c is deployed
 * to a live database that already has tenants; tenants created afterwards get no row and are born gated.
 *
 * Extracted from the migration so the SQL, the derived override map, and the count guard are assertable with
 * a database but WITHOUT the migration (a `migrate:fresh` test DB is empty at migration time). The pure parts
 * are unit-tested in tests/Unit/LegacyOverrideBackfillTest.php; the grandfather effect is proven behaviourally
 * in tests/Feature/Entitlements/LegacyOverrideTest.php.
 *
 * ── Why this runs on `pgsql_privileged` ──────────────────────────────────────────────────────────────
 * Migrations run on the default `pgsql` connection as `meridian_app` — NOSUPERUSER / NOBYPASSRLS — with NO
 * `app.current_tenant_id` GUC set. Under the strict RLS shape `tenant_id = NULLIF(current_setting(...), '')::uuid`
 * evaluates to `tenant_id = NULL`, matching ZERO rows, and FORCE ROW LEVEL SECURITY binds even the table
 * owner: a naive per-row INSERT on the app connection would write nothing and throw nothing. The backfill is
 * also inherently cross-tenant (each row carries its own `tenant_id` from the RLS-exempt `tenants` table),
 * which no single-tenant GUC could authorize. The privileged (BYPASSRLS) role is what lets one statement stamp
 * every tenant at once. (The {@see CollaboratorBackfill} precedent, G10a.)
 */
final class LegacyOverrideBackfill
{
    /**
     * The five ungated Phase-2 features grandfathered indefinitely (ADR-0008 §D5). The single source of truth
     * for the merge-day override map — the SQL below derives its JSON from this list so the two cannot drift.
     *
     * @var list<string>
     */
    public const array GRANDFATHERED_KEYS = [
        'xlsform_export',
        'offline_sync',
        'form_templates',
        'field_library',
        'api_access',
    ];

    /**
     * The merge-day override map — every grandfathered key set to `true`. The single builder shared by the
     * backfill's INSERT and the {@see LegacyOverrideFactory} default, so a test row and a
     * production row carry byte-identical flags.
     *
     * @return array<string, bool>
     */
    public static function overrideFlags(): array
    {
        return array_fill_keys(self::GRANDFATHERED_KEYS, true);
    }

    public function __invoke(ConnectionInterface $privileged): void
    {
        $this->assertReachable($privileged);

        $expected = (int) $privileged->table('tenants')->count();

        // Derive the override JSON from GRANDFATHERED_KEYS (no drift) and bind it; the identity `id` auto-fills
        // and every row carries its own tenant_id, so one statement grandfathers every existing tenant.
        $flags = json_encode(self::overrideFlags(), JSON_THROW_ON_ERROR);

        $privileged->statement(
            'INSERT INTO legacy_overrides (tenant_id, feature_flags, created_at, updated_at) '
            .'SELECT t.id, ?::jsonb, now(), now() FROM tenants t '
            .'ON CONFLICT (tenant_id) DO NOTHING',
            [$flags],
        );

        $stamped = (int) $privileged->table('legacy_overrides')->count();
        self::assertBackfillComplete($expected, $stamped);
    }

    /**
     * Turn a missing/misconfigured privileged connection into an actionable error BEFORE the DDL migration is
     * stranded. `phpunit.xml` sets DB_CONNECTION but not the privileged credentials, so this is a genuinely
     * reachable misconfiguration.
     */
    private function assertReachable(ConnectionInterface $privileged): void
    {
        try {
            $privileged->select('select 1');
        } catch (Throwable $e) {
            throw new RuntimeException(
                'H5c legacy-override backfill requires the pgsql_privileged connection (DB_PRIVILEGED_USERNAME / '
                .'DB_PRIVILEGED_PASSWORD). It cannot run on the app connection: under FORCE RLS with no tenant '
                .'GUC it would stamp zero tenants and every then-existing tenant would be gated on merge day.',
                0,
                $e
            );
        }
    }

    /**
     * `<` rather than `!=` on purpose: a re-run (ON CONFLICT DO NOTHING keeps `stamped == expected`) and a
     * tenant created between the INSERT and the count must both pass. `0 < 0` is false, so a `migrate:fresh`
     * (no tenants yet) passes cleanly — the merge-day work happens only against a live, populated database.
     */
    public static function assertBackfillComplete(int $expected, int $stamped): void
    {
        if ($stamped < $expected) {
            throw new RuntimeException(
                "H5c legacy-override backfill under-stamped: {$expected} tenants, {$stamped} override rows. "
                .'Refusing to continue — a gated tenant would regress on merge day.'
            );
        }
    }
}
