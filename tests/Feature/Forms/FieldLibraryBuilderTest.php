<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Exceptions\Forms\FormException;
use App\Models\FieldLibrary;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| FormBuilderService question-library methods (Increment G9b): insertFromLibrary (materialize a library item
| into the draft + bump usage) and saveFieldToLibrary (capture a draft field, guarded by draft membership).
| Platform rows are written via the privileged connection (RLS forbids the app role) and cleaned by hand.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    DB::connection('pgsql_privileged')->table('field_library')->delete();
    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->form = app(FormService::class)->create($this->tenant, $this->user, 'Survey');
    $this->draft = FormVersion::query()->whereKey($this->form->draft_version_id)->firstOrFail();
});

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('field_library')->delete();
});

it('inserts a library question into the draft as a materialized field', function (): void {
    $item = FieldLibrary::create([
        'tenant_id' => $this->tenant->id, 'name' => 'Age', 'field_type' => 'integer',
        'default_label' => 'Age (years)', 'default_config' => [], 'default_validations' => [],
        'is_active' => true, 'usage_count' => 0,
    ]);

    $field = app(FormBuilderService::class)->insertFromLibrary($this->form, $this->user, $item, null);

    expect($field->field_type)->toBe(FieldType::Integer)
        ->and($field->label)->toBe('Age (years)')
        ->and($field->form_version_id)->toBe($this->draft->id)
        ->and($field->created_by)->toBe($this->user->id);

    expect(FormField::query()->where('form_version_id', $this->draft->id)->count())->toBe(1);
    expect($item->refresh()->usage_count)->toBe(1); // tenant-owned bump via ordinary Eloquent
});

it('bumps usage_count on a PLATFORM item through the privileged counter', function (): void {
    $id = Uuid::uuid7()->toString();
    DB::connection('pgsql_privileged')->table('field_library')->insert([
        'id' => $id, 'tenant_id' => null, 'name' => 'Platform Age', 'field_type' => 'integer',
        'default_label' => 'Age', 'default_config' => json_encode([]), 'default_validations' => json_encode([]),
        'is_active' => true, 'usage_count' => 0, 'created_at' => now(), 'updated_at' => now(),
    ]);

    // RLS returns platform rows to the tenant; resolve it as the controller would.
    $item = FieldLibrary::query()->where('is_active', true)->findOrFail($id);

    app(FormBuilderService::class)->insertFromLibrary($this->form, $this->user, $item, null);

    // The bump lands on the platform row (a tenant UPDATE would have silently no-oped).
    $count = DB::connection('pgsql_privileged')->table('field_library')->where('id', $id)->value('usage_count');
    expect((int) $count)->toBe(1);
});

it('saves a draft field to the library, naming it from the label', function (): void {
    $field = addFormField($this->draft, $this->user, 'q1', FieldType::ShortText, 0, ['label' => 'Your name']);

    $item = app(FormBuilderService::class)->saveFieldToLibrary($this->form, $this->user, $field, []);

    expect($item->tenant_id)->toBe($this->tenant->id)
        ->and($item->name)->toBe('Your name')
        ->and($item->field_type)->toBe('short_text')
        ->and($item->is_active)->toBeTrue()
        ->and($item->created_by)->toBe($this->user->id);
});

it('refuses to save a field that is not in this form\'s draft', function (): void {
    $otherForm = app(FormService::class)->create($this->tenant, $this->user, 'Other');
    $otherDraft = FormVersion::query()->whereKey($otherForm->draft_version_id)->firstOrFail();
    $foreign = addFormField($otherDraft, $this->user, 'x', FieldType::ShortText, 0);

    expect(fn () => app(FormBuilderService::class)->saveFieldToLibrary($this->form, $this->user, $foreign, []))
        ->toThrow(FormException::class);
});
