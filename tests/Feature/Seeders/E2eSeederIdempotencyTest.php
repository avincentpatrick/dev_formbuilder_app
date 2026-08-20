<?php

declare(strict_types=1);

use App\Enums\TenantUserStatus;
use App\Models\BadgeAward;
use App\Models\Domain;
use App\Models\Form;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\PointAward;
use App\Models\SavedReportView;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\Tenant;
use App\Models\TenantUser;
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
            // K1c — the seeded gamification ledger, projected for the same reason as everything above it:
            // it is written through the real recorder, so what has to converge is `ON CONFLICT DO NOTHING`.
            'awards' => PointAward::count(),
            'badges' => BadgeAward::count(),
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

    // ⚠️ TEN, NOT SEVEN, AND THE THREE EXTRA ROWS ARE NOT SEEDED — THEY ARE EARNED (K1b). This fixture is
    // seven hand-authored rows, and it stayed seven for three increments. It moved when badges shipped,
    // because `seedForms()` drives `FormService::create()` and `PublishService::publish()` FOR REAL: the
    // Owner genuinely creates and publishes forms here, so the engine genuinely awards `first_form`,
    // `first_publish` and `publisher`, and each announces. **Measured, not predicted** — the run that
    // reddened this said 10, and the three rows are all the Owner's and all unread.
    //
    // Read that as the fixture becoming MORE faithful rather than as drift: a workspace that has published
    // three forms really does hold those badges, and a bell that hid them would be the lie.
    expect($shape())->toBe($first)
        ->and($first['rows'])->toHaveCount(10);

    // ⚠️ THE LOAD-BEARING ASSERTION, and the reason the fixture is not ten Owner rows. Playwright only
    // ever logs in as the Owner, so a regression that dropped `Notification::scopeForUser()` from the feed
    // would be INVISIBLE in an all-Owner fixture — the badge would read the same either way. With the
    // reviewer's row present, the bell must say 7 while the table holds 10.
    expect($first['owner_unread'])->toBe(7)
        ->and(collect($first['rows'])->where('user', (string) $owner->getKey())->count())->toBe(9);

    // The reviewer's single row is what the assertion above is actually protecting, so it is pinned
    // directly rather than inferred from the arithmetic — badges are Owner-only here, so a future badge
    // earned by the reviewer would otherwise silently restore the arithmetic while breaking the intent.
    expect(collect($first['rows'])->where('user', '!=', (string) $owner->getKey())->count())->toBe(1);

    // The one diverging preference, so the /settings card renders a non-default state for the axe scan.
    // On `review_requested` deliberately — the one type the Owner holds no notification for, so the
    // fixture cannot contradict itself.
    expect($first['preferences'])->toBe([
        ['type' => 'review_requested', 'in_app' => false, 'email' => false],
    ]);
});

/*
|--------------------------------------------------------------------------
| J3a — every seeded identity is VERIFIED, because the e2e suite cannot log in otherwise.
|
| ⚠️ THIS TEST EXISTS BECAUSE CI FOUND WHAT NOTHING LOCAL COULD. J3a mounted `verified` on the authenticated
| tenant group, and `tests/e2e/global-setup.ts` signs the demo owner in and waits for a dashboard URL BEFORE
| any spec runs — so an unverified seeded identity is not one red test, it is all 506 with none executed and
| a 30-second timeout as the only diagnosis.
|
| The first repair was itself the bug, which is the part worth pinning. `User` declares
| `#[Fillable(['name','email','password'])]`, so `email_verified_at` is not mass-assignable, and the obvious
| fix — create the row, then stamp it — is a SILENT NO-OP: `users` carries a permissive INSERT policy but an
| OWN-ROW UPDATE policy keyed on `app.current_user_id`, which is null throughout seeding. The follow-up
| UPDATE matches no policy, affects zero rows, throws nothing, and the account stays unverified. It has to be
| one INSERT (`forceFill()` on a NEW model). `promoteToSuperAdmin()` carries the privileged-connection
| version of the same lesson, and Lane B's P1b JIT provisioning arrived at it independently.
|
| Read on `pgsql_privileged` for the reason the header above gives: identities are created on the default
| connection inside RefreshDatabase's transaction, but the join-shape RLS on `users` hides them from an
| unscoped read.
*/
it('verifies every seeded identity except the invitation placeholder', function (): void {
    $seeder = new E2eSeeder;
    $seeder->run();

    $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();

    // ⚠️ TWO READS, BECAUSE ONE POLICY ARM CANNOT SEE BOTH KINDS OF ROW — and that asymmetry is the very
    // thing under test. `users_users_visibility` reveals a row only when it is the ACTING user or an
    // **active** co-tenant, so under a plain tenant context `pending@` (status Invited) is invisible and the
    // one identity that must stay UNVERIFIED could not be checked at all.
    //
    // `tenant_users` is strict-RLS by TENANT rather than by status, so the invited membership row is
    // visible; reading the placeholder as ITSELF (`app.current_user_id = <their id>`) then satisfies the
    // policy's first arm.
    //
    // Neither read uses `pgsql_privileged`/`pgsql_auth`: those are separate SESSIONS and cannot see
    // RefreshDatabase's uncommitted transaction, so a privileged read returns nothing and every assertion
    // below passes vacuously — which is exactly how the first draft of this test went green while proving
    // nothing.
    [$active, $pendingVerifiedAt] = DB::transaction(function () use ($tenant): array {
        TenantContext::applyLocal($tenant->id, null);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $rows = User::query()
            ->whereIn('email', ['demo@meridian.test', 'reviewer@meridian.test'])
            ->pluck('email_verified_at', 'email')
            ->all();

        $pendingId = (string) TenantUser::query()
            ->where('status', TenantUserStatus::Invited)
            ->value('user_id');

        TenantContext::applyLocal($tenant->id, $pendingId);
        $pending = User::query()->whereKey($pendingId)->first();

        return [$rows, $pending?->email_verified_at];
    });

    // Anti-vacuity: a renamed constant or a changed address would otherwise make every assertion below true
    // over an empty set.
    expect($active)->toHaveCount(2);

    // `demo@` is the identity `tests/e2e/global-setup.ts` signs in as FIRST, so its verification is what the
    // whole 506-spec suite rests on: unverified, the login lands on /email/verify, the wait for a dashboard
    // URL times out, and every spec fails before one runs. That is the red run this test exists to prevent.
    expect($active['demo@meridian.test'])->not->toBeNull()
        ->and($active['reviewer@meridian.test'])->not->toBeNull();

    // ⚠️ AND THE ONE THAT MUST STAY NULL. Stamping this placeholder turns the invitation page from "set a
    // name and a password" into "sign in as the invited account" — silently breaking the fixture J3b's
    // invitation accessibility scan depends on. Verifying everything is as wrong as verifying nothing.
    //
    // ⚠️ SINCE M8 THIS ASSERTION IS NECESSARY BUT NO LONGER SUFFICIENT, AND THE GAP IS WORTH KNOWING.
    // `InvitationController::show()` used to read this null timestamp directly; it now asks
    // `TenantMembershipService::identityIsEstablished()`, where a verified address is one of FOUR positive
    // signals. A future seeder change that gave this placeholder a confirmed second factor, a `google_id`, or
    // a joined membership in a second workspace would move the page just as surely — and this case would
    // stay green while the axe scan quietly stopped scanning a password form.
    expect($pendingVerifiedAt)->toBeNull();
});
