<?php

declare(strict_types=1);

use App\Enums\FormScheduleState;
use App\Events\FormClosed;
use App\Events\FormOpened;
use App\Jobs\Forms\SweepTenantScheduledFormsJob;
use App\Jobs\Maintenance\SweepScheduledFormsJob;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H12a — the state-flip sweep (a cross-tenant MaintenanceJob fan-out).
|--------------------------------------------------------------------------
| SweepScheduledFormsJob fans out one SweepTenantScheduledFormsJob per active tenant; each advances published
| forms across their opens_at/closes_at boundaries and emits form.opened/form.closed exactly once per
| transition. Committing test: the fan-out enqueues on `database` and is drained with two
| workOneJob('scheduled-maintenance') calls (parent → child). Events are faked (they fire in-transaction, plain
| event()) so they are observable immediately; only FormOpened/FormClosed are faked so job processing runs.
*/

beforeEach(function (): void {
    TenantContext::flush();
    config()->set('queue.default', 'database');
    $this->seed(RolePermissionSeeder::class);
    $this->tenant = inboxTenant('acme');
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
});

/**
 * Run the sweep end to end: the fan-out, then ONE CHILD PER ACTIVE TENANT.
 *
 * ⚠️ THIS WAS TWO HARD-CODED `workOneJob()` CALLS UNTIL M44, AND THAT IS WHY NOTHING HERE COULD SEE A WRONG
 * TENANT ID. With a second tenant the parent enqueues two children and the second was simply never worked, so
 * every downstream assertion read identically — a new green test that still cannot see the defect.
 *
 * ⚠️ `$activeTenants` IS ASSERTED, NEVER INFERRED — the {@see runRefreshSweep()} rationale (M6). Draining
 * "however many jobs happen to be queued" keeps passing when the sweep dispatches NONE; M44 measured a
 * deliberately dead `sweep()` against the webhook sibling of this suite and TWO of its three cases stayed
 * green. The bound is the asserted count, so this cannot hang CI either.
 *
 * ⚠️ The count is filtered to this queue. This helper is called TWICE in one case below, and a real
 * `form.closed` listener enqueues onto `webhooks` in between — an unfiltered count would absorb it and the
 * second call would measure the wrong thing.
 *
 * ⚠️ AND `failed_jobs` IS CHECKED BECAUSE THE WORKER SWALLOWS EXCEPTIONS ({@see workOneJob()}'s docblock): a
 * child that died under the SECOND tenant would otherwise read as "the sweep did nothing".
 */
function runSweep(int $activeTenants = 1): void
{
    SweepScheduledFormsJob::dispatch();
    workOneJob('scheduled-maintenance'); // the fan-out enqueues one child per active tenant

    expect(DB::table('jobs')->where('queue', 'scheduled-maintenance')->count())->toBe($activeTenants);

    for ($i = 0; $i < $activeTenants; $i++) {
        workOneJob('scheduled-maintenance'); // each child advances ITS OWN tenant's scheduled forms
    }

    expect(DB::table('failed_jobs')->count())->toBe(0);
}

it('opens a scheduled form whose opens_at has passed, emitting form.opened once', function (): void {
    Event::fake([FormOpened::class, FormClosed::class]);
    $version = scheduledForm($this->tenant, $this->user, ['opens_at' => now()->subMinute(), 'schedule_state' => 'scheduled']);

    runSweep();

    enterTenant($this->tenant->id, $this->user->id); // context cleared by the worker; re-enter to read
    expect(Form::findOrFail($version->form_id)->schedule_state)->toBe(FormScheduleState::Open);
    Event::assertDispatched(FormOpened::class, 1);
    Event::assertNotDispatched(FormClosed::class);
});

it('closes an open form whose closes_at has passed, emitting form.closed once', function (): void {
    Event::fake([FormOpened::class, FormClosed::class]);
    $version = scheduledForm($this->tenant, $this->user, ['closes_at' => now()->subMinute(), 'schedule_state' => 'open']);

    runSweep();

    enterTenant($this->tenant->id, $this->user->id);
    expect(Form::findOrFail($version->form_id)->schedule_state)->toBe(FormScheduleState::Closed);
    Event::assertDispatched(FormClosed::class, 1);
});

it('does not re-emit on a second sweep of an already-closed form', function (): void {
    Event::fake([FormOpened::class, FormClosed::class]);
    $version = scheduledForm($this->tenant, $this->user, ['closes_at' => now()->subMinute(), 'schedule_state' => 'open']);

    runSweep();
    runSweep(); // the form is already 'closed' — the prior-state filter finds nothing to flip

    enterTenant($this->tenant->id, $this->user->id);
    expect(Form::findOrFail($version->form_id)->schedule_state)->toBe(FormScheduleState::Closed);
    Event::assertDispatched(FormClosed::class, 1);
});

it('closes a form past BOTH boundaries directly, emitting only form.closed', function (): void {
    Event::fake([FormOpened::class, FormClosed::class]);
    $version = scheduledForm($this->tenant, $this->user, [
        'opens_at' => now()->subHour(),
        'closes_at' => now()->subMinute(),
        'schedule_state' => 'scheduled',
    ]);

    runSweep();

    enterTenant($this->tenant->id, $this->user->id);
    expect(Form::findOrFail($version->form_id)->schedule_state)->toBe(FormScheduleState::Closed);
    Event::assertDispatched(FormClosed::class, 1);
    Event::assertNotDispatched(FormOpened::class);
});

