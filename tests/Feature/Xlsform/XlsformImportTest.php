<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\RequiredMode;
use App\Exceptions\Xlsform\XlsformImportException;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\User;
use App\Services\Forms\PublishService;
use App\Services\Xlsform\XlsformExporter;
use App\Services\Xlsform\XlsformImporter;
use App\Services\Xlsform\XlsformWorkbookWriter;
use App\Support\Api\ApiAbilities;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment G7b — XLSForm import (docs/xlsform-interop-spec.md §5/§6): the export→import→publish round-trip
| keystone, the upfront-validating destructive draft-replace, settings mapping, and import authorization.
|--------------------------------------------------------------------------
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

/** Write workbook sheets ({name:{headers,rows}}) to a real .xlsx UploadedFile the importer/route can consume. */
function xlsxUpload(array $sheets, string $name = 'form.xlsx'): UploadedFile
{
    $bytes = app(XlsformWorkbookWriter::class)->writeToString($sheets);
    $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
    file_put_contents($path, $bytes);

    return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
}

/** A minimal, valid one-field workbook upload (for the authorization tests). */
function minimalXlsxUpload(): UploadedFile
{
    return xlsxUpload([
        'survey' => ['headers' => ['type', 'name', 'label'], 'rows' => [['type' => 'text', 'name' => 'q1', 'label' => 'Q1']]],
        'choices' => ['headers' => ['list_name', 'name', 'label'], 'rows' => []],
        'settings' => ['headers' => ['form_title'], 'rows' => [['form_title' => 'Imported']]],
    ]);
}

/** An empty-draft form the import targets (draft linked so the importer resolves it). */
function emptyTargetForm(User $owner, string $title = 'Empty Target'): array
{
    $form = makeForm($owner, $title);
    $draft = makeDraftVersion($form);
    $form->forceFill(['draft_version_id' => $draft->id])->save();

    return [$form->refresh(), $draft];
}

