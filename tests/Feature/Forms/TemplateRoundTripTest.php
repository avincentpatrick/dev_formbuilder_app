<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\SchemaSnapshotSerializer;
use App\Services\Forms\TemplateService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Template round-trip (Increment G9a) — the keystone coupling of SchemaSnapshotSerializer and
| SchemaBlueprintMaterializer. Snapshot a real form (sections + fields + a field→field rule inside a
| logic group) → store as a template → instantiate → re-snapshot the new draft → assert the two snapshots
| are byte-identical (equal SHA-256). If the serializer and materializer ever drift in shape, this reddens.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
});

it('round-trips a form through save-as-template and instantiate byte-for-byte', function (): void {
    $form = makeForm($this->user, 'Original');
    $draft = makeDraftVersion($form);
    $form->forceFill(['draft_version_id' => $draft->id])->save();

    $section = FormSection::create([
        'form_version_id' => $draft->id, 'key' => 's1', 'label' => 'Details', 'sequence' => 1,
    ]);
    addFormField($draft, $this->user, 'name', FieldType::ShortText, 1, [
        'form_section_id' => $section->id, 'is_required' => RequiredMode::Required,
    ]);
    $age = addFormField($draft, $this->user, 'age', FieldType::Integer, 2, ['form_section_id' => $section->id]);
    $confirm = addFormField($draft, $this->user, 'confirm_age', FieldType::Integer, 3, ['form_section_id' => $section->id]);

    // A field→field rule inside a logic group — exercises related_field_key + the logic_group_ordinal remap.
    FormFieldValidation::create([
        'form_version_id' => $draft->id,
        'form_field_id' => $confirm->id,
        'related_form_field_id' => $age->id,
        'rule_type' => 'greater_than_field',
        'logic_group' => (string) Str::uuid(),
        'logic_operator' => 'and',
        'sequence' => 0,
    ]);

    $serializer = app(SchemaSnapshotSerializer::class);
    $original = $serializer->snapshot($draft);

    // Save as a template, then instantiate into a brand-new form.
    $template = FormTemplate::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Reusable',
        'schema_blueprint' => $original,
        'source_form_version_id' => $draft->id,
        'is_public' => false,
        'usage_count' => 0,
        'created_by' => $this->user->id,
    ]);

    $newForm = app(TemplateService::class)->instantiate($template, $this->tenant, $this->user);
    $newDraft = FormVersion::findOrFail($newForm->draft_version_id);

    $reSnapshot = $serializer->snapshot($newDraft);

    // Byte-identical: the canonical, id-free snapshot survives the store → materialize → re-snapshot loop.
    expect($serializer->checksumOf($reSnapshot))->toBe($serializer->checksumOf($original));
});
