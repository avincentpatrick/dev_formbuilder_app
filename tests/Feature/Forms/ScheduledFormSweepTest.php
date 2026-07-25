<?php

declare(strict_types=1);

use App\Enums\FormScheduleState;
use App\Events\FormClosed;
use App\Events\FormOpened;
use App\Jobs\Maintenance\SweepScheduledFormsJob;
use App\Models\Form;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

/** Run the sweep end to end: fan-out then the single acme child. */
function runSweep(): void
{
    SweepScheduledFormsJob::dispatch();
    workOneJob('scheduled-maintenance'); // the fan-out enqueues one child (acme is the only active tenant)
    workOneJob('scheduled-maintenance'); // the child advances acme's scheduled forms
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
