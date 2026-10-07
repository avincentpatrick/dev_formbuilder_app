<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Http\Requests\Forms\UpdateFieldRequest;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M146 (`R-b2d4a2c5`) — a refused field save names the setting, not the request path.
|--------------------------------------------------------------------------
| `UpdateFieldRequest` declared no `attributes()` and the app has no `lang/` directory, so Laravel wrote
| "The config.options.0.label field must not be greater than 500 characters." into the builder's save alert
| (`ConfigPanel.vue` shows the server's message as sent). The request is the one place that knows every path, so
| it names each one — keyed by the wildcard path, which Laravel resolves for the numbered one.
|
| ⚠️ The row's own example ("… field is required") cannot occur: an option label is `nullable`. A length overrun
| is the reachable case, and the one pinned here.
|
| ⚠️ Helpers are prefixed `fieldAttr*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * An admin on a fresh tenant with one draft form.
 *
 * @return array{0: Tenant, 1: User, 2: Form}
 */
function fieldAttrAuthor(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Intake');

    return [$tenant, $admin, $form];
}

/**
 * The request as the router would hand it to `rules()` for a field of `$type`, with no database: the route
 * parameters are in-memory models carrying only what `rules()` reads (the draft version id, the field type).
 */
function fieldAttrRequest(FieldType $type): UpdateFieldRequest
{
    $request = UpdateFieldRequest::create('/forms/f/fields/x', 'PATCH');
    $route = new Route(['PATCH'], 'forms/{form}/fields/{field}', fn () => null);
    $route->bind($request);
    $route->setParameter('form', new Form(['draft_version_id' => Uuid::uuid7()->toString()]));
    $route->setParameter('field', new FormField(['field_type' => $type]));
    $request->setRouteResolver(fn () => $route);

    return $request;
}

it('names the setting, not the request path, when a save is refused', function (): void {
    [, $admin, $form] = fieldAttrAuthor();
    $added = $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'single_select'])
        ->assertOk();

    $response = $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$added->json('id')}", [
            'key' => $added->json('key'),
            'label' => 'Question',
            'is_required' => 'optional',
            'config' => ['options' => [['value' => 'a', 'label' => str_repeat('a', 501)]]],
            'validations' => [],
            'version' => null,
        ])
        ->assertStatus(422);

    $message = (string) $response->json('errors.config.options.0.label.0');

    expect($message)->toContain('choice label')
        ->and(str_contains($message, 'config.options'))->toBeFalse()
        // The alert's headline is Laravel's top-level `message`, the first error's text — the same words.
        ->and((string) $response->json('message'))->toContain('choice label');
});

it('names every path the rules know, for every field type, so a new path cannot ship as a raw key', function (): void {
    foreach (FieldType::cases() as $type) {
        $request = fieldAttrRequest($type);
        $unnamed = array_diff(array_keys($request->rules()), array_keys($request->attributes()));

        expect(array_values($unnamed))->toBe([], $type->value.' leaves unnamed: '.implode(', ', $unnamed));
    }

    // A name is plain words: a dot or an underscore in one means a path leaked into the map.
    foreach (fieldAttrRequest(FieldType::ShortText)->attributes() as $path => $noun) {
        expect(str_contains($noun, '.') || str_contains($noun, '_'))->toBeFalse("{$path} is named '{$noun}'");
    }
});
