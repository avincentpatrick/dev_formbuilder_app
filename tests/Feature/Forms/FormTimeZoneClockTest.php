<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Validation\SemanticValidator;
use App\Support\Migrations\FormTimeZoneSwitch;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| M180 (`D153`, `R-9fb95b45`'s clock half) — "Today" follows the form's time zone.
|--------------------------------------------------------------------------
| New forms start on Asia/Manila; the forms still on UTC are switched by `2026_08_17_000142`, whose work is
| {@see FormTimeZoneSwitch} — driven here on the app connection under a live tenant, because a `migrate:fresh` test
| database holds no forms when the migration runs (the `SubmissionReferenceBackfill` precedent). The server's clock
| is stamped in the form's zone, so `today()` is the form's calendar day.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->forms = app(FormService::class);
});

it('starts a new form on Asia/Manila', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');

    expect($form->refresh()->timezone)->toBe('Asia/Manila');
});

it('works today() out on the form\'s calendar day, on the server', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Survey');
    $form->forceFill(['timezone' => 'Asia/Manila'])->save();
    addFormField($form->draftVersion, $this->user, 'stamp', FieldType::Calculated, 0, ['config' => ['calculated_formula' => 'today()']]);

    // 23:30 UTC on the 20th is 07:30 on the 21st in Manila.
    $result = app(SemanticValidator::class)->validate($form->draftVersion->refresh(), [], null, '2026-10-20T23:30:00+00:00');

    expect($result->computed)->toBe(['stamp' => '2026-10-21']);
});

it('switches the forms still on UTC to Asia/Manila, and leaves a form an author set elsewhere alone', function (): void {
    $utc = $this->forms->create($this->tenant, $this->user, 'On UTC');
    $york = $this->forms->create($this->tenant, $this->user, 'In New York');
    $utc->forceFill(['timezone' => 'UTC'])->save();
    $york->forceFill(['timezone' => 'America/New_York'])->save();

    $switched = (new FormTimeZoneSwitch)(DB::connection());

    expect($switched)->toBe(1)
        ->and(Form::query()->findOrFail($utc->id)->timezone)->toBe('Asia/Manila')
        ->and(Form::query()->findOrFail($york->id)->timezone)->toBe('America/New_York');
});

it('refuses to report success when a form is still on UTC after the write, rather than migrating green over nothing', function (): void {
    $connection = Mockery::mock(ConnectionInterface::class);
    $connection->shouldReceive('update')->once()->andReturn(0);
    $connection->shouldReceive('table->where->count')->once()->andReturn(3);

    expect(fn () => (new FormTimeZoneSwitch)($connection))
        ->toThrow(RuntimeException::class, '3 form(s) are still on UTC');
});
