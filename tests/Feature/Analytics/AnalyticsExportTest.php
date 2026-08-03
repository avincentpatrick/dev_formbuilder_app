<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| ADR-0011 §D13 -- the streamed analytics export.
|
| ASSERT ON ROWS, NEVER ON STATUS. The export closure fires during Response::send(), AFTER
| EstablishTenantDatabaseContext::terminate() has torn the tenant GUC down. Omitting the
| TenantContext::applyLocal() re-assertion inside the closure yields an EMPTY FILE WITH HTTP 200 -- which
| every status-only test passes. docs/testing-strategy.md:81 names this as one of three analytics
| obligations a happy-path suite structurally cannot cover.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    assignPlanTier(PlanTier::Business);

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Intake');
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-02 09:00', 'UTC'));
    seedCountableAt($this->form, CarbonImmutable::parse('2026-03-04 09:00', 'UTC'));
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function exportUrl(string $format = 'csv'): string
{
    return 'http://acme.meridian.test/api/v1/analytics/report/export?'.http_build_query([
        'from' => '2026-03-01', 'to' => '2026-03-07', 'format' => $format,
    ]);
}

function exportToken(): string
{
    return test()->owner->createToken('ci', [ApiAbilities::READ_ANALYTICS])->plainTextToken;
}

/** Parse openspout CSV, stripping its UTF-8 BOM. @return list<list<string>> */
function exportRows(string $csv): array
{
    $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);

    return array_values(array_filter(array_map(
        static fn (string $line): array => str_getcsv($line, escape: ''),
        explode("\n", trim($csv))
    )));
}

it('streams a NON-EMPTY body, which is the only assertion that catches the context teardown', function (): void {
    $response = $this->withToken(exportToken())->get(exportUrl());

    $response->assertOk();

    $rows = exportRows($response->streamedContent());

    // The header plus real data. A file that is header-only -- or zero bytes -- is what a missing
    // applyLocal() produces, and the status would still be 200.
    expect($rows[0])->toBe(['Section', 'Key', 'Value'])
        ->and(count($rows))->toBeGreaterThan(1);

    $flat = array_map(static fn (array $r): string => implode('|', $r), $rows);

    // The totals really carry the seeded submissions -- not an empty aggregate that happens to render rows.
    expect($flat)->toContain('Total|Current period|2')
        // One row per bucket across the whole range, zeros included (the series gap-fill).
        ->toContain('Series|2026-03-02|1')
        ->toContain('Series|2026-03-03|0')
        ->toContain('Series|2026-03-04|1');
});

it('meters the export BEFORE the stream, not on completion', function (): void {
    // §D13's first line. A streamed response cannot reliably increment on completion -- a client that
    // disconnects mid-body never finishes the callback -- so the counter must already be up before the body
    // is read. Asserting without touching streamedContent() is what proves the ordering.
    $this->withToken(exportToken())->get(exportUrl())->assertOk();

    enterTenant($this->tenant->id, $this->owner->id);

    expect(DB::table('usage_counters')->where('metric', UsageMetric::ExportsCount->value)->sum('value'))
        ->toBeGreaterThan(0);
});

it('exports the breakdown UN-COLLAPSED, including an explicit Unassigned bucket', function (): void {
    // §D11: the "Other (N)" bucket is a PLOTTING concession -- nothing is hidden, only un-plotted -- so the
    // export is where the full set lives. And §D6's Unassigned bucket is emitted even at zero, or the export
    // would not reconcile against its own totals.
    $rows = exportRows($this->withToken(exportToken())->get(exportUrl())->streamedContent());
    $flat = array_map(static fn (array $r): string => implode('|', $r), $rows);

    expect($flat)->toContain('Breakdown|Unassigned|0');

    $breakdownRows = array_values(array_filter($rows, static fn (array $r): bool => ($r[0] ?? '') === 'Breakdown'));
    $counts = array_sum(array_map(static fn (array $r): int => (int) ($r[2] ?? 0), $breakdownRows));

    // Reconciles against the total, which is the property the collapse must not break.
    expect($counts)->toBe(2);
});

it('exports suppression as suppression, never as a blank that reads like zero', function (): void {
    // §D5. A blank cell in a spreadsheet is indistinguishable from 0%, which is the exact failure the
    // suppression rule exists to prevent -- and it survives into the export or it does not hold.
    $this->tenant->forceFill(['draft_ttl_days' => 7])->save();

    $url = 'http://acme.meridian.test/api/v1/analytics/report/export?'.http_build_query([
        'from' => CarbonImmutable::now()->subDays(60)->toDateString(),
        'to' => CarbonImmutable::now()->toDateString(),
    ]);

    $rows = exportRows($this->withToken(exportToken())->get($url)->streamedContent());
    $flat = array_map(static fn (array $r): string => implode('|', $r), $rows);

    expect($flat)->toContain('Drafts|Conversion %|suppressed: beyond_draft_retention');
});

it('streams a valid XLSX that round-trips through the reader', function (): void {
    $response = $this->withToken(exportToken())->get(exportUrl('xlsx'));

    $response->assertOk();

    $path = tempnam(sys_get_temp_dir(), 'analytics').'.xlsx';
    file_put_contents($path, $response->streamedContent());

    $reader = new XlsxReader;
    $reader->open($path);

    $read = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $read[] = array_map(static fn ($c): string => (string) $c, $row->toArray());
        }
        break;
    }
    $reader->close();
    @unlink($path);

    expect($read[0])->toBe(['Section', 'Key', 'Value'])
        ->and(count($read))->toBeGreaterThan(1);
});
