<?php

declare(strict_types=1);

namespace App\Services\Gamification;

/**
 * One chunk of an audit replay, plus where to resume — Increment K1c.
 *
 * `nextCursor` is an `audits.id`, which is a **uuidv7** and therefore time-ordered: the same value that
 * makes the walk chronological makes it resumable, and `audits_tenant_recent_idx (tenant_id, id)` covers
 * both. Null means the ledger is exhausted and the fan-out for this tenant is finished.
 */
final readonly class BackfillChunk
{
    public function __construct(
        public BackfillTally $tally,
        public ?string $nextCursor,
    ) {}
}
