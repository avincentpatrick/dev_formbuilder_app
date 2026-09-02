<?php

declare(strict_types=1);

use App\Enums\PlanTier;
use App\Models\SavedReportView;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Analytics\SavedReportViewService;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The /api/v1 analytics surface (ADR-0011 §D9, Increment H24a).
|
| REAL TOKENS via withToken(), never actingAs() -- the AuditApiTest/WebhookEndpointApiTest doctrine:
| actingAs() resolves a TransientToken that passes EVERY ability, which would make the two-layer
| ability-plus-policy check vacuous, and the whole point of read:analytics being a NEW ability is that a
| token minted without it holds nothing.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->owner = User::factory()->create();
    $this->viewer = User::factory()->create();

    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    makeActiveMember($this->viewer, 'viewer');

    // Business is the only tier carrying advanced_analytics. assignPlanTier() looks up by CODE, so it works
    // despite Business being seeded is_active:false (held from sale, ADR-0008 §D6).
    assignPlanTier(PlanTier::Business);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function analyticsUrl(string $suffix = 'report'): string
{
    return 'http://acme.meridian.test/api/v1/analytics/'.$suffix;
}

/** @return array<string, string> */
function reportParams(): array
{
    return ['from' => '2026-03-01', 'to' => '2026-03-07'];
}

function analyticsToken(?User $user = null, ?string $ability = null): string
{
    return ($user ?? test()->owner)
        ->createToken('ci', [$ability ?? ApiAbilities::READ_ANALYTICS])
        ->plainTextToken;
}

/*
|--------------------------------------------------------------------------
| The ability layer -- the whole reason read:analytics is NEW
*/

it('serves the report to a token carrying read:analytics', function (): void {
    $response = $this->withToken(analyticsToken())->getJson(analyticsUrl().'?'.http_build_query(reportParams()));

    $response->assertOk()
        ->assertJsonStructure(['data' => ['total', 'series', 'breakdown', 'drafts', 'forms_accepting'], 'meta' => ['query']])
        // The declaration comes back so a client can tell which query produced these numbers -- including
        // the timezone the buckets were computed in, which is never the server's session zone.
        ->assertJsonPath('meta.query.timezone', 'UTC')
        ->assertJsonPath('meta.week_starts_on', 'monday');
});

it('refuses a token that holds every OTHER ability but not read:analytics', function (): void {
    // THE test that proves the ability was not folded into an existing one. The user holds
    // dashboard.org.view, so the permission side passes -- only the token scope refuses.
    $abilities = array_values(array_diff(ApiAbilities::all(), [ApiAbilities::READ_ANALYTICS]));
    $token = $this->owner->createToken('ci', $abilities)->plainTextToken;

    $this->withToken($token)
        ->getJson(analyticsUrl().'?'.http_build_query(reportParams()))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_ability');
});

it('lets a Viewer read analytics, which is intended and worth pinning', function (): void {
    // A Viewer holds dashboard.org.view, so read:analytics is the broadest-ISSUABLE read ability in the
    // catalog. Intended -- a Viewer already sees every submission in the inbox -- and recorded here so a
    // later reader does not "fix" it.
    $this->withToken(analyticsToken($this->viewer))
        ->getJson(analyticsUrl().'?'.http_build_query(reportParams()))
        ->assertOk();
});

/*
|--------------------------------------------------------------------------
| The plan gate -- and the trap that makes it pass vacuously
*/

it('returns 402 naming the feature when the plan lacks advanced_analytics', function (): void {
    // assignPlanTier() is MANDATORY here, not scene-setting: RequireFeature FAILS OPEN when
    // currentPlan() is null, so without a seeded plan this test would pass while proving nothing.
    assignPlanTier(PlanTier::Professional);

    $response = $this->withToken(analyticsToken())
        ->getJson(analyticsUrl().'?'.http_build_query(reportParams()));

    $response->assertStatus(402)
        ->assertJsonPath('error.code', 'feature_not_available')
        ->assertJsonPath('error.details.feature', 'advanced_analytics');

    // The FeatureGateException arm ADR-0011 §D9 assigns to H24a by name: without it the message renders the
    // raw snake_case key at the user.
    expect($response->json('error.message'))
        ->toContain('advanced cross-form analytics')
        ->not->toContain('advanced_analytics');
});

