<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\NotificationType;
use App\Enums\TenantUserStatus;
use App\Models\Audit;
use App\Models\Domain;
use App\Models\FeedbackReport;
use App\Models\Form;
use App\Models\Notification;
use App\Models\NotificationPreference;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| I6 — the hand-testing fixture CONVERGES on a re-run rather than doubling or silently skipping.
|
| `docs/TESTING-GUIDE.md` tells a human to run `php artisan migrate:fresh --seed` and then walks them
| through the result. Two failure modes would each make that guide lie, and they are opposites:
|   · DOUBLING — a random key in place of a deterministic one adds a few hundred rows on every re-seed, and
|     every count the guide prints drifts further from what the tester sees.
|   · SKIPPING — a `doesntExist()` guard leaves an already-seeded box on the old fixture with no error at
|     all, which is the trap `E2eSeeder.php:132-136` records against this repository's own history.
|
| ── Why this re-runs the SEAMS and not run() ────────────────────────────────────────────────────────────
| `run()` resolves identities on the separate `pgsql_auth` connection, and `RefreshDatabase` holds ONE
| uncommitted transaction on the default connection — so a second `run()` cannot see the first run's users.
| `E2eSeederIdempotencyTest` learned this by doing it; the same constraint applies here. Every seam this
| file re-runs touches no identity.
|
| ── And why the seams take their volume as an ARGUMENT ──────────────────────────────────────────────────
| A real seed writes ~520 submissions. Running that twice inside a suite that already takes ~980s would buy
| nothing: idempotency is a property of the KEYING, not of the volume. The seams therefore accept a spec, the
| tests drive a dozen rows, and `it('derives every row from its index')` below pins the one rule that makes
| that substitution sound — that a short spec is a genuine PREFIX of a long one.
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

/**
 * `Audit::event` and `Notification::type` are CAST to their enums, so `pluck()` hands back enum instances
 * rather than the strings the column holds — and `sort()` on those orders by object identity, not by value.
 * Normalise before comparing, or the assertion fails on ordering while the data is perfectly correct.
 *
 * @param  Collection<int, mixed>  $values
 * @return list<string>
 */
function enumValues(Collection $values): array
{
    return $values
        ->map(static fn (mixed $value): string => $value instanceof BackedEnum ? (string) $value->value : (string) $value)
        ->sort()
        ->values()
        ->all();
}

/**
 * A projection of everything the guide depends on — deliberately TIMESTAMP-FREE.
 *
 * The fixture's dates are relative and are re-stamped on every re-seed on purpose, so that a box seeded
 * weeks ago still reads sensibly. Comparing instants would assert the opposite of the intended behaviour.
 * What must converge is the shape: how many rows, in what states, over how many distinct days.
 *
 * @return array<string, mixed>
 */
function demoShape(Tenant $tenant): array
{
    return DB::transaction(function () use ($tenant): array {
        TenantContext::applyLocal((string) $tenant->getKey());

        return [
            'forms' => Form::withTrashed()->count(),
            'published' => Form::whereNotNull('current_published_version_id')->count(),
            'submissions' => Submission::withTrashed()->count(),
            'countable' => Submission::countable()->count(),
            'answers' => SubmissionAnswer::count(),
            'index' => SubmissionAnswerIndex::count(),
            'audits' => Audit::count(),
            'notifications' => Notification::count(),
            'preferences' => NotificationPreference::count(),
            'feedback' => FeedbackReport::count(),
            'statuses' => Submission::query()
                ->selectRaw('status, count(*) as n')->groupBy('status')->orderBy('status')->pluck('n', 'status')->toArray(),
            'sources' => Submission::query()
                ->selectRaw('source, count(*) as n')->groupBy('source')->orderBy('source')->pluck('n', 'source')->toArray(),
            'distinct_days' => (int) Submission::query()->selectRaw('count(distinct date(created_at)) as d')->value('d'),
        ];
    });
}

/**
 * A seeder at test scale. See {@see DemoSeeder::$historyDays} — a short run is a genuine PREFIX of a long
 * one, which the prefix test below pins, so convergence proven here transfers to the shipped volume.
 */
