<?php

declare(strict_types=1);

namespace App\Support\Mapping;

use InvalidArgumentException;

/**
 * A one-time, reused binding between a tabular artifact's columns and this platform's form-field keys (H16a),
 * plus the {@see ColumnFingerprint} of the header row it was authored against.
 *
 * TWO CONSUMERS, TWO DIRECTIONS, ONE OBJECT — and that is the point of the class rather than a side effect:
 *
 *   - {@see project()} turns `fieldKey ⇒ value` into a positional row. Google Sheets writes it (H16a).
 *   - {@see interpret()} turns a positional row back into `fieldKey ⇒ value`. The OCR linelist reads it
 *     (`docs/ocr-pipeline-design.md` §4, H19a).
 *
 * H19a is the reason this lives in a provider-neutral namespace instead of inside the Sheets adapter, and the
 * reverse direction is shipped HERE rather than deferred so the reuse is demonstrable now rather than asserted
 * now and discovered to not fit later. `MappingNamespaceBoundaryTest` is what keeps that true mechanically.
 *
 * A binding may have a NULL field key. That is not an incomplete mapping — it is a column the tenant keeps for
 * their own use (a notes column, a formula, a manual sign-off). {@see project()} must still emit a cell for it
 * or every column after it shifts left by one, so an unbound column writes the empty string rather than being
 * skipped. This is exactly the hazard that makes a positional model unforgiving and a fingerprint mandatory.
 */
