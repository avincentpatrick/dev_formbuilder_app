<?php

declare(strict_types=1);

use App\Enums\BadgeKey;
use App\Enums\PlanTier;
use App\Enums\PointRule;
use App\Models\BadgeAward;
use App\Models\PointAward;
use App\Models\User;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| K1e — the achievements PAGE and its streak sidecar (doc #28 §10, ADR-0020 §D7, §D11).
|
| WHAT CLASS OF BUG THIS FILE EXISTS TO CATCH, in the order the bugs would actually happen:
|
|  1. THE GATED HALF LEAKING. §D7 splits the feature so that everyone sees their OWN numbers and only
|     `dashboard.org.view` sees colleagues' names. The split is resolved in the controller into a null
|     prop rather than at the door, so the thing that can go wrong is a payload arriving for a reader who
|     may not have it -- and it would go wrong SILENTLY, because the page simply renders what it is given.
|  2. `team` DRIFTING OUT FROM BEHIND THAT GATE. TeamProgress carries no colleague's NAME, so it looks
|     ungated and the obvious "tidy-up" is to serve it beside the ladder instead of inside it. That would
|     hand a Form Editor the workspace-wide totals `DashboardMetricsService` is careful to withhold from
|     them -- a widening of an existing permission performed by a new page.
|  3. THE WRONG REFUSAL SENTENCE. These are the FIRST `module:` gates on a web route in this application,
|     so `bootstrap/app.php`'s web arm for ModuleDisabledException had never executed before this row.
|  4. THE SIDECAR BECOMING A SHARED PROP. doc #28 §10 forbids it by name: an Inertia partial reload
|     re-dispatches the current page's controller, so the cost would be paid on every page in the app.
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
    assignPlanTier(PlanTier::Free);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

const ACHIEVEMENTS_URL = 'http://acme.meridian.test/achievements';
const STREAK_URL = 'http://acme.meridian.test/achievements/streak';

/** A member of this workspace in `$roleName`, with tenant context left on THEM. */
function memberIn(string $roleName): User
{
    $user = User::factory()->create();
    enterTenant(test()->tenant->id, $user->id);
    makeActiveMember($user, $roleName);

    return $user;
}

/** One award of `$rule` for `$user`, on `$day`. `subject_id` varies so the unique index does not swallow it. */
function awardOn(User $user, PointRule $rule, string $day, string $subject): void
{
    PointAward::factory()->create([
        'user_id' => $user->id,
        'rule' => $rule,
        'points' => $rule->points(),
        'subject_type' => 'submission',
        'subject_id' => $subject,
        'awarded_at' => $day,
    ]);
}

/*
|--------------------------------------------------------------------------
| The ungated half — every member, no permission at all
*/

it('renders a members own points, badges, streak and standing with no permission', function (): void {
    $this->withoutVite();

    // A Form Editor: the LEAST-privileged role that still holds a membership here, and the one §D7 says
    // must still get their own numbers. If the page were gated at the door this would be a 403.
    $editor = memberIn('form_editor');
    awardOn($editor, PointRule::SubmissionCollected, now()->toDateString(), 'sub-a');
    awardOn($editor, PointRule::SubmissionCollected, now()->subDay()->toDateString(), 'sub-b');
    BadgeAward::factory()->create([
        'user_id' => $editor->id,
        'badge' => BadgeKey::FirstResponse,
        'awarded_at' => now()->subDay(),
    ]);

    $this->actingAs($editor)
        ->get(ACHIEVEMENTS_URL)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('achievements/Index', false)
            ->where('progress.points', PointRule::SubmissionCollected->points() * 2)
            ->where('progress.badges', 1)
            // Two consecutive days ending today.
            ->where('progress.streak.current', 2)
            ->where('progress.streak.longest', 2)
            // Two active members (the owner and this editor); the editor has scored, the owner has not.
            ->where('progress.standing.rank', 1)
            // ⚠️ THIS LINE READ `2` UNTIL M26, AND THAT IS THE DEFECT ADR-0020 §D13 RECORDS — asserted here
            // as a requirement, for a role the same test names as the least privileged one. The rank is the
            // member's own and §D7 grants it; `of` is the workspace headcount, which `/dashboard`, `/members`
            // and the member search arm all withhold from exactly this reader.
            ->where('progress.standing.of', null));
});

