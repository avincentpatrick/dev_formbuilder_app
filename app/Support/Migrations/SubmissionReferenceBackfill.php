<?php

declare(strict_types=1);

namespace App\Support\Migrations;

use App\Support\Submissions\RandomSubmissionReferenceIssuer;
use App\Support\Submissions\SubmissionReference;
use App\Support\Submissions\SubmissionReferenceIssuer;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use RuntimeException;
use Throwable;

/**
 * Mints a `submissions.reference` for every row that predates the column (Increment J2e).
 *
 * The column is `NOT NULL` by the end of its migration, so this is what makes that possible on a populated
 * database. New rows get theirs from a `creating` hook on the model; these are the ones that already existed.
 *
 * Extracted from the migration so the body is assertable WITHOUT it — a `migrate:fresh` test database is
 * empty at migration time, so a test that only ran the migration would prove nothing. The
 * {@see OcrCompatibilityBackfill} / {@see LegacyOverrideBackfill} precedent.
 *
 * ── ⚠️ THE UNIQUE INDEX EXISTS BEFORE THIS RUNS, AND THAT IS THE DESIGN ──────────────────────────────────
 * The obvious shape is "mint into a PHP set, de-duplicate in memory, then add the index". It was rejected.
 * A per-tenant `seen` map is a SECOND authority on uniqueness that agrees with the index only by inspection,
 * its failure is invisible until the `CREATE UNIQUE INDEX` at the end blows up mid-migration, and — the
 * decisive objection — it can only be tested PROBABILISTICALLY, because forcing an in-memory collision at
 * fixture scale means minting ~10^6 codes.
 *
 * So the migration creates `submissions_tenant_id_reference_unique` while the column is still nullable
 * (Postgres treats NULLs as distinct, so unlimited un-backfilled rows coexist happily under it), and this
 * class writes THROUGH that index. A collision is then a 23505 on one chunk's UPDATE, which is retried with
 * fresh codes. The database is the only authority, exactly as it is everywhere else in this schema, and the
 * retry is deterministically testable by pre-seeding a taken code.
 *
 * ── Why it runs on `pgsql_privileged` ────────────────────────────────────────────────────────────────────
 * Migrations run on the default `pgsql` connection as `meridian_app` — NOSUPERUSER / NOBYPASSRLS — with NO
 * `app.current_tenant_id` GUC set. Under the strict RLS shape
 * `tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::uuid` that evaluates to
 * `tenant_id = NULL`, which matches ZERO rows, and FORCE ROW LEVEL SECURITY binds even the table owner. A
 * naive run on the app connection would read nothing, write nothing, throw nothing — and then pass its own
 * postcondition vacuously, leaving `SET NOT NULL` to fail on the next statement with no explanation.
 * The backfill is also inherently cross-tenant, which no single-tenant GUC could authorize.
 * {@see self::assertPrivileged()} turns a misconfigured role into a red migration rather than a vacuous green.
 */
final class SubmissionReferenceBackfill
{
    /** Rows per keyset page. Short enough to keep each autocommit statement brief, long enough to be one round trip per realistic tenant. */
    private const CHUNK = 5000;

    /** The zero uuid — sorts before every uuidv7, so it is the keyset walk's opening cursor. */
    private const CURSOR_START = '00000000-0000-0000-0000-000000000000';

    /** Re-mints for one chunk after a unique violation. At 32^8 codes a second collision on the same chunk is not a real event; five is the point past which something else is wrong. */
    private const MAX_CHUNK_ATTEMPTS = 5;

    /**
     * The candidates. Keyset-paginated on the uuidv7 primary key rather than OFFSET, so the walk cost does
     * not grow with how far through it is.
     *
     * No `deleted_at IS NULL`, deliberately: a soft-deleted submission is still a row the `NOT NULL`
     * constraint will apply to, and a restored one must not come back reference-less. Raw SQL applies no
     * Eloquent scope, so this is free — but it is a decision, not an accident.
     *
     * `tenant_id` is not selected because nothing here needs it: the code is random rather than derived, and
     * uniqueness is the index's job. Recorded because "scope the mint per tenant" is the first thing a reader
     * will expect to see.
     */
    public const string SELECT_SQL = <<<'SQL'
        SELECT id
        FROM submissions
        WHERE reference IS NULL
          AND id > ?::uuid
        ORDER BY id
        LIMIT ?
        SQL;

