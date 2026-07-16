<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Services\Forms\SchemaSnapshotSerializer;

/**
 * Increment G8c — a deterministic SHA-256 over a submission's ANSWER content, used by the Submission
 * Pipeline to tell an idempotent replay (byte-identical content under a `client_submission_uuid`) apart
 * from a genuine concurrent-edit conflict (the same uuid carrying different answers → 409). It mirrors the
 * canonical-serialization discipline of {@see SchemaSnapshotSerializer} (the schema
 * checksum): object keys are recursively byte-sorted while list order is preserved (repeat-group instance
 * order is meaningful), then hashed under fixed JSON flags — so the same answers always hash identically
 * regardless of PHP array key insertion order.
 *
 * The pipeline computes this over the STRUCTURALLY-NORMALIZED answers (Stage 1 output), so the stored value
 * and the incoming-replay value hash the exact same representation of "what the client submitted".
 */
final class AnswersContentChecksum
{
    private const JSON_FLAGS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /**
     * @param  array<string, mixed>  $answers
     */
    public static function of(array $answers): string
    {
        return hash('sha256', (string) json_encode(self::canonicalize($answers), self::JSON_FLAGS));
    }

    /**
     * Recursively sort associative (object) keys by byte order; leave list order untouched (repeat-group
     * instance arrays and multi-select lists are order-significant).
     *
     * @param  array<mixed>  $value
     * @return array<mixed>
     */
    private static function canonicalize(array $value): array
    {
        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $value[$k] = self::canonicalize($v);
            }
        }

        return $value;
    }
}
