<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Forms\TemplateService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| saveAsTemplate() reads its tree inside ONE transaction (M90, docs/feature-backlog.md:8943).
|--------------------------------------------------------------------------
| SchemaSnapshotSerializer::snapshot() issues exactly three reads. Outside a transaction they are three
| separately autocommitted statements, so a builder edit landing between them yields a blueprint
| describing a tree that existed at no instant — a field whose `section_key` names a section the first
| read did not return.
|
| ⛔ WHAT THIS FILE CAN AND CANNOT SEE, STATED UP FRONT BECAUSE THE GAP IS THE INTERESTING PART.
| The repair is a transaction PLUS `set transaction isolation level repeatable read`, and the isolation
| statement is guarded on `DB::transactionLevel() === 1` because PostgreSQL refuses it once a statement
| has run. Under RefreshDatabase the outer transaction has already executed, so the guard correctly
| declines and the isolation level is NOT observable from this suite. TenantExtractService carries the
| same guard for the same reason, and TenantExtractTest lives with it by reading back what it actually
| ran under rather than asserting what was requested.
|
| So this pins the half that IS observable and that was genuinely absent: the transaction BOUNDARY. It
| is not a consolation assertion — before M90 there was no transaction at all, so every statement ran at
| the ambient level, and that is exactly what the mutation below restores.
|
| ⚠️ THE `toBeGreaterThan` IS DELIBERATE AND THE EQUALITY WOULD BE WRONG. Under RefreshDatabase the
| ambient level is 1 and the service's own transaction makes it 2; asserting `2` would pin the HARNESS
| rather than the service, and would go red the day this is called from inside another transaction.
| The claim is "deeper than the caller's", which is the property that matters.
|
| Helpers are prefixed `templateIsolation*`: Pest loads the whole suite into one process, so a
| file-scope helper sharing a name with another file's is a fatal redeclaration.
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

/** A tenant, an admin and a draft carrying one section and one field. Returns [tenant, admin, draft]. */
function templateIsolationFixture(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    $form = app(FormService::class)->create($tenant, $admin, 'Survey');
    app(FormBuilderService::class)->addSection($form->refresh());
    app(FormBuilderService::class)->addField($form->refresh(), $admin, FieldType::ShortText, null);

    $draft = FormVersion::query()->whereKey($form->refresh()->draft_version_id)->firstOrFail();

    return [$tenant, $admin, $draft];
}

it('reads the whole tree and writes the row inside one transaction', function (): void {
    [, $admin, $draft] = templateIsolationFixture();

    $ambient = DB::transactionLevel();

    /** @var list<array{sql: string, level: int}> $seen */
    $seen = [];
    DB::listen(function ($query) use (&$seen): void {
        $seen[] = ['sql' => strtolower($query->sql), 'level' => DB::transactionLevel()];
    });

    app(TemplateService::class)->saveAsTemplate($draft, $admin, ['name' => 'Reusable']);

    // The four statements the method is made of — three child reads plus the INSERT.
    $sections = array_values(array_filter($seen, static fn (array $q): bool => str_contains($q['sql'], 'from "form_sections"')));
    $fields = array_values(array_filter($seen, static fn (array $q): bool => str_contains($q['sql'], 'from "form_fields"')));
    $validations = array_values(array_filter($seen, static fn (array $q): bool => str_contains($q['sql'], 'from "form_field_validations"')));
    $insert = array_values(array_filter($seen, static fn (array $q): bool => str_contains($q['sql'], 'insert into "form_templates"')));

    // Non-vacuity first: an empty harvest would make every assertion below trivially true, and this
    // repository has shipped exactly that shape before.
    expect($sections)->not->toBeEmpty()
        ->and($fields)->not->toBeEmpty()
        ->and($validations)->not->toBeEmpty()
        ->and($insert)->not->toBeEmpty();

    foreach ([...$sections, ...$fields, ...$validations, ...$insert] as $query) {
        expect($query['level'])->toBeGreaterThan($ambient, "ran at level {$query['level']} against an ambient {$ambient} — it is outside the service's transaction: {$query['sql']}");
    }
});

it('still produces a blueprint carrying the tree it captured', function (): void {
    // The transaction must not change the OUTPUT, and nothing in the repository asserted this output at
    // all before M90 — TemplateRoundTripTest, the file that exists to prove the serialize/instantiate
    // loop, hand-builds its FormTemplate and never calls saveAsTemplate. That is what would have made a
    // mis-placed lock here a silent regression rather than a loud one.
    [, $admin, $draft] = templateIsolationFixture();

    $template = app(TemplateService::class)->saveAsTemplate($draft, $admin, ['name' => 'Reusable']);

    $blueprint = FormTemplate::query()->whereKey($template->id)->value('schema_blueprint');

    expect($blueprint)->toBeArray()
        ->and($blueprint['sections'] ?? null)->toBeArray()->not->toBeEmpty()
        ->and($blueprint['fields'] ?? null)->toBeArray()->not->toBeEmpty();
});