it('withholds the workspace headcount from a member without dashboard.org.view', function (): void {
    $this->withoutVite();

    // ⛔ ADR-0020 §D13. The controller argued in its own docblock that a plain workspace-wide COUNT is the
    // sensitive thing rather than merely a colleague's NAME — and then served `standing.of` three fields
    // ahead of the gated `scoreboard.team.active_members`, which is the same number.
    $editor = memberIn('form_editor');
    awardOn($editor, PointRule::SubmissionCollected, now()->toDateString(), 'sub-a');

    $this->actingAs($editor)
        ->get(ACHIEVEMENTS_URL)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // Present-but-null, the `kpis.members` contract: the page reads the null and drops the
            // denominator rather than re-deriving the permission client-side.
            ->where('progress.standing.of', null)
            // §D7's grant is untouched — a withheld field, not a degraded page.
            ->where('progress.standing.rank', 1)
            ->where('progress.points', PointRule::SubmissionCollected->points())
            // The gated payload is null for this reader too, which is what makes the two consistent. Before
            // §D13 this assertion and the one above disagreed about the same workspace figure.
            ->where('scoreboard', null));
});

it('still gives the headcount to a member who may see workspace-wide numbers', function (): void {
    $this->withoutVite();

    // ⚠️ THE POSITIVE CONTROL, AND IT IS NOT OPTIONAL. Without it the gate above passes just as well when
    // `of` is deleted from the payload outright — a green test proving the opposite of what it claims. This
    // is also the pairing that makes a mutation in either direction red.
    awardOn($this->owner, PointRule::SubmissionCollected, now()->toDateString(), 'sub-owner');

    $this->actingAs($this->owner)
        ->get(ACHIEVEMENTS_URL)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('progress.standing.of', 1)
            ->where('progress.standing.rank', 1));
});

it('reports current and longest separately, because they are different measurements', function (): void {
    $this->withoutVite();

    // A three-day run that ENDED a week ago, plus one award today. `current` is 1; `longest` is 3. A page
    // showing one and labelling it the other tells a member they lost an achievement they still hold.
    $editor = memberIn('form_editor');
    foreach (['sub-1' => 10, 'sub-2' => 9, 'sub-3' => 8] as $subject => $daysAgo) {
        awardOn($editor, PointRule::SubmissionCollected, now()->subDays($daysAgo)->toDateString(), $subject);
    }
    awardOn($editor, PointRule::SubmissionCollected, now()->toDateString(), 'sub-today');

    $this->actingAs($editor)
        ->get(ACHIEVEMENTS_URL)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('progress.streak.current', 1)
            ->where('progress.streak.longest', 3));
});

it('lists the whole badge catalog, holding what was earned and naming what was not', function (): void {
    $this->withoutVite();

    $editor = memberIn('form_editor');
    BadgeAward::factory()->create([
        'user_id' => $editor->id,
        'badge' => BadgeKey::Welcome,
        'awarded_at' => now()->subMonth(),
    ]);

    $this->actingAs($editor)
        ->get(ACHIEVEMENTS_URL)
        ->assertOk()
        ->assertInertia(function ($page) {
            $shelf = $page->toArray()['props']['shelf'];

            // The two halves always sum to the catalog — which is what makes an unearned badge render at
            // zero rather than vanish, and what makes the page's empty state a reachable state rather
            // than dead code.
            expect(count($shelf['earned']) + count($shelf['in_progress']))->toBe(count(BadgeKey::cases()))
                ->and($shelf['earned'][0]['key'])->toBe(BadgeKey::Welcome->value)
                ->and($shelf['earned'][0]['earned_on'])->not->toBeNull()
                // Copy comes from the enum, never from a second table in the page.
                ->and($shelf['earned'][0]['label'])->toBe(BadgeKey::Welcome->label())
                ->and($shelf['in_progress'][0]['earned_on'])->toBeNull();

            return $page;
        });
});

/*
|--------------------------------------------------------------------------
| The gated half — the ladder AND the workspace totals
*/

it('withholds the ladder AND the team totals from a reader without dashboard.org.view', function (): void {
    $this->withoutVite();

    $editor = memberIn('form_editor');

    $this->actingAs($editor)
        ->get(ACHIEVEMENTS_URL)
        ->assertOk()
        // ⚠️ NULL, not an empty ladder: the page reads the null and omits the whole section. An empty
        // array would render the heading over nothing and would also be indistinguishable from a
        // workspace that genuinely has no members.
        ->assertInertia(fn ($page) => $page->where('scoreboard', null));
});

