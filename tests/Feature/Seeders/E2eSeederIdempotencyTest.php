<?php

declare(strict_types=1);

use App\Models\Domain;
use App\Models\Form;
use App\Models\Notification;
use App\Models\NotificationPreference;
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

it('converges the custom-domain fixture too, and never activates one', function (): void {
    // H22b. Same upsert-keyed shape as the analytics block, and the same reason: every OTHER block in the
    // seeder is doesntExist()-guarded, so a change here would go green on CI's fresh database while every
    // developer's box kept the old rows.
    $seeder = new E2eSeeder;
    $seeder->run();

    $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();

    // The projection is deliberately TIMESTAMP-FREE except for whether each stamp is set. The fixture's
    // dates are RELATIVE ("checked 20 minutes ago") and are re-stamped on every re-seed on purpose, so the
    // page keeps reading sensibly on a box seeded weeks ago — comparing them byte-for-byte would assert
    // the opposite of the intended behaviour. What must converge is the SHAPE: which hostnames exist, what
    // state each is in, and that there are still two of them rather than four.
    /** @return array<int, array<string, mixed>> */
    $shape = fn (): array => Domain::unscopedQuery()
        ->where('tenant_id', $tenant->id)
        ->whereRaw("position('.' in domain) > 0")
        ->orderBy('domain')
        ->get()
        ->map(fn (Domain $d): array => [
            'domain' => $d->domain,
            'token' => $d->verification_token,
            'verified' => $d->verified_at !== null,
            'activated' => $d->activated_at !== null,
            'is_primary' => $d->is_primary,
        ])
        ->all();

    $first = $shape();

    $seeder->seedCustomDomains($tenant);

    expect($shape())->toBe($first)
        ->and($first)->toHaveCount(2);

    // ⚠️ THE LOAD-BEARING ASSERTION. An activated row is visible to Domain's global scope, so it would
    // become the host TenantUrl::toPublic() builds every resume link on — and in e2e that is a hostname
    // the browser cannot resolve. The guest-runtime specs would fail looking like a public-runtime defect.
    expect(collect($first)->where('activated', true)->all())->toBe([]);

    // One pending, one verified-awaiting-operator: the two states the page has to render honestly.
    expect(collect($first)->where('verified', true)->count())->toBe(1);
});

it('converges the notification fixture, and keeps one row on the reviewer on purpose', function (): void {
    // I4. Upsert-keyed on `fixtureUuid()` rather than guarded by `exists()`, and the guard choice is the
    // interesting part: the table IS empty when the block runs (this seeder never routes a write through
    // SubmissionPipeline / TenantMembershipService / SubmissionReviewService, the only announce sites for
    // I3's listeners), so an emptiness guard would work today and start silently skipping the moment a
    // later seed uses the real writers.
    $seeder = new E2eSeeder;
    $seeder->run();

    $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();
    /** @var User $owner */
    $owner = User::query()->withoutGlobalScopes()->findOrFail($tenant->owner_user_id);

    /** @return array<string, mixed> */
    $shape = fn (): array => DB::transaction(function () use ($tenant, $owner): array {
        TenantContext::applyLocal($tenant->id, (string) $owner->getKey());

        return [
            // Timestamp-free by design: `created_at` is relative ("6 minutes ago") and is re-stamped on
            // every re-seed on purpose, so the popover keeps reading sensibly on a box seeded weeks ago.
            // What must converge is which rows exist, whose they are, and which are unread.
            'rows' => Notification::query()
                ->orderBy('id')
                ->get()
                ->map(fn (Notification $n): array => [
                    'user' => (string) $n->user_id,
                    'type' => $n->type->value,
                    'read' => $n->read_at !== null,
                    'emailed' => $n->emailed_at !== null,
                ])
                ->all(),
            'owner_unread' => Notification::query()->forUser($owner)->unread()->count(),
            'preferences' => NotificationPreference::query()
                ->orderBy('notification_type')
                ->get()
                ->map(fn (NotificationPreference $p): array => [
                    'type' => $p->notification_type->value,
                    'in_app' => $p->in_app_enabled,
                    'email' => $p->email_enabled,
                ])
                ->all(),
        ];
    });

    $first = $shape();

    DB::transaction(function () use ($seeder, $tenant, $owner): void {
        TenantContext::applyLocal($tenant->id, (string) $owner->getKey());
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        // Resolved on the DEFAULT connection, inside the tenant context: `pgsql_auth` is a separate
        // connection and cannot see RefreshDatabase's uncommitted transaction — the same trap this file's
        // header records for re-running `run()`. Under the users join-shape policy the reviewer is visible
        // here because they are an ACTIVE co-tenant member of the acting owner.
        /** @var User $reviewer */
        $reviewer = User::query()->where('email', 'reviewer@meridian.test')->firstOrFail();
        $seeder->seedNotifications($owner, $reviewer);
    });

    expect($shape())->toBe($first)
        ->and($first['rows'])->toHaveCount(7);

    // ⚠️ THE LOAD-BEARING ASSERTION, and the reason the fixture is not seven Owner rows. Playwright only
    // ever logs in as the Owner, so a regression that dropped `Notification::scopeForUser()` from the feed
    // would be INVISIBLE in an all-Owner fixture — the badge would read the same either way. With the
    // reviewer's row present, the bell must say 4 while the table holds 7.
    expect($first['owner_unread'])->toBe(4)
        ->and(collect($first['rows'])->where('user', (string) $owner->getKey())->count())->toBe(6);

    // The one diverging preference, so the /settings card renders a non-default state for the axe scan.
    // On `review_requested` deliberately — the one type the Owner holds no notification for, so the
    // fixture cannot contradict itself.
    expect($first['preferences'])->toBe([
        ['type' => 'review_requested', 'in_app' => false, 'email' => false],
    ]);
});
