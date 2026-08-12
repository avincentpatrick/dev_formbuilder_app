<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Support\Branding\BrandRamp;
use App\Support\Branding\BrandRampGenerator;
use App\Support\Migrations\BrandRampRederivation;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/**
 * The effect test for JR1's engine-v2 re-derivation.
 *
 * **The migration's own green is worth nothing here and that is the entire reason this file exists.** A
 * `migrate:fresh` database has zero tenants at the moment `2026_08_12_000100` runs, so the migration
 * examines nothing, succeeds, and proves nothing whatsoever — the same vacuity `BrandingStorageTest` was
 * written about, and the reason {@see BrandRampRederivation} is a class rather than a closure in the
 * migration file.
 *
 * What is actually being guarded: a ramp left at v1 renders perfectly. `BrandRamp::fromArray()` does not
 * re-validate on read, so a missed row shows the tenant a normal-looking brand whose stored `measurements`
 * certify contrast against grounds the product no longer paints. There is no visual symptom to notice.
 */
uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
});

/** Write a ramp payload straight to the column, bypassing the service, at an arbitrary engine version. */
function storeRampAtVersion(Tenant $tenant, string $input, ?int $version): void
{
    $payload = (new BrandRampGenerator)->generate($input)->toArray();

    if ($version !== null) {
        $payload['engine_version'] = $version;
    }

    DB::table('tenants')
        ->where('id', $tenant->getKey())
        ->update(['primary_color' => $input, 'brand_ramp' => json_encode($payload, JSON_THROW_ON_ERROR)]);
}

