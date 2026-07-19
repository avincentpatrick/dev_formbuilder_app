<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FieldLibrary;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BlueprintValidator;
use App\Services\Forms\FormService;
use App\Services\Forms\SchemaBlueprintMaterializer;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlatformFieldLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Platform field-library seed drift guard (Increment G9b) — the executable CI gate that every seeded question
| stays valid against the CURRENT FieldType catalog: run the seeder, then prove each item's field_type is real,
| its blueprint passes validateField (incl. the rule_xor_chk), and it materializes into a scratch draft.
| Platform rows commit outside the transaction (privileged connection), so they are cleaned by hand.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    DB::connection('pgsql_privileged')->table('field_library')->delete();
});

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('field_library')->delete();
});

it('seeds platform library questions that all validate and materialize against the current catalog', function (): void {
    (new PlatformFieldLibrarySeeder)->run();

    $rows = DB::connection('pgsql_privileged')->table('field_library')->whereNull('tenant_id')->get();
    expect($rows)->toHaveCount(6);

    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);

    $validator = app(BlueprintValidator::class);
    $materializer = app(SchemaBlueprintMaterializer::class);

    $form = app(FormService::class)->create($tenant, $user, 'Scratch');
    $draft = FormVersion::query()->whereKey($form->draft_version_id)->firstOrFail();

    foreach ($rows as $row) {
        // Resolve through the model so the casts (jsonb → array) and mappers run as they do at runtime.
        $item = FieldLibrary::query()->where('is_active', true)->findOrFail($row->id);
        $blueprint = $item->toBlueprintField();

        // 1. field_type is a real member of the current catalog.
        expect(FieldType::tryFrom($item->field_type))->not->toBeNull($item->name);

        // 2. Structurally valid (a bad field_type or a rule_xor violation throws).
        $validator->validateField($blueprint);

        // 3. Materializes cleanly into a scratch draft.
        $field = $materializer->materializeField($draft, $blueprint, null, $user);
        expect($field->field_type->value)->toBe($item->field_type);
    }
});

it('is idempotent — re-running the seeder never duplicates', function (): void {
    (new PlatformFieldLibrarySeeder)->run();
    (new PlatformFieldLibrarySeeder)->run();

    $count = DB::connection('pgsql_privileged')->table('field_library')->whereNull('tenant_id')->count();
    expect($count)->toBe(6);
});
