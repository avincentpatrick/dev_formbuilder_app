<?php

declare(strict_types=1);

namespace App\Services\Gamification;

use App\Enums\BadgeKey;
use App\Models\BadgeAward;
use Carbon\CarbonImmutable;

/**
 * One badge as it stands for one member — held with a date, or not held with a distance to go
 * (gamification-design.md §7, ADR-0020 §D9) — Increment K1e.
 *
 * The achievements surface's row type. It exists rather than a loose array because two of the three facts
 * here are easy to derive wrongly from each other, and the class docblock is where that is stated once.
 *
 * ── ⚠️ EARNED-NESS COMES FROM THE LEDGER AND IS **NEVER** DERIVED FROM `progress` ───────────────────────
 * The tempting shape is `earned = progress >= threshold`. It is wrong, and ADR-0020 §D9 is the reason: a
 * badge stores its key and its date and nothing else, precisely so that **re-thresholding moves future
 * earnings only**. Raise {@see BadgeKey::Collector} from 25 to 40 tomorrow and every member who earned it
 * at 25 still holds it — with `progress` now *below* `threshold`. Deriving the flag would silently
 * un-award all of them, and the ledger row that proves otherwise would still be sitting in the table. So
 * `earnedOn` is read from {@see BadgeAward} and `progress` is read from the point ledger, and neither one
 * is allowed to be a function of the other.
 *
 * The inverse case is reachable too and is not a defect: `progress >= threshold` while `earnedOn` is null
 * means the evaluator has not run since the qualifying act — which is exactly the state K1c's backfill
 * exists to clear, and the state every member of every workspace was in before it ran.
 *
 * ⚠️ **`progress` IS THE RAW COUNT AND IS DELIBERATELY NOT CLAMPED HERE.** A member with 40 responses reads
 * 40 against {@see BadgeKey::Collector}'s 25, because the number of things they have done is a fact about
 * them and not about the badge. Clamping is a rendering concern — `MdsProgress` clamps into `[0, max]`
 * itself — and folding it in here would destroy the one number a later tier ({@see BadgeKey::FieldVeteran},
 * at 250) still needs.
 */
final readonly class BadgeStanding
{
    private function __construct(
        public BadgeKey $badge,
        /** When this member earned it, from the award ledger; null means not held. See the class docblock. */
        public ?CarbonImmutable $earnedOn,
        /** How many times this member has been awarded {@see BadgeKey::rule()}. Unclamped. */
        public int $progress,
        /** How many it takes, as the catalog says TODAY — which may differ from when it was earned. */
        public int $threshold,
    ) {}

    public static function earned(BadgeKey $badge, CarbonImmutable $on, int $progress): self
    {
        return new self(badge: $badge, earnedOn: $on, progress: $progress, threshold: $badge->threshold());
    }

    public static function unearned(BadgeKey $badge, int $progress): self
    {
        return new self(badge: $badge, earnedOn: null, progress: $progress, threshold: $badge->threshold());
    }

    public function isEarned(): bool
    {
        return $this->earnedOn !== null;
    }

    /**
     * How many more acts it takes, floored at zero.
     *
     * Floored because of the un-awarded-but-qualifying case above: a negative remainder would render as
     * "-3 to go", and there is no reading of that sentence which is true.
     */
    public function remaining(): int
    {
        return max(0, $this->threshold - $this->progress);
    }
}
