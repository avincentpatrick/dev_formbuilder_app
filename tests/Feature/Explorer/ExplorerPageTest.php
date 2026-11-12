<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\PlanTier;
use App\Enums\ResourceCapacity;
use App\Enums\SubmissionStatus;
use App\Models\ExplorerChart;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Explorer\ExplorerCatalog;
use App\Services\Explorer\Sources\ExplorerSource;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M236 — the data explorer's pages and JSON reads (ADR-0025 §D3, §D5).
|
| ExplorerQueryRunnerTest pins what a chart computes. This file pins the surface: who gets through the
| door, which data sources each role is then offered, and that a declaration which cannot be run reaches the
| page as a sentence in a 200 rather than as an error.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    assignPlanTier(PlanTier::Business);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function exploreUrl(string $suffix = ''): string
{
    return 'http://acme.meridian.test/explore'.$suffix;
}

function exploreMember(string $role): User
{
    $user = User::factory()->create();
    makeActiveMember($user, $role);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function exploreBody(array $overrides = []): array
{
    return ['definition' => [
        'source' => 'responses',
        'chart' => 'bar',
        'dimensions' => [['field' => 'status']],
        'measures' => [['aggregate' => 'count']],
        'filters' => [],
        'from' => now('UTC')->subDays(29)->toDateString(),
        'to' => now('UTC')->toDateString(),
        'timezone' => 'UTC',
        ...$overrides,
    ]];
}

/** @return list<string> */
function exploreSourcesFor(User $user): array
{
    app(EntitlementService::class)->forget();

    return array_map(
        static fn (ExplorerSource $source): string => $source->key(),
        app(ExplorerCatalog::class)->sourcesFor($user),
    );
}

// ── The door ─────────────────────────────────────────────────────────────────────────────────────────────

it('opens the board and the builder for every seeded role', function (string $role): void {
    $this->withoutVite();
    $member = $role === 'owner' ? $this->owner : exploreMember($role);

    $this->actingAs($member)->get(exploreUrl())
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            // The `false` is mandatory: Inertia's default page path is lower-case `js/pages`, which does not
            // exist on a case-sensitive file system, so the existence check would fail in CI and pass on Windows.
            ->component('explore/Index', false)
            ->where('charts', [])
            ->where('can.create', true)
            ->where('limits.max_charts', 50));

    $this->actingAs($member)->get(exploreUrl('/new'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('explore/Chart', false)->where('chart', null));
})->with(['owner', 'admin', 'form_editor', 'reviewer', 'viewer']);

it('403s a member holding neither dashboard permission, on every route', function (): void {
    // Every seeded role holds `dashboard.form.view`, so a role-less member is the only construction that
    // exercises the `can:` arm at all. Without this case that middleware could be deleted unnoticed.
    $this->withoutVite();

    $nobody = exploreMember('viewer');
    $nobody->syncRoles([]);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $chart = ExplorerChart::factory()->shared()->create(['user_id' => $this->owner->id]);

    $this->actingAs($nobody)->get(exploreUrl())->assertForbidden();
    $this->actingAs($nobody)->get(exploreUrl('/new'))->assertForbidden();
    $this->actingAs($nobody)->getJson(exploreUrl('/fields?source=responses'))->assertForbidden();
    $this->actingAs($nobody)->postJson(exploreUrl('/query'), exploreBody())->assertForbidden();
    $this->actingAs($nobody)->get(exploreUrl("/charts/{$chart->id}"))->assertForbidden();
    $this->actingAs($nobody)->getJson(exploreUrl("/charts/{$chart->id}/result"))->assertForbidden();
    $this->actingAs($nobody)->post(exploreUrl('/charts'), ['name' => 'x', ...exploreBody()])->assertForbidden();
});

it('sends a signed-out visitor to sign in', function (): void {
    $this->get(exploreUrl())->assertRedirect();
    $this->postJson(exploreUrl('/query'), exploreBody())->assertUnauthorized();
});

it('is behind the advanced-analytics plan key on every route: a page bounces with a toast, a fetch reads a 402', function (): void {
    // An ENTITLEMENT assertion, not an authorization one: the caller is an Owner, who passes every `can:`
    // gate on the way to the refusal.
    $this->withoutVite();
    $chart = ExplorerChart::factory()->create(['user_id' => $this->owner->id]);
    assignPlanTier(PlanTier::Professional);

    foreach (['', '/new', "/charts/{$chart->id}"] as $path) {
        $this->actingAs($this->owner)->get(exploreUrl($path))->assertRedirect()->assertSessionHas('toast');
    }

    $this->actingAs($this->owner)->getJson(exploreUrl('/fields?source=responses'))->assertStatus(402);
    $this->actingAs($this->owner)->postJson(exploreUrl('/query'), exploreBody())->assertStatus(402);
    $this->actingAs($this->owner)->getJson(exploreUrl("/charts/{$chart->id}/result"))->assertStatus(402);
    $this->actingAs($this->owner)->postJson(exploreUrl('/charts'), ['name' => 'x', ...exploreBody()])->assertStatus(402);
    $this->actingAs($this->owner)->patchJson(exploreUrl("/charts/{$chart->id}"), ['name' => 'y'])->assertStatus(402);
    $this->actingAs($this->owner)->deleteJson(exploreUrl("/charts/{$chart->id}"))->assertStatus(402);

    enterTenant($this->tenant->id, $this->owner->id);
    expect(ExplorerChart::query()->count())->toBe(1);
});

it('is hidden with the Advanced analytics module switch as well as by the plan', function (): void {
    $this->actingAs($this->owner)->postJson(exploreUrl('/query'), exploreBody())->assertOk();

    enterTenant($this->tenant->id, $this->owner->id);
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.advanced_analytics' => false], $this->owner);
    app(EntitlementService::class)->forget();

    $this->actingAs($this->owner)->postJson(exploreUrl('/query'), exploreBody())->assertStatus(402);
});

// ── Which sources a role is offered ──────────────────────────────────────────────────────────────────────

it('offers each role exactly the data sources whose own pages it can open', function (string $role, array $sources): void {
    $member = $role === 'owner' ? $this->owner : exploreMember($role);

    expect(exploreSourcesFor($member))->toBe($sources);
})->with([
    'an Owner: everything' => ['owner', ['responses', 'answers', 'forms', 'members', 'activity', 'deliveries', 'points', 'feedback']],
    'an Admin: everything' => ['admin', ['responses', 'answers', 'forms', 'members', 'activity', 'deliveries', 'points', 'feedback']],
    // A Viewer reads org-wide numbers and the leaderboard; not the roster, the audit trail or deliveries.
    'a Viewer' => ['viewer', ['responses', 'answers', 'forms', 'points']],
    // An Editor and a Reviewer are grant-scoped: their own forms' responses and answers, nothing workspace-wide.
    'a Form Editor' => ['form_editor', ['responses', 'answers', 'forms']],
    'a Reviewer' => ['reviewer', ['responses', 'answers', 'forms']],
]);

it('offers deliveries only for the half a member could open a page for', function (): void {
    $webhooksOnly = memberHoldingOnly('dashboard.form.view', 'webhooks.manage');
    $neither = memberHoldingOnly('dashboard.form.view');

    expect(exploreSourcesFor($webhooksOnly))->toContain('deliveries')
        ->and(exploreSourcesFor($neither))->not->toContain('deliveries');

    // On a plan without webhooks or connectors there is no page to open, so there is no source either.
    assignPlanTier(PlanTier::Free);
    expect(exploreSourcesFor($this->owner))->not->toContain('deliveries');
});

it('refuses a source by name to a member who is not offered it, in the body of a 200', function (): void {
    $viewer = exploreMember('viewer');

    foreach (['activity', 'members', 'feedback', 'deliveries'] as $source) {
        $this->actingAs($viewer)
            ->postJson(exploreUrl('/query'), exploreBody(['source' => $source, 'dimensions' => [['field' => 'status']]]))
            ->assertOk()
            ->assertJsonPath('result', null)
            ->assertJsonPath('refusal.reason', 'source_forbidden');

        $this->actingAs($viewer)
            ->getJson(exploreUrl("/fields?source={$source}"))
            ->assertStatus(422)
            ->assertJsonPath('reason', 'source_forbidden');

        enterTenant($this->tenant->id, $viewer->id);
    }
});

// ── The builder's props and the three reads ──────────────────────────────────────────────────────────────

it('gives the builder its sources with their fields, and never a field\'s SQL', function (): void {
    $this->withoutVite();

    $response = $this->actingAs($this->owner)->get(exploreUrl('/new'))->assertOk();

    $response->assertInertia(fn (Assert $page) => $page
        ->component('explore/Chart', false)
        ->has('sources', 8)
        ->where('sources.0.key', 'responses')
        ->where('sources.0.range_field', 'submitted_at')
        ->where('sources.1.key', 'answers')
        ->where('sources.1.requires_form', true)
        ->has('sources.0.fields.0', fn (Assert $field) => $field
            ->where('key', 'form')
            ->where('label', 'Form')
            ->where('type', 'category')
            ->where('group', 'Response')
            ->where('options', []))
        ->has('options.charts', 8)
        ->has('options.aggregates', 7)
        ->has('options.buckets', 7)
        ->where('options.limits.max_range_days', 1096)
        ->has('options.timezones'));

    expect(json_encode($response->viewData('page')))->not->toContain('submissions.')
        ->not->toContain('submission_answers');
});

it('lists the forms a member may chart the answers of: reachable and published, never a draft', function (): void {
    $this->withoutVite();

    $published = publishedInboxForm($this->tenant, $this->owner, 'Published');
    app(FormService::class)->create($this->tenant, $this->owner, 'Still a draft');
    $unreachable = publishedInboxForm($this->tenant, $this->owner, 'Not granted');

    $reviewer = exploreMember('reviewer');
    makeCollaborator($published, $reviewer, ResourceCapacity::Reviewer);

    $this->actingAs($this->owner)->get(exploreUrl('/new'))
        ->assertInertia(fn (Assert $page) => $page->where('forms', [
            ['value' => $unreachable->id, 'label' => 'Not granted'],
            ['value' => $published->id, 'label' => 'Published'],
        ]));

    $this->actingAs($reviewer)->get(exploreUrl('/new'))
        ->assertInertia(fn (Assert $page) => $page->where('forms', [
            ['value' => $published->id, 'label' => 'Published'],
        ]));
});

it('returns a form\'s questions as fields, with its choices and what was left out', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Intake');
    addFormField($form->draftVersion, $this->owner, 'color', FieldType::SingleSelect, 0, ['label' => 'Favourite colour', 'config' => ['options' => [
        ['value' => 'r', 'label' => 'Red'], ['value' => 'b', 'label' => 'Blue'],
    ]]]);
    addFormField($form->draftVersion, $this->owner, 'age', FieldType::Integer, 1);
    addFormField($form->draftVersion, $this->owner, 'full_name', FieldType::ShortText, 2, ['is_pii' => true]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);

    $response = $this->actingAs($this->owner)
        ->getJson(exploreUrl("/fields?source=answers&form_id={$form->id}"))
        ->assertOk();

    $questions = collect($response->json('fields'))->where('group', 'Questions')->values()->all();

    expect($questions)->toBe([
        ['key' => 'answer:color', 'label' => 'Favourite colour', 'type' => 'category', 'group' => 'Questions', 'options' => [
            ['value' => 'r', 'label' => 'Red'], ['value' => 'b', 'label' => 'Blue'],
        ]],
        ['key' => 'answer:age', 'label' => 'Age', 'type' => 'number', 'group' => 'Questions', 'options' => null],
    ])->and($response->json('notes'))->toBe(['1 question marked personal or sensitive is not offered here.']);
});

