<?php

declare(strict_types=1);

namespace App\Support\Migrations;

use App\Enums\FormVersionStatus;
use App\Support\Tenancy\TenantIsolation;
use Illuminate\Support\Facades\DB;

/**
 * The published-version immutability guard (Increment H25, ADR-0013, Risk R5).
 *
 * This is the schema's FIRST database trigger, and the boundary that justifies it is narrow:
 * ADR-0002 made Row-Level Security this schema's one DB-level-guard idiom, and
 * `docs/form-versioning-schema-migration.md` §2 resolved the published-immutability guard onto RLS
 * rather than a trigger. That resolution stands — for the three CONTENT CHILD tables, where it is
 * built ({@see TenantIsolation::draftChildGuardSql()}). It cannot serve the
 * PARENT ROW, because a policy's USING clause sees only the OLD row and WITH CHECK only the NEW one,
 * and NO clause can compare them. A per-column immutability rule *is* an OLD-vs-NEW comparison. A
 * CHECK constraint cannot see OLD either. A row trigger is the only tool in PostgreSQL that can
 * express it, which is why R5's own mitigation text says "trigger or check constraint".
 *
 * Mirrors {@see TenantIsolation}'s split: the `*Sql()` methods are pure
 * generators (no database, unit-testable in the fast suite), and `apply()`/`drop()` execute them
 * inside a migration. The table name is deliberately NOT parameterised — TenantIsolation is generic
 * because many tables share a policy shape; this is one table with one lifecycle, and parameterising
 * it would invite reuse where the semantics do not transfer.
 *
 * ── DENY BY DEFAULT, WHICH IS THE WHOLE DESIGN ────────────────────────────────────────────────────
 * The guard does NOT carry a list of frozen columns. A hand-written frozen list is fail-OPEN for the
 * future: the day someone adds a column to `form_versions` it is silently mutable on a published row,
 * and nothing in CI notices (`scripts/migration-lint.php` early-returns on alter-only migrations, so
 * a migration that adds a column is never even read by it). Instead the trigger diffs the WHOLE ROW —
 * `to_jsonb(OLD)` against `to_jsonb(NEW)`, per key — exempting exactly the four names in
 * {@see MUTABLE_COLUMNS}. Every other column, including every column a future migration adds, is
 * frozen the moment it exists. The corollary is deliberate and is the intended workflow: a backfill
 * that must touch a frozen column has to disable this trigger inside its own migration (see the
 * break-glass note on the migration), which is loud, reviewable and in version control.
 *
 * Two properties of the jsonb projection are load-bearing rather than incidental:
 *
 *   1. It is NULL-SAFE BY CONSTRUCTION. A SQL NULL column becomes the jsonb value `null`, never SQL
 *      NULL, so the comparison always returns a definite verdict. Seven of the frozen columns are
 *      nullable — including `created_at`, because `timestampsTz()` emits both timestamps nullable —
 *      and the suite routinely produces published rows with a NULL `checksum`. A guard written with
 *      `<>` would compare NULL to a value, yield NULL, and let the write through. Nothing in this
 *      class may use a bare `<>`; the unit test asserts it appears nowhere.
 *   2. It compares `::text`, NOT jsonb values. jsonb equality is numeric-aware, so
 *      `'1'::jsonb = '1.0'::jsonb` is TRUE — a scale-only rewrite of `schema_snapshot` would pass a
 *      jsonb `=` guard while silently invalidating `checksum`, which is SHA-256 over the canonical
 *      TEXT and is the offline-sync cache-busting key. jsonb's text output is canonical (key order
 *      normalised, whitespace normalised, duplicates dropped at parse time), so `::text` keeps the
 *      no-false-positive property — a snapshot re-encoded by Laravel's `array` cast in a different
 *      key order still reads as unchanged — while catching the scale case.
 *
 * ── THREAT MODEL: AN INTEGRITY GUARD, NOT A SECURITY BOUNDARY ─────────────────────────────────────
 * `meridian_app` OWNS `form_versions`, and a table owner can `ALTER TABLE ... DISABLE TRIGGER` or
 * `DROP TRIGGER`; a superuser can additionally `SET session_replication_role = 'replica'`. That is the
 * crucial asymmetry with RLS, where `FORCE ROW LEVEL SECURITY` was chosen PRECISELY because it binds
 * the owner (ADR-0002 §D2). So this guard stops application bugs and raw queries — the R5 threat, which
 * is worded "accidentally mutates" — and does not pretend to stop a holder of the app credentials.
 *
 * What it DOES add over RLS: it binds `pgsql_privileged` (a real superuser bypasses RLS but never a
 * trigger), and it fires on referential actions, which bypass RLS entirely.
 */
final class PublishedVersionGuard
{
    public const TABLE = 'form_versions';

    public const FUNCTION = 'form_versions_published_immutable_fn';

    public const TRIGGER = 'form_versions_published_immutable_trg';

    public const STATUS_CHECK = 'form_versions_status_chk';

