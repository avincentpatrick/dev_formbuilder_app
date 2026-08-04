<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\SavedReportView;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\E2eSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| H24b2 — the analytics fixture CONVERGES on a re-run rather than doubling or silently skipping.
|
| "Upsert-safe" was an obligation handed to this increment in prose, and nothing in the repository checked
| it — which is how the trap the seeder's own Business-plan comment records came to exist in the first
| place: a `doesntExist()`-guarded block goes green on CI (a fresh database every run) while every
| developer's box keeps the old shape forever, and nobody finds out until a Playwright spec fails on state
| rather than on code.
|
| The two failure modes are opposite and this test catches both:
|   · DOUBLING — a random `client_submission_uuid` in place of the deterministic one would add 30 more rows
|     on every re-seed, and every chart in the Playwright suite would drift further from its assertions.
|   · SKIPPING — a `doesntExist()` guard would leave an already-seeded box on the old three-row fixture with
|     no error at all.
|
| ── Why this re-runs seedAnalyticsFixture() and not run() ───────────────────────────────────────────────
| `run()` resolves identities on the separate `pgsql_auth` connection, and `RefreshDatabase` holds ONE
| uncommitted transaction on the default connection — so a second `run()` cannot see the first run's user
| and tries to insert the same email again. That 23505 says nothing about the fixture. `DatabaseTruncation`
| would commit and avoid it, but it truncates on teardown, which both fails on PostGIS's `spatial_ref_sys`
| (the app role has no privilege) and wipes state out from under every later test in the suite — a fix
| worse than the problem, and one this file learned by doing it.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

afterEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @return array<string, mixed> */
function seededShape(Tenant $tenant, User $owner): array
{
    return DB::transaction(function () use ($tenant, $owner): array {
        TenantContext::applyLocal($tenant->id, (string) $owner->getKey());

        $intake = Form::query()->where('title', 'Clinic Intake')->firstOrFail();

        return [
            'forms' => Form::withTrashed()->count(),
            'published' => Form::whereNotNull('current_published_version_id')->count(),
            'submissions' => Submission::withTrashed()->count(),
            'countable' => Submission::countable()->count(),
            'answers' => SubmissionAnswer::count(),
            'index' => SubmissionAnswerIndex::count(),
            'views' => SavedReportView::count(),
            'intake_statuses' => Submission::query()
                ->where('form_id', $intake->id)
                ->pluck('status')->map->value->sort()->values()->all(),
        ];
    });
}

it('converges on exactly the shape the analytics page needs, however many times it is run', function (): void {
    $seeder = new E2eSeeder;
    $seeder->run();

    $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();
    /** @var User $owner */
    $owner = User::query()->withoutGlobalScopes()->findOrFail($tenant->owner_user_id);

    $first = seededShape($tenant, $owner);

    // The re-run. Tenant context is re-entered because the shape read above closed its transaction.
    DB::transaction(function () use ($seeder, $tenant, $owner): void {
        TenantContext::applyLocal($tenant->id, (string) $owner->getKey());
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $seeder->seedAnalyticsFixture($owner, $tenant);
    });

    $second = seededShape($tenant, $owner);

    // Convergence: identical, not merely "no error".
    expect($second)->toBe($first);

    // The numbers `/analytics` and `/dashboard` actually render in Playwright, written down HERE so a
    // fixture change reddens the file that names the shape rather than an axe assertion three files away
    // that only knows a chart looks different.
    expect($first['published'])->toBe(7);        // six legacy + Programme Uptake → the form axis OVERFLOWS top-5
    expect($first['countable'])->toBe(30);       // 27 new + the 3 legacy Clinic Intake rows
    expect($first['submissions'])->toBe(33);     // the 3 unconverted drafts scopeCountable() excludes
    expect($first['views'])->toBe(2);            // one working, one deliberately unreadable (`v: 99`)
    expect($first['index'])->toBeGreaterThan(0); // the question explorer has real values to summarise

    // The legacy Clinic Intake rows survive. The new block runs BEFORE them and writes nothing on that
    // form, because their guard is `doesntExist()` on it: a single row from the new fixture would suppress
    // all three status-varied rows, and responsive-axe's "Submission detail" scan opens the inbox's FIRST
    // row — which is one of these, since the inbox orders by uuidv7 rather than by submitted_at.
    expect($first['intake_statuses'])->toBe(['approved', 'returned', 'submitted']);
});