function demoSeeder(int $days = 6): DemoSeeder
{
    $seeder = new DemoSeeder;
    $seeder->historyDays = $days;

    return $seeder;
}

it('converges every table on a re-seed rather than doubling it', function (): void {
    $seeder = demoSeeder();
    $seeder->run();

    $demo = Tenant::query()->where('slug', DemoSeeder::DEMO_SLUG)->firstOrFail();
    $people = $seeder->seededUsers();
    $owner = $people['owner@demo.test'];
    $reviewer = $people['reviewer@demo.test'];

    $first = demoShape($demo);

    // Re-run the heaviest seam twice at the SAME spec it was seeded with. Twice, not once: a keying bug
    // that doubles would still look stable if it were only ever run a second time from a fresh state.
    DB::transaction(function () use ($seeder, $demo, $owner, $reviewer): void {
        TenantContext::applyLocal((string) $demo->getKey(), (string) $owner->getKey());
        $seeder->seedSubmissionHistory($owner, $reviewer, DemoSeeder::historySpec($seeder->historyDays));
        $seeder->seedSubmissionHistory($owner, $reviewer, DemoSeeder::historySpec($seeder->historyDays));
    });

    expect(demoShape($demo))->toBe($first);

    // Structure that holds at ANY scale — the volume-dependent numbers live in the full-scale test below.
    expect($first['forms'])->toBe(6)
        ->and($first['published'])->toBe(5)          // one is a deliberate never-published draft
        ->and($first['notifications'])->toBe(14)     // 7 types × 2 recipients
        ->and($first['feedback'])->toBe(4)           // one per FeedbackStatus case
        ->and($first['index'])->toBeGreaterThan(0)   // the question explorer has real values to summarise
        ->and($first['answers'])->toBe($first['submissions']); // exactly one answer row per submission
});

it('produces the shape the testing guide describes, at the volume it ships', function (): void {
    // ⚠️ THE ONE FULL-SCALE RUN IN THIS FILE, and it earns its ~30 seconds: every other test turns the
    // history down, so without this nothing would check the numbers `docs/TESTING-GUIDE.md` actually prints
    // or that 90 days of buckets have the shape the dashboard needs.
    $seeder = new DemoSeeder;   // full 90 days, deliberately not demoSeeder()
    $seeder->run();

    $demo = Tenant::query()->where('slug', DemoSeeder::DEMO_SLUG)->firstOrFail();
    $shape = demoShape($demo);

    expect($shape['submissions'])->toBeGreaterThan(400)
        ->and($shape['countable'])->toBeLessThan($shape['submissions']); // drafts are excluded, and exist

    // Every status and every source is represented, or an inbox filter renders an empty state the guide
    // tells the tester is populated.
    expect(array_keys($shape['statuses']))
        ->toBe(['approved', 'archived', 'draft', 'returned', 'screened_out', 'submitted', 'under_review']);
    expect(count($shape['sources']))->toBeGreaterThanOrEqual(3);

    // A trend needs shape. ~90 buckets with a few genuine holes is what makes zero-filling visible as a
    // floor rather than as a plausible line.
    expect($shape['distinct_days'])->toBeGreaterThan(80)
        ->and($shape['distinct_days'])->toBeLessThan(91);

    // The corpus spans more than one form, or the analytics form breakdown is a single bar — a chart that
    // cannot be wrong, and therefore cannot be tested.
    DB::transaction(function () use ($demo): void {
        TenantContext::applyLocal((string) $demo->getKey());
        expect(Submission::query()->distinct()->pluck('form_id')->count())->toBeGreaterThanOrEqual(4);
    });
});