    /**
     * The ONLY columns a non-draft row may change. Everything else — including every column added by a
     * future migration — is frozen by {@see functionSql()}'s whole-row diff.
     *
     * `updated_at` must be here: every Eloquent `save()` bumps it, so freezing it would block the one
     * legitimate published-row write (`PublishService`'s supersede). The other three are not "free" —
     * each is re-guarded by its own rule in the function body, because their lawful movement is a
     * TRANSITION rather than an ordinary write.
     *
     * @var list<string>
     */
    private const MUTABLE_COLUMNS = ['status', 'superseded_at', 'updated_at', 'published_by'];

    /**
     * The version lifecycle, pinned in DDL. Mirrors {@see FormVersionStatus}.
     *
     * @var list<string>
     */
    private const STATUSES = ['draft', 'published', 'superseded'];

    /**
     * @return list<string>
     */
    public static function mutableColumns(): array
    {
        return self::MUTABLE_COLUMNS;
    }

    /**
     * @return list<string>
     */
    public static function statuses(): array
    {
        return self::STATUSES;
    }

    // ── Pure SQL generators (no database access — the unit-test surface) ─────────────────────────

    /**
     * The guard function.
     *
     * `CREATE OR REPLACE` is MANDATORY, not stylistic. `migrate:fresh` runs `db:wipe`, which drops
     * tables, views and types — never routines. `DROP TABLE ... CASCADE` takes the trigger with it; the
     * FUNCTION survives. A bare `CREATE FUNCTION` therefore dies on the SECOND `migrate:fresh` on any
     * database with `42723 function already exists` — and CI cannot catch it, because every CI job
     * provisions a brand-new database, so it would ship green and break only developers.
     *
     * The four rules are INDEPENDENT, not chained: a bare `updated_at` touch on a superseded row must
     * pass (nothing frozen changed, status unchanged, superseded_at unchanged, published_by unchanged),
     * or the guard is stricter than the invariant it encodes and brittle against ordinary maintenance.
     *
     * `restrict_violation` (SQLSTATE 23001) is unambiguous in this schema: PostgreSQL core never emits
     * it (a RESTRICT foreign key raises 23503), and nothing else in the codebase does — so a test or a
     * log filter keying on 23001 is exact. `SECURITY INVOKER` (the default) is correct because the body
     * reads no relation at all, only OLD and NEW; the `SET search_path` is ordinary hygiene so the
     * catalog functions it calls cannot be shadowed.
     */
    public static function functionSql(): string
    {
        $sql = <<<'SQL'
            CREATE OR REPLACE FUNCTION form_versions_published_immutable_fn()
            RETURNS trigger
            LANGUAGE plpgsql
            SET search_path = pg_catalog, pg_temp
            AS $fn$
            DECLARE
                frozen text;
            BEGIN
                -- There is deliberately not one apostrophe in this body. PDO scans a query for placeholders
                -- while tracking single quotes, and it does not understand dollar-quoting — so a lone
                -- apostrophe in a comment here would desynchronise that scanner. Keep prose apostrophe-free.
                --
                -- RULE 1 — deny by default. Every key of the row is compared; only the exempt names are
                -- skipped, so a column added by a future migration is frozen without anyone remembering.
                SELECT string_agg(o.key, ', ' ORDER BY o.key)
                  INTO frozen
                  FROM jsonb_each(to_jsonb(OLD)) AS o
                  JOIN jsonb_each(to_jsonb(NEW)) AS n ON n.key = o.key
                 WHERE o.key NOT IN (__MUTABLE_COLUMNS__)
                   AND n.value::text IS DISTINCT FROM o.value::text;

                IF frozen IS NOT NULL THEN
                    RAISE EXCEPTION
                        'form_versions: column(s) % are immutable once a version leaves draft (id=%, status=%)',
                        frozen, OLD.id, OLD.status
                        USING ERRCODE = 'restrict_violation',
                              HINT = 'Publish forward into a NEW version; a published or superseded version is history.';
                END IF;

                -- RULE 2 — the one lawful status move off a non-draft row. The case that matters most is a
                -- new status of draft: resurrecting a published version would re-open every
                -- form_sections / form_fields / form_field_validations row beneath it, because the
                -- draft-child RLS shape keys on the parent status being draft. There is deliberately no
                -- branch anywhere below that readmits it, and the unit test asserts that absence.
                IF NEW.status IS DISTINCT FROM OLD.status
                   AND NOT (OLD.status = 'published' AND NEW.status = 'superseded') THEN
                    RAISE EXCEPTION
                        'form_versions: status may only move published to superseded (id=%, attempted % to %)',
                        OLD.id, OLD.status, NEW.status
                        USING ERRCODE = 'restrict_violation',
                              HINT = 'superseded is terminal, and a published version can never return to draft.';
                END IF;

                -- RULE 3 — superseded_at rides that transition exactly once: writable ONLY by the statement
                -- performing the flip, and only out of NULL, so it can never be re-dated or cleared.
                IF NEW.superseded_at IS DISTINCT FROM OLD.superseded_at
                   AND NOT (OLD.status = 'published' AND NEW.status = 'superseded'
                            AND OLD.superseded_at IS NULL) THEN
                    RAISE EXCEPTION
                        'form_versions: superseded_at is writable only by the published to superseded transition (id=%, status=%)',
                        OLD.id, OLD.status
                        USING ERRCODE = 'restrict_violation';
                END IF;

                -- RULE 4 — published_by may only be CLEARED, never re-pointed. `published_by` is
                -- ON DELETE SET NULL against `users`, and PostgreSQL runs that referential action as an
                -- ordinary UPDATE through SPI: it bypasses RLS but NOT user triggers. Freezing the column
                -- outright would make user hard-deletion permanently impossible (and GDPR erasure with it),
                -- raising a form-version error from an unrelated tenant row on a statement about users.
                -- Losing the attribution when the person is erased is the whole intent of that FK;
                -- re-pointing a publish at somebody else is the falsification this rule refuses.
                IF NEW.published_by IS DISTINCT FROM OLD.published_by AND NEW.published_by IS NOT NULL THEN
                    RAISE EXCEPTION
                        'form_versions: published_by may only be cleared, never re-pointed (id=%, status=%)',
                        OLD.id, OLD.status
                        USING ERRCODE = 'restrict_violation';
                END IF;

                RETURN NEW;
            END;
            $fn$;
            SQL;

        return str_replace('__MUTABLE_COLUMNS__', self::quotedList(self::MUTABLE_COLUMNS), $sql);
    }