    /**
     * The write: one statement per chunk, two array binds, no interpolation — so this is a single assertable
     * constant rather than SQL assembled from a loop.
     *
     * `unnest` of two parallel arrays rather than a built-up `VALUES (...), (...)` list for exactly that
     * reason (the {@see OcrCompatibilityBackfill::FLIP_SQL} `id = ANY(?::uuid[])` precedent, extended to
     * carry a per-row value).
     *
     * `AND s.reference IS NULL` is what makes a re-run and a concurrent writer both safe: this statement can
     * only ever fill a hole, never overwrite a code someone has already been shown. `updated_at` is untouched
     * — this fills in a new column rather than editing the submission, and `updated_at` is a meaningful
     * timestamp on this table.
     */
    public const string FILL_SQL = <<<'SQL'
        UPDATE submissions s
        SET reference = v.reference
        FROM (SELECT unnest(?::uuid[]) AS id, unnest(?::text[]) AS reference) v
        WHERE s.id = v.id
          AND s.reference IS NULL
        SQL;

    /** Does `current_user` bypass RLS? Cast to `int` in SQL so the answer does not depend on how the driver marshals a Postgres boolean. */
    public const string PRIVILEGE_SQL = 'select (rolsuper or rolbypassrls)::int as ok from pg_roles where rolname = current_user';

    /**
     * The generator, injectable for one reason: {@see fillChunk()}'s retry is otherwise untestable. At 32^8
     * codes no test can make two draws collide by chance, so the recovery path would ship unexercised — and
     * a recovery path that only ever runs in the rare case is one nobody would notice was broken.
     */
    private readonly SubmissionReferenceIssuer $issuer;

    public function __construct(?SubmissionReferenceIssuer $issuer = null)
    {
        // Not resolved from the container: this class runs inside a migration, where binding a test double
        // through the container would be action at a distance. The migration constructs it with no argument.
        $this->issuer = $issuer ?? new RandomSubmissionReferenceIssuer;
    }

    /** The postcondition: not one row may still be missing a reference when `SET NOT NULL` runs next. */
    public const string REMAINING_SQL = 'select count(*) as remaining from submissions where reference is null';

    /**
     * Walk every reference-less row and fill it.
     *
     * Connection-agnostic on purpose — "mint for whatever rows this connection can see" — which is what lets
     * a Pest test drive it on the app connection under a live tenant GUC and prove the effect on real
     * Postgres rows. The migration is where the connection is CHOSEN, so the migration is where
     * {@see self::assertPrivileged()} ("and it must see everything") belongs.
     */
    public function __invoke(ConnectionInterface $conn): void
    {
        $this->assertReachable($conn);

        $cursor = self::CURSOR_START;

        while (true) {
            /** @var list<object{id: string}> $rows */
            $rows = $conn->select(self::SELECT_SQL, [$cursor, self::CHUNK]);
            if ($rows === []) {
                break;
            }

            $ids = [];
            foreach ($rows as $row) {
                $ids[] = (string) $row->id;
            }

            $this->fillChunk($conn, $ids);

            // The cursor advances even though the rows just filled no longer match SELECT_SQL's predicate.
            // Advancing is what keeps the walk O(n) rather than re-reading from the start each pass; the
            // predicate is what keeps it correct if a row is somehow skipped.
            $cursor = (string) $ids[count($ids) - 1];
        }

        self::assertNoneRemaining($this->countRemaining($conn));
    }

