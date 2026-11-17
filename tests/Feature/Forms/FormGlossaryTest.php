<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Exceptions\Forms\FormException;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\FormVersionGlossaryEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormGlossaryService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Forms\RestoreService;
use App\Services\Forms\SchemaSnapshotSerializer;
use App\Services\Forms\TemplateService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M245 (`R-d0bbb7bb`) — a form's glossary: terms and definitions on the draft, frozen with each published version.
|--------------------------------------------------------------------------
| Edited under Form settings → Glossary (`/forms/{form}/glossary`), carried in the snapshot as `glossary` (omitted
| when empty), copied to the next draft, replaced by a restore, carried by a template, and the authority a note's
| marked word is checked against at publish.
|
| ⚠️ Helpers are prefixed `glossaryForm*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);
    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Household Visit');
    $this->url = "http://acme.meridian.test/forms/{$this->form->id}/glossary";
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** A note on the draft whose content marks `$term` as a glossary word. */
function glossaryFormMarkedNote(object $test, string $term, string $key = 'intro'): void
{
    addFormField($test->form->refresh()->draftVersion, $test->admin, $key, FieldType::Note, 0, [
        'config' => ['content' => [
            ['type' => 'paragraph', 'spans' => [['text' => 'Ask the '], ['text' => $term, 'term' => $term], ['text' => ' first.']]],
        ]],
    ]);
}

/** @return list<string> the draft's terms, in the order the author is shown them */
function glossaryFormDraftTerms(object $test): array
{
    return array_column(app(FormGlossaryService::class)->forAuthor($test->form->refresh()), 'term');
}

function glossaryFormPublishError(object $test): string
{
    try {
        app(PublishService::class)->publish($test->form->refresh(), $test->admin);
    } catch (PublishValidationException $e) {
        return $e->getMessage().' '.json_encode($e->violations(), JSON_UNESCAPED_UNICODE);
    }

    return '';
}

// ── The routes ────────────────────────────────────────────────────────────────────────────────────────────

it('adds, lists in key order, changes and removes a term on the draft', function (): void {
    $twg = $this->actingAs($this->admin)->postJson($this->url, ['term' => '  TWG ', 'definition' => "The technical\nworking group."])
        ->assertCreated()
        ->assertJsonPath('data.term', 'TWG')
        ->assertJsonPath('data.definition', 'The technical working group.')
        ->json('data.id');
    $this->actingAs($this->admin)->postJson($this->url, ['term' => 'barangay', 'definition' => 'A village.'])->assertCreated();

    $this->actingAs($this->admin)->getJson($this->url)
        ->assertOk()
        ->assertJsonPath('data.0.term', 'barangay')
        ->assertJsonPath('data.1.term', 'TWG');

    $this->actingAs($this->admin)->patchJson("{$this->url}/{$twg}", ['term' => 'TWG', 'definition' => 'Changed.'])
        ->assertOk()
        ->assertJsonPath('data.definition', 'Changed.');
    // A request leaves the tenant context behind, so a read after one enters it again.
    enterTenant($this->tenant->id, $this->admin->id);
    expect(FormVersionGlossaryEntry::query()->whereKey($twg)->value('term_key'))->toBe('twg');

    $this->actingAs($this->admin)->deleteJson("{$this->url}/{$twg}")->assertNoContent();
    enterTenant($this->tenant->id, $this->admin->id);
    expect(glossaryFormDraftTerms($this))->toBe(['barangay']);
});

it('refuses a term the glossary already has, ignoring case and spacing, and names it', function (): void {
    $this->actingAs($this->admin)->postJson($this->url, ['term' => 'Barangay Health Worker', 'definition' => 'A volunteer.'])->assertCreated();
    $other = $this->actingAs($this->admin)->postJson($this->url, ['term' => 'BHW', 'definition' => 'Short.'])->json('data.id');

    $this->actingAs($this->admin)->postJson($this->url, ['term' => 'barangay  health worker', 'definition' => 'Again.'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['term' => 'This glossary already has “Barangay Health Worker”.']);
    $this->actingAs($this->admin)->patchJson("{$this->url}/{$other}", ['term' => 'BARANGAY HEALTH WORKER', 'definition' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['term' => 'This glossary already has “Barangay Health Worker”.']);
});

it('refuses a term that could never be marked, an empty definition, and one too long', function (array $body, string $field, string $message): void {
    $this->actingAs($this->admin)->postJson($this->url, $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$field => $message]);
})->with([
    'a bar' => [['term' => 'a|b', 'definition' => 'x'], 'term', 'The term holds a “|” or a control character.'],
    'too long' => [['term' => str_repeat('a', 101), 'definition' => 'x'], 'term', 'The term is longer than 100 characters.'],
    'no term' => [['term' => '', 'definition' => 'x'], 'term', 'Write the term.'],
    'no definition' => [['term' => 'TWG', 'definition' => ''], 'definition', 'Write what the term means.'],
    'spaces only' => [['term' => 'TWG', 'definition' => '   '], 'definition', 'Write what the term means.'],
    'a long definition' => [['term' => 'TWG', 'definition' => str_repeat('a', 1001)], 'definition', 'A definition can be at most 1000 characters.'],
]);

it('holds at most two hundred terms', function (): void {
    FormGlossaryService::writeAll($this->form->refresh()->draftVersion, array_map(
        static fn (int $n): array => ['term' => "T{$n}", 'definition' => 'x'],
        range(1, 200),
    ));

    $this->actingAs($this->admin)->postJson($this->url, ['term' => 'One more', 'definition' => 'x'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['term' => 'A glossary can hold 200 terms.']);
});

it('answers 404 for a term that is not on this form’s draft, and 403 to a viewer', function (): void {
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($this->admin)->patchJson("{$this->url}/0192e2e0-0000-7000-8000-0000000000c1", ['term' => 'x', 'definition' => 'y'])->assertNotFound();
    $this->actingAs($this->admin)->deleteJson("{$this->url}/0192e2e0-0000-7000-8000-0000000000c1")->assertNotFound();
    $this->actingAs($viewer)->getJson($this->url)->assertForbidden();
    $this->actingAs($viewer)->postJson($this->url, ['term' => 'TWG', 'definition' => 'x'])->assertForbidden();
});

it('takes the form’s row lock before it writes a term, so a publish cannot freeze a half-made change', function (): void {
    $statements = [];
    DB::listen(static function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    app(FormGlossaryService::class)->add($this->form->refresh(), 'TWG', 'x');

    $lock = array_key_first(array_filter($statements, static fn (string $sql): bool => str_contains($sql, 'from "forms"') && str_contains($sql, 'for update')));
    $write = array_key_first(array_filter($statements, static fn (string $sql): bool => str_starts_with($sql, 'insert into "form_version_glossary_entries"')));

    expect($lock)->not->toBeNull()
        ->and($write)->not->toBeNull()
        ->and($lock < $write)->toBeTrue();
});

// ── Freezing it with the version ──────────────────────────────────────────────────────────────────────────

it('freezes the glossary into the published snapshot in key order, and copies it to the next draft', function (): void {
    $service = app(FormGlossaryService::class);
    $service->add($this->form->refresh(), 'TWG', 'The technical working group.');
    $service->add($this->form->refresh(), 'barangay', 'A village.');
    $draftId = (string) $this->form->refresh()->draft_version_id;

    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $form = $this->form->refresh();

    // `toEqual`: Postgres keeps a `jsonb` object's keys in its own order (length, then bytes), so key order is not compared.
    expect(FormVersion::query()->findOrFail($draftId)->schema_snapshot['glossary'])->toEqual([
        ['definition' => 'A village.', 'term' => 'barangay'],
        ['definition' => 'The technical working group.', 'term' => 'TWG'],
    ])
        ->and($form->draft_version_id)->not->toBe($draftId)
        ->and(glossaryFormDraftTerms($this))->toBe(['barangay', 'TWG']);
});

it('leaves the glossary out of a snapshot that has none, so an older version’s checksum does not move', function (): void {
    addFormField($this->form->refresh()->draftVersion, $this->admin, 'name');
    $draft = $this->form->refresh()->draftVersion;
    $serializer = app(SchemaSnapshotSerializer::class);
    $before = $serializer->checksum($draft);

    expect($serializer->snapshot($draft))->not->toHaveKey('glossary');

    app(FormGlossaryService::class)->add($this->form->refresh(), 'TWG', 'x');
    expect($serializer->checksum($draft))->not->toBe($before);
});

it('lets no path change a published version’s glossary: the database refuses each write, after a draft write lands', function (): void {
    app(FormGlossaryService::class)->add($this->form->refresh(), 'TWG', 'x');
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $form = $this->form->refresh();
    $frozen = FormVersionGlossaryEntry::query()->where('form_version_id', $form->current_published_version_id)->sole();
    $draftRow = FormVersionGlossaryEntry::query()->where('form_version_id', $form->draft_version_id)->sole();

    // The positive control: the same raw UPDATE on the draft's row lands.
    expect(FormVersionGlossaryEntry::query()->whereKey($draftRow->id)->update(['definition' => 'renamed']))->toBe(1);

    // RLS filters the published row out of an UPDATE and a DELETE (zero rows), and refuses an INSERT outright.
    expect(FormVersionGlossaryEntry::query()->whereKey($frozen->id)->update(['definition' => 'tampered']))->toBe(0)
        ->and(FormVersionGlossaryEntry::query()->whereKey($frozen->id)->delete())->toBe(0)
        ->and(fn () => DB::transaction(static fn () => (new FormVersionGlossaryEntry([
            'form_version_id' => $form->current_published_version_id,
            'term' => 'smuggled',
            'definition' => 'x',
        ]))->save()))->toThrow(QueryException::class)
        ->and(FormVersionGlossaryEntry::query()->whereKey($frozen->id)->value('definition'))->toBe('x');

    // And the route, given the published entry's id, finds nothing on the draft to change.
    $this->actingAs($this->admin)->patchJson("{$this->url}/{$frozen->id}", ['term' => 'TWG', 'definition' => 'y'])->assertNotFound();
});

it('replaces the draft’s glossary with the restored version’s', function (): void {
    app(FormGlossaryService::class)->add($this->form->refresh(), 'TWG', 'Version one.');
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);
    $source = FormVersion::query()->findOrFail($this->form->refresh()->current_published_version_id);

    $service = app(FormGlossaryService::class);
    $entry = $service->forAuthor($this->form->refresh())[0];
    $service->update($this->form->refresh(), $entry['id'], 'TWG', 'Changed on the draft.');
    $service->add($this->form->refresh(), 'barangay', 'Added on the draft.');

    app(RestoreService::class)->restore($this->form->refresh(), $source, $this->admin);

    expect(array_map(static fn (array $e): string => "{$e['term']}={$e['definition']}", $service->forAuthor($this->form->refresh())))
        ->toBe(['TWG=Version one.']);
});

it('carries the glossary through a template, so the new form’s marked words still find their terms', function (): void {
    glossaryFormMarkedNote($this, 'TWG');
    app(FormGlossaryService::class)->add($this->form->refresh(), 'TWG', 'The technical working group.');

    $template = app(TemplateService::class)->saveAsTemplate($this->form->refresh()->draftVersion, $this->admin, ['name' => 'Reusable']);
    $copy = app(TemplateService::class)->instantiate($template, $this->tenant, $this->admin);

    expect($template->schema_blueprint['glossary'])->toBe([['definition' => 'The technical working group.', 'term' => 'TWG']])
        ->and(array_column(app(FormGlossaryService::class)->forAuthor($copy), 'term'))->toBe(['TWG']);

    app(PublishService::class)->publish($copy->refresh(), $this->admin);
    expect($copy->refresh()->current_published_version_id)->not->toBeNull();
});

it('refuses a template whose glossary is not one, before writing a row', function (): void {
    $template = FormTemplate::create([
        'tenant_id' => $this->tenant->id,
        'name' => 'Broken',
        'schema_blueprint' => ['sections' => [], 'fields' => [], 'glossary' => [['term' => 'TWG', 'definition' => 'x'], ['term' => 'twg', 'definition' => 'y']]],
        'is_public' => false,
        'usage_count' => 0,
        'created_by' => $this->admin->id,
    ]);

    expect(fn () => app(TemplateService::class)->instantiate($template, $this->tenant, $this->admin))
        ->toThrow(FormException::class, 'term 2 repeats “twg”');
});

// ── A marked word is checked at publish ───────────────────────────────────────────────────────────────────

it('refuses to publish a note whose marked word is not in the glossary, naming the word, and publishes once it is', function (): void {
    glossaryFormMarkedNote($this, 'TWG');

    expect(glossaryFormPublishError($this))->toContain('glossary word “TWG” is not in this form’s glossary');

    app(FormGlossaryService::class)->add($this->form->refresh(), 'twg', 'Matched ignoring case.');
    app(PublishService::class)->publish($this->form->refresh(), $this->admin);

    expect($this->form->refresh()->current_published_version_id)->not->toBeNull();
});

it('accepts a glossary term no note marks', function (): void {
    addFormField($this->form->refresh()->draftVersion, $this->admin, 'name');
    app(FormGlossaryService::class)->add($this->form->refresh(), 'Unused', 'Nobody marks this.');

    app(PublishService::class)->publish($this->form->refresh(), $this->admin);

    expect($this->form->refresh()->current_published_version_id)->not->toBeNull();
});