/** A rich draft covering the round-trip field families + the documented narrowings + a repeatable section. */
function roundTripSourceForm(User $owner): array
{
    $form = makeForm($owner, 'Round Trip');
    $v = makeDraftVersion($form);

    addFormField($v, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    addFormField($v, $owner, 'bio', FieldType::LongText, 1, ['hint' => 'Tell us', 'label_translations' => ['fr' => 'Bio']]);
    addFormField($v, $owner, 'website', FieldType::Url, 2);
    addFormField($v, $owner, 'secret', FieldType::Hidden, 3);

    $count = addFormField($v, $owner, 'count', FieldType::Integer, 4);
    FormFieldValidation::create(['form_version_id' => $v->id, 'form_field_id' => $count->id, 'expression' => '${count} >= 0', 'error_message' => 'Min 0', 'sequence' => 0]);

    $price = addFormField($v, $owner, 'price', FieldType::Decimal, 5);
    // A structured rule the frozen grammar renders to `. >= 0` → re-imports as an expression validation.
    FormFieldValidation::create(['form_version_id' => $v->id, 'form_field_id' => $price->id, 'rule_type' => 'min_value', 'rule_value' => '0', 'error_message' => 'Too small', 'sequence' => 0]);

    addFormField($v, $owner, 'when', FieldType::Date, 6);
    addFormField($v, $owner, 'score', FieldType::Calculated, 7, ['config' => ['calculated_formula' => '${count} + 1']]);
    addFormField($v, $owner, 'color', FieldType::SingleSelect, 8, ['config' => ['options' => [['value' => 'r', 'label' => 'Red'], ['value' => 'b', 'label' => 'Blue']]]]);
    addFormField($v, $owner, 'hobbies', FieldType::MultiSelect, 9, ['config' => ['options' => [['value' => 'read', 'label' => 'Reading'], ['value' => 'run', 'label' => 'Running']]]]);
    addFormField($v, $owner, 'pick', FieldType::Dropdown, 10, ['config' => ['options' => [['value' => 'x', 'label' => 'X'], ['value' => 'y', 'label' => 'Y']]]]);
    addFormField($v, $owner, 'rating', FieldType::LikertScale, 11, ['config' => ['options' => [['value' => '1', 'label' => 'Low'], ['value' => '5', 'label' => 'High']]]]);
    addFormField($v, $owner, 'subscribe', FieldType::YesNo, 12);
    addFormField($v, $owner, 'home', FieldType::Geopoint, 13, ['default_value' => json_encode(['type' => 'Point', 'coordinates' => [121.0, 14.6]]), 'default_value_is_expression' => false]);
    addFormField($v, $owner, 'photo', FieldType::ImageCapture, 14);
    addFormField($v, $owner, 'sig', FieldType::Signature, 15);
    addFormField($v, $owner, 'note1', FieldType::Note, 16);
    addFormField($v, $owner, 'brk', FieldType::PageBreak, 17);
    addFormField($v, $owner, 'dur', FieldType::Duration, 18);
    addFormField($v, $owner, 'region', FieldType::CascadingSelect, 19, ['config' => [
        'levels' => [['key' => 'region'], ['key' => 'province']],
        'options' => [
            ['value' => 'ncr', 'label' => 'NCR', 'level' => 'region', 'parent' => null],
            ['value' => 'manila', 'label' => 'Manila', 'level' => 'province', 'parent' => 'ncr'],
        ],
    ]]);

    $section = FormSection::create(['form_version_id' => $v->id, 'key' => 'members', 'label' => 'Members', 'sequence' => 0, 'is_repeatable' => true, 'max_instances' => 5]);
    addFormField($v, $owner, 'member_name', FieldType::ShortText, 20, ['form_section_id' => $section->id, 'section_sequence' => 0]);

    return [$form, $v->refresh()];
}

it('round-trips export → import → publish, matching by key', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    // Export the source, then import its bytes into a fresh empty-draft form.
    [$source, $sourceVersion] = roundTripSourceForm($owner);
    $sheets = app(XlsformExporter::class)->build($source, $sourceVersion);
    $upload = xlsxUpload($sheets);

    [$target, $draft] = emptyTargetForm($owner);
    $result = app(XlsformImporter::class)->import($target, $upload, $owner);

    $fields = FormField::query()->where('form_version_id', $draft->id)->get()->keyBy('key');

    // Full-fidelity families — field_type + config preserved by key.
    expect($fields)->toHaveKeys(['full_name', 'bio', 'website', 'secret', 'count', 'price', 'when', 'score', 'color', 'hobbies', 'pick', 'home', 'photo', 'sig', 'note1', 'brk', 'region'])
        ->and($fields['full_name']->field_type)->toBe(FieldType::ShortText)
        ->and($fields['full_name']->is_required)->toBe(RequiredMode::Required)
        ->and($fields['bio']->field_type)->toBe(FieldType::LongText)
        ->and($fields['bio']->appearance)->toBeNull()            // synthetic `multiline` dropped
        ->and($fields['bio']->hint)->toBe('Tell us')
        ->and($fields['bio']->label_translations)->toBe(['fr' => 'Bio'])
        ->and($fields['website']->field_type)->toBe(FieldType::Url)
        ->and($fields['secret']->field_type)->toBe(FieldType::Hidden)
        ->and($fields['when']->field_type)->toBe(FieldType::Date)
        ->and($fields['photo']->field_type)->toBe(FieldType::ImageCapture)
        ->and($fields['sig']->field_type)->toBe(FieldType::Signature)
        ->and($fields['note1']->field_type)->toBe(FieldType::Note)
        ->and($fields['brk']->field_type)->toBe(FieldType::PageBreak);

    // Calculated formula lands in config; select options preserved.
    expect($fields['score']->field_type)->toBe(FieldType::Calculated)
        ->and($fields['score']->config['calculated_formula'])->toBe('${count} + 1')
        ->and($fields['color']->field_type)->toBe(FieldType::SingleSelect)
        ->and($fields['color']->config['options'])->toHaveCount(2)
        ->and($fields['hobbies']->field_type)->toBe(FieldType::MultiSelect)
        ->and($fields['pick']->field_type)->toBe(FieldType::Dropdown);

    // Documented narrowings — data preserved, type conservatively single_select / decimal (§3).
    expect($fields['subscribe']->field_type)->toBe(FieldType::SingleSelect)
        ->and($fields['rating']->field_type)->toBe(FieldType::SingleSelect)
        ->and($fields['rating']->config['options'])->toHaveCount(2)
        ->and($fields['dur']->field_type)->toBe(FieldType::Decimal);

    // Geo default round-trips through the lat/lon flip.
    $env = json_decode((string) $fields['home']->default_value, true);
    expect($fields['home']->field_type)->toBe(FieldType::Geopoint)
        ->and($env['coordinates'][0])->toEqual(121.0)
        ->and($env['coordinates'][1])->toEqual(14.6);

    // Cascading reconstructed.
    expect($fields['region']->field_type)->toBe(FieldType::CascadingSelect)
        ->and($fields['region']->config['levels'])->toHaveCount(2);
    $manila = collect($fields['region']->config['options'])->firstWhere('value', 'manila');
    expect($manila['parent'])->toBe('ncr');

    // Constraints — expression-based rows (the structured min_value became `. >= 0`).
    $countVal = FormFieldValidation::query()->where('form_field_id', $fields['count']->id)->first();
    $priceVal = FormFieldValidation::query()->where('form_field_id', $fields['price']->id)->first();
    expect($countVal->expression)->toBe('${count} >= 0')
        ->and($countVal->rule_type)->toBeNull()
        ->and($priceVal->expression)->toBe('. >= 0')
        ->and($priceVal->rule_type)->toBeNull();

    // Repeatable section + its inner field.
    $members = FormSection::query()->where('form_version_id', $draft->id)->where('key', 'members')->first();
    expect($members->is_repeatable)->toBeTrue()
        ->and($members->max_instances)->toBe(5)
        ->and($fields['member_name']->form_section_id)->toBe($members->id);

    // Settings mapped onto the form (version untouched).
    $target->refresh();
    expect($target->title)->toBe('Round Trip')
        ->and($target->public_slug)->toBe('round-trip')
        ->and($result->fieldCount)->toBeGreaterThan(15);

    // The keystone: the imported draft passes BOTH publish gates.
    $published = app(PublishService::class)->publish($target->refresh(), $owner);
    expect($published->status)->toBe(FormVersionStatus::Published);
});