    /**
     * The trigger.
     *
     * The gate keys on `OLD.status`, NEVER on `published_at`: a draft row can legitimately carry a
     * `published_at` (the connector fan-out fixture does exactly that), and a `published_at IS NOT NULL`
     * gate would both break it and miss a frozen row that was never published.
     *
     * `IS DISTINCT FROM` rather than `<>`: a WHEN clause evaluating to NULL is treated as FALSE and the
     * trigger does not fire. `status` is NOT NULL today so the two are equivalent — but `<>` would fail
     * OPEN the day that changed.
     *
     * The predicate lives HERE and nowhere else. Repeating it inside the body invites drift, and the
     * drift direction where WHEN fires but the body early-returns is fail-open. Keeping it in the WHEN
     * clause also puts it in `pg_get_triggerdef()`, which is what the structural test pins.
     */
    public static function triggerSql(): string
    {
        return <<<'SQL'
            CREATE TRIGGER form_versions_published_immutable_trg
                BEFORE UPDATE ON form_versions
                FOR EACH ROW
                WHEN (OLD.status IS DISTINCT FROM 'draft')
                EXECUTE FUNCTION form_versions_published_immutable_fn()
            SQL;
    }

    /**
     * The guard's own fail-closed precondition.
     *
     * `form_versions.status` is a bare `varchar(20)` with a default and no CHECK, so an arbitrary string
     * is storable. That is pre-existing looseness — but the trigger TURNS IT INTO A TRAP, because it
     * treats "anything that is not 'draft'" as frozen: one typo'd status would freeze a row permanently
     * with no ordinary way back. Pinning the vocabulary in DDL is what keeps that door shut.
     */
    public static function statusCheckSql(): string
    {
        return str_replace('__STATUSES__', self::quotedList(self::STATUSES), <<<'SQL'
            ALTER TABLE form_versions
                ADD CONSTRAINT form_versions_status_chk
                CHECK (status IN (__STATUSES__))
            SQL);
    }

    /**
     * @return list<string>
     */
    public static function createSql(): array
    {
        return [self::statusCheckSql(), self::functionSql(), self::triggerSql()];
    }

    /**
     * Teardown, in dependency order: the trigger depends on the function, so dropping the function
     * first fails — and `DROP FUNCTION ... CASCADE` would silently take the trigger with it, which is a
     * bad habit to establish in the schema's first function.
     *
     * @return list<string>
     */
    public static function dropSql(): array
    {
        return [
            'DROP TRIGGER IF EXISTS '.self::TRIGGER.' ON '.self::TABLE,
            'DROP FUNCTION IF EXISTS '.self::FUNCTION.'()',
            'ALTER TABLE '.self::TABLE.' DROP CONSTRAINT IF EXISTS '.self::STATUS_CHECK,
        ];
    }

    // ── Execution (called from the migration) ────────────────────────────────────────────────────

    public static function apply(): void
    {
        self::execute(self::createSql());
    }

    public static function drop(): void
    {
        self::execute(self::dropSql());
    }

    /**
     * @param  list<string>  $statements
     */
    private static function execute(array $statements): void
    {
        foreach ($statements as $statement) {
            // One statement per call: DB::statement() prepares a single statement, and DB::unprepared()
            // would hand the whole batch to the driver with no such guarantee.
            DB::statement($statement);
        }
    }

    /**
     * @param  list<string>  $values
     */
    private static function quotedList(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));
    }
}
