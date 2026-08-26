<?php

declare(strict_types=1);

use App\Enums\BadgeKey;
use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Http\Middleware\RequireModule;
use App\Models\BadgeAward;
use App\Models\PointAward;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Gamification\StreakCalculator;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The /api/v1 gamification surface (ADR-0020 §D7, Increment K1d).
|
| REAL TOKENS via withToken(), never actingAs() -- the AuditApiTest / AnalyticsApiTest doctrine:
| actingAs() resolves a TransientToken that passes EVERY ability, which would make the two-layer
| ability-plus-policy check vacuous, and the whole point of read:gamification being a NEW ability is that a
| token minted without it holds nothing.
|
| THE THREE THINGS THIS FILE IS SHAPED TO CATCH:
|
|  1. THE ORG/OWN SPLIT COLLAPSING. §D7 mints no permission and instead gates only the NAMED list. A
|     `can:` gate accidentally added to `me`, or accidentally dropped from `leaderboard`, breaks the
|     feature in opposite directions -- so BOTH are asserted, for a role that holds `dashboard.org.view`
|     and a role that does not.
|  2. THE WRONG REFUSAL SENTENCE. A tenant that switched the module off must NOT be told to upgrade its
|     plan. The assertion names the string that must be ABSENT, because a test that only checks the new
|     copy is present would pass if both were rendered.
|  3. THE ABILITY HAVING BEEN FOLDED INTO AN EXISTING ONE. A token carrying every OTHER ability is refused.
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
    $this->editor = User::factory()->create();

    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    makeActiveMember($this->viewer, 'viewer');
    makeActiveMember($this->editor, 'form_editor');

    // Starter, which is the CHEAPEST tier that can reach /api/v1 at all -- the whole of Group B carries
    // `feature:api_access`, which Free does not grant. Measured rather than assumed: on Free every request
    // below 402s with `api_access` before any gamification code runs. See the Free case at the foot of this
    // file, which pins that fact rather than hiding it.
    assignPlanTier(PlanTier::Starter);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function gamificationUrl(string $suffix): string
{
    return 'http://acme.meridian.test/api/v1/gamification/'.$suffix;
}

function gamificationToken(?User $user = null, ?string $ability = null): string
{
    return ($user ?? test()->owner)
        ->createToken('ci', [$ability ?? ApiAbilities::READ_GAMIFICATION])
        ->plainTextToken;
}

/*
|--------------------------------------------------------------------------
| The ability layer -- the whole reason read:gamification is NEW
*/

it('refuses a token that holds every OTHER ability but not read:gamification', function (): void {
    // THE test that proves the ability was not folded into read:analytics. The user holds
    // dashboard.org.view, so the permission side passes -- only the token scope refuses.
    $abilities = array_values(array_diff(ApiAbilities::all(), [ApiAbilities::READ_GAMIFICATION]));
    $token = $this->owner->createToken('ci', $abilities)->plainTextToken;

    $this->withToken($token)
        ->getJson(gamificationUrl('me'))
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_ability');
});

it('lets every role mint the ability, because everyone may see their own standing', function (): void {
    // The map is any-of over both dashboard permissions, and all five roles hold dashboard.form.view. A
    // Form Editor holding NO org permission must still be able to mint and read their own card.
    expect(ApiAbilities::intersect($this->editor, [ApiAbilities::READ_GAMIFICATION]))
        ->toBe([ApiAbilities::READ_GAMIFICATION]);
});

/*
|--------------------------------------------------------------------------
| GET /gamification/me -- ungated by design
*/

it('serves a members own points, badges, standing and streak', function (): void {
    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);
    BadgeAward::factory()->forBadge(BadgeKey::FirstPublish)->create(['user_id' => $this->owner->id]);

    $this->withToken(gamificationToken())
        ->getJson(gamificationUrl('me'))
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'points', 'badges',
            'standing' => ['rank', 'of'],
            'streak' => ['current', 'longest', 'last_active_on'],
        ]])
        ->assertJsonPath('data.points', 25)
        ->assertJsonPath('data.badges', 1)
        ->assertJsonPath('data.standing.rank', 1)
        // ⚠️ THREE active members, only one of whom has scored. `of` counts the TEAM, so a denominator
        // built from scorers would read 1 here and this is the assertion that separates them.
        ->assertJsonPath('data.standing.of', 3);
});

