<?php

declare(strict_types=1);

use App\Models\Form;
use App\Models\FormSection;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\FormService;
use App\Services\Forms\SchemaBlueprintMaterializer;
use App\Services\Forms\SchemaSnapshotSerializer;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M249 (`progress-chapters`, `D233`) — a section's place on the progress bar.
|--------------------------------------------------------------------------
| A section STARTS a chapter by carrying its name (`chapter`), and may give its page a short name for the bar
| (`progress_label`). Both are plain text, trimmed, with a blank saved as none; both reach the snapshot only when set, so
| every version published without them keeps its checksum; a copied section never starts a second chapter; and a
| template or library entry keeps both.
|
| ⚠️ Helpers are prefixed `chapterTest*`: Pest loads every test file into one process. EVERY `${` literal is SINGLE-quoted.
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

/** @return array{0: User, 1: Form, 2: FormSection} */
function chapterTestForm(): array
{
    $tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $tenant->domains()->create(['domain' => 'acme']);
    $admin = User::factory()->create();
    enterTenant($tenant->id, $admin->id);
    makeActiveMember($admin, 'admin');

    $form = app(FormService::class)->create($tenant, $admin, 'Chapters');
    test()->chapterTenant = [$tenant->id, $admin->id];
    $section = FormSection::create(['form_version_id' => $form->draft_version_id, 'key' => 'equity', 'label' => 'What people mean by equity', 'sequence' => 0]);
    test()->withHeaders(builderHeaders($form));

    return [$admin, $form, $section];
}

/** A request drops the test's tenant context; a read after one enters it again (see `builderHeaders()`). */
function chapterTestReenter(): void
{
    enterTenant(...test()->chapterTenant);
}

/** @param array<string, mixed> $settings */
function chapterTestSave(User $admin, Form $form, FormSection $section, array $settings): TestResponse
{
    chapterTestReenter();
    $section->refresh();

    return test()->actingAs($admin)->patchJson("http://acme.meridian.test/forms/{$form->id}/sections/{$section->id}", [
        'key' => $section->key,
        'label' => $section->label,
        'description' => null,
        'is_repeatable' => false,
        'min_instances' => null,
        'max_instances' => null,
        'repeat_layout' => null,
        'page_width' => null,
        'relevant_expression' => null,
        'version' => FormBuilderService::rowVersion($section),
        ...$settings,
    ]);
}

it('saves the chapter a section starts and its progress label, trimmed, and a blank as none', function (): void {
    [$admin, $form, $section] = chapterTestForm();

    chapterTestSave($admin, $form, $section, ['chapter' => '  What is health equity  ', 'progress_label' => ' Equity '])
        ->assertOk()
        ->assertJsonPath('chapter', 'What is health equity')
        ->assertJsonPath('progress_label', 'Equity');

    chapterTestSave($admin, $form, $section, ['chapter' => '   ', 'progress_label' => ''])
        ->assertOk()
        ->assertJsonPath('chapter', null)
        ->assertJsonPath('progress_label', null);

    // A client that sends neither (an older builder) leaves the section starting no chapter, as `page_width` does.
    chapterTestSave($admin, $form, $section, [])->assertOk()->assertJsonPath('chapter', null);
});

it('refuses a piped answer or more than 60 characters in either, in words that say why', function (): void {
    [$admin, $form, $section] = chapterTestForm();

    chapterTestSave($admin, $form, $section, ['chapter' => 'For ${name}'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['chapter' => 'shown as typed']);
    chapterTestSave($admin, $form, $section, ['progress_label' => str_repeat('a', 61)])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['progress_label' => 'progress label']);
});

it('sends both back on the builder page', function (): void {
    [$admin, $form, $section] = chapterTestForm();
    chapterTestSave($admin, $form, $section, ['chapter' => 'What is health equity', 'progress_label' => 'Equity'])->assertOk();

    $page = test()->withoutVite()->actingAs($admin)->get("http://acme.meridian.test/forms/{$form->id}/builder")->assertOk()->viewData('page');
    $back = collect(json_decode((string) json_encode($page['props']['sections']), true))->keyBy('id')[$section->id];

    expect($back['chapter'])->toBe('What is health equity')
        ->and($back['progress_label'])->toBe('Equity');
});

