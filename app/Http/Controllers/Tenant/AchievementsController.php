<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\PointAward;
use App\Models\User;
use App\Policies\PointAwardPolicy;
use App\Services\Gamification\BadgeShelf;
use App\Services\Gamification\BadgeShelfService;
use App\Services\Gamification\BadgeStanding;
use App\Services\Gamification\LeaderboardService;
use App\Services\Gamification\MemberProgress;
use App\Services\Gamification\MemberStreak;
use App\Services\Gamification\Scoreboard;
use App\Services\Gamification\StreakCalculator;
use App\Services\Gamification\TeamProgressService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The achievements surface (K1e; gamification-design.md §10, ADR-0020 §D7) — the web half of the engine,
 * and for a Free tenant the ONLY half.
 *
 * ⚠️ **THAT IS NOT A FIGURE OF SPEECH.** ADR-0020 §D11(e) records it as a measured property of the plan
 * catalog: the whole `/api/v1` group sits behind `feature:api_access`, which Free does not carry, while
 * §D6 grants `gamification` on **every** tier. So K1d's two endpoints are unreachable for exactly the
 * audience §D6 refused to put a tier ladder in front of, and this page is their only door. It raises the
 * bar on the empty state rather than lowering it.
 *
 * A thin adapter, the {@see DashboardController} posture: every number here is read by a service K1a–K1e
 * built, and nothing is re-derived. `standing` and `streak` compose into the same {@see MemberProgress}
 * the API serializes, and `ladder` + `team` into the same {@see Scoreboard} — the value objects are shared
 * so the two surfaces cannot come to disagree, while the prop shapes below are deliberately NOT the API
 * resources.
 *
 * ⚠️ **WHY NOT REUSE `MemberProgressResource` / `ScoreboardResource`, WHICH WOULD HAVE BEEN ONE LINE EACH.**
 * They are the `/api/v1` **wire contract** — versioned, published in `openapi.json`, and changeable only
 * with a deprecation. Rendering the page through them would tie an internal prop rename to a public
 * contract in both directions: a page tweak would move the OpenAPI document, and a v2 reshape would
 * silently reshape this page. The DATA is shared (the services and their value objects); the two
 * SERIALIZATIONS are separate on purpose.
 *
 * ── ⚠️ `team` IS BEHIND THE LEADERBOARD GATE, NOT BESIDE IT, AND THAT IS THE ONE NON-OBVIOUS GATING CALL ─
 * The tempting split is "names are gated, plain counts are not" — `TeamProgress` carries no colleague's
 * name, so it looks ungated. It is not, and `GamificationController::leaderboard()` already bundles
 * the two behind `can:viewAny,PointAward` — which {@see PointAwardPolicy::viewAny()} defines as exactly
 * `dashboard.org.view`, and that key is precisely the one deciding whether somebody may read
 * WORKSPACE-WIDE numbers about other people's work. `DashboardMetricsService` withholds the Members tile
 * and scopes the form and submission counts on it, so a Form Editor sees their own grants and nothing
 * else. Serving
 * `team.responses`, `team.contributors` and `team.activeMembers` ungated here would hand that same reader
 * the org-wide totals the dashboard is careful to withhold — **a widening of an existing permission,
 * performed by a new page**, which is the shape §D7 minted no thirtieth key in order to avoid. So one
 * gated payload, matching the API's, and `scoreboard` is null for everyone else.
 *
 * ⛔ **AND FOR TWO INCREMENTS THIS FILE DID NOT FINISH APPLYING ITS OWN PARAGRAPH (M26, ADR-0020 §D13).**
 * The argument above is that a plain workspace-wide COUNT is the sensitive thing, not merely a colleague's
 * name — which is why `team.active_members` is inside the gated payload. **`standing.of` is that same count**,
 * and it sat three fields earlier with no permission at all, so this controller asserted both halves of the
 * split it had just rejected. It is now resolved from the one `$orgWide` variable that decides `scoreboard`,
 * so the two cannot disagree again. `rank` is untouched: §D7 grants a member their own position, and a rank
 * discloses a floor rather than the headcount.
 */
final class AchievementsController extends Controller
{
    public function __construct(
        private readonly LeaderboardService $leaderboard,
        private readonly StreakCalculator $streaks,
        private readonly TeamProgressService $team,
        private readonly BadgeShelfService $shelf,
    ) {}