it('serves a member who has earned nothing a real position rather than an absence', function (): void {
    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);

    $this->withToken(gamificationToken($this->editor))
        ->getJson(gamificationUrl('me'))
        ->assertOk()
        ->assertJsonPath('data.points', 0)
        // Joint second with the other scoreless member -- competition ranking, so the tie shares a place.
        ->assertJsonPath('data.standing.rank', 2)
        // ⚠️ NULL, NOT 3, AND THIS LINE USED TO READ 3 (ADR-0020 §D13). The caller is a Form Editor, who
        // holds no org permission: the RANK is theirs and §D7 grants it, but the denominator is the
        // workspace headcount. The owner's identical request two tests up still reads 3.
        ->assertJsonPath('data.standing.of', null);
});

it('withholds the workspace headcount from a caller without dashboard.org.view', function (): void {
    // ⛔ ADR-0020 §D13, the API half. `read:gamification` is mintable by all five roles (see the ability
    // test above), so this token is the cheapest route to the number — the page is not the only door.
    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);

    // ⚠️ ONE CALLER PER TEST, AND THE SECOND DRAFT OF THIS TEST LEARNED WHY THE HARD WAY. Driving the editor
    // and then the owner inside ONE test fails twice over: minting the second token after the first request
    // raises `42501` on `personal_access_tokens` (the request leaves the RLS tenant GUC set to whoever made
    // it), and minting both up front instead still reads `of` as null for the OWNER, because the resolved
    // permission state from the first request survives into the second. Neither is a defect in the fix —
    // both are two auth contexts sharing one container. The positive half therefore lives in its own test.
    $this->withToken(gamificationToken($this->editor))
        ->getJson(gamificationUrl('me'))
        ->assertOk()
        // The KEY is still present: a client distinguishes "withheld" from "this endpoint changed shape".
        ->assertJsonStructure(['data' => ['standing' => ['rank', 'of']]])
        ->assertJsonPath('data.standing.of', null)
        // Everything §D7 actually grants survives, so this is a withheld field and not a degraded endpoint.
        ->assertJsonPath('data.standing.rank', 2);

    // ⛔ THE POSITIVE CONTROL FOR THIS GATE IS `it serves a members own points, badges, standing and streak`
    // ABOVE, which drives the same endpoint as the OWNER and pins `data.standing.of` at 3. It is named here
    // rather than assumed: without it, deleting `of` from the payload outright would turn this test green
    // while breaking the feature, and the two together are what a mutation in either direction reddens.
});

it('lets a Form Editor read their own card even though they hold no org permission', function (): void {
    // The §D7 split's ungated half. A `can:` gate mistakenly added to this route turns this red.
    $this->withToken(gamificationToken($this->editor))
        ->getJson(gamificationUrl('me'))
        ->assertOk();
});

it('reports the same streak the calculator does, so the endpoint cannot recompute it differently', function (): void {
    PointAward::factory()->forRule(PointRule::FormCreated)->create([
        'user_id' => $this->owner->id,
        'awarded_at' => now(),
    ]);
    PointAward::factory()->forRule(PointRule::FormPublished)->create([
        'user_id' => $this->owner->id,
        'awarded_at' => now()->subDay(),
    ]);

    $expected = app(StreakCalculator::class)->for((string) $this->tenant->id, (string) $this->owner->id);

    $this->withToken(gamificationToken())
        ->getJson(gamificationUrl('me'))
        ->assertOk()
        ->assertJsonPath('data.streak.current', $expected->current)
        ->assertJsonPath('data.streak.longest', $expected->longest);

    // Pinned literally as well, so the line above cannot pass by comparing the code with itself.
    expect($expected->current)->toBe(2);
});

