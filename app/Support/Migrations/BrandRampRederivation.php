<?php

declare(strict_types=1);

namespace App\Support\Migrations;

use App\Support\Branding\BrandRampGenerator;
use Illuminate\Database\ConnectionInterface;
use RuntimeException;

/**
 * Re-derives every stored `tenants.brand_ramp` under the current {@see BrandRampGenerator::VERSION} (JR1).
 *
 * This is the "re-derivation plan for stored ramps" that {@see BrandRampGenerator::VERSION}'s docblock — and
 * `BrandRampParityTest`'s — name as the price of an engine change. JR1 is the first one to pay it: the Vivid
 * re-skin moved the neutral ramp, four of the engine's six measurement grounds ARE neutral primitives, and a
 * ramp derived against the old grounds carries `measurements` that describe a canvas the product stopped
 * painting.
 *
 * Extracted from the migration so the body is assertable WITHOUT it — a `migrate:fresh` test database has no
 * tenants at migration time, so a test that only ran the migration would prove exactly nothing. The
 * {@see SubmissionReferenceBackfill} / {@see OcrCompatibilityBackfill} precedent, and the same reason.
 *
 * ── WHY IT RE-DERIVES RATHER THAN TRANSFORMS ─────────────────────────────────────────────────────────────
 * There is no mapping from a v1 ramp to a v2 one. The tokens are the OUTPUT of a lightness search against a
 * ground; change the ground and the answer is a different search, not a shifted result. What survives a
 * version bump is the tenant's INPUT — `tenants.primary_color`, the hex they actually chose — so that is what
 * this reads, and the engine does the rest. A tenant sees the colour they picked, re-measured honestly.
 *
 * ── `tenants` IS RLS-EXEMPT (ADR-0002 §D1), SO THE DEFAULT CONNECTION SEES EVERY ROW ─────────────────────
 * Unlike {@see SubmissionReferenceBackfill}, this needs no privileged connection: `tenants` carries no
 * `tenant_id` discriminator and no RLS policy, so `meridian_app` reads all of it with no GUC set. That is the
 * same reason `BrandPalette::forTenantId()` queries it directly from a queue worker.
 *
 * ── Idempotency ──────────────────────────────────────────────────────────────────────────────────────────
 * Re-running is a no-op by construction: a row whose stored `engine_version` already equals the generator's
 * is skipped, and re-deriving a row that was NOT skipped produces byte-identical output for the same input.
 * Recovery is fail-forward — run it again.
 */
final class BrandRampRederivation
{
    /** Chunked so a tenant table of any size never materialises in one array. */
    private const int CHUNK = 200;

    /**
     * @return array{rederived: int, skipped_current: int, skipped_no_input: int}
     */
    public function __invoke(ConnectionInterface $connection): array
    {
        $generator = new BrandRampGenerator;
        $counts = ['rederived' => 0, 'skipped_current' => 0, 'skipped_no_input' => 0];

        $connection->table('tenants')
            ->select(['id', 'primary_color', 'brand_ramp'])
            ->whereNotNull('brand_ramp')
            ->orderBy('id')
            ->chunkById(self::CHUNK, function (iterable $rows) use ($connection, $generator, &$counts): void {
                foreach ($rows as $row) {
                    /** @var object{id: string, primary_color: ?string, brand_ramp: ?string} $row */
                    $stored = json_decode((string) $row->brand_ramp, true);

                    if (is_array($stored) && ($stored['engine_version'] ?? null) === BrandRampGenerator::VERSION) {
                        $counts['skipped_current']++;

                        continue;
                    }

                    // A ramp with no input is already inconsistent — `TenantBrandingService` is the only
                    // writer of either column and writes both together. It is deliberately LEFT ALONE rather
                    // than repaired or deleted: there is nothing to re-derive from, and a migration that
                    // deletes a tenant's brand because its row was already odd is a worse outcome than a
                    // stale ramp. `BrandRamp::fromArray()` does not re-validate on read, so it keeps working.
                    if ($row->primary_color === null || $row->primary_color === '') {
                        $counts['skipped_no_input']++;

                        continue;
                    }

                    $ramp = $generator->generate($row->primary_color);

                    $connection->table('tenants')
                        ->where('id', $row->id)
                        ->update(['brand_ramp' => json_encode($ramp->toArray(), JSON_THROW_ON_ERROR)]);

                    $counts['rederived']++;
                }
            });

        $this->assertNoneStale($connection, $counts['skipped_no_input']);

        return $counts;
    }

    /**
     * Postcondition: no ramp is left at an older engine version except the ones with no input to re-derive
     * from — which are counted, so "we skipped some" can never quietly become "we skipped all of them".
     *
     * Asserted rather than trusted because the failure this guards is silent: `BrandRamp::fromArray()`
     * deliberately does not re-validate, so a ramp left at v1 renders perfectly well and merely certifies
     * ratios against grounds that no longer exist.
     *
     * ⚠️ The throwing branch is NOT reachable from a consistent database and its test says so rather than
     * contriving a mock: every row carrying a `brand_ramp` is visited, and a visited row is either
     * re-derived or counted. This is defence in depth against a future edit to the chunked query above —
     * the class of change that would otherwise silently narrow what the pass sees.
     */
    private function assertNoneStale(ConnectionInterface $connection, int $expectedStale): void
    {
        $stale = $connection->table('tenants')
            ->whereNotNull('brand_ramp')
            ->whereRaw("(brand_ramp->>'engine_version')::int <> ?", [BrandRampGenerator::VERSION])
            ->count();

        if ($stale !== $expectedStale) {
            throw new RuntimeException(
                "Brand-ramp re-derivation left {$stale} ramp(s) below engine version "
                .BrandRampGenerator::VERSION.", but only {$expectedStale} lacked an input colour."
            );
        }
    }
}
