<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\TemplateGalleryPresenter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment G9a — the web (Inertia) template surface end-to-end through the real subdomain pipeline: the
| gallery render (platform + own via the widened SELECT), instantiate → new-form redirect, save-as-template,
| and the can:create/can:view route gates. Platform rows are seeded via the privileged connection (cleaned
| up in afterEach).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    DB::connection('pgsql_privileged')->table('form_templates')->delete();
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    DB::connection('pgsql_privileged')->table('form_templates')->delete();
});

function templateWebTenant(): Tenant
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);

    return $tenant;
}

it('renders the gallery with platform templates plus the tenant\'s own', function (): void {
    $this->withoutVite();
    $tenant = templateWebTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    // One platform template (privileged) + one tenant-owned.
    DB::connection('pgsql_privileged')->table('form_templates')->insert([
        'id' => Uuid::uuid7()->toString(), 'tenant_id' => null, 'name' => 'Platform gallery pick',
        'schema_blueprint' => json_encode(['sections' => [], 'fields' => []]), 'is_public' => true,
        'usage_count' => 5, 'created_at' => now(), 'updated_at' => now(),
    ]);
    FormTemplate::create([
        'tenant_id' => $tenant->id, 'name' => 'My saved template', 'is_public' => false, 'usage_count' => 0,
        'schema_blueprint' => ['sections' => [], 'fields' => []],
    ]);

    $this->actingAs($admin)
        ->get('http://acme.meridian.test/forms/templates')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            // `false` skips the on-disk page-file existence check (repo convention, cf. FormRoutesTest's
            // component('forms/Index', false)) — the CI Pest job's view-finder doesn't resolve raw .vue files.
            ->component('forms/Templates', false)
            ->has('templates', 2)
            // Platform sorts first (by usage), then the tenant's own.
            ->where('templates.0.name', 'Platform gallery pick')
            ->where('templates.0.is_platform', true)
            ->where('templates.1.name', 'My saved template'));
});

it('derives field_count in SQL without ever reading the blueprint jsonb', function (): void {
    $tenant = templateWebTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    FormTemplate::create([
        'tenant_id' => $tenant->id, 'name' => 'Three fields', 'is_public' => false, 'usage_count' => 0,
        'schema_blueprint' => ['sections' => [], 'fields' => [
            ['key' => 'a', 'field_type' => 'short_text'],
            ['key' => 'b', 'field_type' => 'short_text'],
            ['key' => 'c', 'field_type' => 'email'],
        ]],
    ]);

    $selects = [];
    DB::listen(function ($query) use (&$selects): void {
        if (str_starts_with(strtolower(trim($query->sql)), 'select') && str_contains($query->sql, 'form_templates')) {
            $selects[] = $query->sql;
        }
    });

    $cards = app(TemplateGalleryPresenter::class)->list($admin);

    expect($cards)->toHaveCount(1)
        ->and($cards[0]['field_count'])->toBe(3);

    // The whole point of scopeWithoutBlueprint: the heavy jsonb is counted in Postgres, never selected.
    expect($selects)->not->toBeEmpty();
    foreach ($selects as $sql) {
        expect($sql)->toContain('jsonb_array_length')
            ->and($sql)->not->toContain('"form_templates".*');
    }
});

it('counts zero fields for a blueprint with no usable fields array', function (): void {
    $tenant = templateWebTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    // jsonb_array_length() raises on a non-array, so the SQL guards with jsonb_typeof — a blueprint missing
    // `fields` (or holding an object there) must count 0, exactly as count($blueprint['fields'] ?? []) did.
    FormTemplate::create([
        'tenant_id' => $tenant->id, 'name' => 'Malformed', 'is_public' => false, 'usage_count' => 0,
        'schema_blueprint' => ['sections' => []],
    ]);

    $cards = app(TemplateGalleryPresenter::class)->list($admin);

    expect($cards[0]['field_count'])->toBe(0);
});

it('forbids a Viewer from the gallery and from instantiating', function (): void {
    $this->withoutVite();
    $tenant = templateWebTenant();
    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer'); // no forms.create

    $template = FormTemplate::create([
        'tenant_id' => $tenant->id, 'name' => 'T', 'is_public' => false, 'usage_count' => 0,
        'schema_blueprint' => ['sections' => [], 'fields' => []],
    ]);

    $this->actingAs($viewer)->get('http://acme.meridian.test/forms/templates')->assertForbidden();
    $this->actingAs($viewer)
        ->post("http://acme.meridian.test/forms/templates/{$template->id}/instantiate")
        ->assertForbidden();
});

it('instantiates a template into a new form and redirects to its builder', function (): void {
    $tenant = templateWebTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    $template = FormTemplate::create([
        'tenant_id' => $tenant->id, 'name' => 'Contact form', 'is_public' => false, 'usage_count' => 0,
        'schema_blueprint' => ['sections' => [], 'fields' => [
            ['key' => 'name', 'section_key' => null, 'field_type' => 'short_text', 'label' => 'Name', 'is_required' => 'required', 'sequence' => 1, 'config' => []],
        ]],
    ]);

    $before = Form::query()->count();

    $response = $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/templates/{$template->id}/instantiate")
        ->assertRedirect();

    // Re-establish context in the test process (the request cleared it on terminate) before querying.
    enterTenant($tenant->id, $admin->id);
    expect(Form::query()->count())->toBe($before + 1);

    $newForm = Form::query()->where('title', 'Contact form')->firstOrFail();
    expect($response->headers->get('Location'))->toContain("/forms/{$newForm->id}/builder");
    expect(FormField::query()->where('form_version_id', $newForm->draft_version_id)->count())->toBe(1);
});

it('saves a form as a tenant-owned template', function (): void {
    $tenant = templateWebTenant();
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Survey');

    $this->actingAs($admin)
        ->post("http://acme.meridian.test/forms/{$form->id}/save-as-template", [
            'name' => 'Saved survey',
            'category' => 'M&E / Research',
        ])
        ->assertRedirect();

    enterTenant($tenant->id, $admin->id);
    $template = FormTemplate::query()->where('name', 'Saved survey')->firstOrFail();
    expect($template->tenant_id)->toBe($tenant->id)
        ->and($template->is_public)->toBeFalse()
        ->and($template->source_form_version_id)->toBe($form->draft_version_id);
});
