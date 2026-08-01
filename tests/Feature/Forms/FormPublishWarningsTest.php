<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| H21a — the publish-warnings DELIVERY path (Doc #27 §6).
|--------------------------------------------------------------------------
| `StepGraphInspectorTest` covers WHAT is detected; this covers what the author actually receives, which is
| a separate failure surface: notices computed and then dropped on the floor are worth nothing.
|
| Two delivery details are pinned here because both were nearly got wrong:
|   - The publish SUCCEEDS and the toast says so. A version is published either way.
|   - The COUNT rides the toast as well as the banner, because publish is reachable from two pages (the
|     builder and the forms list) and `back()` returns to whichever called it. Only the builder renders the
|     banner, so without the toast the list-page publish would drop every notice silently.
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);
    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

it('flashes branching notices alongside a successful publish', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');
    $draft = $form->draftVersion;

    // A section gated on a field that lives LATER in the form — legal, and warned (§3.1).
    $early = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 'early', 'label' => 'Early', 'sequence' => 1,
        'relevant_expression' => '${answered_later} = \'yes\'',
    ]);
    $late = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 'late', 'label' => 'Late', 'sequence' => 2,
    ]);
    addFormField($draft, $this->admin, 'early_field', FieldType::ShortText, 1, ['form_section_id' => $early->id]);
    addFormField($draft, $this->admin, 'answered_later', FieldType::ShortText, 2, ['form_section_id' => $late->id]);

    $this->actingAs($this->admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/publish")
        ->assertRedirect()
        ->assertSessionHas('publishWarnings', fn (array $warnings): bool => count($warnings) === 1
            && str_contains($warnings[0], 'Forward reference')
            && str_contains($warnings[0], 'answered_later'))
        // The toast carries the count so a publish from the forms list — which renders no banner — is never
        // silent, and it says `info` rather than `error`: the version IS published.
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'info'
            && str_contains($toast['message'], 'Published version 1.')
            && str_contains($toast['message'], '1 branching warning'));

    enterTenant($this->tenant->id, $this->admin->id);
    expect(FormVersion::where('form_id', $form->id)->where('status', FormVersionStatus::Published)->count())->toBe(1);
});

it('keeps the plain success toast and flashes nothing when the graph is clean', function (): void {
    // The silence case. Without it a notice service that returned a constant would satisfy the test above.
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');
    $draft = $form->draftVersion;

    $s1 = FormSection::create(['form_version_id' => $draft->id, 'key' => 'basics', 'label' => 'Basics', 'sequence' => 1]);
    $s2 = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 'details', 'label' => 'Details', 'sequence' => 2,
        'relevant_expression' => '${gate} = \'yes\'',
    ]);
    addFormField($draft, $this->admin, 'gate', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
    addFormField($draft, $this->admin, 'detail', FieldType::ShortText, 2, ['form_section_id' => $s2->id]);

    $this->actingAs($this->admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/publish")
        ->assertRedirect()
        ->assertSessionMissing('publishWarnings')
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'success'
            && $toast['message'] === 'Published version 1.');
});

it('still reports a refusal as an error, so a notice never masks a real gate failure', function (): void {
    // A publish that the expression gate REFUSES must keep its error toast and flash no notices — the
    // inspector runs only after `publish()` returns, so an exception must never reach it.
    $form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey');
    addFormField($form->draftVersion, $this->admin, 'a', FieldType::ShortText, 1, [
        'relevant_expression' => '${nonexistent} = \'1\'',
    ]);

    $this->actingAs($this->admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/publish")
        ->assertRedirect()
        ->assertSessionMissing('publishWarnings')
        ->assertSessionHas('toast', fn (array $toast): bool => $toast['type'] === 'error');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(FormVersion::where('form_id', $form->id)->where('status', FormVersionStatus::Published)->count())->toBe(0);
});