it('answers a fields request it cannot serve with a sentence and a reason', function (string $query, string $reason): void {
    $this->actingAs($this->owner)
        ->getJson(exploreUrl('/fields?'.$query))
        ->assertStatus(422)
        ->assertJsonPath('reason', $reason);
})->with([
    'no source' => ['', 'unknown_source'],
    'a source that does not exist' => ['source=users', 'unknown_source'],
    'answers with no form' => ['source=answers', 'form_required'],
    'answers of a form that does not exist' => ['source=answers&form_id=019a0000-0000-7000-8000-000000000000', 'form_unavailable'],
    'answers of something that is not an id' => ['source=answers&form_id=nope', 'form_unavailable'],
]);

it('runs a declaration and returns the result the page draws', function (): void {
    $form = publishedInboxForm($this->tenant, $this->owner);
    seedInboxSubmission($form, null, SubmissionStatus::Submitted, []);
    seedInboxSubmission($form, null, SubmissionStatus::Submitted, []);
    seedInboxSubmission($form, null, SubmissionStatus::Approved, []);

    $this->actingAs($this->owner)
        ->postJson(exploreUrl('/query'), exploreBody())
        ->assertOk()
        ->assertJsonPath('refusal', null)
        ->assertJsonPath('result.chart', 'bar')
        ->assertJsonPath('result.dimensions.0', ['key' => 'status', 'label' => 'Status', 'type' => 'category', 'bucket' => null])
        ->assertJsonPath('result.measures.0', ['key' => 'm0', 'label' => 'Count of responses', 'aggregate' => 'count', 'additive' => true])
        ->assertJsonPath('result.categories', [
            ['key' => 'submitted', 'label' => 'Submitted', 'other' => false],
            ['key' => 'approved', 'label' => 'Approved', 'other' => false],
        ])
        ->assertJsonPath('result.series.0.values', [2, 1])
        ->assertJsonPath('result.totals', ['rows' => 3, 'values' => [3], 'coverage' => [null]])
        ->assertJsonPath('result.meta.source_label', 'Responses')
        ->assertJsonPath('result.meta.range_label', 'Submitted')
        ->assertJsonPath('result.meta.capped', false);
});

