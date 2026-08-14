<?php

declare(strict_types=1);

namespace App\Support\Tenancy;

use App\Exceptions\Tenancy\TenantExtractException;
use Tests\Feature\Tenancy\TenantExtractColumnDriftTest;

/**
 * WHICH COLUMNS LEAVE THE BUILDING, and why each withheld one does not (Phase 4, P2b — ADR-0018 §D3).
 *
 * {@see TenantScopedTables} decides which TABLES a tenant-scoped read reaches. This class decides what a
 * row of one of them is allowed to contain once it has been written to a file that will be handed to
 * somebody. Those are different questions with different failure modes: getting the first wrong extracts
 * another tenant's rows, getting the second wrong extracts this tenant's *credentials*.
 *
 * ── WHY THE CONSTANT IS THE WITHHELD LIST AND NOT THE EXTRACTED LIST ────────────────────────────────────
 * The safer-LOOKING design is an allowlist: name every column that may leave, and a new column is excluded
 * until somebody classifies it. It was rejected for a measured reason — the allowlist is ~700 entries
 * across 41 tables, and a constant that large is maintained by find-and-replace rather than by thought.
 * The failure it invites is worse than the one it prevents: a reviewer confronted with 700 lines approves
 * the diff, and the one line that mattered is indistinguishable from the 699 that did not.
 *
 * So the reasoning lives here, small enough to read in one sitting, and the DRIFT lives in
 * {@see TenantExtractColumnDriftTest}, which pins the full per-table column census against the live
 * catalog and fails the moment a migration adds, drops or renames anything. That is this repo's own
 * idiom — {@see TenantScopedTables} and its drift test, `ToggleableModules::KEYS` and
 * `PlanCatalog::FEATURE_KEYS` — applied a layer down.
 *
 * ⚠️ AND ONE HALF OF IT IS ENFORCED AT RUNTIME, BECAUSE A TEST CANNOT CATCH THIS ONE IN TIME.
 * {@see self::assertWithheldColumnsExist()} refuses to extract when a withheld column is absent from the
 * live catalog. The failure it closes is a RENAME: `users.password` becoming `users.password_hash` turns
 * the entry below into a no-op that matches nothing, and the extract then ships the hash while every
 * assertion about "password is withheld" stays green — the entry is still there, it just stops meaning
 * anything. A withheld name that matches no column is not a harmless leftover; it is a withholding that
 * has silently stopped happening.
 */
