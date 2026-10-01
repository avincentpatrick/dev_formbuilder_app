<?php

declare(strict_types=1);

use App\Enums\ConversionImpact;
use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Forms\DefaultFieldRules;
use App\Support\Forms\FieldTypeConversion;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M122 — the type-conversion HTTP surface (R-495abf48, B5a second half).
|--------------------------------------------------------------------------
| `GET …/fields/{field}/conversions` reads every plan the engine offers plus each one's cross-field census;
| `POST …/fields/{field}/convert` applies one. Driven end to end through the real subdomain pipeline: the
| `can:update,form` gate, route-model binding under RLS, the engine, and the controller's own 409/422 mapping
| — which it needs because `FormException` has no web render arm (`bootstrap/app.php`).
|
| ⚠️ Helpers are prefixed `conversionRoute*`: Pest loads every test file into one process.
*/

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
    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Survey')->refresh();
    $this->builder = app(FormBuilderService::class);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function conversionRouteUrl(Form $form, FormField $field, string $tail): string
{
    return "http://acme.meridian.test/forms/{$form->id}/fields/{$field->id}/{$tail}";
}

/**
 * Re-read a field after a request. The request runs the tenancy middleware, which leaves the RLS context
 * elsewhere when it returns, so a bare `refresh()` finds no row — `BuilderRoutesTest` re-enters the same way.
 */
function conversionRouteFresh(FormField $field, Tenant $tenant, User $user): FormField
{
    enterTenant($tenant->id, $user->id);

    return $field->refresh();
}

/** The GET's entry for one target. */
function conversionRoutePlan(array $plans, FieldType $to): array
{
    foreach ($plans as $plan) {
        if ($plan['to'] === $to->value) {
            return $plan;
        }
    }

    throw new LogicException("no plan offered for {$to->value}");
}

// ── The read ─────────────────────────────────────────────────────────────────────────────────────────

it('lists every target the engine offers, in its order, each with the plan and a census', function (): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::Email, null);

    $plans = $this->actingAs($this->admin)
        ->getJson(conversionRouteUrl($this->form, $field, 'conversions'))
        ->assertOk()
        ->json('plans');

    expect(array_column($plans, 'to'))
        ->toBe(array_map(static fn (FieldType $t): string => $t->value, FieldTypeConversion::targetsFor(FieldType::Email)));

    foreach ($plans as $plan) {
        expect(array_keys($plan))->toBe([
            'from', 'to', 'lossless', 'requires_confirmation', 'fingerprint', 'config_dropped',
            'changes', 'kept', 'dropped', 'added', 'warnings', 'census',
        ])->and($plan['census'])->toBe([]);
    }
});

it('carries the cross-field census on the plan it belongs to', function (): void {
    $draft = $this->form->draftVersion()->firstOrFail();
    $age = addFormField($draft, $this->admin, 'age', FieldType::Integer, 1);
    addFormField($draft, $this->admin, 'guardian', FieldType::ShortText, 2, ['relevant_expression' => '${age} < 18']);

    $plans = $this->actingAs($this->admin)->getJson(conversionRouteUrl($this->form, $age, 'conversions'))->assertOk()->json('plans');

    expect(conversionRoutePlan($plans, FieldType::Note)['census'])->toBe([[
        'code' => 'reference_has_no_answer',
        'site' => 'relevance',
        'key' => 'guardian',
        'message' => ConversionImpact::ReferenceHasNoAnswer->message(),
    ]])
        ->and(conversionRoutePlan($plans, FieldType::Decimal)['census'])->toBe([]);
});

it('offers a page break nothing', function (): void {
    $break = $this->builder->addField($this->form, $this->admin, FieldType::PageBreak, null);

    $this->actingAs($this->admin)->getJson(conversionRouteUrl($this->form, $break, 'conversions'))
        ->assertOk()->assertExactJson(['plans' => []]);
});

