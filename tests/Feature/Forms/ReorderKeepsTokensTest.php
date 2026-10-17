<?php

declare(strict_types=1);

use App\Models\FormField;
use App\Models\FormSection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M176 (`R-c4802b60`) — a move never re-stamps a save token.
|--------------------------------------------------------------------------
| The save token is the row's `updated_at` at second precision, compared exactly. `reorder()` wrote positions
| through Eloquent's query builder, whose `update()` adds `updated_at` — and the builder sends EVERY section and
| question of the draft on each move — so once a second had passed, the author's next ordinary edit of any
| question answered 409 and opened "This item changed elsewhere" with nobody else editing. The clock is moved
| on by a second BEFORE each move: a re-stamp inside the second the page's token was taken in leaves the token
| unchanged, so a move made at once hid the defect — the first draft of this file moved it after, and passed
| on the defective code at random.
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
 * A draft with one section and two questions, as an Admin sees it on load.
 *
 * @return array{0: Tenant, 1: User, 2: \App\Models\Form, 3: array<string, mixed>, 4: array<string, mixed>, 5: array<string, mixed>}
 */
function reorderTokensDraft(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Household Survey');
    test()->withHeaders(builderHeaders($form));

    $section = test()->actingAs($admin)->postJson("http://acme.meridian.test/forms/{$form->id}/sections")->assertOk()->json();
    $first = test()->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'short_text', 'section_id' => $section['id']])
        ->assertOk()->json();
    $second = test()->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'short_text', 'section_id' => $section['id']])
        ->assertOk()->json();

    return [$tenant, $admin, $form, $section, $first, $second];
}

/** Every section and question of the draft, in the order given — the body `persistOrder()` sends. */
function reorderBody(array $section, array $fields, ?string $targetSection): array
{
    return [
        'sections' => [['id' => $section['id'], 'sequence' => 0]],
        'fields' => array_map(static fn (array $field, int $i): array => [
            'id' => $field['id'], 'form_section_id' => $targetSection, 'sequence' => $i, 'section_sequence' => $i,
        ], $fields, array_keys($fields)),
    ];
}

/** An ordinary edit of a question's required setting, carrying the token the page holds. */
function requiredEdit(array $field): array
{
    return [
        'key' => $field['key'], 'label' => $field['label'], 'is_required' => 'required', 'config' => [],
        'validations' => [], 'version' => $field['version'],
    ];
}

it('lets the next edit of any question save after a drag, once a second has passed', function (): void {
    [, $admin, $form, $section, $first, $second] = reorderTokensDraft();

    $this->travel(1)->seconds();

    $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/reorder", reorderBody($section, [$second, $first], $section['id']))
        ->assertOk();

    // Both the moved question and the one it moved past keep the token the page loaded with.
    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$first['id']}", requiredEdit($first))
        ->assertOk()->assertJsonPath('is_required', 'required');
    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$second['id']}", requiredEdit($second))
        ->assertOk();
});

it('lets the next edit of a section save after a move', function (): void {
    [, $admin, $form, $section, $first, $second] = reorderTokensDraft();

    $this->travel(1)->seconds();

    $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/reorder", reorderBody($section, [$first, $second], $section['id']))
        ->assertOk();

    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/sections/{$section['id']}", [
            'key' => $section['key'], 'label' => 'Household members', 'description' => null, 'is_repeatable' => false,
            'min_instances' => null, 'max_instances' => null, 'relevant_expression' => null, 'version' => $section['version'],
        ])
        ->assertOk();
});

it('lets the next edit save after the Advanced tab moves a question out of its section', function (): void {
    [, $admin, $form, $section, $first, $second] = reorderTokensDraft();

    $this->travel(1)->seconds();

    // The Section choice re-parents through the same reorder endpoint.
    $this->actingAs($admin)->postJson("http://acme.meridian.test/forms/{$form->id}/reorder", [
        'sections' => [['id' => $section['id'], 'sequence' => 0]],
        'fields' => [
            ['id' => $first['id'], 'form_section_id' => null, 'sequence' => 0, 'section_sequence' => null],
            ['id' => $second['id'], 'form_section_id' => $section['id'], 'sequence' => 1, 'section_sequence' => 0],
        ],
    ])->assertOk();

    $this->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$first['id']}", requiredEdit($first))
        ->assertOk();
});

it('writes the new order without touching any row\'s updated_at', function (): void {
    [$tenant, $admin, $form, $section, $first, $second] = reorderTokensDraft();

    enterTenant($tenant->id, $admin->id);
    $before = [
        FormSection::query()->whereKey($section['id'])->value('updated_at'),
        FormField::query()->whereKey($first['id'])->value('updated_at'),
        FormField::query()->whereKey($second['id'])->value('updated_at'),
    ];

    $this->travel(1)->seconds();

    $this->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/reorder", reorderBody($section, [$second, $first], $section['id']))
        ->assertOk();

    enterTenant($tenant->id, $admin->id);
    // The positions DID move — so an unchanged timestamp is not a reorder that wrote nothing.
    expect(FormField::query()->whereKey($second['id'])->value('sequence'))->toBe(0);
    expect(FormField::query()->whereKey($first['id'])->value('sequence'))->toBe(1);
    expect([
        FormSection::query()->whereKey($section['id'])->value('updated_at'),
        FormField::query()->whereKey($first['id'])->value('updated_at'),
        FormField::query()->whereKey($second['id'])->value('updated_at'),
    ])->toEqual($before);
});
