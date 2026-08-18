<?php

declare(strict_types=1);

namespace App\Services\Gamification;

/**
 * What one pass of {@see GamificationBackfill} actually did — Increment K1c.
 *
 * ⚠️ **THE POINT OF COUNTING THE REFUSALS SEPARATELY IS THAT A SILENT ZERO IS THE FAILURE MODE HERE.**
 * A backfill that writes nothing looks identical to a backfill that had nothing to write: both print `0`.
 * This tenancy model makes that worse rather than better, because an RLS-filtered read with no tenant GUC
 * returns no rows rather than raising — so "the operator ran it wrong" and "this workspace is new" would be
 * the same output. Every row this class saw is accounted for in exactly one bucket, and `scanned` must
 * equal the sum of the other four.
 *
 * ⚠️ **`existing` ALSO ABSORBS A FAILED WRITE, AND THAT IS STATED RATHER THAN HIDDEN.**
 * `PointsRecorder::award()` returns `false` for three different facts — the module is off, the act was
 * already awarded, and the write failed — and it does not distinguish them, deliberately. The backfill
 * removes the first by checking the module once, up front, and refusing to start; a genuine failure is
 * logged at WARNING by the recorder itself. What is left in this bucket is overwhelmingly "already
 * awarded", which is what a re-run should report for everything.
 */
final readonly class BackfillTally
{
    public function __construct(
        /** Rows examined. Must equal created + existing + unmapped + uncredited. */
        public int $scanned = 0,
        /** Awards genuinely created — the only number that means work was done. */
        public int $created = 0,
        /** A candidate was built and the ledger already had it. See the class docblock. */
        public int $existing = 0,
        /** No earnable act: an unscored tuple, or a `('submission','updated')` carrying neither marker. */
        public int $unmapped = 0,
        /** An act with nobody to credit: a guest response, a deleted actor, an unreadable invitee. */
        public int $uncredited = 0,
    ) {}

    public function plus(self $other): self
    {
        return new self(
            scanned: $this->scanned + $other->scanned,
            created: $this->created + $other->created,
            existing: $this->existing + $other->existing,
            unmapped: $this->unmapped + $other->unmapped,
            uncredited: $this->uncredited + $other->uncredited,
        );
    }

    /** Does every row land in exactly one bucket? The invariant this type exists to make checkable. */
    public function balances(): bool
    {
        return $this->scanned === $this->created + $this->existing + $this->unmapped + $this->uncredited;
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return [
            'scanned' => $this->scanned,
            'created' => $this->created,
            'existing' => $this->existing,
            'unmapped' => $this->unmapped,
            'uncredited' => $this->uncredited,
        ];
    }
}
