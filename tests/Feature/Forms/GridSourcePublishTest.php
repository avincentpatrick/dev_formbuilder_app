<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Exceptions\Forms\PublishValidationException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormChoiceListService;
use App\Services\Forms\FormService;
use App\Services\Forms\FormSettingsPresenter;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M185 (`R-bb79aec0`, `D161`) — a grid's rows, cells and a column's choices from a list file or another form's answers.
|--------------------------------------------------------------------------
| Both are COPIED IN at publish (`ChoiceListMaterializer`), because every reader of a grid reads its rows frozen: Stage 1
| refuses a row the config does not hold, both engines loop over the config's rows, and the exports, the paper and the
| encode page draw them. Publishing again picks up new answers; the next draft keeps only the source. A link that cannot
| serve is refused once, by `LinkedChoiceGate`, naming the part.
|
| ⚠️ Helpers are prefixed `gridSource*`: Pest loads every test file into one process.
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

    $this->source = app(FormService::class)->create($this->tenant, $this->admin, 'Facility Register');
    addFormField($this->source->draftVersion, $this->admin, 'facility_name', FieldType::ShortText, 1);
    app(PublishService::class)->publish($this->source->refresh(), $this->admin);
    $this->source = app(FormService::class)->setDataSharing($this->source->refresh(), true, null, $this->admin);
    seedInboxSubmission($this->source, null, SubmissionStatus::Submitted, ['facility_name' => 'San Jose RHU']);
    seedInboxSubmission($this->source, null, SubmissionStatus::Submitted, ['facility_name' => 'Poblacion BHS']);

    $this->form = app(FormService::class)->create($this->tenant, $this->admin, 'Facility Check');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function gridSourceUpload(object $test, string $name, string $content): void
{
    $path = (string) tempnam(sys_get_temp_dir(), 'm185');
    file_put_contents($path, $content);
    app(FormChoiceListService::class)->upload($test->form->refresh(), new UploadedFile($path, $name, null, null, true));
}

/** @param  array<string, mixed>  $config */
function gridSourceField(object $test, array $config, FieldType $type = FieldType::Matrix): FormField
{
    return addFormField($test->form->refresh()->draftVersion, $test->admin, 'check', $type, 1, ['config' => $config]);
}

/** @return array<string, mixed> a Grid of one Single choice column over shared cells, its rows from $rows */
function gridSourceConfig(array $rows): array
{
    return [
        'rows' => [],
        ...$rows,
        'columns' => [['value' => 'stock', 'label' => 'Stocked']],
        'cells' => [['value' => 'yes', 'label' => 'Yes'], ['value' => 'no', 'label' => 'No']],
    ];
}

function gridSourcePublishError(object $test): string
{
    try {
        app(PublishService::class)->publish($test->form->refresh(), $test->admin);
    } catch (PublishValidationException $e) {
        return $e->getMessage();
    }

    return 'published';
}

/** @return array<string, mixed> the published question's config, as the version's snapshot froze it */
function gridSourcePublished(object $test): array
{
    $version = FormVersion::query()->findOrFail($test->form->refresh()->current_published_version_id);

    return collect($version->schema_snapshot['fields'])->firstWhere('key', 'check')['config'];
}

function gridSourceNextDraft(object $test): array
{
    return FormField::query()->where('form_version_id', $test->form->refresh()->draft_version_id)->where('key', 'check')->firstOrFail()->config;
}

it('copies a list file\'s rows into the published grid, and the next draft names the list and holds none of them', function (FieldType $type): void {
    gridSourceUpload($this, 'wards.csv', "name,label\nw1,Ward 1\nw2,Ward 2\n");
    $config = gridSourceConfig(['rows_list' => 'wards']);
    if ($type === FieldType::LikertMatrix) {
        unset($config['cells']);
    }
    gridSourceField($this, $config, $type);

    expect(gridSourcePublishError($this))->toBe('published')
        ->and(gridSourcePublished($this)['rows'])->toEqual([['value' => 'w1', 'label' => 'Ward 1'], ['value' => 'w2', 'label' => 'Ward 2']])
        ->and(gridSourceNextDraft($this)['rows'])->toBe([])
        ->and(gridSourceNextDraft($this)['rows_list'])->toBe('wards');
})->with([FieldType::Matrix, FieldType::LikertMatrix]);

