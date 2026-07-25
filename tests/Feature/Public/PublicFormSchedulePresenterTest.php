<?php

declare(strict_types=1);

use App\Enums\SubmissionSource;
use App\Models\Form;
use App\Models\User;
use App\Services\Submissions\PublicFormPresenter;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment H12a — the runtime schedule block (H12b prep) on PublicFormPresenter.
|--------------------------------------------------------------------------
| The presenter surfaces opens_at/closes_at/timezone/max_responses + a live `acceptance` label + `remaining`
| headroom for the guest runtime. It is advisory — enforcement is the write path — and it never withholds the
| schema for a closed form (so H12b can render the closed state). The finalized COUNT is taken only when a cap
| exists.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->seed(RolePermissionSeeder::class);
    $this->tenant = inboxTenant('acme');
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->presenter = app(PublicFormPresenter::class);
});

it('exposes the schedule block for an open, capped form', function (): void {
    $version = scheduledForm($this->tenant, $this->user, ['closes_at' => now()->addDay(), 'timezone' => 'UTC', 'max_responses' => 5]);
    $form = Form::findOrFail($version->form_id);

    $schedule = $this->presenter->present($form, $version)['form']['schedule'];

    expect($schedule['acceptance'])->toBe('open')
        ->and($schedule['max_responses'])->toBe(5)
        ->and($schedule['remaining'])->toBe(5)
        ->and($schedule['closes_at'])->not->toBeNull()
        ->and($schedule['timezone'])->toBe('UTC');
});

it('labels a closed form and still returns the schema (no withholding)', function (): void {
    $version = scheduledForm($this->tenant, $this->user, ['closes_at' => now()->subDay()]);
    $form = Form::findOrFail($version->form_id);

    $out = $this->presenter->present($form, $version);

    expect($out['form']['schedule']['acceptance'])->toBe('closed')
        ->and($out['version']['schema'])->not->toBeNull();
});

it('labels a not-yet-open form', function (): void {
    $version = scheduledForm($this->tenant, $this->user, ['opens_at' => now()->addDay()]);
    $form = Form::findOrFail($version->form_id);

    expect($this->presenter->present($form, $version)['form']['schedule']['acceptance'])->toBe('opens_soon');
});

it('reports capacity_reached with zero remaining once the cap is full', function (): void {
    $version = scheduledForm($this->tenant, $this->user, ['max_responses' => 1]);
    app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $version,
        answers: ['full_name' => 'Ada'],
        source: SubmissionSource::Guest,
        clientSubmissionUuid: Uuid::uuid7()->toString(),
    ));
    $form = Form::findOrFail($version->form_id);

    $schedule = $this->presenter->present($form, $version)['form']['schedule'];

    expect($schedule['acceptance'])->toBe('capacity_reached')
        ->and($schedule['remaining'])->toBe(0);
});

it('leaves remaining null for an uncapped form', function (): void {
    $version = scheduledForm($this->tenant, $this->user);
    $form = Form::findOrFail($version->form_id);

    $schedule = $this->presenter->present($form, $version)['form']['schedule'];

    expect($schedule['max_responses'])->toBeNull()
        ->and($schedule['remaining'])->toBeNull()
        ->and($schedule['acceptance'])->toBe('open');
});
