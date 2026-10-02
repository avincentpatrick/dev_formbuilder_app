<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Exceptions\Forms\FormException;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BlueprintValidator;
use App\Services\Forms\FormService;
use App\Services\Forms\StructuralValidationGate;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M125 — a note's content blocks, through every door that writes them (R-6dedc3a9, the shape half).
|--------------------------------------------------------------------------
| The builder's PATCH (lenient, through the real subdomain pipeline and its global middleware), the blueprint
| validator that the template materializer and the question library share (lenient), and the publish gate (strict).
| The unit matrix in tests/Unit/Forms/ContentBlocksRuleTest.php owns the shape itself; this file owns the WIRING.
|
| ⚠️ Helpers are prefixed `noteContent*`: Pest loads every test file into one process.
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

/** @return array{0: Tenant, 1: User, 2: Form, 3: string, 4: string} an admin, a draft form and one note's [id, key] */
function noteContentAuthor(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');
    $form = app(FormService::class)->create($tenant, $admin, 'Welcome Page');

    $add = test()->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/fields", ['field_type' => 'note'])
        ->assertOk();

    return [$tenant, $admin, $form, $add->json('id'), $add->json('key')];
}

/** @param  array<string, mixed>  $config */
function noteContentPatch(User $admin, Form $form, string $fieldId, string $key, array $config, string $label = 'Welcome')
{
    return test()->actingAs($admin)
        ->patchJson("http://acme.meridian.test/forms/{$form->id}/fields/{$fieldId}", [
            'key' => $key,
            'label' => $label,
            'is_required' => 'optional',
            'config' => $config,
            'validations' => [],
            'version' => null,
        ]);
}

/** @return list<array<string, mixed>> */
function noteContentLinkedParagraph(string $link = 'https://example.org/consent'): array
{
    return [
        ['type' => 'heading', 'level' => 1, 'text' => 'Before you begin'],
        ['type' => 'paragraph', 'spans' => [
            ['text' => 'Read the '],
            ['text' => 'consent form', 'link' => $link],
            ['text' => ' first.'],
        ]],
    ];
}

it('keeps the whitespace at a span’s edges through the save, and still trims everything else', function (): void {
    [$tenant, $admin, $form, $fieldId, $key] = noteContentAuthor();

    noteContentPatch($admin, $form, $fieldId, $key, ['content' => noteContentLinkedParagraph()], '  Welcome  ')
        ->assertOk();

    enterTenant($tenant->id, $admin->id);
    $stored = FormField::query()->findOrFail($fieldId);
    $spans = $stored->config['content'][1]['spans'];
    // "Read the " + "consent form": trimmed, the server would store "Read theconsent form" while the builder kept the space.
    expect($spans[0]['text'])->toBe('Read the ')
        ->and($spans[2]['text'])->toBe(' first.')
        ->and($spans[1]['link'])->toBe('https://example.org/consent')
        // The exemption is scoped to the content: the label beside it is still trimmed.
        ->and($stored->label)->toBe('Welcome');
});

it('accepts what an author has mid-edit, an emptied heading included', function (): void {
    [$tenant, $admin, $form, $fieldId, $key] = noteContentAuthor();

    // `ConvertEmptyStringsToNull` makes the cleared heading null before validation; the lenient rule takes it as blank.
    noteContentPatch($admin, $form, $fieldId, $key, ['content' => [['type' => 'heading', 'level' => 1, 'text' => '']]])
        ->assertOk();

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->findOrFail($fieldId)->config['content'][0]['text'])->toBeNull();
});

it('refuses a shape the renderers cannot read, and persists none of it', function (array $content): void {
    [$tenant, $admin, $form, $fieldId, $key] = noteContentAuthor();

    noteContentPatch($admin, $form, $fieldId, $key, ['content' => $content])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['config.content']);

    enterTenant($tenant->id, $admin->id);
    expect(FormField::query()->findOrFail($fieldId)->config)->not->toHaveKey('content');
})->with([
    'an html key on a span' => [[['type' => 'paragraph', 'spans' => [['text' => 'Hi', 'html' => '<img src=x onerror=alert(1)>']]]]],
    'a javascript link' => [noteContentLinkedParagraph('javascript:alert(1)')],
    'an image before D58' => [[['type' => 'image', 'attachment_id' => '01J']]],
]);

it('refuses a blank heading at publish, naming the note — and passes good content and a plain note', function (): void {
    $user = User::factory()->create();
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    enterTenant($tenant->id, $user->id);
    $gate = new StructuralValidationGate;

    $version = makeDraftVersion(makeForm($user));
    addFormField($version, $user, 'welcome', FieldType::Note, 0, ['config' => ['content' => [['type' => 'heading', 'level' => 1, 'text' => null]]]]);
    addFormField($version, $user, 'intro', FieldType::Note, 1, ['config' => ['content' => noteContentLinkedParagraph()]]);
    addFormField($version, $user, 'plain', FieldType::Note, 2);

    try {
        $gate->assertPublishable($version->refresh());
        $violations = [];
    } catch (PublishValidationException $e) {
        $violations = $e->violations();
    }

    expect(array_column($violations, 'code'))->toBe(['note_content_invalid'])
        ->and(array_column($violations, 'field'))->toBe(['welcome'])
        ->and($violations[0]['message'])->toContain('heading with no text');
});

it('refuses bad content through the blueprint door the templates and the question library share', function (): void {
    $validator = new BlueprintValidator;
    $note = fn (array $content): array => ['key' => 'welcome', 'field_type' => 'note', 'config' => ['content' => $content]];

    expect(fn () => $validator->validate(['sections' => [], 'fields' => [$note(noteContentLinkedParagraph('javascript:alert(1)'))]]))
        ->toThrow(FormException::class, 'link this form cannot show')
        ->and(fn () => $validator->validateField($note([['type' => 'heading', 'level' => 1, 'text' => 'Hi', 'html' => '<b>x</b>']])))
        ->toThrow(FormException::class, 'unknown setting');

    // Leniently, as the builder's own save: a blank heading is a draft's business, refused only at publish.
    $validator->validate(['sections' => [], 'fields' => [$note([['type' => 'heading', 'level' => 1, 'text' => null]])]]);
    $validator->validateField($note(noteContentLinkedParagraph()));
    // A short text carrying a `content` key is not a note, and its config is not this rule's business.
    $validator->validateField(['key' => 'name', 'field_type' => 'short_text', 'config' => ['content' => 'anything']]);
});