final readonly class ColumnMapping
{
    /**
     * @param  list<MappedColumn>  $columns  in artifact column order, index 0 = the leftmost column
     */
    private function __construct(
        public array $columns,
        public ColumnFingerprint $fingerprint,
    ) {}

    /**
     * Author a mapping against the header row it was read from.
     *
     * @param  list<string|null>  $headers  the raw header labels, in column order
     * @param  array<string, string>  $fieldKeysByHeader  raw header label ⇒ form-field key; a header absent
     *                                                    from this map becomes an unbound column
     *
     * @throws InvalidArgumentException a field key is bound to more than one column
     */
    public static function author(array $headers, array $fieldKeysByHeader): self
    {
        $fingerprint = ColumnFingerprint::forHeaders($headers);

        // Re-key the caller's map through the same normalizer the fingerprint uses, so a mapping authored
        // against "Village Name" still binds when the caller passes "village name". Anything else would make
        // the two halves of this class disagree about what a header IS.
        $byNormalized = [];

        foreach ($fieldKeysByHeader as $header => $fieldKey) {
            $byNormalized[ColumnFingerprint::normalize($header)] = $fieldKey;
        }

        $columns = [];
        $seen = [];

        foreach ($fingerprint->headers as $header) {
            $fieldKey = $header === '' ? null : ($byNormalized[$header] ?? null);

            if ($fieldKey !== null) {
                if (isset($seen[$fieldKey])) {
                    // Two columns fed by one field is not a preference, it is ambiguity on the READ side:
                    // interpret() would have to pick a winner, and whichever it picked would be arbitrary.
                    throw new InvalidArgumentException("Field key [{$fieldKey}] is bound to more than one column.");
                }

                $seen[$fieldKey] = true;
            }

            $columns[] = new MappedColumn($header, $fieldKey);
        }

        return new self($columns, $fingerprint);
    }

    /**
     * Rehydrate from the `connection_subscriptions.config` payload (or, for H19a, wherever the linelist keeps
     * its cached mapping). Tolerates nothing: a malformed stored mapping is a bug, and silently repairing one
     * would write answers into columns nobody authored.
     *
     * @param  array<string, mixed>  $payload
     *
     * @throws InvalidArgumentException the payload is not a mapping this class wrote
     */
    public static function fromArray(array $payload): self
    {
        $rawColumns = $payload['columns'] ?? null;
        $digest = $payload['fingerprint'] ?? null;

        if (! is_array($rawColumns) || ! is_string($digest) || $digest === '') {
            throw new InvalidArgumentException('A stored column mapping needs a `columns` list and a `fingerprint`.');
        }

        $columns = [];
        $headers = [];

        foreach (array_values($rawColumns) as $index => $column) {
            if (! is_array($column) || ! array_key_exists('header', $column)) {
                throw new InvalidArgumentException("Stored column [{$index}] has no `header`.");
            }

            $fieldKey = $column['field_key'] ?? null;
            $header = ColumnFingerprint::normalize(is_string($column['header']) ? $column['header'] : '');

            $columns[] = new MappedColumn($header, is_string($fieldKey) && $fieldKey !== '' ? $fieldKey : null);
            $headers[] = $header;
        }

        // The digest is taken from the payload rather than recomputed from the stored headers. Recomputing
        // would make every stored mapping fingerprint-valid by construction and the drift check vacuous — the
        // stored digest is a claim about the ARTIFACT, and it has to be able to disagree with what we hold.
        return new self($columns, ColumnFingerprint::fromDigest($digest, $headers));
    }

    /**
     * @return array{fingerprint: string, columns: list<array{header: string, field_key: string|null}>}
     */
    public function toArray(): array
    {
        return [
            'fingerprint' => $this->fingerprint->digest,
            'columns' => array_map(
                static fn (MappedColumn $column): array => ['header' => $column->header, 'field_key' => $column->fieldKey],
                $this->columns,
            ),
        ];
    }

    /**
     * `fieldKey ⇒ value` into a positional row (the WRITE direction — H16a's Sheets append).
     *
     * Every column produces exactly one cell, in order, including unbound ones and bound ones with no answer.
     * A row shorter than the mapping would silently re-align the tenant's spreadsheet.
     *
     * @param  array<string, string>  $valuesByFieldKey
     * @return list<string>
     */
    public function project(array $valuesByFieldKey): array
    {
        return array_map(
            static fn (MappedColumn $column): string => $column->fieldKey === null
                ? ''
                : ($valuesByFieldKey[$column->fieldKey] ?? ''),
            $this->columns,
        );
    }

    /**
     * A positional row back into `fieldKey ⇒ value` (the READ direction — H19a's OCR linelist).
     *
     * A row SHORTER than the mapping is not an error: a spreadsheet omits trailing empty cells and a scanned
     * table can lose its last column to a page edge. Missing cells read as absent rather than as blank
     * answers, because "the OCR could not see this" and "the respondent left it empty" are different facts and
     * the review UI has to be able to tell them apart. Extra cells beyond the mapping are ignored — they are
     * columns nobody bound.
     *
     * @param  list<string|null>  $row
     * @return array<string, string>
     */
    public function interpret(array $row): array
    {
        $values = [];

        foreach ($this->columns as $index => $column) {
            if ($column->fieldKey === null || ! array_key_exists($index, $row)) {
                continue;
            }

            $values[$column->fieldKey] = (string) $row[$index];
        }

        return $values;
    }

    /**
     * The form-field keys this mapping binds, in column order.
     *
     * @return list<string>
     */
    public function boundFieldKeys(): array
    {
        return array_values(array_filter(array_map(
            static fn (MappedColumn $column): ?string => $column->fieldKey,
            $this->columns,
        )));
    }

    /**
     * Which column carries one particular field key, or null if the tenant bound it to none (M5).
     *
     * The index is the answer rather than the header, because both directions of this class are POSITIONAL:
     * a caller that has the artifact's own header list — the verbatim one, which this class deliberately does
     * not keep (see {@see MappedColumn}) — can index straight into it, and one writing A1 notation can turn
     * the position into a column letter. Handing back the normalized header instead would invite exactly the
     * casefolded-name write `AirtableConnector::fieldsFor()` exists to prevent.
     *
     * `author()` already refuses to bind one field key to two columns, so "the" column is well defined here
     * rather than a first-match convention.
     */
    public function indexOfFieldKey(string $fieldKey): ?int
    {
        foreach ($this->columns as $index => $column) {
            if ($column->fieldKey === $fieldKey) {
                return $index;
            }
        }

        return null;
    }

    public function columnCount(): int
    {
        return count($this->columns);
    }
}