it('serves the ladder and the team totals together to an Owner', function (): void {
    $this->withoutVite();

    awardOn($this->owner, PointRule::SubmissionCollected, now()->toDateString(), 'sub-owner');

    $this->actingAs($this->owner)
        ->get(ACHIEVEMENTS_URL)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('scoreboard.entries', 1)
            ->where('scoreboard.entries.0.name', $this->owner->name)
            ->where('scoreboard.member_count', 1)
            ->where('scoreboard.team.points', PointRule::SubmissionCollected->points())
            ->has('scoreboard.team.responses')
            ->has('scoreboard.team.contributors'));
});

it('keeps team totals INSIDE the gate, so a Form Editor cannot read workspace-wide numbers', function (): void {
    $this->withoutVite();

    // ⚠️ THE ASSERTION IS ON THE ABSENCE OF THE WHOLE PAYLOAD, and it is separate from the null case above
    // on purpose: the tempting refactor is "names are gated, plain counts are not", which would keep
    // `scoreboard` null while adding a sibling `team` prop. That would pass the previous case and fail
    // this one. `dashboard.org.view` is precisely the key deciding who may read workspace-wide numbers
    // about other people's work — DashboardMetricsService withholds the Members tile on it.
    $editor = memberIn('form_editor');
    awardOn($this->owner, PointRule::SubmissionCollected, now()->toDateString(), 'sub-owner');

    $props = $this->actingAs($editor)
        ->get(ACHIEVEMENTS_URL)
        ->assertOk()
        ->viewData('page')['props'];

    expect($props)->not->toHaveKey('team')
        ->and($props)->not->toHaveKey('ladder')
        ->and($props['scoreboard'])->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The module gate — the FIRST `module:` gate on a web route in this application
*/

it('refuses the page when the workspace switched gamification off, with copy naming the toggle', function (): void {
    $this->withoutVite();
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.gamification' => false], $this->owner);

    $this->actingAs($this->owner)
        ->from('http://acme.meridian.test/dashboard')
        ->get(ACHIEVEMENTS_URL)
        ->assertRedirect('http://acme.meridian.test/dashboard')
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error'
            && str_contains($toast['message'], 'switched off for this workspace'));
});

it('does not tell a self-disabled workspace to upgrade its plan', function (): void {
    // ⚠️ THE ONLY THING THAT CAN CATCH A WRONGLY-MOUNTED `feature:gamification` HERE, and it is a property
    // of the plan catalog rather than of this page: ADR-0020 §D6 grants `gamification` on EVERY tier, so
    // no plan fixture separates the two gates. A self-disabled workspace is the one state where a
    // `feature:` gate would fire, and it would fire with "Upgrade your plan to use it" — pointing at a
    // purchase that would change nothing. Asserting only that the RIGHT copy is present would stay green
    // against a response carrying both.
    $this->withoutVite();
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.gamification' => false], $this->owner);

    $toast = $this->actingAs($this->owner)
        ->from('http://acme.meridian.test/dashboard')
        ->get(ACHIEVEMENTS_URL)
        ->assertRedirect()
        ->getSession()
        ->get('toast');

    expect($toast['message'])->not->toContain('Upgrade your plan')
        ->and($toast['message'])->not->toContain('plan')
        ->and($toast['message'])->toContain('switched off for this workspace');
});

it('does not redirect the refusal to the achievements page itself', function (): void {
    // ⚠️ THE EDGE THE `back()` ARM MAKES REACHABLE FOR THE FIRST TIME. `bootstrap/app.php` answers a web
    // ModuleDisabledException with `back()`, which resolves the referer and then the session's previous
    // URL. A reader who arrives with NEITHER — a typed URL in a fresh session — must not be sent to the
    // very page that just refused them, which would be an infinite bounce.
    $this->withoutVite();
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.gamification' => false], $this->owner);

    $response = $this->actingAs($this->owner)->get(ACHIEVEMENTS_URL)->assertRedirect();

    expect($response->headers->get('Location'))->not->toContain('/achievements');
});

/*
|--------------------------------------------------------------------------
| The streak sidecar
*/

it('serves the streak as plain JSON carrying the count and nothing else', function (): void {
    $editor = memberIn('form_editor');
    awardOn($editor, PointRule::SubmissionCollected, now()->toDateString(), 'sub-a');
    awardOn($editor, PointRule::SubmissionCollected, now()->subDay()->toDateString(), 'sub-b');

    $body = $this->actingAs($editor)->getJson(STREAK_URL)->assertOk()->json();

    // ⚠️ EXACTLY ONE KEY. The sidecar fires on every navigation, so anything added here is paid for on
    // every navigation — a rank in particular would cost three statements, because
    // LeaderboardService::standingFor() ranks the whole tenant to answer for one member.
    expect($body)->toBe(['current' => 2]);
});

it('gates the sidecar on the module too, so a disabled workspace is not polled forever', function (): void {
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.gamification' => false], $this->owner);

    $this->actingAs($this->owner)->get(STREAK_URL)->assertRedirect();
});

it('reports a broken streak as zero rather than omitting the key', function (): void {
    // A member with awards, none of them recent. `0` and "no answer" are different things to the composable
    // that reads this: it holds its last known value on a failure and would otherwise never learn that a
    // run had ended.
    $editor = memberIn('form_editor');
    awardOn($editor, PointRule::SubmissionCollected, now()->subDays(9)->toDateString(), 'sub-old');

    expect($this->actingAs($editor)->getJson(STREAK_URL)->assertOk()->json())->toBe(['current' => 0]);
});

/*
|--------------------------------------------------------------------------
| The dashboard card — the third surface, and the one gated on the OTHER axis
*/

it('puts the members own progress on the dashboard', function (): void {
    $this->withoutVite();

    $editor = memberIn('form_editor');
    awardOn($editor, PointRule::SubmissionCollected, now()->toDateString(), 'sub-a');

    $this->actingAs($editor)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('progress.points', PointRule::SubmissionCollected->points())
            ->where('progress.streak', 1)
            // ⛔ `progress.rank` AND `progress.of` WERE ASSERTED HERE AND ARE NOW ABSENT (M26, §D13). They
            // were serialized into every dashboard payload and rendered by nothing: `Dashboard.vue` declared
            // both in its prop type and its card shows points, badges and streak. `of` is the workspace
            // headcount — the same integer `kpis.members` is nulled out of for this very reader, in this very
            // payload. Deleted rather than gated, because a field nothing displays has no gated form worth
            // keeping.
            ->missing('progress.rank')
            ->missing('progress.of')
            // And the tile that DOES carry this number is still withheld from the same reader, which is what
            // makes the dashboard's two answers agree for the first time.
            ->where('kpis.members', null));
});