    /**
     * One chunk, retried on a unique violation with an entirely fresh set of codes.
     *
     * The whole chunk is re-minted rather than the offending row alone, because Postgres reports the FIRST
     * conflicting value and nothing about which of the 5,000 rows it belonged to; narrowing would mean a
     * statement per row. At these odds the retry is a fail-safe that will essentially never run in anger —
     * which is precisely why it is covered by a test that pre-seeds a taken code rather than by hoping.
     *
     * @param  list<string>  $ids
     */
    private function fillChunk(ConnectionInterface $conn, array $ids): void
    {
        for ($attempt = 1; $attempt <= self::MAX_CHUNK_ATTEMPTS; $attempt++) {
            $codes = [];
            foreach ($ids as $ignored) {
                $codes[] = $this->issuer->issue();
            }

            try {
                // ⚠️ WRAPPED IN A TRANSACTION SO THE RETRY WORKS IN BOTH MODES, AND THIS IS NOT BELT-AND-BRACES.
                // In production the migration runs with `$withinTransaction = false` and this connection is in
                // AUTOCOMMIT, so a failed statement aborts nothing and a bare `update()` could simply be
                // retried. Under a test — or any caller that already opened one — the 23505 would instead put
                // the OUTER transaction into ERROR state and every retry would die on 25P02, which is the
                // same wall that pushed the writers' recovery out to their transaction boundary.
                // `Connection::transaction()` issues a SAVEPOINT when it is already inside one and rolls back
                // only to that savepoint, so the enclosing transaction survives and the next attempt runs.
                // Measured, not assumed: without this the backfill's own effect test fails on 25P02.
                $conn->transaction(function () use ($conn, $ids, $codes): void {
                    $conn->update(self::FILL_SQL, [
                        self::uuidArrayLiteral($ids),
                        self::textArrayLiteral($codes),
                    ]);
                });

                return;
            } catch (QueryException $e) {
                if ((string) $e->getCode() !== '23505' || $attempt === self::MAX_CHUNK_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    /**
     * The connection must actually BYPASS RLS. `select 1` proves only that it OPENS: a NOBYPASSRLS role with
     * valid credentials reads zero submissions under FORCE ROW LEVEL SECURITY with no tenant GUC, fills
     * nothing, and then passes a postcondition that reads the same zero rows — a vacuous green whose symptom
     * would surface one statement later as an unexplained `SET NOT NULL` failure. Asked of `pg_roles` rather
     * than inferred from data, so it is equally decisive on an empty database.
     */
    public static function assertPrivileged(ConnectionInterface $privileged): void
    {
        /** @var object{ok: int|string|bool}|null $row */
        $row = $privileged->selectOne(self::PRIVILEGE_SQL);

        self::assertPrivilegedRole($row?->ok);
    }

    /**
     * The decision, split from the query so it is assertable with scalars and no database — the
     * {@see OcrCompatibilityBackfill::assertPrivilegedRole()} shape. A missing row (`null`) fails too: it
     * means `current_user` was not found in `pg_roles`, which is not a reason to proceed.
     */
    public static function assertPrivilegedRole(int|string|bool|null $ok): void
    {
        if ((int) $ok !== 1) {
            throw new RuntimeException(
                'J2e submissions.reference backfill: the pgsql_privileged role has neither SUPERUSER nor '
                .'BYPASSRLS. Under FORCE ROW LEVEL SECURITY with no app.current_tenant_id GUC it would see '
                .'zero submissions, fill nothing, and report success — and the SET NOT NULL that follows '
                .'would then fail with no explanation. Point DB_PRIVILEGED_USERNAME at a role that bypasses RLS.'
            );
        }
    }

    /**
     * The postcondition. `0 > 0` is false, so a `migrate:fresh` on an empty database passes cleanly and a
     * re-run has nothing left to fill.
     *
     * It counts only NULLs, not duplicates: uniqueness is enforced by
     * `submissions_tenant_id_reference_unique`, which already exists by the time this runs. Re-asserting it
     * here would be a second authority that can only ever agree.
     */
    public static function assertNoneRemaining(int $remaining): void
    {
        if ($remaining > 0) {
            throw new RuntimeException(
                "J2e submissions.reference backfill left {$remaining} submission(s) with no reference. "
                .'Refusing to continue — the SET NOT NULL that follows would fail on exactly these rows.'
            );
        }
    }

    private function countRemaining(ConnectionInterface $conn): int
    {
        /** @var object{remaining: int|string}|null $row */
        $row = $conn->selectOne(self::REMAINING_SQL);

        // A `count(*)` always returns a row, so the null arm is unreachable against a live connection — but
        // `selectOne()` is typed nullable and treating "no row" as "nothing remaining" is the wrong default
        // for a guard, so it fails CLOSED at 1 instead.
        return $row === null ? 1 : (int) $row->remaining;
    }

    /**
     * A Postgres array literal (`{uuid,uuid}`) for the `?::uuid[]` bind. Every id came from `submissions.id` a
     * moment ago, so the shape guard is belt-and-braces — but it means a malformed value fails loudly here
     * rather than as an opaque cast error inside the UPDATE.
     *
     * @param  list<string>  $ids
     */
    private static function uuidArrayLiteral(array $ids): string
    {
        foreach ($ids as $id) {
            if (preg_match('/^[0-9a-fA-F-]{36}$/', $id) !== 1) {
                throw new RuntimeException("J2e submissions.reference backfill: refusing to bind a malformed submission id: {$id}.");
            }
        }

        return '{'.implode(',', $ids).'}';
    }

    /**
     * A Postgres array literal for the `?::text[]` bind.
     *
     * Every code is validated rather than quoted: {@see SubmissionReference::isValid()} admits only eight
     * characters of `[0-9A-Z]`, which contains no comma, brace, backslash or quote, so an unquoted literal is
     * safe BY CONSTRUCTION rather than by escaping. If the alphabet ever gains a character that needs
     * quoting, this guard fails loudly instead of silently producing a malformed array.
     *
     * @param  list<string>  $codes
     */
    private static function textArrayLiteral(array $codes): string
    {
        foreach ($codes as $code) {
            if (! SubmissionReference::isValid($code)) {
                throw new RuntimeException("J2e submissions.reference backfill: refusing to bind a malformed reference: {$code}.");
            }
        }

        return '{'.implode(',', $codes).'}';
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
                'J2e submissions.reference backfill requires the pgsql_privileged connection '
                .'(DB_PRIVILEGED_USERNAME / DB_PRIVILEGED_PASSWORD). It cannot run on the app connection: '
                .'under FORCE RLS with no tenant GUC it would read ZERO submissions, fill nothing, throw '
                .'nothing and pass its own postcondition vacuously.',
                0,
                $e
            );
        }
    }
}