it('refuses a field that is not in this form draft with a 422, on both the read and the write', function (): void {
    $other = app(FormService::class)->create($this->tenant, $this->admin, 'Other')->refresh();
    $foreign = $this->builder->addField($other, $this->admin, FieldType::ShortText, null);

    // Bound by id under RLS — so the field resolves, and the draft guard is what refuses it.
    $this->actingAs($this->admin)->getJson(conversionRouteUrl($this->form, $foreign, 'conversions'))
        ->assertStatus(422)->assertJsonStructure(['message']);
    $this->actingAs($this->admin)->postJson(conversionRouteUrl($this->form, $foreign, 'convert'), [
        'to' => 'long_text', 'version' => (string) FormBuilderService::rowVersion($foreign),
    ])->assertStatus(422);

    expect(conversionRouteFresh($foreign, $this->tenant, $this->admin)->field_type)->toBe(FieldType::ShortText);
});

it('refuses a field on a published version with a 422', function (): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::ShortText, null);
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    // Publishing freezes the draft in place, so this id now belongs to the PUBLISHED version.
    $published = FormField::query()->whereKey($field->id)->firstOrFail();
    expect($published->form_version_id)->not->toBe($this->form->refresh()->draft_version_id);

    $this->actingAs($this->admin)->getJson(conversionRouteUrl($this->form->refresh(), $published, 'conversions'))
        ->assertStatus(422);
});

// ── The write ────────────────────────────────────────────────────────────────────────────────────────

it('applies a lossless conversion with no fingerprint and keeps the id and the key', function (): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::ShortText, null);

    $body = $this->actingAs($this->admin)->postJson(conversionRouteUrl($this->form, $field, 'convert'), [
        'to' => 'long_text', 'version' => (string) FormBuilderService::rowVersion(conversionRouteFresh($field, $this->tenant, $this->admin)),
    ])->assertOk()->json();

    expect($body['id'])->toBe($field->id)
        ->and($body['key'])->toBe($field->key)
        ->and($body['field_type'])->toBe('long_text')
        ->and($body)->toHaveKeys(['version', 'validations'])
        ->and(conversionRouteFresh($field, $this->tenant, $this->admin)->field_type)->toBe(FieldType::LongText);
});

it('applies a confirmed lossy conversion with the fingerprint the read returned', function (): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::Email, null); // seeds the email check
    $plans = $this->actingAs($this->admin)->getJson(conversionRouteUrl($this->form, $field, 'conversions'))->json('plans');
    $plan = conversionRoutePlan($plans, FieldType::Phone);

    $this->actingAs($this->admin)->postJson(conversionRouteUrl($this->form, $field, 'convert'), [
        'to' => 'phone', 'version' => (string) FormBuilderService::rowVersion(conversionRouteFresh($field, $this->tenant, $this->admin)), 'fingerprint' => $plan['fingerprint'],
    ])->assertOk()->assertJsonPath('field_type', 'phone');

    enterTenant($this->tenant->id, $this->admin->id);
    $values = FormFieldValidation::query()->where('form_field_id', $field->id)->pluck('rule_value')->all();
    expect($values)->toBe([DefaultFieldRules::for(FieldType::Phone)[0]['rule_value']]);
});

it('answers 409 with the current row when a plan needing confirmation arrives without its fingerprint', function (): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::Email, null);

    $this->actingAs($this->admin)->postJson(conversionRouteUrl($this->form, $field, 'convert'), [
        'to' => 'phone', 'version' => (string) FormBuilderService::rowVersion(conversionRouteFresh($field, $this->tenant, $this->admin)),
    ])->assertStatus(409)->assertJsonStructure(['message', 'current' => ['id', 'version']]);

    expect(conversionRouteFresh($field, $this->tenant, $this->admin)->field_type)->toBe(FieldType::Email);
});

