<?php

declare(strict_types=1);

namespace Database\Seeders\Concerns;

/**
 * Stable, greppable primary keys for seeded fixture rows (extracted from `E2eSeeder` in I6).
 *
 * ── WHY THIS IS SHARED RATHER THAN COPIED ──────────────────────────────────────────────────────────────
 * `E2eSeeder` and `DemoSeeder` both key their upserts on a hash of a human-readable fixture key, and a
 * second hand-rolled copy of the shaping below is how two fixtures come to disagree about what a key
 * *means* — the same row would carry two different ids depending on which seeder wrote it first, and every
 * `updateOrCreate` keyed on it would silently start inserting instead of updating. The extraction is
 * mechanical and `E2eSeederIdempotencyTest` stands over it: if the body drifts, that suite reddens.
 */
trait DeterministicIds
{
    /**
     * A stable UUID from a human-readable fixture key, so a row is greppable and a re-seed converges.
     *
     * Hand-rolled rather than `Ramsey\Uuid::uuid5()`: that package is only a transitive dependency here,
     * and `Str::uuid()`/`orderedUuid()` are both random, which is exactly what an upsert key must not be.
     */
    protected static function fixtureUuid(string $key): string
    {
        $hash = hash('sha256', $key);

        return sprintf(
            '%s-%s-5%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            substr($hash, 13, 3),
            dechex((hexdec(substr($hash, 16, 2)) & 0x3F) | 0x80).substr($hash, 18, 2),
            substr($hash, 20, 12),
        );
    }
}
