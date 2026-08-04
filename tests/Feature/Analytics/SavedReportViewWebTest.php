<?php

declare(strict_types=1);

use App\Enums\AnalyticsAxis;
use App\Enums\PlanTier;
use App\Models\SavedReportView;
use App\Models\User;
use App\Services\Analytics\SavedReportViewService;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H24b2 — saved report views written from the WEB surface (ADR-0011 §D8).
|
| SavedReportViewTest already pins the service. What is new here is the surface: redirect-plus-flash rather
| than JSON, a ValidationException rendered as a session error rather than a 422 body, and — the one that
| matters most — a stale definition rendering as a STATED REFUSAL on a page that still returns 200.
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

function viewsUrl(string $suffix = ''): string
{
    return 'http://acme.meridian.test/analytics/views'.$suffix;
}

/** @param array<string, mixed> $overrides */
function seedView(User $owner, string $name, array $overrides = []): SavedReportView
{
    return app(SavedReportViewService::class)->create($owner, $name, [
        ...(new AnalyticsQuery(
            from: CarbonImmutable::now()->subDays(9),
            to: CarbonImmutable::now(),
            axis: AnalyticsAxis::Source,
        ))->toArray(),
        ...$overrides,
    ]);
}

it('creates a saved view from the CURRENT declaration and redirects with a toast', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->post(viewsUrl(), [
            'name' => 'Field team',
            'from' => CarbonImmutable::now()->subDays(6)->toDateString(),
            'to' => CarbonImmutable::now()->toDateString(),
            'axis' => 'source',
            'granularity' => 'week',
            'top_n' => 3,
        ])
        ->assertRedirect()
        ->assertSessionHas('toast');

    enterTenant($this->tenant->id, $this->owner->id);
    $view = SavedReportView::query()->firstOrFail();

    // The client never builds a `definition`: it posts the same flat params the page round-trips, and the
    // VO stamps the shape. That is what keeps `v` server-authored.
    expect($view->definition['v'])->toBe(AnalyticsQuery::SCHEMA_VERSION);
    expect($view->definition['axis'])->toBe('source');
    expect($view->definition['granularity'])->toBe('week');
    expect($view->definition['top_n'])->toBe(3);
});

it('refuses a duplicate name with a field error rather than a 500', function (): void {
    // SQLSTATE 23505 → ValidationException in the service. On THIS surface that renders as a redirect with
    // a session error on `name`, not as the JSON 422 the API twin asserts — which is why the same exception
    // needs a test on both surfaces.
    $this->withoutVite();
    seedView($this->owner, 'Field team');

    $this->actingAs($this->owner)
        ->post(viewsUrl(), ['name' => 'Field team'])
        ->assertRedirect()
        ->assertSessionHasErrors('name');
});

it('renames a saved view WITHOUT rewriting the declaration it stores', function (): void {
    // The trap SaveReportViewRequest::definitionOrNull() exists to close. toQuery() defaults an absent
    // range to the rolling window, so a rename that sent a definition would silently move a saved report's
    // dates to the last thirty days, in front of nobody.
    $this->withoutVite();
    $view = seedView($this->owner, 'Q1 field team');
    $before = $view->definition;

    $this->actingAs($this->owner)
        ->patch(viewsUrl("/{$view->id}"), ['name' => 'Q1 field team (revised)'])
        ->assertRedirect()
        ->assertSessionHas('toast');

    enterTenant($this->tenant->id, $this->owner->id);
    $view->refresh();

    expect($view->name)->toBe('Q1 field team (revised)');
    // toEqual, not toBe: a JSONB round-trip preserves the keys and values but not their insertion order,
    // and the claim here is that nothing CHANGED, not that PostgreSQL kept a hash order.
    expect($view->definition)->toEqual($before);
});

it('updates the declaration when filter params ARE submitted', function (): void {
    $this->withoutVite();
    $view = seedView($this->owner, 'Field team');

    $this->actingAs($this->owner)
        ->patch(viewsUrl("/{$view->id}"), ['axis' => 'status', 'top_n' => 7])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->owner->id);

    expect($view->refresh()->definition['axis'])->toBe('status');
    expect($view->definition['top_n'])->toBe(7);
});