it('answers a declaration it cannot run with a refusal in a 200, never an error status', function (array $overrides, string $reason): void {
    $this->actingAs($this->owner)
        ->postJson(exploreUrl('/query'), exploreBody($overrides))
        ->assertOk()
        ->assertJsonPath('result', null)
        ->assertJsonPath('refusal.reason', $reason);
})->with([
    'a range past the maximum' => [['from' => '2020-01-01'], 'range_too_long'],
    'a field that is not one' => [['dimensions' => [['field' => 'guest_ip']]], 'unknown_field'],
    'a source that does not exist' => [['source' => 'tenants'], 'unknown_source'],
    'a chart shape that cannot be drawn' => [['chart' => 'number'], 'chart_shape'],
    'a format this version does not read' => [['v' => 9], 'malformed_definition'],
]);

it('requires a declaration to be sent at all', function (): void {
    $this->actingAs($this->owner)
        ->postJson(exploreUrl('/query'), [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('definition');
});

it('throttles the one endpoint a reader can aim', function (): void {
    $route = Route::getRoutes()->getByName('explore.query');

    expect($route->gatherMiddleware())->toContain('throttle:120,1');
});

it('mounts every explorer route inside the tenant group, with the same pipeline as the analytics routes', function (): void {
    $routes = Route::getRoutes();
    $analytics = $routes->getByName('analytics.index')->gatherMiddleware();
    $shared = array_values(array_filter($analytics, static fn (string $m): bool => ! str_starts_with($m, 'can:') && ! str_starts_with($m, 'feature:')));

    $names = [
        'explore.index', 'explore.create', 'explore.fields', 'explore.query', 'explore.charts.store',
        'explore.charts.show', 'explore.charts.result', 'explore.charts.update', 'explore.charts.destroy',
    ];

    foreach ($names as $name) {
        $route = $routes->getByName($name);

        expect($route)->not->toBeNull("{$name} is not registered");

        $middleware = $route->gatherMiddleware();

        // A route file `require`d from the wrong place would carry no tenant context at all.
        expect(array_values(array_intersect($shared, $middleware)))->toBe($shared, "{$name} is missing part of the tenant pipeline")
            ->and($middleware)->toContain('feature:advanced_analytics');

        expect(array_filter($middleware, static fn (string $m): bool => str_starts_with($m, 'can:')))
            ->not->toBeEmpty("{$name} has no can: gate");
    }

    expect($shared)->not->toBeEmpty();
});
