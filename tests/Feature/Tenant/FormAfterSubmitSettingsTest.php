<?php

declare(strict_types=1);

use App\Enums\AfterSubmit;
use App\Enums\AuditEvent;
use App\Models\Audit;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\FormSettingsPresenter;
use App\Services\Submissions\PublicFormPresenter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M248 (`D232`) — a form's "After submitting" setting (PATCH /forms/{form}/after-submit).
|--------------------------------------------------------------------------
| Another response (the default, what every form did before), "Change my answers" until the form closes, or one
| response. A guarded FormService::setAfterSubmit write behind can:update,form alone; the column is not fillable,
| because `amend` lets the holder of a link change a sent response.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function afterSubmitUrl(Form $form): string
{
    return "http://acme.meridian.test/forms/{$form->id}/after-submit";
}

it('starts every new form on "another response", in the model AND in the row', function (): void {
    // Both halves, for the reason FormPageModeSettingsTest's first case gives: a model carrying null would publish a
    // null into a value the guest page reads as one of three words.
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');

    expect($form->after_submit)->toBe(AfterSubmit::Another);

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->after_submit)->toBe(AfterSubmit::Another);
});

it('lets an editor choose each of the three, and records each change', function (string $value): void {
    $form = makeForm($this->admin, 'Survey');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(afterSubmitUrl($form), ['after_submit' => $value])
        ->assertRedirect()
        ->assertSessionHas('toast');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->after_submit->value)->toBe($value);

    $audit = Audit::query()
        ->where('auditable_type', 'form')
        ->where('auditable_id', $form->id)
        ->where('event', AuditEvent::Updated)
        ->latest('created_at')
        ->first();

    if ($value === 'another') {
        return; // the value it already held — whether an unchanged save writes a row is recordFormUpdate()'s business
    }

    expect($audit)->not->toBeNull()
        ->and($audit->old_values['after_submit'])->toBe('another')
        ->and($audit->new_values['after_submit'])->toBe($value)
        ->and($audit->user_id)->toBe($this->admin->id);
})->with(['another', 'amend', 'once']);

it('switches back, so the submitted value is read rather than assumed', function (): void {
    $form = makeForm($this->admin, 'Survey');
    $form->forceFill(['after_submit' => AfterSubmit::Amend])->save();

    $this->actingAs($this->admin)->withoutVite()
        ->patch(afterSubmitUrl($form), ['after_submit' => 'another'])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->after_submit)->toBe(AfterSubmit::Another);
});

it('refuses a member without can:update,form (403)', function (): void {
    $form = makeForm($this->admin, 'Survey');

    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->withoutVite()
        ->patch(afterSubmitUrl($form), ['after_submit' => 'amend'])
        ->assertForbidden();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->after_submit)->toBe(AfterSubmit::Another);
});

it('requires one of the three words', function (array $body): void {
    $form = makeForm($this->admin, 'Survey');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(afterSubmitUrl($form), $body)
        ->assertSessionHasErrors('after_submit');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->after_submit)->toBe(AfterSubmit::Another);
})->with([
    'missing' => [[]],
    'unknown' => [['after_submit' => 'twice']],
    'a boolean' => [['after_submit' => true]],
]);

it('is not mass-assignable, so an unrelated update cannot switch it on', function (): void {
    $form = makeForm($this->admin, 'Survey');

    $form->update(['title' => 'Renamed', 'after_submit' => 'amend']);

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::findOrFail($form->id)->after_submit)->toBe(AfterSubmit::Another);
});

it('reaches the settings page and the guest page', function (): void {
    $form = makeForm($this->admin, 'Survey');
    $form->forceFill(['after_submit' => AfterSubmit::Once])->save();
    $form->refresh();

    expect(app(FormSettingsPresenter::class)->form($form)['after_submit'])->toBe('once');

    $version = makeDraftVersion($form);
    expect(app(PublicFormPresenter::class)->present($form, $version)['form']['after_submit'])->toBe('once');
});