final class TenantExtractColumns
{
    /**
     * Columns that are read by nothing and written to no file. Table => column => the reason, in the same
     * spirit as {@see TenantScopedTables::UNPROTECTED_TENANT_TABLES}: an entry with no rationale is
     * indistinguishable from a column somebody removed out of caution and nobody can ever put back.
     *
     * Three kinds of thing appear here, and they are not interchangeable:
     *
     *   1. **Live credentials** — a value that still authenticates something at the moment the extract is
     *      read. These are the reason this class exists.
     *   2. **Facts about a different subject** — the platform's relationship with a person, or another
     *      workspace's identifier, which this tenant has no claim on and no business receiving.
     *   3. **Derived columns** — reproducible from data that IS in the extract, and not portable anyway.
     *
     * @var array<string, array<string, string>>
     */
    public const array WITHHELD = [
        'connections' => [
            'access_token' => 'A live third-party OAuth access token (ADR-0009). It is ciphertext under this '
                .'installation\'s APP_KEY, so it is simultaneously useless at the destination and a working '
                .'credential anywhere the key travels with it — the worst combination an extracted column can have.',
            'refresh_token' => 'A live third-party OAuth refresh token (ADR-0009), which outlives the access '
                .'token it mints and is the credential an attacker actually wants. Same custody argument as above.',
        ],
        'domains' => [
            'verification_token' => 'The TXT-record secret that proves control of a hostname (ADR-0012). Holding '
                .'it is how a domain reaches `verified`, so it is a credential for the routing layer, not a '
                .'record of the tenant\'s data.',
        ],
        'forms' => [
            'search_vector' => 'A derived tsvector maintained by trigger from columns that ARE extracted. Not '
                .'portable across PostgreSQL text-search configurations and reproducible by reindexing.',
        ],
        'impersonation_tokens' => [
            'token_hash' => 'Credential material for the operator impersonation path. The row is extracted so the '
                .'tenant can see THAT an impersonation was authorised and when; the hash adds nothing a reader needs.',
        ],
        'personal_access_tokens' => [
            'token' => 'The hashed API token. It authenticates against this installation right now, and the row '
                .'without it still answers every question an extract is for (which tokens exist, their abilities, '
                .'when they were last used).',
        ],
        'submission_geo_index' => [
            'geom' => 'Emitted instead as GeoJSON — see self::TRANSFORMED. Named here so that a reader comparing '
                .'the manifest against the catalog does not conclude the column was dropped.',
        ],
        'submissions' => [
            'guest_token' => 'A live resume credential: it is the entire authentication for an in-flight guest '
                .'draft, so anyone holding the extract could open and alter a response that has not been submitted.',
            'search_vector' => 'A derived tsvector maintained by trigger from the submission\'s own columns, '
                .'as `forms.search_vector`. Not portable across PostgreSQL text-search configurations, and '
                .'rebuilt by reindexing at any destination that wants one.',
        ],
        'tenant_users' => [
            'invite_token' => 'A live credential. An outstanding invitation is accepted by presenting this value, '
                .'so extracting it hands the reader the ability to join the workspace as the invited person.',
        ],
        'users' => [
            'password' => 'A credential, and the one that matters most: the hash is offline-crackable, and this '
                .'platform\'s identities are CENTRAL (ADR-0017 Context §2) — one human, one password, N workspaces. '
                .'An extract handed to one tenant would therefore carry material for an identity that is still '
                .'active in workspaces that tenant has nothing to do with.',
            'remember_token' => 'A live session credential: presenting it authenticates as this person with '
                .'no password and no second factor, on the same central identity as above.',
            'two_factor_secret' => 'The TOTP seed. Anyone holding it can generate the person\'s second factor '
                .'indefinitely, which is strictly worse than holding the password.',
            'two_factor_recovery_codes' => 'Single-use second-factor bypasses for the same central identity.',
            'two_factor_confirmed_at' => 'A fact about the person\'s security posture on the PLATFORM, not about '
                .'their membership of this workspace — and a map of which members have no second factor is an '
                .'attacker\'s shortlist.',
            'is_super_admin' => 'A platform-operator flag. It describes this installation\'s staff, which no '
                .'tenant is a party to.',
            'last_active_tenant_id' => 'ANOTHER WORKSPACE\'S UUID. Extracting it discloses that a shared member '
                .'also belongs somewhere else, which is the one cross-tenant fact this whole architecture exists '
                .'to withhold.',
            'tos_accepted_at' => 'The person\'s contractual relationship with the platform operator, not with '
                .'this tenant.',
            'privacy_policy_accepted_at' => 'As `tos_accepted_at`: the timestamp records acceptance of THIS '
                .'INSTALLATION\'S privacy policy, which is an agreement between the person and the platform '
                .'operator. A tenant is not a party to it and cannot rely on it for their own consent record.',
        ],
        'webhook_endpoints' => [
            'secret' => 'The live HMAC signing secret. A reader holding it can forge a delivery that the tenant\'s '
                .'own receiver will verify as genuine.',
            'secret_previous' => 'The previous signing secret, still accepted during rotation, so still live.',
        ],
    ];

    /**
     * Columns read through a SQL expression rather than verbatim. Table => column => expression.
     *
     * ⚠️ EVERY KEY HERE MUST ALSO APPEAR IN {@see self::WITHHELD}, and {@see TenantExtractColumnDriftTest}
     * asserts it. The two lists do different jobs on the same column: WITHHELD removes it from the verbatim
     * select list, TRANSFORMED adds it back under an expression. Present in TRANSFORMED alone, the column
     * would be selected TWICE — once raw and once transformed — and the raw copy is the one that would have
     * defeated the point.
     *
     * The `type` is fed to the same coercion the catalog's `data_type` drives, because an expression's
     * result type is not in `information_schema` and guessing it from the value is how a string that
     * happens to parse as JSON becomes an object.
     *
     * ⚠️ `sql` CARRIES ITS OWN ALIAS and must alias to the array key, which `TenantExtractColumnDriftTest`
     * asserts. Two reasons, and the second is the real one: the encoder looks the result column up by that
     * key, so a mismatch silently drops the transform's type coercion; and a `literal-string` here is what
     * lets the expression reach the query builder without a runtime concatenation, which keeps every byte
     * of raw SQL in this constant where it can be read, rather than assembled somewhere it cannot.
     *
     * @var array<string, array<string, array{sql: literal-string, type: string}>>
     */
    public const array TRANSFORMED = [
        'submission_geo_index' => [
            // PostGIS hands `geometry` back as EWKB hex (`0101000020E6100000…`), which is JSON-safe and
            // completely unreadable. An extract exists to be read somewhere that is not this application, so
            // the interchange format wins over the storage format. ST_AsGeoJSON is lossless for the 2D
            // geometries this column holds and is what ADR-0006 §3.1 already treats as the wire shape.
            'geom' => ['sql' => 'st_asgeojson(geom) as "geom"', 'type' => 'jsonb'],
        ],
    ];

