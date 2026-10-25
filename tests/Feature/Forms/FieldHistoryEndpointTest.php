<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\FieldHistoryPresenter;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M188 (`R-3894369d`, `D167`, `D168`) — GET /forms/{form}/fields/{field}/history, the History tab's read
|--------------------------------------------------------------------------
| Newest first; one person's saves to the same settings under two minutes apart read as one change, from the first value
| to the last (`D167`); each entry says whether it can be put back and, when not, why (`D168`). A read like the delete
| dialog's count: `can:update,form`, and a question of another form is not this form's.
|
| ⚠️ Helpers are prefixed `historyApi*`: Pest loads every test file into one process.
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

/** @return array{tenant: Tenant, user: User, form: Form, field: FormField} */
function historyApiSetup(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $user = User::factory()->create(['name' => 'Ana Reyes']);
    enterTenant($tenant->id, $user->id);
    makeActiveMember($user, 'admin');
    $form = app(FormService::class)->create($tenant, $user, 'Household survey')->refresh();
    $field = app(FormBuilderService::class)->addField($form, $user, FieldType::ShortText, null)->refresh();

    return ['tenant' => $tenant, 'user' => $user, 'form' => $form, 'field' => $field];
}

function historyApiSave(Form $form, FormField $field, User $user, array $changes): void
{
    $row = app(BuilderPresenter::class)->field($field->refresh());
    app(FormBuilderService::class)->updateField($form->refresh(), $field, $user, [...$row, 'config' => (array) $row['config'], ...$changes], $row['version']);
}

function historyApiGet(Form $form, FormField $field, User $user, string $query = ''): TestResponse
{
    return test()->actingAs($user)->getJson("http://acme.meridian.test/forms/{$form->id}/fields/{$field->id}/history{$query}");
}

it('lists the changes newest first, a typing burst as one change from its first value to its last', function (): void {
    ['user' => $user, 'form' => $form, 'field' => $field] = historyApiSetup();

    historyApiSave($form, $field, $user, ['label' => 'Hou']);
    $this->travel(30)->seconds();
    historyApiSave($form, $field, $user, ['label' => 'Household']);
    $this->travel(30)->seconds();
    historyApiSave($form, $field, $user, ['label' => 'Household size']);
    $this->travel(10)->seconds();
    historyApiSave($form, $field, $user, ['hint' => 'Everyone who sleeps here']); // another setting: its own change
    $this->travel(3)->minutes();
    historyApiSave($form, $field, $user, ['hint' => 'Everyone who slept here last night']); // too long after: its own

    $data = historyApiGet($form, $field, $user)->assertOk()->json('data');

    expect(array_column($data, 'kind'))->toBe(['edited', 'edited', 'edited'])
        ->and($data[0]['changes'])->toEqual([['setting' => 'hint', 'before' => 'Everyone who sleeps here', 'after' => 'Everyone who slept here last night']])
        ->and($data[1]['changes'])->toEqual([['setting' => 'hint', 'before' => null, 'after' => 'Everyone who sleeps here']])
        ->and($data[2]['changes'])->toEqual([['setting' => 'label', 'before' => 'Short text', 'after' => 'Household size']])
        ->and($data[2]['by'])->toBe('Ana Reyes')
        ->and($data[2]['from'])->not->toBe($data[2]['at'])
        ->and($data[2]['can_put_back'])->toBeTrue()
        ->and($data[2]['reason'])->toBeNull();
});

it('says why a change cannot be put back: a move, a type change, a change made as another type, the Other switch', function (): void {
    ['tenant' => $tenant, 'user' => $user, 'form' => $form, 'field' => $field] = historyApiSetup();
    $builder = app(FormBuilderService::class);
    $other = $builder->addField($form->refresh(), $user, FieldType::ShortText, null)->refresh();

    historyApiSave($form, $field, $user, ['label' => 'Age']);
    $this->travel(5)->minutes();
    $builder->reorder($form->refresh(), $user, [], [
        ['id' => $other->id, 'form_section_id' => null, 'sequence' => 0, 'section_sequence' => null],
        ['id' => $field->id, 'form_section_id' => null, 'sequence' => 1, 'section_sequence' => null],
    ]);
    $this->travel(1)->minutes();
    $builder->convertField($form->refresh(), $field->refresh(), $user, FieldType::LongText, (string) FormBuilderService::rowVersion($field->refresh()), null);

    $reasons = array_column(historyApiGet($form, $field, $user)->assertOk()->json('data'), 'reason');
    expect($reasons)->toBe(['type_changed', 'moved', 'other_type']);

    enterTenant($tenant->id, $user->id); // an HTTP request leaves no tenant context behind
    $choice = $builder->addField($form->refresh(), $user, FieldType::SingleSelect, null)->refresh();
    $builder->addOtherSpecify($form->refresh(), $user, $choice);
    expect(historyApiGet($form, $choice, $user)->assertOk()->json('data.0.reason'))->toBe('other_specify');
});

it('pages fifty changes at a time, older ones behind a cursor', function (): void {
    ['user' => $user, 'form' => $form, 'field' => $field] = historyApiSetup();

    foreach (range(1, FieldHistoryPresenter::PAGE + 1) as $i) {
        historyApiSave($form, $field, $user, ['label' => "Question {$i}"]);
        $this->travel(3)->minutes(); // never merged
    }

    $first = historyApiGet($form, $field, $user)->assertOk();
    expect($first->json('data'))->toHaveCount(FieldHistoryPresenter::PAGE)
        ->and($first->json('data.0.changes.0.after'))->toBe('Question 51')
        ->and($first->json('next'))->toBeString();

    $second = historyApiGet($form, $field, $user, '?before='.$first->json('next'))->assertOk();
    expect($second->json('data'))->toHaveCount(1)
        ->and($second->json('data.0.changes.0.after'))->toBe('Question 1')
        ->and($second->json('next'))->toBeNull();
});

it('answers only for a question of this form, and only to someone who may edit it', function (): void {
    ['tenant' => $tenant, 'user' => $user, 'form' => $form, 'field' => $field] = historyApiSetup();
    $elsewhere = app(FormService::class)->create($tenant, $user, 'Another form')->refresh();

    historyApiGet($elsewhere, $field, $user)->assertNotFound();

    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');
    historyApiGet($form, $field, $viewer)->assertForbidden();
});