it('emits a null last_active_on for a member who has never earned anything', function (): void {
    $this->withToken(gamificationToken($this->viewer))
        ->getJson(gamificationUrl('me'))
        ->assertOk()
        ->assertJsonPath('data.streak.current', 0)
        ->assertJsonPath('data.streak.last_active_on', null);
});

/*
|--------------------------------------------------------------------------
| GET /gamification/leaderboard -- the NAMED list, dashboard.org.view only
*/

it('serves the named ladder and the team totals to an org-wide reader', function (): void {
    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);
    PointAward::factory()->forRule(PointRule::SubmissionReviewed)->create(['user_id' => $this->editor->id]);

    $response = $this->withToken(gamificationToken())
        ->getJson(gamificationUrl('leaderboard'))
        ->assertOk()
        ->assertJsonStructure(['data' => [
            'entries' => [['rank', 'user_id', 'name', 'points', 'badges']],
            'member_count',
            'team' => ['points', 'responses', 'published_forms', 'active_members', 'badges', 'contributors'],
        ]]);

    $response->assertJsonPath('data.member_count', 3)
        ->assertJsonPath('data.entries.0.points', 25)
        ->assertJsonPath('data.entries.0.name', $this->owner->name)
        ->assertJsonPath('data.entries.1.points', 3)
        ->assertJsonPath('data.entries.2.points', 0)
        ->assertJsonPath('data.team.points', 28)
        ->assertJsonPath('data.team.active_members', 3);
});

it('lets a Viewer read the ladder, which is intended and worth pinning', function (): void {
    // A Viewer holds dashboard.org.view, so they see workspace-wide numbers about colleagues. Intended --
    // a Viewer already sees every submission in the inbox -- and recorded so a later reader does not
    // "fix" it.
    $this->withToken(gamificationToken($this->viewer))
        ->getJson(gamificationUrl('leaderboard'))
        ->assertOk();
});

it('refuses the ladder to a Form Editor, who may see only themselves', function (): void {
    // The gated half of §D7. Note this is the SAME token ability as the passing `me` case above: the
    // policy, not the ability, is what withholds the list.
    $this->withToken(gamificationToken($this->editor))
        ->getJson(gamificationUrl('leaderboard'))
        ->assertForbidden();
});

it('names nobody else in the me payload, which is what makes the ungated route safe', function (): void {
    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $this->owner->id]);

    $body = $this->withToken(gamificationToken($this->editor))
        ->getJson(gamificationUrl('me'))
        ->assertOk()
        ->getContent();

    // The one assertion that would catch a future field leaking a colleague into the ungated half.
    expect($body)->not->toContain($this->owner->name)
        ->and($body)->not->toContain((string) $this->owner->id);
});

/*
|--------------------------------------------------------------------------
| The module gate -- and the sentence it must NOT say
*/

it('refuses both routes with module_disabled when the tenant switched gamification off', function (): void {
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.gamification' => false], $this->owner);

    // ⚠️ MINTED ONCE, BEFORE THE LOOP, AND THAT IS NOT TIDINESS. `personal_access_tokens` carries strict
    // RLS, and an HTTP request through the tenancy middleware leaves the test's own ambient context
    // cleared on the way out -- so a second createToken() between requests writes with no tenant GUC and
    // dies on 42501. The write-side refusal RAISES rather than silently affecting zero rows, which is the
    // one reason this surfaced as a failing test instead of as a mysteriously empty result.
    $token = gamificationToken();

    foreach (['me', 'leaderboard'] as $suffix) {
        $this->withToken($token)
            ->getJson(gamificationUrl($suffix))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'module_disabled')
            ->assertJsonPath('error.details.module', 'gamification');
    }
});