    /**
     * Tables and artefacts deliberately left out, with the reason, reported verbatim in the manifest.
     *
     * This exists because an extract's most dangerous property is looking complete. A reader who cannot
     * tell "this tenant has no webhooks" from "webhooks were not extracted" will assume the first.
     *
     * @var array<string, string>
     */
    public const array NOT_EXTRACTED = [
        'user_ui_preferences' => 'Keyed on a person, not an organisation (TenantScopedTables::NON_TENANT_RLS). '
            .'Theme, accent and font-size choices follow the human across every workspace they belong to; they '
            .'are not this tenant\'s record and would be wrong to import into a destination as if they were.',
        'attachment file contents' => 'The `attachments` ROWS are extracted in full — disk, path, checksum, size '
            .'and PII flag — but the underlying objects are not copied. Blob transfer is a bandwidth and storage '
            .'decision with its own failure modes (partial copies that still produce a manifest), and the '
            .'checksum column makes a separate, verifiable copy possible without pretending this command did it.',
        'platform catalog rows' => 'The rows with a NULL `tenant_id` in the six widened tables — the template '
            .'catalog, the question library, the 29 permissions and the 5 roles. They are the PRODUCT, identical '
            .'for every tenant, and RLS returns them only because the SELECT policy is widened '
            .'(TenantScopedTables::rlsReturnsSuperset). Counted in the manifest rather than silently dropped.',
        'central platform tables' => '`plans`, `sessions`, `cache`, `jobs`, `job_batches`, `failed_jobs`, '
            .'`migrations`, `password_reset_tokens`, `role_has_permissions`, `spatial_ref_sys` and the PostGIS '
            .'views. None carries `tenant_id`; none is the tenant\'s record.',
    ];

    /**
     * The columns to select verbatim: everything the catalog reports, minus the withheld names.
     *
     * @param  list<string>  $catalogColumns
     * @return list<string>
     */
    public static function verbatimFor(string $table, array $catalogColumns): array
    {
        $withheld = self::withheldFor($table);

        return array_values(array_filter(
            $catalogColumns,
            static fn (string $column): bool => ! array_key_exists($column, $withheld)
        ));
    }

    /** @return array<string, string> */
    public static function withheldFor(string $table): array
    {
        return self::WITHHELD[$table] ?? [];
    }

    /** @return array<string, array{sql: literal-string, type: string}> */
    public static function transformedFor(string $table): array
    {
        return self::TRANSFORMED[$table] ?? [];
    }

    /**
     * Refuse to extract a table whose withheld columns are not all present in the live catalog.
     *
     * ⚠️ THIS IS THE RENAME GUARD AND IT IS THE ONLY RUNTIME HALF OF THIS CLASS. A migration that renames
     * `users.password` leaves the WITHHELD entry pointing at nothing: it matches no catalog column, filters
     * nothing out, and the new column — which the census test will flag, but only once somebody runs it —
     * flows into the verbatim select list. Every unit assertion of the form "password is withheld" keeps
     * passing, because the entry is still there. The absence IS the defect.
     *
     * @param  list<string>  $catalogColumns
     *
     * @throws TenantExtractException
     */
    public static function assertWithheldColumnsExist(string $table, array $catalogColumns): void
    {
        $missing = array_values(array_diff(
            array_keys(self::withheldFor($table)),
            $catalogColumns
        ));

        if ($missing !== []) {
            throw TenantExtractException::withheldColumnAbsent($table, $missing);
        }
    }
}