it('answers 409 for a stale version and for a fingerprint that no longer matches', function (): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::Email, null);
    $plan = conversionRoutePlan(
        $this->actingAs($this->admin)->getJson(conversionRouteUrl($this->form, $field, 'conversions'))->json('plans'),
        FieldType::Phone,
    );

    $this->actingAs($this->admin)->postJson(conversionRouteUrl($this->form, $field, 'convert'), [
        'to' => 'phone', 'version' => '2000-01-01T00:00:00.000000+00:00', 'fingerprint' => $plan['fingerprint'],
    ])->assertStatus(409)->assertJsonPath('current.id', $field->id);

    $this->actingAs($this->admin)->postJson(conversionRouteUrl($this->form, $field, 'convert'), [
        'to' => 'phone', 'version' => (string) FormBuilderService::rowVersion(conversionRouteFresh($field, $this->tenant, $this->admin)), 'fingerprint' => str_repeat('0', 64),
    ])->assertStatus(409)->assertJsonPath('current.id', $field->id);

    expect(conversionRouteFresh($field, $this->tenant, $this->admin)->field_type)->toBe(FieldType::Email);
});

it('answers 422 with a message for a pair the engine refuses', function (): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::Geopoint, null);

    $this->actingAs($this->admin)->postJson(conversionRouteUrl($this->form, $field, 'convert'), [
        'to' => 'short_text', 'version' => (string) FormBuilderService::rowVersion(conversionRouteFresh($field, $this->tenant, $this->admin)),
    ])->assertStatus(422)->assertJsonStructure(['message'])->assertJsonMissingPath('errors');

    expect(conversionRouteFresh($field, $this->tenant, $this->admin)->field_type)->toBe(FieldType::Geopoint);
});

it('validates the request body before the engine sees it', function (array $body, string $error): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::ShortText, null);
    $version = (string) FormBuilderService::rowVersion(conversionRouteFresh($field, $this->tenant, $this->admin));

    $this->actingAs($this->admin)
        ->postJson(conversionRouteUrl($this->form, $field, 'convert'), array_map(static fn ($v) => $v === '@version' ? $version : $v, $body))
        ->assertStatus(422)->assertJsonValidationErrors([$error]);
})->with([
    'unknown type' => [['to' => 'paragraph', 'version' => '@version'], 'to'],
    'no type' => [['version' => '@version'], 'to'],
    'no version' => [['to' => 'long_text'], 'version'],
    'short fingerprint' => [['to' => 'long_text', 'version' => '@version', 'fingerprint' => 'abc'], 'fingerprint'],
    'uppercase fingerprint' => [['to' => 'long_text', 'version' => '@version', 'fingerprint' => str_repeat('A', 64)], 'fingerprint'],
]);

// ── Who may ──────────────────────────────────────────────────────────────────────────────────────────

it('forbids a viewer both routes', function (): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::ShortText, null);
    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->getJson(conversionRouteUrl($this->form, $field, 'conversions'))->assertForbidden();
    $this->actingAs($viewer)->postJson(conversionRouteUrl($this->form, $field, 'convert'), [
        'to' => 'long_text', 'version' => (string) FormBuilderService::rowVersion(conversionRouteFresh($field, $this->tenant, $this->admin)),
    ])->assertForbidden();

    expect(conversionRouteFresh($field, $this->tenant, $this->admin)->field_type)->toBe(FieldType::ShortText);
});

it('does not resolve another workspace field', function (): void {
    $field = $this->builder->addField($this->form, $this->admin, FieldType::ShortText, null);
    // Positive control: without it, the 404 below would also be what a missing route answers.
    $this->actingAs($this->admin)->getJson(conversionRouteUrl($this->form, $field, 'conversions'))->assertOk();

    $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $beta->domains()->create(['domain' => 'beta']);
    $stranger = User::factory()->create();
    enterTenant($beta->id, $stranger->id);
    makeActiveMember($stranger, 'admin');

    $this->actingAs($stranger)
        ->getJson("http://beta.meridian.test/forms/{$this->form->id}/fields/{$field->id}/conversions")
        ->assertNotFound();
});
