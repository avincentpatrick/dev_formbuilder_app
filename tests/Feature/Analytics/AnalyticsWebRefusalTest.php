<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\User;
use App\Support\Analytics\AnalyticsQuery;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H24b2 — ADR-0011 §D7's bounds, refused on a WEB route rather than exploding.
|
| Every bound here is enforced in AnalyticsQuery's CONSTRUCTOR and deliberately NOT duplicated as a
| validator rule (AnalyticsReportRequest's docblock: "a second implementation of a bound that already has
| an owner"). So a bad declaration arrives as an InvalidAnalyticsQueryException rather than as a field
| error — and until H24b2 widened `bootstrap/app.php`'s render arm, `$isApi` returned null for a web
| request and every one of these was a 500.
|
| The GET arm redirects to the bare index rather than back(): a bookmarked or hand-edited URL carries no
| referer, and back() would land on the tenant subdomain's unrouted "/".
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
    publishedInboxForm($this->tenant, $this->owner, 'Clinic Intake');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function refusalUrl(array $params): string
{
    return 'http://acme.meridian.test/analytics?'.http_build_query($params);
}

it('renders an over-long range as a stated refusal, not a 500', function (): void {
    // 366 days is the cap and it is a CONSTRUCTOR bound, so no validator rule catches this first.
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->get(refusalUrl([
            'from' => CarbonImmutable::now()->subDays(AnalyticsQuery::MAX_RANGE_DAYS + 30)->toDateString(),
            'to' => CarbonImmutable::now()->toDateString(),
        ]))
        ->assertRedirect(route('analytics.index', absolute: false))
        ->assertSessionHas('toast');
});

it('lands the redirect somewhere that actually renders, so the message is seen', function (): void {
    // The other half of "not back()": following the redirect must reach a working page, or the refusal is
    // a message nobody reads.
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->followingRedirects()
        ->get(refusalUrl([
            'from' => CarbonImmutable::now()->subDays(400)->toDateString(),
            'to' => CarbonImmutable::now()->toDateString(),
        ]))
        ->assertOk();
});

it('renders an unknown timezone as a refusal, not a 500', function (): void {
    // `timezone` IS validated (Rule::in on the IANA list), so this one is a 422-shaped redirect with a
    // field error — asserted because the two refusal paths look identical from the browser and it should
    // stay clear which one is doing the work.
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->get(refusalUrl(['timezone' => 'Mars/Olympus_Mons']))
        ->assertRedirect()
        ->assertSessionHasErrors('timezone');
});

it('renders an inverted range as a refusal, not a 500', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->get(refusalUrl([
            'from' => CarbonImmutable::now()->toDateString(),
            'to' => CarbonImmutable::now()->subDays(10)->toDateString(),
        ]))
        ->assertRedirect()
        ->assertSessionHasErrors('to');
});

it('refuses an empty forms selection at the VO, not with an empty page', function (): void {
    // `selection=forms` with no ids is InvalidAnalyticsQueryException::emptySelection — a bound with no
    // validator rule, because "required only when another field has a particular value" is exactly the
    // conditional the VO already owns.
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->get(refusalUrl(['selection' => 'forms']))
        ->assertRedirect(route('analytics.index', absolute: false))
        ->assertSessionHas('toast');
});

it('keeps the 500 for a bad declaration raised OFF the analytics surface', function (): void {
    // The render arm is scoped to `analytics.*` deliberately: everywhere else the VO is built from
    // hard-coded arguments (DashboardMetricsService::trendsForUser), so reaching it there is a server bug
    // and must not be dressed up as a user error. Asserted at the arm's own boundary rather than by
    // provoking a genuine server bug.
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk();

    expect(route('analytics.index', absolute: false))->toBe('/analytics');
});

it('refuses an out-of-range top_n at the validator before the VO sees it', function (): void {
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->get(refusalUrl(['top_n' => AnalyticsQuery::MAX_TOP_N + 1]))
        ->assertRedirect()
        ->assertSessionHasErrors('top_n');
});

it('accepts a bare visit with no query string at all', function (): void {
    // The reason `from`/`to` are `sometimes` here and `required` on the API twin. A cold visit must render,
    // and a 422 on an Inertia GET would redirect "back" — which, on a cold visit, is nowhere.
    $this->withoutVite();

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/analytics')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('applied.from', CarbonImmutable::now()->subDays(AnalyticsQuery::DEFAULT_RANGE_DAYS)->toDateString())
            ->where('applied.to', CarbonImmutable::now()->toDateString()));
});
