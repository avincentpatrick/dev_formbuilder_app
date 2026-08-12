<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Submissions\SubmissionDraftService;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Migrations\SubmissionReferenceBackfill;
use App\Support\Submissions\SubmissionReferenceIssuer;

/**
 * A {@see SubmissionReferenceIssuer} that hands out a scripted sequence, so a collision becomes deterministic
 * (Increment J2e).
 *
 * At 32^8 ≈ 1.1e12 codes, no test can make two real draws agree by chance — so without this seam the retry
 * paths in {@see SubmissionPipeline}, {@see SubmissionDraftService}
 * and {@see SubmissionReferenceBackfill} would ship unexercised, which for code that
 * only runs in the rare case is indistinguishable from shipping it broken.
 *
 * Once the script runs out it REPEATS the last value rather than throwing. That is deliberate: a test that
 * scripts two codes for a two-attempt scenario should not also have to predict how many times a chunked
 * backfill will call it. A test that cares about the count asserts {@see calls()} instead.
 */
final class ScriptedSubmissionReferenceIssuer implements SubmissionReferenceIssuer
{
    /** @var list<string> */
    private array $script;

    private int $calls = 0;

    /**
     * @param  list<string>  $script  codes to hand out in order; the last repeats once exhausted
     */
    public function __construct(array $script)
    {
        if ($script === []) {
            throw new \InvalidArgumentException('A scripted reference issuer needs at least one code.');
        }

        $this->script = $script;
    }

    public function issue(): string
    {
        $index = min($this->calls, count($this->script) - 1);
        $this->calls++;

        return $this->script[$index];
    }

    /** How many times the code under test asked for a reference. */
    public function calls(): int
    {
        return $this->calls;
    }
}