it('validates upfront — a bad row never touches the draft', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    [$target, $draft] = emptyTargetForm($owner, 'Has Content');
    addFormField($draft, $owner, 'existing1', FieldType::ShortText, 0);
    addFormField($draft, $owner, 'existing2', FieldType::ShortText, 1);
    addFormField($draft, $owner, 'existing3', FieldType::ShortText, 2);

    $upload = xlsxUpload([
        'survey' => ['headers' => ['type', 'name', 'label'], 'rows' => [
            ['type' => 'text', 'name' => 'a', 'label' => 'A'],
            ['type' => 'text', 'name' => 'b', 'label' => 'B'],
            ['type' => 'rank', 'name' => 'c', 'label' => 'C'], // unmapped → throws before the destructive txn
        ]],
        'choices' => ['headers' => ['list_name', 'name', 'label'], 'rows' => []],
        'settings' => ['headers' => ['form_title'], 'rows' => []],
    ]);

    expect(fn () => app(XlsformImporter::class)->import($target, $upload, $owner))
        ->toThrow(XlsformImportException::class);

    // The three pre-existing fields are untouched — the delete never ran.
    expect(FormField::query()->where('form_version_id', $draft->id)->count())->toBe(3);
});

it('authorizes web import as a form update', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    [$form, $draft] = emptyTargetForm($owner);

    // Owner (edit-any) succeeds and populates the draft.
    $this->actingAs($owner)
        ->post("http://acme.meridian.test/forms/{$form->id}/draft/xlsform-import", ['file' => minimalXlsxUpload()])
        ->assertRedirect();
    // The request tears down the RLS context on terminate() — re-enter to read the written rows.
    enterTenant($tenant->id, $owner->id);
    expect(FormField::query()->where('form_version_id', $draft->id)->count())->toBe(1);

    // A form_editor who is not a collaborator cannot update this form.
    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    $this->actingAs($editor)
        ->post("http://acme.meridian.test/forms/{$form->id}/draft/xlsform-import", ['file' => minimalXlsxUpload()])
        ->assertForbidden();

    // A viewer cannot import.
    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');
    $this->actingAs($viewer)
        ->post("http://acme.meridian.test/forms/{$form->id}/draft/xlsform-import", ['file' => minimalXlsxUpload()])
        ->assertForbidden();
});

it('imports over the API with a WRITE token and returns counts + warnings', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    [$form] = emptyTargetForm($owner);
    $token = $owner->createToken('ci', [ApiAbilities::WRITE_FORMS])->plainTextToken;

    $this->withToken($token)
        ->post("http://acme.meridian.test/api/v1/forms/{$form->id}/draft/xlsform-import", ['file' => minimalXlsxUpload()])
        ->assertOk()
        ->assertJsonPath('data.field_count', 1)
        ->assertJsonStructure(['data' => ['section_count', 'field_count', 'validation_count'], 'warnings']);
});

it('rejects an API import from a read-only token', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    [$form] = emptyTargetForm($owner);
    $token = $owner->createToken('ci', [ApiAbilities::READ_FORMS])->plainTextToken;

    $this->withToken($token)
        ->post("http://acme.meridian.test/api/v1/forms/{$form->id}/draft/xlsform-import", ['file' => minimalXlsxUpload()])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'insufficient_ability');
});

it('returns the structured error envelope for an unsupported type over the API', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    [$form] = emptyTargetForm($owner);
    $token = $owner->createToken('ci', [ApiAbilities::WRITE_FORMS])->plainTextToken;

    $upload = xlsxUpload([
        'survey' => ['headers' => ['type', 'name', 'label'], 'rows' => [['type' => 'rank', 'name' => 'x', 'label' => 'X']]],
        'choices' => ['headers' => ['list_name', 'name', 'label'], 'rows' => []],
        'settings' => ['headers' => ['form_title'], 'rows' => []],
    ]);

    $this->withToken($token)
        ->post("http://acme.meridian.test/api/v1/forms/{$form->id}/draft/xlsform-import", ['file' => $upload])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'xlsform_unsupported_field_type')
        ->assertJsonPath('error.details.type', 'rank');
});
