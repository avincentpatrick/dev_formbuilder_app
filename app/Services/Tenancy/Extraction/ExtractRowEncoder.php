<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Extraction;

/**
 * Turns one `pdo_pgsql` row into one JSON object (Phase 4, P2b — ADR-0018 §D5).
 *
 * ── WHAT THE DRIVER ACTUALLY RETURNS, MEASURED RATHER THAN RECALLED ─────────────────────────────────────
 * ⚠️ THIS REPOSITORY HAS BELIEVED SINCE PHASE 0 THAT `pdo_pgsql` RETURNS BOOLEANS AS THE STRINGS `'t'` /
 * `'f'`, AND ON THIS STACK IT DOES NOT. Measured on PHP 8.4.24 / pdo_pgsql 8.4.24 with Laravel's default
 * `ATTR_EMULATE_PREPARES = false`:
 *
 *   `boolean` → PHP bool · `smallint`/`integer`/`bigint` → PHP int · `double precision` → PHP float
 *   `numeric` → **string** (`'1.50'`) · `json`/`jsonb` → **string** · every timestamp → **string**
 *
 * Native prepared statements carry the column's type OID, so the driver converts. The `'t'`/`'f'` folklore
 * is true only under EMULATED prepares, which is a connection option and not a law. Three other places in
 * this repo cast `::int` in SQL on the strength of it — {@see \App\Support\Tenancy\ExtractionGuard},
 * `TenantTableClassificationDriftTest`, `CrossTenantIsolationTest`. Those casts stay correct either way
 * (casting an int to an int is free); only their stated reason is stale.
 *
 * ── SO WHY THE BOOLEAN BRANCH IS STILL HERE ─────────────────────────────────────────────────────────────
 * Because the behaviour above is a property of a CONFIGURATION, not of PostgreSQL. Flip
 * `ATTR_EMULATE_PREPARES` — which a `options` override in `config/database.php` can do in one line — and
 * every boolean in every artefact silently becomes the string `"f"`, which a destination that trusts JSON
 * types reads as TRUTHY. Every `is_pii`, `allow_guest_submissions` and `is_active` flag would arrive
 * inverted, with nothing failing anywhere. The branch costs one comparison and closes that. `DriverTypeMappingTest`
 * pins the measured behaviour above so a stack change is loud rather than silent.
 *
 * **`json` / `jsonb` are the load-bearing coercion**, and that one is live today: written verbatim they
 * nest an encoded document inside a JSON string and the reader has to know to decode twice. `submissions`'
 * answers and `form_versions`' schema snapshot are both this shape — the substance of the extract.
 *
 * **`numeric` is left as the string the driver gives**, and that is worth keeping rather than "fixing":
 * PHP floats cannot hold arbitrary precision, and a JSON number cannot express the difference between
 * `1.50` and `1.5`, which for a `numeric` column is a difference the database was storing on purpose.
 * Timestamps likewise stay in PostgreSQL's own rendering — `timestamptz` already carries an offset, and
 * re-formatting through Carbon would silently apply the application timezone to a `timestamp without time
 * zone`.
 */
final readonly class ExtractRowEncoder
{
    /** @param  array<string, string>  $types  column => `information_schema.data_type`, plus transformed aliases */
    public function __construct(private array $types) {}

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function encode(array $row): array
    {
        $encoded = [];

        foreach ($row as $column => $value) {
            $encoded[$column] = $this->coerce($this->types[$column] ?? 'text', $value);
        }

        return $encoded;
    }

    private function coerce(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value === true || $value === 't' || $value === '1' || $value === 1,
            // Depth 512 is the default and is not lowered: a truncating decode would return null and turn a
            // deeply-nested form schema into a silently empty column. If a document is too deep to decode it
            // is too deep to have been stored, and the right outcome is a loud JSON error, not a null.
            'json', 'jsonb' => json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR),
            default => $value,
        };
    }
}
