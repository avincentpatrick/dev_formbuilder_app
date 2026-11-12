<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionStatus;
use App\Models\ExplorerChart;
use App\Models\User;
use App\Services\Explorer\ExplorerChartService;
use App\Support\Explorer\ExplorerQuery;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M236 — saved explorer charts (ADR-0025 §D8).
|
| `explorer_charts` is `strict` RLS, which scopes to the tenant only. Everything narrower than that — whose
| chart it is, who else may open it, who may change it — is the policy and one model scope, and this file
| is what holds them: a private chart stays private from an Owner, and a shared one stays read-only.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create(['name' => 'Olivia Owner']);
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    assignPlanTier(PlanTier::Business);

    $this->colleague = User::factory()->create(['name' => 'Raj Reviewer']);
    makeActiveMember($this->colleague, 'reviewer');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function chartsUrl(string $suffix = ''): string
{
    return 'http://acme.meridian.test/explore/charts'.$suffix;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function chartDefinition(array $overrides = []): array
{
    return [
        'source' => 'responses',
        'chart' => 'bar',
        'dimensions' => [['field' => 'status']],
        'measures' => [['aggregate' => 'count']],
        'filters' => [],
        'from' => '2026-01-01',
        'to' => '2026-03-31',
        'timezone' => 'Asia/Manila',
        ...$overrides,
    ];
}

/** Re-enter the tenant after a request, which tears the context down. */
function chartRows(object $test): Collection
{
    enterTenant($test->tenant->id, $test->owner->id);

    return ExplorerChart::query()->orderBy('name')->get();
}

// ── Saving ───────────────────────────────────────────────────────────────────────────────────────────────

it('saves a chart as the declaration the server wrote, and lands on the chart\'s own page', function (): void {
    $response = $this->actingAs($this->owner)->post(chartsUrl(), [
        'name' => '  Responses by status  ',
        'definition' => chartDefinition(),
    ]);

    $chart = chartRows($this)->sole();

    $response->assertRedirect(chartsUrl("/{$chart->id}"))->assertSessionHas('toast.type', 'success');

    expect($chart->name)->toBe('Responses by status')
        ->and($chart->user_id)->toBe($this->owner->id)
        ->and($chart->tenant_id)->toBe($this->tenant->id)
        ->and($chart->is_shared)->toBeFalse()
        // Every key the value object writes, including the ones the request left out. `toEqual`, because
        // jsonb hands an object's keys back in its own order and that order means nothing.
        ->and($chart->definition)->toEqual(ExplorerQuery::fromArray(chartDefinition())->toArray())
        ->and($chart->definition['v'])->toBe(1)
        ->and($chart->definition['limit'])->toBe(10);
});

it('does not store a key the declaration does not have, whatever the request carries', function (): void {
    $this->actingAs($this->owner)->post(chartsUrl(), [
        'name' => 'Smuggler',
        'definition' => chartDefinition(['sql' => 'select * from users', 'tenant_id' => 'x', 'v' => 1]),
    ])->assertRedirect();

    $keys = array_keys(chartRows($this)->sole()->definition);
    sort($keys);

    expect($keys)->toBe([
        'chart', 'dimensions', 'filters', 'form_id', 'from', 'limit', 'measures', 'sort', 'source', 'timezone', 'to', 'v',
    ]);
});

it('refuses to save a chart it could not run, as a field error and with nothing stored', function (array $overrides, string $fragment): void {
    $this->actingAs($this->owner)
        ->from('http://acme.meridian.test/explore/new')
        ->post(chartsUrl(), ['name' => 'Broken', 'definition' => chartDefinition($overrides)])
        ->assertRedirect('http://acme.meridian.test/explore/new')
        ->assertSessionHasErrors(['definition']);

    expect(session('errors')->first('definition'))->toContain($fragment)
        ->and(chartRows($this))->toHaveCount(0);
})->with([
    'a field of another source' => [['dimensions' => [['field' => 'event']]], 'not a field of this data source'],
    'a range past the maximum' => [['from' => '2020-01-01'], 'longer than'],
    'a source that does not exist' => [['source' => 'tenants'], 'no data source'],
    'a date with no period' => [['chart' => 'line', 'dimensions' => [['field' => 'submitted_at']]], 'Choose how to group'],
    'answers of a form that does not exist' => [['source' => 'answers', 'form_id' => '019a0000-0000-7000-8000-000000000000'], 'no longer exists'],
]);

it('refuses to save a chart over a source its author may not read', function (): void {
    // A Reviewer cannot open the audit trail, so a Reviewer cannot keep a chart of it either.
    $this->actingAs($this->colleague)
        ->from('http://acme.meridian.test/explore/new')
        ->post(chartsUrl(), [
            'name' => 'Audit',
            'definition' => chartDefinition(['source' => 'activity', 'dimensions' => [['field' => 'event']]]),
        ])
        ->assertSessionHasErrors(['definition']);

    expect(chartRows($this))->toHaveCount(0);
});

it('requires a name and a declaration on a new chart', function (): void {
    $this->actingAs($this->owner)->postJson(chartsUrl(), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'definition']);

    $this->actingAs($this->owner)->postJson(chartsUrl(), ['name' => str_repeat('x', 151), 'definition' => chartDefinition()])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('turns a name the member already used into a field error, and lets a colleague use the same name', function (): void {
    $body = ['name' => 'Weekly intake', 'definition' => chartDefinition()];

    $this->actingAs($this->owner)->post(chartsUrl(), $body)->assertSessionHasNoErrors();
    $this->actingAs($this->owner)->post(chartsUrl(), $body)->assertSessionHasErrors(['name']);
    $this->actingAs($this->colleague)->post(chartsUrl(), $body)->assertSessionHasNoErrors();

    expect(chartRows($this))->toHaveCount(2);
});

it('stops at the per-member limit with a sentence, not a five-hundred', function (): void {
    ExplorerChart::factory()->count(ExplorerChartService::MAX_CHARTS_PER_USER)->create(['user_id' => $this->owner->id]);

    $this->actingAs($this->owner)
        ->post(chartsUrl(), ['name' => 'One too many', 'definition' => chartDefinition()])
        ->assertSessionHasErrors(['name']);

    // The limit is per member: a colleague is not affected by the Owner's fifty.
    $this->actingAs($this->colleague)
        ->post(chartsUrl(), ['name' => 'Mine', 'definition' => chartDefinition()])
        ->assertSessionHasNoErrors();
});

// ── Changing ─────────────────────────────────────────────────────────────────────────────────────────────

it('renames and shares a chart without touching what it charts', function (): void {
    $chart = ExplorerChart::factory()->create(['user_id' => $this->owner->id, 'name' => 'Before']);
    $stored = $chart->definition;

    $this->actingAs($this->owner)
        ->patch(chartsUrl("/{$chart->id}"), ['name' => 'After', 'is_shared' => true])
        ->assertRedirect()
        ->assertSessionHas('toast.type', 'success');

    $fresh = chartRows($this)->sole();

    expect($fresh->name)->toBe('After')
        ->and($fresh->is_shared)->toBeTrue()
        ->and($fresh->definition)->toEqual($stored);
});

it('saves a changed declaration without touching the name or the sharing', function (): void {
    $chart = ExplorerChart::factory()->shared()->create(['user_id' => $this->owner->id, 'name' => 'Kept']);

    $this->actingAs($this->owner)
        ->patch(chartsUrl("/{$chart->id}"), ['definition' => chartDefinition(['chart' => 'donut', 'dimensions' => [['field' => 'source']]])])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $fresh = chartRows($this)->sole();

    expect($fresh->name)->toBe('Kept')
        ->and($fresh->is_shared)->toBeTrue()
        ->and($fresh->definition['chart'])->toBe('donut')
        ->and($fresh->definition['dimensions'])->toEqual([['field' => 'source', 'bucket' => null]]);
});

it('refuses a change that would leave the chart unable to run, and keeps the stored one', function (): void {
    $chart = ExplorerChart::factory()->create(['user_id' => $this->owner->id]);
    $stored = $chart->definition;

    $this->actingAs($this->owner)
        ->patch(chartsUrl("/{$chart->id}"), ['definition' => chartDefinition(['dimensions' => [['field' => 'guest_ip']]])])
        ->assertSessionHasErrors(['definition']);

    expect(chartRows($this)->sole()->definition)->toEqual($stored);
});

it('deletes a chart and returns to the board', function (): void {
    $chart = ExplorerChart::factory()->create(['user_id' => $this->owner->id]);

    $this->actingAs($this->owner)
        ->delete(chartsUrl("/{$chart->id}"))
        ->assertRedirect('http://acme.meridian.test/explore')
        ->assertSessionHas('toast.type', 'success');

    expect(chartRows($this))->toHaveCount(0);
});

// ── Whose chart ──────────────────────────────────────────────────────────────────────────────────────────

it('keeps a private chart private from everyone but its author, an Owner included', function (): void {
    $this->withoutVite();
    $private = ExplorerChart::factory()->create(['user_id' => $this->colleague->id, 'name' => 'Raj only']);

    // The Owner holds every permission there is. A chart is still its author's.
    $this->actingAs($this->owner)->get(chartsUrl("/{$private->id}"))->assertForbidden();
    $this->actingAs($this->owner)->getJson(chartsUrl("/{$private->id}/result"))->assertForbidden();
    $this->actingAs($this->owner)->patch(chartsUrl("/{$private->id}"), ['name' => 'Mine now'])->assertForbidden();
    $this->actingAs($this->owner)->delete(chartsUrl("/{$private->id}"))->assertForbidden();

    $this->actingAs($this->owner)->get('http://acme.meridian.test/explore')
        ->assertInertia(fn (Assert $page) => $page->where('charts', []));

    $this->actingAs($this->colleague)->get(chartsUrl("/{$private->id}"))->assertOk();

    expect(chartRows($this)->sole()->name)->toBe('Raj only');
});

it('lets a colleague open a shared chart and nothing more', function (): void {
    $this->withoutVite();
    $shared = ExplorerChart::factory()->shared()->create(['user_id' => $this->owner->id, 'name' => 'For everyone']);

    $this->actingAs($this->colleague)->get(chartsUrl("/{$shared->id}"))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('explore/Chart', false)
            ->where('chart.id', $shared->id)
            ->where('chart.name', 'For everyone')
            ->where('chart.is_mine', false)
            ->where('chart.is_shared', true)
            ->where('chart.owner', 'Olivia Owner')
            ->where('chart.can', ['update' => false, 'delete' => false])
            ->where('chart.source_label', 'Responses'));

    $this->actingAs($this->colleague)->patch(chartsUrl("/{$shared->id}"), ['name' => 'Renamed'])->assertForbidden();
    $this->actingAs($this->colleague)->patch(chartsUrl("/{$shared->id}"), ['is_shared' => false])->assertForbidden();
    $this->actingAs($this->colleague)->delete(chartsUrl("/{$shared->id}"))->assertForbidden();

    expect(chartRows($this)->sole()->name)->toBe('For everyone');
});

it('lists a member\'s own charts first and then the shared ones, each by name', function (): void {
    $this->withoutVite();

    ExplorerChart::factory()->create(['user_id' => $this->owner->id, 'name' => 'Zebra']);
    ExplorerChart::factory()->shared()->create(['user_id' => $this->owner->id, 'name' => 'Alpha']);
    ExplorerChart::factory()->shared()->create(['user_id' => $this->colleague->id, 'name' => 'Aardvark']);
    ExplorerChart::factory()->create(['user_id' => $this->colleague->id, 'name' => 'Hidden']);

    $this->actingAs($this->owner)->get('http://acme.meridian.test/explore')
        ->assertInertia(fn (Assert $page) => $page
            ->has('charts', 3)
            ->where('charts.0.name', 'Alpha')
            ->where('charts.0.is_mine', true)
            ->where('charts.0.owner', null)
            ->where('charts.0.can', ['update' => true, 'delete' => true])
            ->where('charts.1.name', 'Zebra')
            ->where('charts.2.name', 'Aardvark')
            ->where('charts.2.is_mine', false)
            ->where('charts.2.owner', 'Raj Reviewer')
            ->where('charts.2.can', ['update' => false, 'delete' => false]));
});

it('runs a shared chart as the member looking at it, never as its author', function (): void {
    // The Owner charts every form. The Reviewer holds a grant on one of the two.
    $granted = publishedInboxForm($this->tenant, $this->owner, 'Granted');
    $other = publishedInboxForm($this->tenant, $this->owner, 'Other');
    seedInboxSubmission($granted, null, SubmissionStatus::Submitted, []);
    seedInboxSubmission($other, null, SubmissionStatus::Submitted, []);
    seedInboxSubmission($other, null, SubmissionStatus::Submitted, []);
    makeCollaborator($granted, $this->colleague, ResourceCapacity::Reviewer);

    $shared = ExplorerChart::factory()->shared()->create([
        'user_id' => $this->owner->id,
        'definition' => ExplorerQuery::fromArray(chartDefinition([
            'dimensions' => [['field' => 'form']],
            'from' => now('UTC')->subDays(5)->toDateString(),
            'to' => now('UTC')->addDay()->toDateString(),
            'timezone' => 'UTC',
        ]))->toArray(),
    ]);

    $this->actingAs($this->owner)->getJson(chartsUrl("/{$shared->id}/result"))
        ->assertOk()
        ->assertJsonPath('result.totals.rows', 3)
        ->assertJsonPath('result.categories.0.label', 'Other');

    $this->actingAs($this->colleague)->getJson(chartsUrl("/{$shared->id}/result"))
        ->assertOk()
        ->assertJsonPath('result.totals.rows', 1)
        ->assertJsonCount(1, 'result.categories')
        ->assertJsonPath('result.categories.0.label', 'Granted');
});

it('shows a colleague a refusal, not the data, when a chart is shared over a source they cannot read', function (): void {
    $audit = ExplorerChart::factory()->shared()->create([
        'user_id' => $this->owner->id,
        'definition' => ExplorerQuery::fromArray(chartDefinition(['source' => 'activity', 'dimensions' => [['field' => 'event']]]))->toArray(),
    ]);

    $this->actingAs($this->owner)->getJson(chartsUrl("/{$audit->id}/result"))
        ->assertOk()
        ->assertJsonPath('refusal', null);

    $this->actingAs($this->colleague)->getJson(chartsUrl("/{$audit->id}/result"))
        ->assertOk()
        ->assertJsonPath('result', null)
        ->assertJsonPath('refusal.reason', 'source_forbidden');
});

it('degrades a stored declaration it can no longer read to a stated refusal on a page that still opens', function (array $definition, string $reason): void {
    $this->withoutVite();

    $chart = ExplorerChart::factory()->create(['user_id' => $this->owner->id, 'definition' => $definition]);

    $this->actingAs($this->owner)->get(chartsUrl("/{$chart->id}"))->assertOk();

    $this->actingAs($this->owner)->getJson(chartsUrl("/{$chart->id}/result"))
        ->assertOk()
        ->assertJsonPath('result', null)
        ->assertJsonPath('refusal.reason', $reason);

    // And the board that lists it still loads.
    $this->actingAs($this->owner)->get('http://acme.meridian.test/explore')->assertOk();
})->with([
    'written in a format this version does not read' => [['v' => 7, 'source' => 'responses'], 'malformed_definition'],
    'emptied by hand' => [[], 'malformed_definition'],
    'naming a field that has since gone' => [chartDefinition(['dimensions' => [['field' => 'retired_field']]]), 'unknown_field'],
    'naming a form that has since been deleted' => [chartDefinition(['source' => 'answers', 'form_id' => '019a0000-0000-7000-8000-000000000000']), 'form_unavailable'],
]);

it('404s a chart from another workspace rather than admitting it exists', function (): void {
    $chart = ExplorerChart::factory()->shared()->create(['user_id' => $this->owner->id]);

    $elsewhere = inboxTenant('globex');
    $stranger = User::factory()->create();
    enterTenant($elsewhere->id, $stranger->id);
    makeActiveMember($stranger, 'owner');
    assignPlanTier(PlanTier::Business);

    $base = "http://globex.meridian.test/explore/charts/{$chart->id}";

    $this->actingAs($stranger)->get($base)->assertNotFound();
    $this->actingAs($stranger)->getJson("{$base}/result")->assertNotFound();
    $this->actingAs($stranger)->patch($base, ['name' => 'Taken'])->assertNotFound();
    $this->actingAs($stranger)->delete($base)->assertNotFound();

    expect(chartRows($this)->sole()->name)->toBe($chart->name);
});