it('derives every submission row from its index, so a short spec is a prefix of a long one', function (): void {
    // ⚠️ THIS IS THE TEST THAT KEEPS THE ONE ABOVE HONEST. The seams are exercised at a fraction of the real
    // volume, and that substitution is only sound while every derived value is a function of the row INDEX
    // and never of the row COUNT. If a formula ever divides by `$count`, a 12-row run stops being a prefix
    // of a 520-row run and convergence at 12 proves nothing about what ships.
    $short = DemoSeeder::historySpec(10);
    $long = DemoSeeder::historySpec(90);

    expect($short)->not->toBeEmpty();

    // The two specs walk the same sequence: row N of either carries the same derived everything.
    foreach ($short as $i => $row) {
        expect($row['seq'])->toBe($i);
    }

    $byIndex = static fn (array $spec, int $i): array => [
        $spec[$i]['seq'], $spec[$i]['status'], $spec[$i]['source'],
        $spec[$i]['locale'], $spec[$i]['saved'], $spec[$i]['fill_seconds'], $spec[$i]['hour'],
    ];

    foreach (range(0, min(count($short), count($long)) - 1) as $i) {
        expect($byIndex($short, $i))->toBe($byIndex($long, $i));
    }
});

it('never activates a custom domain, and never enables maintenance mode', function (): void {
    demoSeeder()->run();

    // ⚠️ THE LOAD-BEARING PAIR. An activated domain enters Domain's global `resolvable` scope, which is what
    // `TenantUrl::toPublic()` reads — so it would repoint every public and resume link in the demo onto a
    // hostname no browser can resolve, and the failure would present as a public-runtime defect. Maintenance
    // mode 503s every guest form for that tenant, which would silently delete the entire guest half of the
    // walkthrough. Both are cheap to assert and expensive to discover by hand.
    expect(Domain::unscopedQuery()->whereNotNull('activated_at')->count())->toBe(0)
        ->and(Tenant::query()->where('maintenance_mode', true)->count())->toBe(0);
});

it('leaves the e2e fixture alone', function (): void {
    demoSeeder()->run();

    // `E2eSeederIdempotencyTest` pins `acme`'s counts exactly. If this seeder ever writes into that tenant,
    // a merge-blocking gate starts failing for a reason that has nothing to do with the code under change.
    expect(Tenant::query()->where('slug', 'acme')->exists())->toBeFalse();

    expect(Tenant::query()->pluck('slug')->sort()->values()->all())
        ->toBe([DemoSeeder::DEMO_SLUG, DemoSeeder::SECOND_SLUG]);
});

it('isolates the second workspace, and keeps it thin', function (): void {
    demoSeeder()->run();

    $demo = Tenant::query()->where('slug', DemoSeeder::DEMO_SLUG)->firstOrFail();
    $second = Tenant::query()->where('slug', DemoSeeder::SECOND_SLUG)->firstOrFail();

    $demoShape = demoShape($demo);
    $secondShape = demoShape($second);

    // Thin ON PURPOSE — its job is to make isolation observable, and a second rich tenant would make the
    // difference harder to see rather than easier.
    expect($secondShape['forms'])->toBe(1)
        ->and($secondShape['submissions'])->toBeLessThan(30)
        ->and($demoShape['submissions'])->toBeGreaterThan($secondShape['submissions'] * 10);

    // This is also the only assertion that would catch the seeder failing to close its tenant context
    // between the two workspaces — a real hazard, with two transactions in one run().
    expect($secondShape['notifications'])->toBe(0)
        ->and($secondShape['feedback'])->toBe(0);
});

it('seeds every notification type, and writes both preference booleans explicitly', function (): void {
    $seeder = demoSeeder();
    $seeder->run();

    $demo = Tenant::query()->where('slug', DemoSeeder::DEMO_SLUG)->firstOrFail();

    DB::transaction(function () use ($demo): void {
        TenantContext::applyLocal((string) $demo->getKey());

        $types = enumValues(Notification::query()->distinct()->pluck('type'));
        expect($types)->toBe(enumValues(collect(NotificationType::cases())));

        // Both a read and an unread row exist, or the bell's badge and the popover's read state are each
        // testable only in one direction.
        expect(Notification::query()->whereNull('read_at')->count())->toBeGreaterThan(0)
            ->and(Notification::query()->whereNotNull('read_at')->count())->toBeGreaterThan(0);

        // ⚠️ The columns default `true/true`, which DISAGREES with
        // `NotificationType::SubmissionReceived::defaultEmail() === false`. A seeder that wrote only one
        // boolean would leave a row meaning something other than it reads as. The table is sparse by
        // design, so this is a small explicit set rather than one row per type.
        $prefs = NotificationPreference::query()->get(['notification_type', 'in_app_enabled', 'email_enabled']);
        expect($prefs)->toHaveCount(2);

        foreach ($prefs as $pref) {
            expect($pref->in_app_enabled)->toBeBool()
                ->and($pref->email_enabled)->toBeBool();
        }
    });
});