/** @return array<string, mixed> */
function storedRamp(Tenant $tenant): array
{
    /** @var object{brand_ramp: string} $row */
    $row = DB::table('tenants')->where('id', $tenant->getKey())->first(['brand_ramp']);

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($row->brand_ramp, true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

it('re-derives a stale ramp to the current engine version, from the tenant\'s own input colour', function (): void {
    $tenant = inboxTenant();
    storeRampAtVersion($tenant, '#C0392B', 1);

    $counts = (new BrandRampRederivation)(DB::connection());

    $expected = (new BrandRampGenerator)->generate('#C0392B');
    $stored = storedRamp($tenant);

    expect($counts)->toBe(['rederived' => 1, 'skipped_current' => 0, 'skipped_no_input' => 0])
        ->and($stored['engine_version'])->toBe(BrandRampGenerator::VERSION)
        // The INPUT is what survives a version bump — the tenant still has the colour they chose.
        ->and($stored['input'])->toBe('#C0392B');

    // ⚠️ Compared role by role, NOT with `toBe($expected->tokens)`. Postgres `jsonb` does not preserve
    // key order (it sorts by key length then bytewise), so a strict identity assertion fails on a
    // reordered-but-identical payload and reports it as twelve wrong colours. Cost one run to learn.
    foreach (['light', 'dark'] as $theme) {
        foreach (BrandRamp::ROLES as $role) {
            expect($stored['tokens'][$theme][$role])->toBe($expected->tokens[$theme][$role], "{$theme}.{$role}");
        }
    }
});

it('re-measures the stored ratios rather than carrying the old ones across', function (): void {
    // The whole point of the bump. A pass that copied `measurements` forward would leave numbers measured
    // against #F3F4F1 / #0E1620 sitting under a v2 stamp — strictly worse than a v1 stamp, because it
    // would claim to have been checked.
    //
    // ⚠️ THE FIRST VERSION OF THIS TEST COULD NOT FAIL, and the reason generalises: it stored a ramp built
    // by the CURRENT engine and merely relabelled it `engine_version: 1`, so the "old" measurements were
    // already the new ones and "did they change?" was always false. Genuine v1 output cannot be produced —
    // the v1 grounds do not exist in the tree any more. So the stored ratios are deliberately CORRUPTED to
    // a value the engine cannot produce, and the assertion is that they come back correct.
    $tenant = inboxTenant();
    storeRampAtVersion($tenant, '#C0392B', 1);

    $payload = storedRamp($tenant);
    $payload['measurements'] = array_map(
        static fn (array $m): array => [...$m, 'ratio' => 1.0],
        $payload['measurements'],
    );
    DB::table('tenants')
        ->where('id', $tenant->getKey())
        ->update(['brand_ramp' => json_encode($payload, JSON_THROW_ON_ERROR)]);

    (new BrandRampRederivation)(DB::connection());

    $after = storedRamp($tenant)['measurements'];
    $expected = (new BrandRampGenerator)->generate('#C0392B')->toArray()['measurements'];

    // Compared COLUMN by column for the jsonb key-order reason noted in the test above — but element
    // order is preserved deliberately, because the seventeen pairings are an ordered correspondence with
    // DSR §4.1 and a sorted comparison would stop noticing if they were shuffled.
    foreach (['theme', 'pairing', 'ratio'] as $column) {
        expect(array_column($after, $column))->toBe(array_column($expected, $column), $column);
    }

    // `min` is cast because the NON_TEXT_MIN floor is the float 3.0 and JSON has one number type:
    // `json_encode(3.0)` emits `3`, which decodes to an int. Strict identity therefore reports a
    // round-tripped 3.0 as wrong. A property of the storage, not of the ramp — so it is normalised here
    // rather than papered over by loosening every column above.
    expect(array_map('floatval', array_column($after, 'min')))
        ->toBe(array_map('floatval', array_column($expected, 'min')));

    expect(array_column($after, 'ratio'))->not->toContain(1.0);
});

it('is idempotent — a second pass skips every row it already re-derived', function (): void {
    $tenant = inboxTenant();
    storeRampAtVersion($tenant, '#2E8B57', 1);

    (new BrandRampRederivation)(DB::connection());
    $first = storedRamp($tenant);

    $counts = (new BrandRampRederivation)(DB::connection());

    expect($counts)->toBe(['rederived' => 0, 'skipped_current' => 1, 'skipped_no_input' => 0])
        ->and(storedRamp($tenant))->toBe($first);
});

it('leaves a ramp with no input colour alone, and COUNTS it rather than swallowing it', function (): void {
    // An inconsistent row (ramp without input) predates this migration and cannot be re-derived. Deleting a
    // tenant's brand because its row was already odd is a worse outcome than a stale ramp — but a silent
    // skip is how "we skipped one" becomes "we skipped all of them", so the postcondition counts it.
    $tenant = inboxTenant();
    storeRampAtVersion($tenant, '#C0392B', 1);
    DB::table('tenants')->where('id', $tenant->getKey())->update(['primary_color' => null]);

    $counts = (new BrandRampRederivation)(DB::connection());

    expect($counts)->toBe(['rederived' => 0, 'skipped_current' => 0, 'skipped_no_input' => 1])
        ->and(storedRamp($tenant)['engine_version'])->toBe(1);
});

it('touches nothing on an unbranded tenant', function (): void {
    $tenant = inboxTenant();

    $counts = (new BrandRampRederivation)(DB::connection());

    expect($counts)->toBe(['rederived' => 0, 'skipped_current' => 0, 'skipped_no_input' => 0])
        ->and(DB::table('tenants')->where('id', $tenant->getKey())->value('brand_ramp'))->toBeNull();
});

it('re-derives across chunk boundaries and reconciles its own count with the database', function (): void {
    // The postcondition's arithmetic, exercised with a mixed population: two derivable rows, one not.
    // The guard is `stale === skipped_no_input`, so this is the shape that would expose an off-by-one in
    // either the counter or the query — a pass that quietly did nothing would report 0 re-derived here.
    //
    // ⚠️ The THROWING branch of assertNoneStale() is deliberately not exercised, because it is not
    // reachable from a consistent database: every row with a `brand_ramp` is visited, and a visited row is
    // either re-derived or counted. It is defence in depth against a future edit to the query, not a
    // behaviour with a caller — asserting it would take mocking the connection, which would test the mock.
    $derivable = inboxTenant('alpha');
    $alsoDerivable = inboxTenant('beta');
    $orphaned = inboxTenant('gamma');

    storeRampAtVersion($derivable, '#C0392B', 1);
    storeRampAtVersion($alsoDerivable, '#2E8B57', 1);
    storeRampAtVersion($orphaned, '#7B2FF7', 1);
    DB::table('tenants')->where('id', $orphaned->getKey())->update(['primary_color' => null]);

    $counts = (new BrandRampRederivation)(DB::connection());

    expect($counts)->toBe(['rederived' => 2, 'skipped_current' => 0, 'skipped_no_input' => 1])
        ->and(storedRamp($derivable)['engine_version'])->toBe(BrandRampGenerator::VERSION)
        ->and(storedRamp($alsoDerivable)['engine_version'])->toBe(BrandRampGenerator::VERSION)
        ->and(storedRamp($orphaned)['engine_version'])->toBe(1);
});
