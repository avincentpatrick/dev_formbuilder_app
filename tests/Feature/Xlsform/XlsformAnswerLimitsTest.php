<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Models\FormField;
use App\Models\User;
use App\Services\Forms\PublishService;
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
| M243 (`R-1da063f4`) — answer limits through XLSForm (docs/xlsform-interop-spec.md §8).
|--------------------------------------------------------------------------
| XLSForm has no column for a word or choice limit, and an ODK `constraint` would not round-trip (this product's own
| import refuses `regex` and `count-selected` at publish). So the limits ride the `#meridian` column pyxform ignores:
| `meridian:max_words=1500` after the row's type marker, and `meridian:exclusive` on a choice row.
|
| ⚠️ Helpers are prefixed `xlsLimits*`: Pest loads every test file into one process.
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

/** @param  array<string, array{headers: list<string>, rows: list<array<string, mixed>>}>  $sheets */
function xlsLimitsUpload(array $sheets): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'm243').'.xlsx';
    file_put_contents($path, app(XlsformWorkbookWriter::class)->writeToString($sheets));

    return new UploadedFile($path, 'form.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/**
 * Import sheets into a fresh form, and return its draft's questions by key and the import's warnings.
 *
 * @param  array<string, array{headers: list<string>, rows: list<array<string, mixed>>}>  $sheets
 * @return array{0: \Illuminate\Support\Collection<string, FormField>, 1: list<string>, 2: \App\Models\Form}
 */
function xlsLimitsImport(User $owner, array $sheets): array
{
    $form = makeForm($owner, 'Target');
    $draft = makeDraftVersion($form);
    $form->forceFill(['draft_version_id' => $draft->id])->save();

    $result = app(XlsformImporter::class)->import($form->refresh(), xlsLimitsUpload($sheets), $owner);

    return [FormField::query()->where('form_version_id', $draft->id)->get()->keyBy('key'), $result->warnings, $form->refresh()];
}

it('round-trips word limits, choice limits and "only answer" choices, and the import publishes', function (): void {
    $form = makeForm($this->owner, 'Limits');
    $v = makeDraftVersion($form);
    addFormField($v, $this->owner, 'why', FieldType::LongText, 0, ['config' => ['min_words' => 10, 'max_words' => 1500]]);
    addFormField($v, $this->owner, 'name', FieldType::ShortText, 1, ['config' => ['max_words' => 3]]);
    addFormField($v, $this->owner, 'pick', FieldType::MultiSelect, 2, ['config' => ['max_choices' => 2, 'options' => [
        ['value' => 'a', 'label' => 'A'],
        ['value' => 'noview', 'label' => 'No view', 'exclusive' => true],
    ]]]);
    // A flag a conversion left on a single choice is not exported: it is a multi-select's alone.
    addFormField($v, $this->owner, 'one', FieldType::SingleSelect, 3, ['config' => ['options' => [['value' => 'x', 'label' => 'X', 'exclusive' => true]]]]);
    addFormField($v, $this->owner, 'email', FieldType::Email, 4);

    $sheets = app(XlsformExporter::class)->build($form, $v);
    $meridian = collect($sheets['survey']['rows'])->pluck('#meridian', 'name');

    expect($meridian['why'])->toBe('meridian:min_words=10 meridian:max_words=1500')
        ->and($meridian['name'])->toBe('meridian:max_words=3')
        ->and($meridian['pick'])->toBe('meridian:max_choices=2')
        ->and($meridian['email'])->toBe('meridian:email')
        ->and($sheets['choices']['headers'])->toContain('#meridian')
        ->and(collect($sheets['choices']['rows'])->where('list_name', 'pick')->pluck('#meridian', 'name')->filter()->all())->toBe(['noview' => 'meridian:exclusive'])
        ->and(collect($sheets['choices']['rows'])->firstWhere('list_name', 'one'))->not->toHaveKey('#meridian');

    [$fields, $warnings, $target] = xlsLimitsImport($this->owner, $sheets);

    expect($warnings)->toBe([])
        ->and($fields['why']->answerLimits()['words'])->toBe(['min' => 10, 'max' => 1500])
        ->and($fields['name']->answerLimits()['words'])->toBe(['min' => null, 'max' => 3])
        ->and($fields['pick']->answerLimits())->toBe(['words' => null, 'choices' => ['min' => null, 'max' => 2], 'exclusive' => ['noview']])
        ->and($fields['one']->config['options'][0])->not->toHaveKey('exclusive')
        ->and($fields['email']->field_type)->toBe(FieldType::Email);

    expect(app(PublishService::class)->publish($target, $this->owner)->status)->toBe(FormVersionStatus::Published);
});

it('leaves out a setting a question cannot take, or one that is not a whole number, and says so', function (): void {
    [$fields, $warnings] = xlsLimitsImport($this->owner, [
        'survey' => ['headers' => ['type', 'name', 'label', '#meridian'], 'rows' => [
            ['type' => 'text', 'name' => 'why', 'label' => 'Why', '#meridian' => 'meridian:max_words=ten meridian:min_words=2'],
            ['type' => 'integer', 'name' => 'age', 'label' => 'Age', '#meridian' => 'meridian:max_words=5'],
            ['type' => 'select_one yn', 'name' => 'ok', 'label' => 'OK', '#meridian' => 'meridian:max_choices=1'],
        ]],
        'choices' => ['headers' => ['list_name', 'name', 'label', '#meridian'], 'rows' => [
            ['list_name' => 'yn', 'name' => 'yes', 'label' => 'Yes', '#meridian' => 'meridian:exclusive'],
        ]],
    ]);

    expect($fields['why']->answerLimits()['words'])->toBe(['min' => 2, 'max' => null])
        ->and($fields['age']->config)->not->toHaveKey('max_words')
        ->and($fields['ok']->config)->not->toHaveKey('max_choices')
        ->and($fields['ok']->config['options'][0])->not->toHaveKey('exclusive')
        ->and($warnings)->toBe([
            'Row 2: the setting “meridian:max_words=ten” was left out. It is not a limit this question takes, or not a whole number.',
            'Row 3: the setting “meridian:max_words=5” was left out. It is not a limit this question takes, or not a whole number.',
            'Row 4: the setting “meridian:max_choices=1” was left out. It is not a limit this question takes, or not a whole number.',
        ]);
});