it('copies another form\'s answers in as rows, the answer\'s text as the value, and takes an answer on a copied row', function (): void {
    gridSourceField($this, gridSourceConfig(['rows_source' => ['form_id' => $this->source->id, 'field_key' => 'facility_name']]));

    expect(gridSourcePublishError($this))->toBe('published')
        ->and(gridSourcePublished($this)['rows'])->toEqual([
            ['value' => 'Poblacion BHS', 'label' => 'Poblacion BHS'],
            ['value' => 'San Jose RHU', 'label' => 'San Jose RHU'],
        ]);

    $version = FormVersion::query()->findOrFail($this->form->refresh()->current_published_version_id);
    $errors = [];
    try {
        app(SubmissionPipeline::class)->submit(new SubmissionPayload(version: $version, answers: ['check' => ['San Jose RHU' => ['stock' => 'yes']]], source: SubmissionSource::Manual, respondentUserId: $this->admin->id));
    } catch (SubmissionValidationException $e) {
        $errors = $e->fieldErrors();
    }
    expect($errors)->toBe([]);
});

it('picks up new answers when the form is published again', function (): void {
    gridSourceField($this, gridSourceConfig(['rows_source' => ['form_id' => $this->source->id, 'field_key' => 'facility_name']]));
    expect(gridSourcePublishError($this))->toBe('published');

    seedInboxSubmission($this->source, null, SubmissionStatus::Submitted, ['facility_name' => 'Abra RHU']);

    expect(array_column(gridSourcePublished($this)['rows'], 'value'))->toBe(['Poblacion BHS', 'San Jose RHU'])
        ->and(gridSourcePublishError($this))->toBe('published')
        ->and(array_column(gridSourcePublished($this)['rows'], 'value'))->toBe(['Abra RHU', 'Poblacion BHS', 'San Jose RHU']);
});

it('copies a column\'s own choices and the shared cells from a list or a form, so no shared cells are asked for', function (): void {
    gridSourceUpload($this, 'grades.csv', "name,label\na,Good\nb,Poor\n");
    gridSourceField($this, [
        'rows' => [['value' => 'r1', 'label' => 'Room 1']],
        'columns' => [
            ['value' => 'grade', 'label' => 'Grade', 'options_list' => 'grades'],
            ['value' => 'from', 'label' => 'Referred from', 'type' => 'multi_select', 'options_source' => ['form_id' => $this->source->id, 'field_key' => 'facility_name']],
        ],
        'cells' => [],
    ]);

    expect(gridSourcePublishError($this))->toBe('published');
    $config = gridSourcePublished($this);
    expect($config['columns'][0]['options'])->toEqual([['value' => 'a', 'label' => 'Good'], ['value' => 'b', 'label' => 'Poor']])
        ->and(array_column($config['columns'][1]['options'], 'value'))->toBe(['Poblacion BHS', 'San Jose RHU'])
        ->and(gridSourceNextDraft($this)['columns'][0])->not->toHaveKey('options');
});

it('refuses a grid list it cannot copy, naming the part and the reason', function (Closure $setUp, string $reason): void {
    gridSourceUpload($this, 'wards.csv', "name,label\nw1,Ward 1\n");
    $setUp($this);

    expect(gridSourcePublishError($this))->toContain($reason);
})->with([
    'a list the form does not hold' => [fn (object $t) => gridSourceField($t, gridSourceConfig(['rows_list' => 'zones'])), 'The rows of the grid “check” cannot be filled in (they come from “zones”, which this form does not hold'],
    'a list and a form at once' => [fn (object $t) => gridSourceField($t, gridSourceConfig(['rows_list' => 'wards', 'rows_source' => ['form_id' => $t->source->id, 'field_key' => 'facility_name']])), 'they come from a list file and from another form — choose one'],
    'a question nobody has answered' => [function (object $t): void {
        gridSourceField($t, gridSourceConfig(['rows_source' => ['form_id' => $t->source->id, 'field_key' => 'facility_name']]));
        Submission::query()->where('form_id', $t->source->id)->delete();
    }, 'the question they come from has no answers yet'],
    'a column\'s list the form does not hold' => [fn (object $t) => gridSourceField($t, ['rows' => [['value' => 'r', 'label' => 'R']], 'columns' => [['value' => 'g', 'label' => 'Grade', 'options_list' => 'grades']]]), 'The choices of column “Grade” of the grid “check” cannot be filled in'],
]);

