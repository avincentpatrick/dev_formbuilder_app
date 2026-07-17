<?php

declare(strict_types=1);

use App\Enums\FormCollaboratorCapacity;
use App\Enums\FormVersionStatus;
use App\Models\FormCollaborator;
use App\Models\FormField;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\TemplateService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Template instantiate (Increment G9a) — "new from template" clones into a brand-new form (never a live
| reference) and bumps usage_count, including the platform-row case that must go through the elevated
| PlatformRowCounter (a tenant-connection UPDATE on a NULL-tenant row silently no-ops).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    DB::connection('pgsql_privileged')->table('form_templates')->delete();
    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
});

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('form_templates')->delete();
});

/** @return array<string, mixed> */
function twoFieldBlueprint(): array
{
    return [
        'sections' => [],
        'fields' => [
            ['key' => 'name', 'section_key' => null, 'field_type' => 'short_text', 'label' => 'Name', 'is_required' => 'required', 'sequence' => 1, 'config' => [], 'validations' => []],
            ['key' => 'email', 'section_key' => null, 'field_type' => 'email', 'label' => 'Email', 'is_required' => 'optional', 'sequence' => 2, 'config' => [], 'validations' => []],
        ],
    ];
}

it('creates a new draft form with an editor collaborator and materialized fields', function (): void {
    $template = FormTemplate::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Contact form',
        'schema_blueprint' => twoFieldBlueprint(), 'is_public' => false, 'usage_count' => 0,
    ]);

    $form = app(TemplateService::class)->instantiate($template, $this->tenant, $this->user);

    expect($form->title)->toBe('Contact form')
        ->and($form->draft_version_id)->not->toBeNull();

    $draft = FormVersion::findOrFail($form->draft_version_id);
    expect($draft->status)->toBe(FormVersionStatus::Draft)
        ->and($draft->version_number)->toBe(1);

    expect(FormField::query()->where('form_version_id', $draft->id)->count())->toBe(2);

    expect(FormCollaborator::query()->where('form_id', $form->id)->where('user_id', $this->user->id)
        ->where('capacity', FormCollaboratorCapacity::Editor)->exists())->toBeTrue();
});

it('clones (never live-references) — editing the new form leaves the template untouched', function (): void {
    $template = FormTemplate::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Contact form',
        'schema_blueprint' => twoFieldBlueprint(), 'is_public' => false, 'usage_count' => 0,
    ]);

    $form = app(TemplateService::class)->instantiate($template, $this->tenant, $this->user);
    $field = FormField::query()->where('form_version_id', $form->draft_version_id)->where('key', 'name')->firstOrFail();
    $field->forceFill(['label' => 'Renamed on the form'])->save();

    // The template's stored blueprint is unchanged — the new form is an independent copy.
    $labels = collect($template->fresh()->schema_blueprint['fields'])->pluck('label')->all();
    expect($labels)->toContain('Name')->not->toContain('Renamed on the form');
});

it('increments usage_count for a tenant-owned template', function (): void {
    $template = FormTemplate::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Contact form',
        'schema_blueprint' => twoFieldBlueprint(), 'is_public' => false, 'usage_count' => 0,
    ]);

    app(TemplateService::class)->instantiate($template, $this->tenant, $this->user);

    expect($template->fresh()->usage_count)->toBe(1);
});

it('increments usage_count for a PLATFORM template via the elevated connection', function (): void {
    // A NULL-tenant platform template, seeded via the only path allowed to write one.
    $id = Uuid::uuid7()->toString();
    DB::connection('pgsql_privileged')->table('form_templates')->insert([
        'id' => $id, 'tenant_id' => null, 'name' => 'Platform contact',
        'schema_blueprint' => json_encode(twoFieldBlueprint()), 'is_public' => true, 'usage_count' => 0,
        'created_at' => now(), 'updated_at' => now(),
    ]);

    // Readable by the tenant via the widened SELECT policy.
    $template = FormTemplate::findOrFail($id);
    app(TemplateService::class)->instantiate($template, $this->tenant, $this->user);

    // The elevated PlatformRowCounter actually moved the count (a plain tenant UPDATE would have no-op'd).
    $count = DB::connection('pgsql_privileged')->table('form_templates')->where('id', $id)->value('usage_count');
    expect((int) $count)->toBe(1);
});