it('refuses to gate a key that is not toggleable at all, rather than passing forever', function (): void {
    // ⚠️ A VACUOUS GATE IS THE FAILURE THIS PROJECT KEEPS MEASURING, so RequireModule throws on a key
    // outside ToggleableModules::KEYS instead of consulting a setting that can never be false.
    // `moduleEnabled()` returns TRUE for any unknown key -- `! is_bool($value) || $value` -- so without the
    // guard, `module:not_a_real_key` would sit on a route looking like protection and allowing everything
    // for the life of the route, with nothing anywhere going red.
    $middleware = app(RequireModule::class);

    expect(fn () => $middleware->handle(
        Request::create('/'),
        fn (): Response => new Response,
        'definitely_not_a_module',
    ))->toThrow(InvalidArgumentException::class);

    // And the real key passes through untouched on the same call path, so the assertion above cannot be
    // passing because `handle()` throws for everything.
    expect($middleware->handle(
        Request::create('/'),
        fn (): Response => new Response('through'),
        'gamification',
    )->getContent())->toBe('through');
});

it('does not tell a self-disabled tenant to upgrade its plan', function (): void {
    // ⚠️ THIS IS THE ONLY TEST THAT CAN CATCH A WRONGLY-MOUNTED `feature:gamification`, AND THE REASON IS
    // A PROPERTY OF THE PLAN CATALOG: every tier granting `api_access` also grants `gamification` (Starter
    // upward is built by spreading `$everyTier`), so NO tier separates the two keys and no plan fixture can
    // tell the gates apart. A self-disabled tenant is the one state where a `feature:` gate would fire --
    // and it would fire with the wrong sentence, which is exactly what doc #28 §9 forbids.
    //
    // ⚠️ AND THE ASSERTION IS ON THE STRING THAT MUST BE ABSENT. ADR-0020's Consequences record the inherited
    // wart this route exists to avoid: `gamification` is granted on every tier, so a `feature:` gate could
    // only ever fire here and would answer with "Upgrade your plan to use it" -- pointing at a purchase
    // that would change nothing. A test asserting only that the new copy is PRESENT would pass against a
    // response that rendered both.
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.gamification' => false], $this->owner);

    $body = $this->withToken(gamificationToken())
        ->getJson(gamificationUrl('me'))
        ->assertForbidden()
        ->getContent();

    expect($body)->not->toContain('Upgrade your plan')
        ->and($body)->not->toContain('feature_not_available')
        ->and($body)->toContain('switched off for this workspace');
});

it('is refused on Free by api_access and NOT by its own key, which is worth recording', function (): void {
    // ⚠️ MEASURED WHILE WRITING THIS FILE, AND IT IS NOT WHAT THE ROW ASSUMED. §D6 grants `gamification`
    // on every tier including Free -- but the whole of Group B sits behind `feature:api_access`, which
    // Free does not carry. So a Free tenant HAS gamification and cannot reach it over the API at all; the
    // web surface K1e builds is its only door. That is a pre-existing property of the API group rather
    // than anything this row chose, and it is pinned here so the next reader does not discover it as a bug.
    assignPlanTier(PlanTier::Free);

    $this->withToken(gamificationToken())
        ->getJson(gamificationUrl('me'))
        ->assertStatus(402)
        ->assertJsonPath('error.code', 'feature_not_available')
        ->assertJsonPath('error.details.feature', 'api_access');
});

/*
|--------------------------------------------------------------------------
| Tenancy
*/

it('never shows one workspace the members of another', function (): void {
    $other = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $other->domains()->create(['domain' => 'beta']);

    $stranger = User::factory()->create();
    enterTenant($other->id, $stranger->id);
    makeActiveMember($stranger, 'owner');
    PointAward::factory()->forRule(PointRule::FormPublished)->create(['user_id' => $stranger->id]);

    enterTenant($this->tenant->id, $this->owner->id);

    $body = $this->withToken(gamificationToken())
        ->getJson(gamificationUrl('leaderboard'))
        ->assertOk()
        ->assertJsonPath('data.member_count', 3)
        ->getContent();

    expect($body)->not->toContain($stranger->name);
});
