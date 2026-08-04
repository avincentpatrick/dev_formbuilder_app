<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Support\Tenancy\DnsTxtResolver;

/**
 * In-memory {@see DnsTxtResolver} for the custom-domain tests (H22a).
 *
 * RECORDING, not just stubbing, and the recording is the point. Three things about verification are
 * invisible from the resulting `domains` row and can only be asserted here:
 *
 *   1. THE EXACT NAME QUERIED. A wrong challenge prefix is the single most likely defect in this
 *      feature, and a row-level assertion cannot see it — the row looks identical either way.
 *   2. HOW MANY LOOKUPS HAPPENED. One per candidate per sweep pass; more means the batch bound or the
 *      re-check cadence has quietly stopped working.
 *   3. That an UNLISTED name resolves to `[]` rather than an error — "the zone published nothing" is an
 *      ordinary business state, and treating it as a failure is what would expire live claims.
 *
 * `$failing` returns null for every lookup, which is the contract's "could not ask" case. It must never
 * be conflated with `[]`: only the empty array is evidence about the tenant.
 */
final class FakeDnsTxtResolver implements DnsTxtResolver
{
    /** @var list<string> Every name queried, in order. */
    public array $queried = [];

    public bool $failing = false;

    /**
     * @param  array<string, list<string>>  $records  name => the TXT strings published there
     */
    public function __construct(private array $records = []) {}

    /**
     * @return list<string>|null
     */
    public function txt(string $name): ?array
    {
        $this->queried[] = $name;

        if ($this->failing) {
            return null;
        }

        return $this->records[$name] ?? [];
    }

    /**
     * @param  list<string>  $values
     */
    public function publish(string $name, array $values): void
    {
        $this->records[$name] = $values;
    }

    public function clear(): void
    {
        $this->records = [];
        $this->queried = [];
    }
}
