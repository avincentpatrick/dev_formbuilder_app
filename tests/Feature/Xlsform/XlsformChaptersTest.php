<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Xlsform\XlsformExporter;
use App\Services\Xlsform\XlsformImporter;
use App\Services\Xlsform\XlsformWorkbookWriter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M249 (`progress-chapters`, `D233` answer 1; `dropdown-placeholder`, answer 3) — chapters and short labels in XLSForm.
|--------------------------------------------------------------------------
| ODK and Kobo name a run of pages with an OUTER group around the page groups. Import folds such a pure container into a
| chapter on its first page instead of an empty section; every other outer group keeps the flattening it always had.
| Export writes each chapter back as an outer group, so a round trip keeps the chapters. A choice's short label rides a
| `short_label` column on the choices sheet, in the form's own language.
|
| ⚠️ Helpers are prefixed `chapterXlsx*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function chapterXlsxUpload(array $sheets): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'chp').'.xlsx';
    file_put_contents($path, app(XlsformWorkbookWriter::class)->writeToString($sheets));

    return new UploadedFile($path, 'form.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** @return array{0: Form, 1: FormVersion} */
function chapterXlsxTarget(User $owner): array
{
    $form = makeForm($owner, 'Target');
    $draft = makeDraftVersion($form);
    $form->forceFill(['draft_version_id' => $draft->id])->save();

    return [$form->refresh(), $draft];
}

/** @param list<array<string, string>> $rows */
function chapterXlsxSurvey(array $rows, array $extraHeaders = []): array
{
    return [
        'survey' => ['headers' => ['type', 'name', 'label', 'relevant', 'appearance', ...$extraHeaders], 'rows' => $rows],
        'choices' => ['headers' => ['list_name', 'name', 'label'], 'rows' => []],
        'settings' => ['headers' => ['form_title'], 'rows' => [['form_title' => 'Chapters']]],
    ];
}

it('folds an outer group made only of groups into a chapter on its first page', function (): void {
    [$form, $draft] = chapterXlsxTarget($this->owner);
    $sheets = chapterXlsxSurvey([
        ['type' => 'begin group', 'name' => 'stop_1', 'label' => 'Your starting point', 'label::Filipino (fil)' => 'Ang iyong simula'],
        ['type' => 'begin group', 'name' => 's1a', 'label' => 'Before you begin', 'appearance' => 'field-list'],
        ['type' => 'text', 'name' => 'q1', 'label' => 'Q1'],
        ['type' => 'end group', 'name' => 's1a'],
        ['type' => 'begin group', 'name' => 's1b', 'label' => 'Three situations', 'appearance' => 'field-list'],
        ['type' => 'text', 'name' => 'q2', 'label' => 'Q2'],
        ['type' => 'end group', 'name' => 's1b'],
        ['type' => 'end group', 'name' => 'stop_1'],
        ['type' => 'begin group', 'name' => 's2a', 'label' => 'Why this exists', 'appearance' => 'field-list'],
        ['type' => 'text', 'name' => 'q3', 'label' => 'Q3'],
        ['type' => 'end group', 'name' => 's2a'],
    ], ['label::Filipino (fil)']);

    app(XlsformImporter::class)->import($form, chapterXlsxUpload($sheets), $this->owner);

    $sections = FormSection::query()->where('form_version_id', $draft->id)->orderBy('sequence')->get();
    expect($sections->pluck('key')->all())->toBe(['s1a', 's1b', 's2a'])
        ->and($sections[0]->chapter)->toBe('Your starting point')
        ->and($sections[0]->chapter_translations)->toBe(['Filipino (fil)' => 'Ang iyong simula'])
        ->and($sections[1]->chapter)->toBeNull()
        ->and($sections[2]->chapter)->toBeNull();
});

it('keeps flattening an outer group that is not a pure container', function (string $why, array $outer, array $inside): void {
    [$form, $draft] = chapterXlsxTarget($this->owner);
    $sheets = chapterXlsxSurvey([
        ['type' => 'begin group', 'name' => 'outer', 'label' => 'Outer', ...$outer],
        ...$inside,
        ['type' => 'begin group', 'name' => 'inner', 'label' => 'Inner'],
        ['type' => 'text', 'name' => 'q1', 'label' => 'Q1'],
        ['type' => 'end group', 'name' => 'inner'],
        ['type' => 'end group', 'name' => 'outer'],
    ]);

    app(XlsformImporter::class)->import($form, chapterXlsxUpload($sheets), $this->owner);

    $sections = FormSection::query()->where('form_version_id', $draft->id)->orderBy('sequence')->get();
    expect($sections->pluck('key')->all())->toBe(['outer', 'inner'], $why)
        ->and($sections->pluck('chapter')->filter()->all())->toBe([], $why);
})->with([
    'a condition' => ['it has a relevant', ['relevant' => '1 = 1'], []],
    'one screen' => ['it is a field-list', ['appearance' => 'field-list'], []],
    'a question of its own' => ['it holds a question directly', [], [['type' => 'text', 'name' => 'direct', 'label' => 'Direct']]],
    'a piped label' => ['its label pipes an answer', ['label' => 'For ${q1}'], []],
]);

it('cuts a chapter name longer than 60 characters at a word, and says so', function (): void {
    [$form, $draft] = chapterXlsxTarget($this->owner);
    $long = 'What is settled and what is still open for the group to decide at the meeting';
    $sheets = chapterXlsxSurvey([
        ['type' => 'begin group', 'name' => 'stop', 'label' => $long],
        ['type' => 'begin group', 'name' => 'page', 'label' => 'Page'],
        ['type' => 'text', 'name' => 'q1', 'label' => 'Q1'],
        ['type' => 'end group', 'name' => 'page'],
        ['type' => 'end group', 'name' => 'stop'],
    ]);

    $result = app(XlsformImporter::class)->import($form, chapterXlsxUpload($sheets), $this->owner);

    $chapter = FormSection::query()->where('form_version_id', $draft->id)->value('chapter');
    expect(mb_strlen((string) $chapter))->toBeLessThanOrEqual(60)
        ->and($long)->toStartWith((string) $chapter)
        ->and(implode(' ', $result->warnings))->toContain('became a chapter');
});

it('writes each chapter as an outer group around its pages, and reads it back the same', function (): void {
    $source = makeForm($this->owner, 'Source');
    $version = makeDraftVersion($source);
    $welcome = FormSection::create(['form_version_id' => $version->id, 'key' => 'welcome', 'label' => 'Welcome', 'sequence' => 0]);
    $s1a = FormSection::create(['form_version_id' => $version->id, 'key' => 's1a', 'label' => 'Before you begin', 'sequence' => 1, 'chapter' => 'Your starting point', 'chapter_translations' => ['fil' => 'Ang iyong simula']]);
    $s1b = FormSection::create(['form_version_id' => $version->id, 'key' => 's1b', 'label' => 'Three situations', 'sequence' => 2]);
    // A section already named like the chapter's group pushes the group's name on.
    $clash = FormSection::create(['form_version_id' => $version->id, 'key' => 'ch_s2a', 'label' => 'Clash', 'sequence' => 3]);
    $s2a = FormSection::create(['form_version_id' => $version->id, 'key' => 's2a', 'label' => 'Why', 'sequence' => 4, 'chapter' => 'The problem']);
    foreach ([$welcome, $s1a, $s1b, $clash, $s2a] as $i => $section) {
        addFormField($version, $this->owner, "q{$i}", FieldType::ShortText, $i, ['form_section_id' => $section->id, 'section_sequence' => 0]);
    }

    $sheets = app(XlsformExporter::class)->build($source, $version->refresh());
    $groups = array_values(array_filter($sheets['survey']['rows'], static fn (array $row): bool => str_contains((string) $row['type'], 'group')));
    expect(array_map(static fn (array $row): string => $row['type'].' '.$row['name'], $groups))->toBe([
        'begin group welcome', 'end group welcome',
        'begin group ch_s1a', 'begin group s1a', 'end group s1a', 'begin group s1b', 'end group s1b',
        'begin group ch_s2a', 'end group ch_s2a',   // the section named ch_s2a, inside the first chapter
        'end group ch_s1a',
        'begin group ch_s2a_2', 'begin group s2a', 'end group s2a', 'end group ch_s2a_2',
    ])->and($groups[2]['label'])->toBe('Your starting point')
        ->and($groups[2]['label::fil'])->toBe('Ang iyong simula');

    [$form, $draft] = chapterXlsxTarget($this->owner);
    app(XlsformImporter::class)->import($form, chapterXlsxUpload($sheets), $this->owner);

    expect(FormSection::query()->where('form_version_id', $draft->id)->orderBy('sequence')->get(['key', 'chapter'])->map->only(['key', 'chapter'])->all())->toBe([
        ['key' => 'welcome', 'chapter' => null],
        ['key' => 's1a', 'chapter' => 'Your starting point'],
        ['key' => 's1b', 'chapter' => null],
        ['key' => 'ch_s2a', 'chapter' => null],
        ['key' => 's2a', 'chapter' => 'The problem'],
    ]);
});

it('carries a dropdown choice\'s short label on the choices sheet and back', function (): void {
    $source = makeForm($this->owner, 'Source');
    $version = makeDraftVersion($source);
    addFormField($version, $this->owner, 'use', FieldType::Dropdown, 0, ['config' => ['options' => [
        ['value' => 'include', 'label' => 'Include. Use this indicator as it stands.', 'short_label' => 'Include'],
        ['value' => 'drop', 'label' => 'Drop'],
    ]]]);

    $sheets = app(XlsformExporter::class)->build($source, $version->refresh());
    expect($sheets['choices']['headers'])->toContain('short_label');
    $rows = collect($sheets['choices']['rows'])->keyBy('name');
    expect($rows['include']['short_label'])->toBe('Include')
        ->and($rows['drop'])->not->toHaveKey('short_label');

    [$form, $draft] = chapterXlsxTarget($this->owner);
    app(XlsformImporter::class)->import($form, chapterXlsxUpload($sheets), $this->owner);

    $options = collect(FormField::query()->where('form_version_id', $draft->id)->where('key', 'use')->value('config')['options'])->keyBy('value');
    expect($options['include']['short_label'])->toBe('Include')
        ->and($options['drop'])->not->toHaveKey('short_label');
});

it('reads a short label\'s other languages from short_label::<lang>, and writes no column when no choice has one', function (): void {
    [$form, $draft] = chapterXlsxTarget($this->owner);
    $sheets = [
        'survey' => ['headers' => ['type', 'name', 'label'], 'rows' => [['type' => 'select_one use', 'name' => 'use', 'label' => 'Use?']]],
        'choices' => ['headers' => ['list_name', 'name', 'label', 'label::fil', 'short_label', 'short_label::fil'], 'rows' => [
            ['list_name' => 'use', 'name' => 'include', 'label' => 'Include. As it stands.', 'label::fil' => 'Isama. Gaya ng dati.', 'short_label' => 'Include', 'short_label::fil' => 'Isama'],
        ]],
        'settings' => ['headers' => ['form_title'], 'rows' => [['form_title' => 'Short']]],
    ];

    app(XlsformImporter::class)->import($form, chapterXlsxUpload($sheets), $this->owner);

    $include = FormField::query()->where('form_version_id', $draft->id)->where('key', 'use')->value('config')['options'][0];
    expect($include['short_label'])->toBe('Include')
        ->and($include['short_label_translations'])->toBe(['fil' => 'Isama']);

    $plain = makeForm($this->owner, 'Plain');
    $plainVersion = makeDraftVersion($plain);
    addFormField($plainVersion, $this->owner, 'pick', FieldType::Dropdown, 0, ['config' => ['options' => [['value' => 'a', 'label' => 'A']]]]);
    expect(app(XlsformExporter::class)->build($plain, $plainVersion->refresh())['choices']['headers'])->not->toContain('short_label');
});