it('ignores an unpublished (draft-status) form even if its schedule has arrived', function (): void {
    Event::fake([FormOpened::class, FormClosed::class]);
    $form = makeForm($this->user, 'Unpublished'); // status = draft
    $form->forceFill(['opens_at' => now()->subMinute(), 'schedule_state' => 'scheduled'])->save();

    runSweep();

    enterTenant($this->tenant->id, $this->user->id);
    expect(Form::findOrFail($form->id)->schedule_state)->toBe(FormScheduleState::Scheduled); // untouched
    Event::assertNotDispatched(FormOpened::class);
});

// ── M44 — the fan-out's IDENTITY, which a one-tenant fixture structurally cannot assert ───────────────────
//
// The production loop is correct; what was missing was a fixture wide enough to notice if it stopped being.
// Both cases go red on a hoisted loop variable, and they fail differently on purpose: the first names the
// child class (which appeared under tests/ only inside a comment before M44) and asserts the dispatched
// multiset; the second drives the real queue and asserts the per-tenant effect.

it('fans out one child per active tenant and holds no tenant context itself', function (): void {
    Bus::fake([SweepTenantScheduledFormsJob::class]);

    $other = inboxTenant('other');

    TenantContext::flush();
    (new SweepScheduledFormsJob)->handle();

    Bus::assertDispatchedTimes(SweepTenantScheduledFormsJob::class, 2);

    // ⚠️ Compared as a whole SET, never through Bus::assertDispatched($class, $closure): that is an
    // AT-LEAST-ONE-MATCH predicate, satisfied by the first of two identical jobs, so it cannot tell
    // "one child per tenant" from "the first tenant's id, twice" — which is exactly the defect.
    $expected = collect([$this->tenant, $other])
        ->map(fn (Tenant $tenant): string => (string) $tenant->getKey())
        ->sort()
        ->values()
        ->all();

    expect(Bus::dispatched(SweepTenantScheduledFormsJob::class)
        ->map(fn (SweepTenantScheduledFormsJob $job): string => $job->tenantId)
        ->sort()
        ->values()
        ->all())
        ->toBe($expected);

    // A sweep is cross-tenant by definition, so a context left behind here would be inherited by whatever
    // ran next on the worker. Never asserted in this file before M44.
    expect(TenantContext::currentTenantId())->toBeNull();
});

it('advances each active tenant\'s own form, emitting one form.closed and one form.opened', function (): void {
    Event::fake([FormOpened::class, FormClosed::class]);

    // ⚠️ OPPOSITE TRANSITIONS PER TENANT, DELIBERATELY. If both tenants were closing, a hoisted loop
    // variable would sweep acme twice and the second tenant's form would merely be *unchanged* — visible,
    // but only in one assertion. With acme closing and `other` opening, aliasing reddens FOUR independent
    // ways: other's state, the FormOpened count, and both tenant-id lists.
    $acmeVersion = scheduledForm($this->tenant, $this->user, ['closes_at' => now()->subMinute(), 'schedule_state' => 'open']);

    // The SECOND tenant is created inside this case, not in beforeEach: widening the shared fixture would
    // leave every existing single-tenant case draining only the first of two children — and, because
    // lazyById() enumerates by UUIDv7 (creation order), always acme's. They would all stay GREEN while
    // quietly measuring nothing. Coverage deleted rather than extended.
    $other = inboxTenant('other');
    $otherUser = User::factory()->create();
    enterTenant($other->id, $otherUser->id);
    $otherVersion = scheduledForm($other, $otherUser, ['opens_at' => now()->subMinute(), 'schedule_state' => 'scheduled']);

    enterTenant($this->tenant->id, $this->user->id);

    runSweep(activeTenants: 2);

    enterTenant($this->tenant->id, $this->user->id);
    expect(Form::findOrFail($acmeVersion->form_id)->schedule_state)->toBe(FormScheduleState::Closed);

    // ⛔ THE ASSERTION A ONE-TENANT FIXTURE CANNOT MAKE. Hoist the loop variable and both children carry
    // acme's id, so this form is never advanced at all.
    enterTenant($other->id, $otherUser->id);
    expect(Form::findOrFail($otherVersion->form_id)->schedule_state)->toBe(FormScheduleState::Open);

    Event::assertDispatchedTimes(FormClosed::class, 1);
    Event::assertDispatchedTimes(FormOpened::class, 1);

    // ⚠️ EventFake::dispatched() RETURNS ARGUMENT ARRAYS, NOT THE EVENTS — unlike BusFake::dispatched().
    // `fakeEvent()` stores func_get_args() and `dispatched()` collects those arrays, so a closure typed
    // `fn (FormClosed $e)` here is wrong. (Its *filter* callback is spread, which is what makes this
    // asymmetry so easy to get backwards.) Read from the installed vendor source, not from memory.
    expect(Event::dispatched(FormClosed::class)->map(fn (array $args): string => $args[0]->tenantId)->all())
        ->toBe([(string) $this->tenant->getKey()]);
    expect(Event::dispatched(FormOpened::class)->map(fn (array $args): string => $args[0]->tenantId)->all())
        ->toBe([(string) $other->getKey()]);
});