/*
|--------------------------------------------------------------------------
| The bounds (§D7)
*/

it('refuses an unbounded or over-long range with a 422', function (): void {
    $token = analyticsToken();

    // No range at all -- there is deliberately no "all time".
    $this->withToken($token)->getJson(analyticsUrl())->assertStatus(422);

    // Over the 366-day maximum. Enforced by the VO rather than by a validator rule, so this also proves the
    // exception surfaces as a 422 rather than a 500.
    $this->withToken($token)
        ->getJson(analyticsUrl().'?'.http_build_query(['from' => '2026-01-01', 'to' => '2027-06-01']))
        ->assertStatus(422);

    // An unknown timezone never reaches date_trunc's zone argument.
    $this->withToken($token)
        ->getJson(analyticsUrl().'?'.http_build_query(reportParams() + ['timezone' => 'Mars/Olympus']))
        ->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Saved views -- where the policy gate is load-bearing
*/

it('creates, lists and deletes a saved view', function (): void {
    $token = analyticsToken();

    $created = $this->withToken($token)->postJson(analyticsUrl('views'), [
        'name' => 'Weekly',
        'definition' => ['v' => 1, 'from' => '2026-03-01', 'to' => '2026-03-07'],
    ]);

    $created->assertCreated()
        ->assertJsonPath('data.name', 'Weekly')
        // The declaration round-trips, and its resolution state rides alongside (§D8).
        ->assertJsonPath('data.resolution.refused', false);

    $id = $created->json('data.id');

    $this->withToken($token)->getJson(analyticsUrl('views'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->withToken($token)->deleteJson(analyticsUrl('views/'.$id))->assertNoContent();
});

it('refuses to serve one members saved view to another, because RLS does not scope by user', function (): void {
    // saved_report_views carries `strict` RLS, which scopes to the TENANT. The policy's owner check is the
    // ONLY thing standing between two colleagues, so this route test is not ceremony.
    // The view is seeded IN-PROCESS rather than over HTTP, and only ONE token is used for the whole test.
    // Two reasons, both learned the hard way here: a request tears the tenant GUC down, so minting a second
    // token afterwards hits personal_access_tokens' strict-RLS WITH CHECK with no context (the
    // WebhookEndpointApiTest gotcha); and — the subtler one — the auth guard CACHES its resolved user for
    // the app instance, so a second `withToken()` inside one test does not re-authenticate. Both tokens are
    // valid and point at the right users; the second request simply keeps acting as the first one's owner,
    // which turns an authorization test into a test that always passes.
    $view = app(SavedReportViewService::class)->create($this->owner, 'Private', [
        'v' => 1, 'from' => '2026-03-01', 'to' => '2026-03-07',
    ]);

    $this->withToken(analyticsToken($this->viewer))
        ->getJson(analyticsUrl('views/'.$view->id))
        ->assertForbidden();

    // ...and it is still there. The context re-entry is required, not decorative: the request above tore the
    // tenant GUC down on terminate, so an in-process query afterwards runs with no tenant and RLS hides
    // every row — which would read as "the delete succeeded" rather than "the row is invisible".
    enterTenant($this->tenant->id, $this->owner->id);

    expect(SavedReportView::query()->whereKey($view->id)->exists())->toBeTrue();
});

it('rejects a saved view whose declaration could never resolve', function (): void {
    $this->withToken(analyticsToken())->postJson(analyticsUrl('views'), [
        'name' => 'Broken',
        'definition' => ['v' => 1, 'from' => '2026-01-01', 'to' => '2029-01-01'],
    ])->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Questions
*/

it('lists reportable questions and aggregates one', function (): void {
    $token = analyticsToken();
    $query = http_build_query(reportParams());

    $this->withToken($token)->getJson(analyticsUrl('questions').'?'.$query)
        ->assertOk()
        ->assertJsonStructure(['data']);

    // An unindexed key is a stated refusal with coverage attached, not a 404 and not an empty chart.
    $this->withToken($token)->getJson(analyticsUrl('questions/nonexistent').'?'.$query)
        ->assertOk()
        ->assertJsonPath('data.refused', true)
        ->assertJsonPath('data.reason', 'not_indexed')
        ->assertJsonStructure(['data' => ['coverage' => ['indexed', 'countable']]]);
});

/*
|--------------------------------------------------------------------------
| The POLICY layer — which permission the `can:` gate actually names (Increment M63)
|--------------------------------------------------------------------------
| Until M63 this file had no policy-refusal case at all: its only two `error.code` assertions were
| `insufficient_ability` and `feature_not_available`, so the `can:viewAny,SavedReportView` arm on
| `GET /api/v1/analytics/report` was asserted by NOTHING. The export twin's arm was pinned by M34 and
| this one was filed rather than fixed, because a test aimed at the twin is not coverage of its sibling.
|
| ⛔ AND THE SECOND AND THIRD CASES ARE A DIFFERENT SPECIES FROM THE FIRST. `routes/api.php` rejects
| `can:viewAny,Submission::class` for these routes in a prose comment, duplicated in
| `SavedReportViewPolicy`'s docblock, and defended by nothing executable — swap the subject and every test
| in the repository stayed green, because no principal any test used could tell the two gates apart.
| `SavedReportViewPolicy::viewAny()` reads the two dashboard keys; `SubmissionPolicy::viewAny()` reads
| `submissions.view`. So the ONLY fixture that discriminates is one holding exactly one side and not the
| other — 403 under the right subject and 200 under the wrong one, and the reverse for the third case.
|
| ⚠️ NO SEEDED ROLE CAN PRODUCE EITHER PRINCIPAL, which is why `memberHoldingOnly()` exists and why the
| defect survived: all five roles holding `submissions.view` also hold at least `dashboard.form.view`.
|
| ⚠️ ONE TOKEN AND ONE REQUEST PER TEST, for the reason recorded further up this file: the auth guard
| CACHES its resolved user for the app instance, so a second `withToken()` inside one test keeps acting as
| the first. Three cases, not one.
*/

it('refuses the REPORT to a correctly-scoped token whose owner holds neither dashboard permission', function (): void {
    // The `can:` arm the ability case above cannot reach: this token carries read:analytics, so Sanctum
    // passes it through and only Authorize:viewAny,SavedReportView is left to refuse.
    $nobody = memberHoldingOnly();
    $token = $nobody->createToken('ci', [ApiAbilities::READ_ANALYTICS])->plainTextToken;

    // `forbidden` rather than `insufficient_ability` is what proves the ability gate PASSED and the policy
    // is what refused. Asserting only the status would pass under either.
    $this->withToken($token)
        ->getJson(analyticsUrl().'?'.http_build_query(reportParams()))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

it('refuses the REPORT to a principal holding exactly submissions.view — the key the REJECTED subject names', function (): void {
    // 200 UNDER THE WRONG SUBJECT. `SubmissionPolicy::viewAny()` reads exactly this key, so if the gate
    // were `can:viewAny,Submission::class` this principal would be served — which is the widening
    // `routes/api.php` rejects in prose. This case is that sentence, made executable.
    $principal = memberHoldingOnly('submissions.view');
    $token = $principal->createToken('ci', [ApiAbilities::READ_ANALYTICS])->plainTextToken;

    $this->withToken($token)
        ->getJson(analyticsUrl().'?'.http_build_query(reportParams()))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'forbidden');
});

it('serves the REPORT to a principal holding ONLY dashboard.form.view', function (): void {
    // 403 UNDER THE WRONG SUBJECT, and the positive control that the two refusals above are the gate
    // rather than a broken fixture: the same helper, one permission different, and the route opens.
    // It is also the only thing on the API surface pinning viewAny()'s SECOND disjunct — every other
    // analytics case here is a Viewer or an Owner, both of whom hold dashboard.org.view as well.
    $principal = memberHoldingOnly('dashboard.form.view');
    $token = $principal->createToken('ci', [ApiAbilities::READ_ANALYTICS])->plainTextToken;

    $this->withToken($token)
        ->getJson(analyticsUrl().'?'.http_build_query(reportParams()))
        ->assertOk();
});