it('puts both in the snapshot only when set, so a version without them keeps its checksum', function (): void {
    [, $form, $section] = chapterTestForm();
    $serializer = new SchemaSnapshotSerializer;
    $version = $form->draftVersion;

    $plain = $serializer->snapshot($version->refresh());
    $before = $serializer->checksumOf($plain);
    expect($plain['sections'][0])->not->toHaveKeys(['chapter', 'chapter_translations', 'progress_label']);

    $section->forceFill(['chapter' => 'Equity', 'chapter_translations' => ['fil' => 'Pagkakapantay'], 'progress_label' => 'Meaning'])->save();
    $set = $serializer->snapshot($version->refresh());
    expect($set['sections'][0]['chapter'])->toBe('Equity')
        ->and($set['sections'][0]['chapter_translations'])->toBe(['fil' => 'Pagkakapantay'])
        ->and($set['sections'][0]['progress_label'])->toBe('Meaning')
        ->and($serializer->checksumOf($set))->not->toBe($before);

    // Translations of a chapter the section no longer starts are never carried.
    $section->forceFill(['chapter' => null, 'progress_label' => null])->save();
    $unset = $serializer->snapshot($version->refresh());
    expect($unset['sections'][0])->not->toHaveKey('chapter_translations')
        ->and($serializer->checksumOf($unset))->toBe($before);
});

it('clears an imported chapter\'s translations when the builder stops the section starting it', function (): void {
    [$admin, $form, $section] = chapterTestForm();
    $section->forceFill(['chapter' => 'Equity', 'chapter_translations' => ['fil' => 'Pagkakapantay']])->save();

    chapterTestSave($admin, $form, $section, ['chapter' => null])->assertOk();

    chapterTestReenter();
    expect($section->refresh()->chapter_translations)->toBeNull();
});

it('never makes a copied section start a second chapter, and keeps its progress label', function (): void {
    [$admin, $form, $section] = chapterTestForm();
    $section->forceFill(['chapter' => 'Equity', 'chapter_translations' => ['fil' => 'Pagkakapantay'], 'progress_label' => 'Meaning'])->save();

    $reply = test()->actingAs($admin)
        ->postJson("http://acme.meridian.test/forms/{$form->id}/sections/{$section->id}/duplicate")
        ->assertOk();
    chapterTestReenter();
    $copy = FormSection::query()->findOrFail($reply->json('section.id'));

    expect($copy->chapter)->toBeNull()
        ->and($copy->chapter_translations)->toBeNull()
        ->and($copy->progress_label)->toBe('Meaning')
        ->and($section->refresh()->chapter)->toBe('Equity');
});

it('keeps both through a template or a library entry, trimmed, with a blank as none', function (): void {
    [$admin, $form] = chapterTestForm();
    $draft = $form->draftVersion;
    FormSection::query()->where('form_version_id', $draft->id)->delete();

    app(SchemaBlueprintMaterializer::class)->materializeInto($draft, [
        'sections' => [
            ['key' => 'start', 'label' => 'Welcome', 'sequence' => 1, 'chapter' => ' Welcome ', 'chapter_translations' => ['fil' => 'Maligayang pagdating'], 'progress_label' => 'Hello'],
            ['key' => 'more', 'label' => 'About you', 'sequence' => 2, 'chapter' => '', 'chapter_translations' => ['fil' => 'x'], 'progress_label' => '  '],
        ],
        'fields' => [],
    ], $admin);

    $rows = FormSection::query()->where('form_version_id', $draft->id)->orderBy('sequence')->get()->keyBy('key');
    expect($rows['start']->chapter)->toBe('Welcome')
        ->and($rows['start']->chapter_translations)->toBe(['fil' => 'Maligayang pagdating'])
        ->and($rows['start']->progress_label)->toBe('Hello')
        ->and($rows['more']->chapter)->toBeNull()
        ->and($rows['more']->chapter_translations)->toBeNull()
        ->and($rows['more']->progress_label)->toBeNull();
});
