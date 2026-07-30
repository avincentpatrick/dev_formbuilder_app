<?php

declare(strict_types=1);

namespace App\Support\Migrations;

use App\Enums\FieldType;
use App\Enums\OcrFieldEligibility;
use App\Http\Resources\Api\V1\FormResource;
use App\Services\Forms\CapabilityFlags;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\PublishService;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

/**
 * Clears `forms.capability_flags.ocr_compatible` on every already-published form whose frozen structure the
 * fixed `docs/ocr-pipeline-design.md` §2 rule rejects (Increment H8) — the merge-day correction that makes
 * the fix RETROACTIVE rather than next-publish-only.
 *
 * Why retroactive at all: `CapabilityFlags` recomputes the flag only on publish, so without this every
 * already-published form containing `matrix`/`likert_matrix`/a repeat group would keep advertising
 * `ocr_compatible = true` until someone happened to republish it. That is not merely a future H18 gate
 * input — `capability_flags` is a SHIPPED public API field ({@see FormResource}),
 * so a stale `true` is a wrong API answer being served today.
 *
 * Extracted from the migration so the SQL and the guards are assertable WITHOUT the migration (a
 * `migrate:fresh` test database is empty at migration time) — the {@see LegacyOverrideBackfill} /
 * {@see CollaboratorBackfill} precedent. Pure parts are unit-tested in
 * tests/Unit/OcrCompatibilityBackfillTest.php; the EFFECT on real rows is proven in
 * tests/Feature/Forms/OcrCompatibilityBackfillEffectTest.php.
 *
 * ── Why it reads the snapshot and computes in PHP, rather than restating §2 in SQL ────────────────────
 * A set-based `UPDATE ... WHERE field_type NOT IN (...)` would be shorter and is the obvious temptation.
 * It is also exactly the drift H8 exists to prevent: the rule would then live in two places, and a 32nd
 * {@see FieldType} case would be classified by {@see OcrFieldEligibility}'s
 * `default`-less match and SILENTLY NOT by the SQL. So the SQL here does no classification at all — it
 * selects candidates and writes a constant — and every verdict comes from
 * {@see CapabilityFlags::forSnapshot()}, the same function the publish path calls. A unit test asserts the
 * SQL text contains no field-type value and no `is_repeatable`, so the temptation cannot be acted on
 * quietly.
 *
 * ── Why it reads `schema_snapshot` and not the live `form_fields` / `form_sections` rows ──────────────
 * Not because they can disagree — they cannot: those tables carry the `draft_child` RLS variant, whose
 * INSERT/UPDATE/DELETE policies require the parent version's status to be `draft`, so a published version's
 * content is frozen in Postgres itself. The snapshot wins because it is the same checksummed artefact
 * {@see PublishService} evaluates at publish time and the same one H18a will re-derive
 * from at OCR-request time. One set of bytes, read at all three moments.
 *
 * ── Why this runs on `pgsql_privileged` ──────────────────────────────────────────────────────────────
 * Migrations run on the default `pgsql` connection as `meridian_app` — NOSUPERUSER / NOBYPASSRLS — with NO
 * `app.current_tenant_id` GUC set. Under the strict RLS shape
 * `tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid` that evaluates to
 * `tenant_id = NULL`, which matches ZERO rows, and FORCE ROW LEVEL SECURITY binds even the table owner: a
 * naive run on the app connection would read nothing, write nothing and throw nothing. The correction is
 * also inherently cross-tenant, which no single-tenant GUC could authorize. {@see self::assertPrivileged()}
 * is what turns a misconfigured role into a red migration instead of a vacuous green.
 */
final class OcrCompatibilityBackfill
{
    /** Rows per keyset page. Small enough to keep each autocommit statement short, large enough to be one round trip per realistic tenant. */
    private const CHUNK = 500;

    /** The zero uuid — sorts before every uuidv7, so it is the keyset walk's opening cursor. */
    private const CURSOR_START = '00000000-0000-0000-0000-000000000000';

    /**
     * The candidates: forms whose STORED flag still claims OCR compatibility, joined to the frozen snapshot
     * the rule is evaluated against. Keyset-paginated on the uuidv7 primary key.
     *
     *  - INNER JOIN, deliberately: a form with no published version (or whose pointer was nulled by
     *    `ON DELETE SET NULL`) has nothing to derive a verdict from, and its `'{}'` must stay `'{}'`.
     *  - NO `deleted_at IS NULL`, deliberately: `forms` soft-deletes, and a form restored later must not
     *    come back advertising a stale `true`. Raw SQL applies no Eloquent scope, so this is free — but it
     *    is a decision, not an accident.
     *  - The `ocr_compatible' = 'true'` filter is LOSSLESS, not an optimization. H8's permitted set is
     *    disjoint from the pre-H8 rule's denylist (geo ∪ media ∪ `calculated`) and every structural clause
     *    only adds falsity, so `old = false ⇒ new = false`: a stored `false` cannot be wrong, and skipping
     *    it cannot miss a row. It also doubles as a TYPE guard — `FormFactory` writes `capability_flags` as
     *    the JSON *array* `[]`, and `'[]'::jsonb->>'k'` is NULL rather than an error, so `jsonb_set` is
     *    never handed a non-object (which would raise "path element is not an integer").
     *
     * The same statement is also the VERIFY pass: after the flip, every row it still returns must be
     * eligible under the rule. One SQL text, both passes, so the fix and its own postcondition cannot
     * disagree about what to look at.
     */
    public const string SELECT_SQL = <<<'SQL'
        SELECT f.id AS form_id, v.schema_snapshot::text AS snapshot
        FROM forms f
        JOIN form_versions v ON v.id = f.current_published_version_id
        WHERE f.capability_flags->>'ocr_compatible' = 'true'
          AND f.id > ?::uuid
        ORDER BY f.id
        LIMIT ?
        SQL;