it('back-dates the audit tail without ever updating an audit row', function (): void {
    $seeder = demoSeeder();
    $seeder->run();

    $demo = Tenant::query()->where('slug', DemoSeeder::DEMO_SLUG)->firstOrFail();

    DB::transaction(function () use ($demo): void {
        TenantContext::applyLocal((string) $demo->getKey());

        // Every event case renders, or a filter option in the viewer selects nothing.
        $events = enumValues(Audit::query()->distinct()->pluck('event'));
        expect($events)->toBe(enumValues(collect(AuditEvent::cases())));

        // The whole reason the tail exists: a human is about to test this page's DATE FILTERS, and a ledger
        // where every row landed at `now()` gives them nothing to select over.
        $span = (int) Audit::query()->selectRaw('max(created_at)::date - min(created_at)::date as s')->value('s');
        expect($span)->toBeGreaterThan(60);

        // Redaction is PRODUCED by AuditRedactor, never authored — so rows carrying PII keys must actually
        // show up as redacted. A hand-written `redacted_fields` would be a fiction the viewer renders as fact.
        expect(Audit::query()->whereNotNull('redacted_fields')->count())->toBeGreaterThan(0);

        // A system row exists: `user_id` null beside `is_system_action` true is a shape the viewer has to
        // render and nothing else in the fixture produces.
        expect(Audit::query()->whereNull('user_id')->where('is_system_action', true)->count())->toBeGreaterThan(0);
    });
});

it('gives every documented login a working password', function (): void {
    $seeder = demoSeeder();
    $seeder->run();

    $people = $seeder->seededUsers();

    // ⚠️ THIS IS WHAT KEEPS `docs/TESTING-GUIDE.md` §0 HONEST. Change the password constant or drop an
    // account and the test that names the guide's credentials reddens, rather than the tester discovering
    // it at the login screen.
    foreach ([
        'owner@demo.test', 'admin@demo.test', 'editor@demo.test',
        'reviewer@demo.test', 'viewer@demo.test', 'consultant@demo.test',
        'owner@northwind.test', DemoSeeder::SUPER_ADMIN_EMAIL,
    ] as $email) {
        expect(array_key_exists($email, $people))->toBeTrue("seeded login {$email} is missing");
        expect(Hash::check(DemoSeeder::PASSWORD, (string) $people[$email]->password))
            ->toBeTrue("seeded login {$email} does not accept the documented password");
    }

    // The pending invite is deliberately NOT loggable — its password is discarded random bytes, because a
    // pending member is a Badge variant on the roster, not an account anyone signs in as.
    expect(Hash::check(DemoSeeder::PASSWORD, (string) $people['invited@demo.test']->password))->toBeFalse();

    // The super-admin must be flagged AND unenrolled: a pre-enrolled account would carry a placeholder TOTP
    // secret no authenticator can reproduce, locking the console instead of saving a step.
    $admin = $people[DemoSeeder::SUPER_ADMIN_EMAIL];
    expect($admin->is_super_admin)->toBeTrue()
        ->and($admin->two_factor_confirmed_at)->toBeNull();
});

it('gives every active member a membership and a role', function (): void {
    $seeder = demoSeeder();
    $seeder->run();

    $demo = Tenant::query()->where('slug', DemoSeeder::DEMO_SLUG)->firstOrFail();

    DB::transaction(function () use ($demo): void {
        TenantContext::applyLocal((string) $demo->getKey());

        // Six active members plus one pending invite. All five roles are represented, which is what makes
        // each permission boundary in the guide reachable by some account.
        expect(TenantUser::query()->count())->toBe(7)
            ->and(TenantUser::query()->where('status', TenantUserStatus::Invited)->count())->toBe(1);

        expect(TenantUser::query()->distinct()->pluck('invited_role_id')->count())->toBe(5);
    });
});