it('refuses more rows than a grid takes from a list', function (): void {
    $csv = "name,label\n".implode("\n", array_map(static fn (int $i): string => "w{$i},Ward {$i}", range(1, 201)))."\n";
    gridSourceUpload($this, 'wards.csv', $csv);
    gridSourceField($this, gridSourceConfig(['rows_list' => 'wards']));

    expect(gridSourcePublishError($this))->toContain('“wards” holds 201 rows, and a grid takes at most 200 rows from one list');
});

it('takes a list of exactly two hundred rows', function (): void {
    $csv = "name,label\n".implode("\n", array_map(static fn (int $i): string => "w{$i},Ward {$i}", range(1, 200)))."\n";
    gridSourceUpload($this, 'wards.csv', $csv);
    gridSourceField($this, gridSourceConfig(['rows_list' => 'wards']));

    expect(gridSourcePublishError($this))->toBe('published')
        ->and(gridSourcePublished($this)['rows'])->toHaveCount(200);
});

it('says once, by the link gate, that a source no longer shares the question', function (): void {
    gridSourceField($this, gridSourceConfig(['rows_source' => ['form_id' => $this->source->id, 'field_key' => 'facility_name']]));
    app(FormService::class)->setDataSharing($this->source->refresh(), false, null, $this->admin);

    try {
        app(PublishService::class)->publish($this->form->refresh(), $this->admin);
        $violations = [];
    } catch (PublishValidationException $e) {
        $violations = $e->violations();
    }

    expect(array_column($violations, 'code'))->toBe(['grid_source_invalid'])
        ->and($violations[0]['message'])->toContain('The rows of the grid “check” cannot be filled in (the form the rows come from does not share that question');
});

it('refuses an unfinished link on a grid\'s cells, once', function (): void {
    gridSourceField($this, [
        'rows' => [['value' => 'r', 'label' => 'R']],
        'columns' => [['value' => 'c', 'label' => 'C']],
        'cells' => [],
        'cells_source' => ['form_id' => null, 'field_key' => null],
    ]);

    try {
        app(PublishService::class)->publish($this->form->refresh(), $this->admin);
        $violations = [];
    } catch (PublishValidationException $e) {
        $violations = $e->violations();
    }

    expect(array_column($violations, 'code'))->toBe(['grid_source_invalid'])
        ->and($violations[0]['message'])->toContain('choose the form and the question the cell choices come from');
});

it('counts a grid that copies from a form among the forms using it, in the source\'s Data sharing settings', function (string $where): void {
    $link = ['form_id' => $this->source->id, 'field_key' => 'facility_name'];
    $config = match ($where) {
        'rows' => gridSourceConfig(['rows_source' => $link]),
        'cells' => ['rows' => [['value' => 'r', 'label' => 'R']], 'columns' => [['value' => 'c', 'label' => 'C']], 'cells' => [], 'cells_source' => $link],
        'a column' => ['rows' => [['value' => 'r', 'label' => 'R']], 'columns' => [['value' => 'c', 'label' => 'C', 'options_source' => $link]]],
    };
    gridSourceField($this, $config);
    expect(gridSourcePublishError($this))->toBe('published');

    $sharing = app(FormSettingsPresenter::class)->dataSharing($this->source->refresh(), $this->admin);

    expect(array_column($sharing['used_by'], 'title'))->toBe(['Facility Check']);
})->with(['rows', 'cells', 'a column']);