    /**
     * The monotone write. Three deliberate properties:
     *
     *  1. `jsonb_set` on the SINGLE `{ocr_compatible}` path — every other key survives byte-identical,
     *     including `has_geofields` / `has_media` (unchanged by H8) and any key a FUTURE increment adds. A
     *     whole-object overwrite would guarantee neither.
     *  2. The value is the LITERAL `false`. There is no computed value in this statement at all, because
     *     the fixed rule can only ever REMOVE eligibility (see the monotonicity note on SELECT_SQL). A
     *     `true` cannot be written here under any input.
     *  3. `create_if_missing = false` — this never invents a flag key. Moot given the WHERE clause
     *     guarantees the key is present and `true`, and stated anyway.
     *
     * `updated_at` is deliberately NOT bumped: this corrects a derived value rather than editing the form,
     * and `updated_at` is an optimistic-concurrency token elsewhere in this schema
     * ({@see FormBuilderService::rowVersion()}) that a migration must not invalidate
     * under a client holding it. `id = ANY(?::uuid[])` rather than a built-up `IN (...)` list keeps this a
     * single assertable constant with no interpolation.
     */
    public const string FLIP_SQL = <<<'SQL'
        UPDATE forms
        SET capability_flags = jsonb_set(capability_flags, '{ocr_compatible}', 'false'::jsonb, false)
        WHERE id = ANY(?::uuid[])
          AND capability_flags->>'ocr_compatible' = 'true'
        SQL;

    /**
     * Pass 1 reads, computes and flips; pass 2 re-derives the verdict for every surviving `true` and
     * refuses to let the migration continue if any is still ineligible.
     *
     * Connection-agnostic on purpose — "apply the rule to whatever rows this connection can see" — which is
     * what lets a Pest test drive it on the app connection under a live tenant GUC and prove the effect on
     * real Postgres rows. The migration is where the connection is CHOSEN, so the migration is where
     * {@see self::assertPrivileged()} ("and it must see everything") belongs.
     */
    public function __invoke(ConnectionInterface $conn): void
    {
        $this->assertReachable($conn);

        $cursor = self::CURSOR_START;

        while (true) {
            $rows = $conn->select(self::SELECT_SQL, [$cursor, self::CHUNK]);
            if ($rows === []) {
                break;
            }

            $ineligible = [];
            foreach ($rows as $row) {
                /** @var object{form_id: string, snapshot: string} $row */
                $cursor = (string) $row->form_id;
                if (! self::eligible((string) $row->snapshot)) {
                    $ineligible[] = $cursor;
                }
            }

            if ($ineligible !== []) {
                $conn->update(self::FLIP_SQL, [self::uuidArrayLiteral($ineligible)]);
            }
        }

        self::assertNoIneligibleSurvivor(...$this->findIneligibleSurvivor($conn));
    }

    /**
     * Does `current_user` bypass RLS? Cast to `int` in SQL so the answer does not depend on how the driver
     * marshals a Postgres boolean.
     */
    public const string PRIVILEGE_SQL = 'select (rolsuper or rolbypassrls)::int as ok from pg_roles where rolname = current_user';

    /**
     * The connection must actually BYPASS RLS. `select 1` proves only that it OPENS: a NOBYPASSRLS role with
     * valid credentials reads zero published forms under FORCE ROW LEVEL SECURITY with no tenant GUC, flips
     * nothing, and then passes a postcondition that reads the same zero rows — a vacuous green, and the exact
     * failure both precedent backfills were written to warn about but neither actually detects. Asked of
     * `pg_roles` rather than inferred from data, so it is equally decisive on an empty database.
     */
    public static function assertPrivileged(ConnectionInterface $privileged): void
    {
        /** @var object{ok: int|string|bool}|null $row */
        $row = $privileged->selectOne(self::PRIVILEGE_SQL);

        self::assertPrivilegedRole($row?->ok);
    }

