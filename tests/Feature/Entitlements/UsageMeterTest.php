<?php

declare(strict_types=1);

use App\Enums\UsageMetric;
use App\Models\User;
use App\Services\Entitlements\UsageMeter;
use App\Services\Submissions\SubmissionExporter;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// UsageMeter: the flow-metering upsert (ADR-0008 §D8). One current-period row per (tenant, metric),
// accumulated additively via Postgres ON CONFLICT so concurrent increments cannot 23505.

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    $this->meter = app(UsageMeter::class);
});

it('creates one current-period row and accumulates additively', function (): void {
    $this->meter->increment(UsageMetric::SubmissionsCount);
    $this->meter->increment(UsageMetric::SubmissionsCount, 5);

    $rows = DB::table('usage_counters')->where('metric', 'submissions_count')->get();

    expect($rows)->toHaveCount(1);
    expect((int) $rows[0]->value)->toBe(6)
        ->and($rows[0]->period_start)->toBe(now()->startOfMonth()->toDateString())
        ->and($rows[0]->period_end)->toBe(now()->endOfMonth()->toDateString())
        ->and($rows[0]->last_incremented_at)->not->toBeNull();
});

it('meters different flow metrics onto separate rows', function (): void {
    $this->meter->increment(UsageMetric::ApiRequests, 3);
    $this->meter->increment(UsageMetric::ExportsCount);

    expect((int) DB::table('usage_counters')->where('metric', 'api_requests')->value('value'))->toBe(3)
        ->and((int) DB::table('usage_counters')->where('metric', 'exports_count')->value('value'))->toBe(1);
});

it('is a no-op off-tenant', function (): void {
    TenantContext::flush();
    $this->meter->increment(UsageMetric::ApiRequests);

    enterTenant($this->tenant->id); // re-enter to read
    expect(DB::table('usage_counters')->count())->toBe(0);
});

it('meters exports_count when SubmissionExporter::stream is called', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);

    app(SubmissionExporter::class)->stream($form, [], 'csv'); // increment runs before the stream closure

    expect((int) DB::table('usage_counters')->where('metric', 'exports_count')->value('value'))->toBe(1);
});