it('withholds the dashboard card when the workspace switched gamification off', function (): void {
    // ⚠️ AND THE GATE IS `moduleEnabled()`, NOT `EntitlementService::feature()`. The two disagree in one
    // reachable state — an unseeded plan catalog, where `feature()` fail-closes while `RequireModule`
    // admits — so reading `feature()` would withhold a card whose destination the route would serve. This
    // case cannot tell those apart on its own; what it pins is that SOMETHING consults the toggle, so a
    // mutant that deletes the guard reddens here rather than shipping a card linking to a 302.
    $this->withoutVite();
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.gamification' => false], $this->owner);

    $this->actingAs($this->owner)
        ->get('http://acme.meridian.test/dashboard')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('progress', null));
});

/*
|--------------------------------------------------------------------------
| The route's gates, and the ones it deliberately does not carry
*/

it('carries the module gate and NOT a can: or feature: gate on either route', function (): void {
    // ⚠️ ALL FOUR ABSENCES PINNED TOGETHER so none can be "tidied" into symmetry with the neighbouring
    // routes, every one of which carries `can:` and most of which carry `feature:`. §D7 gives every member
    // their own numbers, and §D6 puts the key on every tier — so a `can:` gate would 403 a Form Editor out
    // of their own achievements and a `feature:` gate could only ever fire with the wrong sentence.
    foreach (['achievements', 'achievements.streak'] as $name) {
        $middleware = app('router')->getRoutes()->getByName($name)->gatherMiddleware();

        expect($middleware)->toContain('module:gamification');

        foreach ($middleware as $layer) {
            expect($layer)->not->toStartWith('can:')
                ->and($layer)->not->toStartWith('feature:');
        }
    }
});
