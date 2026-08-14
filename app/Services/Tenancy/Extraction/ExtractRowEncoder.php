<?php

declare(strict_types=1);

namespace App\Services\Tenancy\Extraction;

/**
 * Turns one `pdo_pgsql` row into one JSON object (Phase 4, P2b — ADR-0018 §D5).
 *
 * ── THE TWO COERCIONS THAT EXIST, AND WHY EVERYTHING ELSE STAYS A STRING ────────────────────────────────
 * `pdo_pgsql` hands back almost every value as a PHP string. Two of those strings are actively wrong if
 * written straight into JSON:
 *
 *   - **`boolean` arrives as `'t'` / `'f'`**, and `(bool) 'f'` is TRUE. This is the same inversion
 *     {@see \App\Support\Tenancy\ExtractionGuard::assertRlsSubjectRole()} casts around in SQL and
 *     `TenantTableClassificationDriftTest` casts around in its assertions. Written verbatim, every boolean
 *     in the artefact would be the string "f", which a destination that trusts JSON types reads as truthy.
 *   - **`json` / `jsonb` arrive as encoded text**, so writing them verbatim nests a JSON document inside a
 *     JSON string and the reader has to know to decode twice. `submissions`' answers, `forms`' schema
 *     snapshot and `plans`' feature flags are all this shape — the substance of the extract, not an edge.
 *
 * **Numerics deliberately stay strings.** `numeric`, `bigint` and `integer` are left exactly as PostgreSQL
 * rendered them. Casting them would be lossless for `integer` and lossy for `numeric` — PHP floats cannot
 * hold arbitrary precision, and a JSON number cannot express the difference between `1.50` and `1.5`,
 * which for a `numeric` column is a difference the database was storing on purpose. A reader that wants a
 * number can parse one; a reader handed a rounded float has no way back.
 *
 * Timestamps likewise stay in PostgreSQL's own rendering rather than being reformatted into ISO-8601 by
 * PHP: `timestamptz` already comes back with an offset, and re-formatting through Carbon would silently
 * apply the application timezone to a `timestamp without time zone`.
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