it('deletes a saved view', function (): void {
    $this->withoutVite();
    $view = seedView($this->owner, 'Field team');

    $this->actingAs($this->owner)
        ->delete(viewsUrl("/{$view->id}"))
        ->assertRedirect()
        ->assertSessionHas('toast');

    enterTenant($this->tenant->id, $this->owner->id);

    expect(SavedReportView::query()->count())->toBe(0);
});

it('403s another user’s saved view on EVERY write verb', function (): void {
    // §D8's per-user privacy is an APPLICATION predicate. `saved_report_views` carries `strict` RLS, which
    // scopes to the tenant only — so RLS hands a co-tenant the row and only SavedReportViewPolicy::owns()
    // refuses. One assertion per verb, because the gate is declared per route.
    $this->withoutVite();
    $view = seedView($this->owner, 'Private');

    $colleague = User::factory()->create();
    enterTenant($this->tenant->id, $colleague->id);
    makeActiveMember($colleague, 'owner'); // a fellow OWNER: there is no admin override on a saved view

    $this->actingAs($colleague)->patch(viewsUrl("/{$view->id}"), ['name' => 'Theirs'])->assertForbidden();
    $this->actingAs($colleague)->delete(viewsUrl("/{$view->id}"))->assertForbidden();

    enterTenant($this->tenant->id, $this->owner->id);
    expect($view->refresh()->name)->toBe('Private');
});

it('lists only the acting user’s views on the page prop', function (): void {
    $this->withoutVite();
    seedView($this->owner, 'Mine');

    $colleague = User::factory()->create();
    enterTenant($this->tenant->id, $colleague->id);
    makeActiveMember($colleague, 'owner');
    seedView($colleague, 'Theirs');

    enterTenant($this->tenant->id, $this->owner->id);

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('views', 1)
            ->where('views.0.name', 'Mine'));
});

it('renders a stale definition as a stated refusal beside the working ones, never as a wrong number', function (): void {
    // THE §D8 test. Written DIRECTLY rather than through the service, because the service refuses it — a
    // `v: 99` definition can only ever arrive from an older build, which is exactly the case read-time
    // resolution exists for. A happy-path suite structurally cannot reach this state.
    $this->withoutVite();
    seedView($this->owner, 'Working');

    SavedReportView::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->owner->id,
        'name' => 'Legacy rollup',
        'definition' => ['v' => 99, 'from' => '2026-01-01', 'to' => '2026-01-31'],
    ]);

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/analytics')
        ->assertOk()
        ->assertInertia(function ($page) {
            $views = collect($page->toArray()['props']['views'])->keyBy('name');

            expect($views['Working']['refused'])->toBeFalse();
            expect($views['Legacy rollup']['refused'])->toBeTrue();
            expect($views['Legacy rollup']['reason'])->toBe('malformed_definition');
            expect($views['Legacy rollup']['message'])->toBeString()->not->toBeEmpty();
        });
});

it('refuses a view whose scope node has been deleted — the rot resolve() cannot see', function (): void {
    // SavedReportViewService::resolve() re-parses SHAPE only; `scope_node_id` is a plain string there and
    // its existence is never checked. Without the presenter's batched reference check this view lists as
    // healthy and 404s the whole page when applied.
    $this->withoutVite();
    $node = makeScopeNode(null, 'Region I');
    seedView($this->owner, 'By area', ['selection' => 'scope_node', 'scope_node_id' => (string) $node->id]);
    $node->forceDelete();

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('views.0.refused', true)
            ->where('views.0.reason', 'scope_node_missing'));
});

it('discloses a dead form id without refusing the view, because it still narrows honestly', function (): void {
    $this->withoutVite();
    $form = publishedInboxForm($this->tenant, $this->owner, 'Doomed');
    $formId = (string) $form->id;
    seedView($this->owner, 'By form', ['selection' => 'forms', 'form_ids' => [$formId]]);
    $form->forceDelete();

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // A `forms` selection with dead ids narrows to an honest zero — a disclosure, not a refusal.
            ->where('views.0.refused', false)
            ->where('views.0.stale_form_ids', [$formId]));
});

it('marks the view whose declaration is currently applied', function (): void {
    $this->withoutVite();
    $view = seedView($this->owner, 'Field team');

    $params = $view->definition;
    unset($params['v']); // the client never echoes it — the server stamps it

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/analytics?'.http_build_query(array_filter(
            $params,
            fn (mixed $value): bool => $value !== null && $value !== [],
        )))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('views.0.is_applied', true));

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('views.0.is_applied', false));
});