    /**
     * The achievements page.
     *
     * No `can:` gate on the route: §D7 gives every member their own numbers with no permission at all, and
     * the one payload that needs one is resolved below rather than at the door — so a Form Editor gets a
     * working page with their own progress on it rather than a 403. That is the `kpis.members` shape
     * {@see DashboardController} already uses: the page reads a null, it never re-derives the condition.
     */
    public function __invoke(Request $request): Response
    {
        // Narrowed rather than null-checked: the route sits behind `auth` + tenant-context middleware, so a
        // null user is unreachable here. The GamificationController / AnalyticsQuestionController device.
        /** @var User $user */
        $user = $request->user();

        $userId = (string) $user->id;
        $tenantId = TenantContext::currentTenantId();

        // Resolved ONCE and read twice, which is the fix M26 made rather than the fix it could have made.
        // §D13: `standing.of` and `scoreboard.team.active_members` are the same workspace figure, and they
        // disagreed here for two increments precisely because two lines decided it separately. One variable
        // is what makes them unable to drift apart again.
        $orgWide = $user->can('viewAny', PointAward::class);

        $standing = $this->leaderboard->standingFor($userId);

        $progress = new MemberProgress(
            standing: $orgWide ? $standing : $standing->withoutHeadcount(),
            streak: $this->streakFor($tenantId, $userId),
        );

        return Inertia::render('achievements/Index', [
            'progress' => [
                'points' => $progress->standing->points,
                'badges' => $progress->standing->badges,
                'standing' => [
                    // Competition rank — one plus the number strictly ahead — so a tie shares a place and
                    // the next one skips it. Null when this reader holds no active membership here.
                    'rank' => $progress->standing->rank,
                    // ACTIVE MEMBERS, never the number who have scored — and NULL for a reader without
                    // `dashboard.org.view`, who may not have the workspace headcount (§D13). The page
                    // degrades the label to "4th"; it does not re-derive the condition.
                    'of' => $progress->standing->of,
                ],
                'streak' => [
                    // Two measurements, not one clamped: `current` decays to zero after a full missed day
                    // while `longest` only ever rises. The page labels them separately for that reason.
                    'current' => $progress->streak->current,
                    'longest' => $progress->streak->longest,
                    'last_active_on' => $progress->streak->lastActiveOn?->toIso8601String(),
                ],
            ],
            'shelf' => $this->shelfProps($this->shelfFor($tenantId, $userId)),
            // Null unless this reader may see workspace-wide numbers about colleagues. See the class docblock.
            'scoreboard' => $orgWide
                ? $this->scoreboardProps(new Scoreboard(
                    ladder: $this->leaderboard->forCurrentTenant(),
                    team: $this->team->forCurrentTenant(),
                ))
                : null,
        ]);
    }

    /**
     * The nav count badge's sidecar — this member's live streak, and nothing else.
     *
     * ⚠️ **A JSON SIDECAR RATHER THAN A SHARED INERTIA PROP, AND THE COST IT AVOIDS IS PAID ON EVERY PAGE
     * IN THE APPLICATION.** `routes/tenant.php`'s notification block records the mechanism: an Inertia
     * partial reload RE-DISPATCHES the current page's controller — Inertia filters what it SERIALIZES, not
     * what it COMPUTES — so a `HandleInertiaRequests::share()` entry would run this read on every render of
     * every page, including `/audit-log` and `/submissions`, which already pay for a paginate plus a
     * `count(*)` per navigation. doc #28 §10 states the requirement; this is it.
     *
     * ⚠️ **IT RETURNS THE STREAK ALONE, AND THE THINGS IT DOES *NOT* RETURN ARE THE DESIGN.** A rank would
     * cost three statements — {@see LeaderboardService::standingFor()} ranks the WHOLE tenant to answer for
     * one member, because a rank is not a property of one person — on a route that fires on every
     * navigation. A badge total would be monotonic, so it would read as a tally rather than as a signal,
     * and the bell already announced each badge as it landed (`NotificationType::BadgeEarned`).
     * The streak is the one number in the engine that DECAYS, it costs a single indexed read, and nothing
     * else in the product shows it. (User decision, 2026-08-18.)
     *
     * A plain 200 with a small object rather than a resource: there is no `/api/v1` contract here to honour,
     * and `openapi.json` must not move for a tenant web route.
     */
    public function streak(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $streak = $this->streakFor(TenantContext::currentTenantId(), (string) $user->id);

        return new JsonResponse(['current' => $streak->current]);
    }