    /**
     * The decision, split from the query so it is assertable with scalars and no database — the
     * {@see LegacyOverrideBackfill::assertBackfillComplete()} shape. A missing row (`null`) fails too: it
     * means `current_user` was not found in `pg_roles`, which is not a reason to proceed.
     */
    public static function assertPrivilegedRole(int|string|bool|null $ok): void
    {
        if ((int) $ok !== 1) {
            throw new RuntimeException(
                'H8 ocr_compatible backfill: the pgsql_privileged role has neither SUPERUSER nor BYPASSRLS. '
                .'Under FORCE ROW LEVEL SECURITY with no app.current_tenant_id GUC it would see zero published '
                .'forms, flip nothing, and report success. Point DB_PRIVILEGED_USERNAME at a role that '
                .'bypasses RLS.'
            );
        }
    }

    /**
     * The postcondition. Deliberately NOT the precedents' `stamped < expected` count comparison: their
     * invariant IS a count, whereas H8's is a property of the data ("no published form advertises a `true`
     * its own snapshot rejects"), so this counts VIOLATIONS and demands zero. It inherits the same two
     * properties for the same reasons — `0 > 0` is false, so a `migrate:fresh` on an empty database passes
     * cleanly, and a re-run has nothing left to violate.
     *
     * It is also race-tolerant *because* it re-derives rather than compares totals: a form published during
     * the run was published by the fixed code, so it is genuinely eligible and passes.
     */
    public static function assertNoIneligibleSurvivor(int $violations, ?string $firstFormId = null): void
    {
        if ($violations > 0) {
            throw new RuntimeException(
                "H8 ocr_compatible backfill left {$violations} published form(s) flagged ocr_compatible=true "
                .'that the fixed docs/ocr-pipeline-design.md §2 rule rejects'
                .($firstFormId !== null ? " (e.g. form {$firstFormId})" : '')
                .'. Refusing to continue — H18 would offer OCR on a form its own §2 rule cannot reconstruct.'
            );
        }
    }

    /**
     * The rule, applied to one stored snapshot — the SAME function the publish path calls.
     *
     * `schema_snapshot` is jsonb, so Postgres has already validated the JSON and `json_decode` cannot fail
     * here; the INNER JOIN on `current_published_version_id` also means it can never be a draft's literal
     * `[]`. The non-array branch is therefore unreachable against real data, and it fails SAFE (ineligible)
     * rather than throwing, because a migration aborting on an impossible condition is a worse operational
     * risk than one that withholds a capability flag.
     */
    private static function eligible(string $snapshotJson): bool
    {
        $decoded = json_decode($snapshotJson, true);

        return CapabilityFlags::forSnapshot(is_array($decoded) ? $decoded : [])['ocr_compatible'];
    }

    /**
     * Re-walk the survivors and count the ones the rule still rejects.
     *
     * @return array{0: int, 1: string|null} violation count, and the first offending form id for the message
     */
    private function findIneligibleSurvivor(ConnectionInterface $conn): array
    {
        $cursor = self::CURSOR_START;
        $violations = 0;
        $first = null;

        while (true) {
            $rows = $conn->select(self::SELECT_SQL, [$cursor, self::CHUNK]);
            if ($rows === []) {
                return [$violations, $first];
            }

            foreach ($rows as $row) {
                /** @var object{form_id: string, snapshot: string} $row */
                $cursor = (string) $row->form_id;
                if (! self::eligible((string) $row->snapshot)) {
                    $violations++;
                    $first ??= $cursor;
                }
            }
        }
    }

    /**
     * A Postgres array literal (`{uuid,uuid}`) for the `?::uuid[]` bind. Every id came from `forms.id` a
     * moment ago, so the shape guard is belt-and-braces — but it means a malformed value fails loudly here
     * rather than as an opaque cast error inside the UPDATE.
     *
     * @param  list<string>  $ids
     */
    private static function uuidArrayLiteral(array $ids): string
    {
        foreach ($ids as $id) {
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
                throw new RuntimeException("H8 ocr_compatible backfill: refusing to bind a malformed form id: {$id}.");
            }
        }

        return '{'.implode(',', $ids).'}';
    }

    /**
     * Turn a missing/misconfigured privileged connection into an actionable error. `phpunit.xml` sets
     * DB_CONNECTION but not the privileged credentials, so this is a genuinely reachable misconfiguration.
     */
    private function assertReachable(ConnectionInterface $conn): void
    {
        try {
            $conn->select('select 1');
        } catch (Throwable $e) {
            throw new RuntimeException(
                'H8 ocr_compatible backfill requires the pgsql_privileged connection (DB_PRIVILEGED_USERNAME / '
                .'DB_PRIVILEGED_PASSWORD). It cannot run on the app connection: under FORCE RLS with no tenant '
                .'GUC it would read ZERO published forms, flip nothing, throw nothing and pass its own '
                .'postcondition vacuously — leaving every already-published matrix / likert / repeat form '
                .'advertising ocr_compatible=true to H18 and to /api/v1 consumers.',
                0,
                $e
            );
        }
    }
}
