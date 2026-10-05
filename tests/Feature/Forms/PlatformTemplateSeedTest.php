<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BlueprintValidator;
use App\Services\Forms\SchemaBlueprintMaterializer;
use App\Support\Forms\DefaultFieldRules;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlatformTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Platform template seed drift guard (Increment G9a / onboarding-content-plan §4) — makes "every template
| is validated against the current field-type catalog each release" an executable CI gate: run the seeder,
| then prove every seeded blueprint validates AND materializes against the CURRENT FieldType catalog.
| Platform rows commit outside the transaction (privileged connection), so they are cleaned by hand.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    DB::connection('pgsql_privileged')->table('form_templates')->delete();
});

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('form_templates')->delete();
});

it('seeds 10 platform templates that all validate and materialize against the current catalog', function (): void {
    (new PlatformTemplateSeeder)->run();

    $rows = DB::connection('pgsql_privileged')->table('form_templates')->whereNull('tenant_id')->get();
    expect($rows)->toHaveCount(10);

    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $user = User::factory()->create();
    enterTenant($tenant->id, $user->id);

    $validator = app(BlueprintValidator::class);
    $materializer = app(SchemaBlueprintMaterializer::class);

    foreach ($rows as $row) {
        /** @var array<string, mixed> $blueprint */
        $blueprint = json_decode($row->schema_blueprint, true);

        // 1. Structurally valid (unknown/duplicate/dangling keys throw).
        $validator->validate($blueprint);

        // 2. Every field_type is a real member of the current catalog.
        foreach ($blueprint['fields'] as $field) {
            expect(FieldType::tryFrom($field['field_type']))->not->toBeNull("{$row->name}: {$field['field_type']}");
        }

        // 3. Materializes cleanly into a scratch draft.
        $form = makeForm($user, $row->name);
        $draft = makeDraftVersion($form);
        $result = $materializer->materializeInto($draft, $blueprint, $user);
        expect($result->fieldCount)->toBe(count($blueprint['fields']));
    }
});

it('is idempotent — re-running the seeder never duplicates', function (): void {
    (new PlatformTemplateSeeder)->run();
    (new PlatformTemplateSeeder)->run();

    $count = DB::connection('pgsql_privileged')->table('form_templates')->whereNull('tenant_id')->count();
    expect($count)->toBe(10);
});

it('gives every email, web-address and phone question in the gallery the check one added by hand gets (M134, D88)', function (): void {
    (new PlatformTemplateSeeder)->run();
    $checked = 0;

    foreach (DB::connection('pgsql_privileged')->table('form_templates')->whereNull('tenant_id')->get() as $row) {
        foreach (json_decode($row->schema_blueprint, true)['fields'] as $field) {
            $expected = array_map(
                static fn (array $rule): array => [$rule['rule_type']->value, $rule['rule_value']],
                DefaultFieldRules::for(FieldType::from($field['field_type'])),
            );
            $actual = array_map(static fn (array $v): array => [$v['rule_type'], $v['rule_value']], $field['validations'] ?? []);

            expect($actual)->toBe($expected, "{$row->name} → {$field['key']}");
            $checked += $expected === [] ? 0 : 1;
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(5); // anti-vacuity: four email questions and at least one phone today
});