    /**
     * This member's streak, or the explicit empty one off-tenant.
     *
     * {@see StreakCalculator} takes the tenant id as an argument rather than reading it, so the off-tenant
     * case has to be made explicit HERE or not at all — and it must be, because an RLS-filtered read with
     * no tenant GUC returns no rows rather than raising. Without this, "you have no streak" and "nobody
     * established tenant context" are the same output: the read-side twin of the silent write refusal.
     * Lifted verbatim in intent from `GamificationController` rather than shared, because a helper
     * spanning the API and web controllers would be one indirection protecting three lines.
     */
    private function streakFor(?string $tenantId, string $userId): MemberStreak
    {
        return $tenantId === null
            ? MemberStreak::none()
            : $this->streaks->for($tenantId, $userId);
    }

    /** This member's badge shelf, or the explicit empty one off-tenant. See {@see streakFor()}. */
    private function shelfFor(?string $tenantId, string $userId): BadgeShelf
    {
        return $tenantId === null
            ? BadgeShelf::none()
            : $this->shelf->for($tenantId, $userId);
    }

    /**
     * @return array{earned: list<array<string, mixed>>, in_progress: list<array<string, mixed>>}
     */
    private function shelfProps(BadgeShelf $shelf): array
    {
        return [
            'earned' => array_map($this->badgeProps(...), $shelf->earned),
            'in_progress' => array_map($this->badgeProps(...), $shelf->inProgress),
        ];
    }

    /**
     * One badge row.
     *
     * `label` and `description` come from `BadgeKey` rather than from a table of copy in the
     * page, which is what that enum's own docblock asks for: the shelf, the notification row and its email
     * all name the same badge, and a second consumer inventing its own wording is how two screens come to
     * disagree about what somebody earned.
     *
     * @return array<string, mixed>
     */
    private function badgeProps(BadgeStanding $standing): array
    {
        return [
            'key' => $standing->badge->value,
            'label' => $standing->badge->label(),
            'description' => $standing->badge->description(),
            'earned_on' => $standing->earnedOn?->toIso8601String(),
            // Unclamped, deliberately — 40 responses against Collector's 25 is a fact about the member.
            // MdsProgress clamps into [0, max] itself, so the raw number survives to the reader.
            'progress' => $standing->progress,
            'threshold' => $standing->threshold,
        ];
    }

    /**
     * The gated payload — the named ladder AND the workspace totals. See the class docblock for why `team`
     * is in here rather than beside it.
     *
     * @return array{entries: list<array<string, mixed>>, member_count: int, team: array<string, int>}
     */
    private function scoreboardProps(Scoreboard $scoreboard): array
    {
        return [
            'entries' => array_map(static fn ($entry): array => [
                'rank' => $entry->rank,
                'user_id' => $entry->userId,
                'name' => $entry->name,
                'points' => $entry->points,
                'badges' => $entry->badges,
            ], $scoreboard->ladder->entries),
            // The ladder's own size — the denominator in "4th of 12". It is `<=` team.active_members, never
            // equal to it: the roster read carries no `withTrashed()`, so a soft-deleted account holding a
            // live membership is a member of the workspace and not a row on the ladder.
            'member_count' => $scoreboard->ladder->memberCount,
            'team' => [
                // ⚠️ THREE OF THESE DELIBERATELY EXCEED WHAT THE LADDER SHOWS, IN THREE DIFFERENT WAYS
                // (ADR-0020 §D11(c)), and the page states each one in COPY rather than in a tooltip:
                // `points` and `contributors` include members who have since LEFT (the award ledger is
                // append-only, so their history outlives their membership), and `responses` includes GUEST
                // submissions, which credit nobody because crediting the form's owner would make the ladder
                // a contest between public links. None is an error; a surface showing them beside the
                // ladder has to say which is which.
                'points' => $scoreboard->team->points,
                'responses' => $scoreboard->team->responses,
                'published_forms' => $scoreboard->team->publishedForms,
                'active_members' => $scoreboard->team->activeMembers,
                'badges' => $scoreboard->team->badges,
                'contributors' => $scoreboard->team->contributors,
            ],
        ];
    }
}
