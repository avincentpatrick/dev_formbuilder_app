<?php

declare(strict_types=1);

use App\Enums\ConnectorProviderKey;
use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Connectors\MappableColumnCatalog;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionRowProjector;
use App\Support\Connectors\SubscriptionConfigRules;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M183 (`R-51e044a6`, `D130` A) — a grid answer reaches a spreadsheet one column per cell, BESIDE the one-cell summary
| (kept, so no Sheets or Airtable mapping changes identity). The cell columns are made where every column is made —
| `SubmissionRowProjector::resolveColumns()` / `answerValues()` — so the export, the Sheets row, the Airtable record,
| the column catalog and the automation webhook all carry them through the one seam. A cell reads as a question of
| its column's type reads: a choice by its label, a multiple choice's labels joined with `; `, a Likert point by its
| words.
|
| ⚠️ Helpers are prefixed `gridCells*`: Pest loads every test file into one process.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Two clinics × a whole number, a multiple choice with its own list and an untyped choice; and a Likert matrix. */
function gridCellsConfig(): array
{
    return [
        'rows' => [['value' => 'a', 'label' => 'Clinic A'], ['value' => 'b', 'label' => 'Clinic B']],
        'columns' => [
            ['value' => 'staff', 'label' => 'Staff', 'type' => 'integer'],
            ['value' => 'svc', 'label' => 'Services', 'type' => 'multi_select', 'options' => [['value' => 'lab', 'label' => 'Lab'], ['value' => 'xray', 'label' => 'X-ray']]],
            ['value' => 'rate', 'label' => 'Rating'],
        ],
        'cells' => [['value' => 'poor', 'label' => 'Poor'], ['value' => 'good', 'label' => 'Good']],
    ];
}

/** A published form holding the grid `g` (with $config) and the Likert matrix `sat`. */
function gridCellsForm(Tenant $tenant, User $owner, ?array $config = null): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Clinic roster');
    addFormField($form->draftVersion, $owner, 'g', FieldType::Matrix, 0, ['label' => 'Clinics', 'config' => $config ?? gridCellsConfig()]);
    addFormField($form->draftVersion, $owner, 'sat', FieldType::LikertMatrix, 1, ['label' => 'Satisfaction', 'config' => [
        'rows' => [['value' => 'clean', 'label' => 'Cleanliness']],
        'columns' => [['value' => '1', 'label' => 'Poor'], ['value' => '2', 'label' => 'Good']],
    ]]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

/** Publish the form's draft again after $change edits it, and return the refreshed form. */
function gridCellsRepublish(Form $form, User $owner, Closure $change): Form
{
    $change(FormVersion::query()->findOrFail($form->draft_version_id));
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

/**
 * The export's header row, and its data rows keyed by header.
 *
 * @return array{0: list<string>, 1: list<array<string, string>>}
 */
function gridCellsExport(User $owner, Form $form): array
{
    $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', test()->actingAs($owner)
        ->get("http://acme.meridian.test/forms/{$form->id}/submissions/export?format=csv")
        ->assertOk()
        ->streamedContent());
    $lines = array_values(array_filter(explode("\n", trim($csv)), fn (string $l): bool => $l !== ''));
    $rows = array_map(fn (string $line): array => str_getcsv($line, escape: ''), $lines);
    $header = array_shift($rows);

    return [$header, array_map(fn (array $row): array => array_combine($header, $row), $rows)];
}

it('writes a column for every grid cell right after the summary, each cell as its question would read', function (): void {
    $form = gridCellsForm($this->tenant, $this->owner);
    seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, [
        'g' => ['a' => ['staff' => 3, 'svc' => ['lab', 'xray'], 'rate' => 'good']],
        'sat' => ['clean' => '2'],
    ]);

    [$header, $rows] = gridCellsExport($this->owner, $form);

    $grid = array_values(array_filter($header, fn (string $h): bool => str_starts_with($h, 'Clinics') || str_starts_with($h, 'Satisfaction')));
    expect($grid)->toBe([
        'Clinics',
        'Clinics — Clinic A — Staff', 'Clinics — Clinic A — Services', 'Clinics — Clinic A — Rating',
        'Clinics — Clinic B — Staff', 'Clinics — Clinic B — Services', 'Clinics — Clinic B — Rating',
        'Satisfaction',
        'Satisfaction — Cleanliness',
    ]);
    expect($rows[0])->toMatchArray([
        'Clinics' => 'a: rate=good, staff=3, svc=lab/xray',
        'Clinics — Clinic A — Staff' => '3',
        'Clinics — Clinic A — Services' => 'Lab; X-ray',
        'Clinics — Clinic A — Rating' => 'Good',
        'Clinics — Clinic B — Staff' => '',
        'Satisfaction' => 'clean=2',
        'Satisfaction — Cleanliness' => 'Good',
    ]);
});

it('keeps the column of a cell a later version dropped, for the responses that answered it', function (): void {
    $form = gridCellsForm($this->tenant, $this->owner);
    seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['g' => ['b' => ['staff' => 7]]]);
    $form = gridCellsRepublish($form, $this->owner, function (FormVersion $draft): void {
        $grid = FormField::query()->where('form_version_id', $draft->id)->where('key', 'g')->sole();
        $grid->update(['config' => [...$grid->config, 'rows' => [['value' => 'a', 'label' => 'Clinic A']]]]);
    });
    seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['g' => ['a' => ['staff' => 2]]]);

    [$header, $rows] = gridCellsExport($this->owner, $form);

    expect($header)->toContain('Clinics — Clinic B — Staff');
    expect(collect($rows)->pluck('Clinics — Clinic B — Staff')->sort()->values()->all())->toBe(['', '7']);
});

it('heads a retired grid\'s cell columns as removed, as it heads the grid\'s own', function (): void {
    $form = gridCellsForm($this->tenant, $this->owner);
    seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['g' => ['a' => ['staff' => 3]]]);
    $form = gridCellsRepublish($form, $this->owner, function (FormVersion $draft): void {
        FormField::query()->where('form_version_id', $draft->id)->where('key', 'g')->delete();
    });

    [$header] = gridCellsExport($this->owner, $form);

    expect($header)->toContain('Clinics (removed from the form in version 2)')
        ->and($header)->toContain('Clinics — Clinic A — Staff (removed from the form in version 2)');
});

it('offers each cell to Google Sheets and Airtable under its own key, and hands it to every channel', function (): void {
    $form = gridCellsForm($this->tenant, $this->owner);
    $submission = seedInboxSubmission($form, $this->owner, SubmissionStatus::Submitted, ['g' => ['a' => ['svc' => ['xray']]]]);

    $keys = array_column(app(MappableColumnCatalog::class)->for($form)['columns'], 'key');
    expect($keys)->toContain('g')->toContain('g.a.svc')->toContain('g.b.rate')->toContain('sat.clean');

    // The seam the export, the Sheets row, the Airtable record and the automation webhook all read.
    $projector = app(SubmissionRowProjector::class);
    [, $fieldMeta] = $projector->resolveColumns(collect([FormVersion::query()->findOrFail($form->current_published_version_id)]), 'en');
    $values = $projector->answerValues(Submission::query()->with('answers')->findOrFail($submission->id), $fieldMeta, 'en');
    expect($values)->toMatchArray(['g' => 'a: svc=xray', 'g.a.svc' => 'X-ray', 'g.a.staff' => '']);
});

it('keeps the first of two cells whose keys would be the same', function (): void {
    $config = [
        'rows' => [['value' => 'a.b', 'label' => 'AB'], ['value' => 'a', 'label' => 'A']],
        'columns' => [['value' => 'c', 'label' => 'C'], ['value' => 'b.c', 'label' => 'BC']],
        'cells' => [['value' => 'x', 'label' => 'X']],
    ];
    $form = gridCellsForm($this->tenant, $this->owner, $config);

    $keys = array_column(app(MappableColumnCatalog::class)->for($form)['columns'], 'label', 'key');

    // (a.b, c) and (a, b.c) both make `g.a.b.c`: the first in the author's order keeps it.
    expect(array_keys(array_filter($keys, fn (string $label, string $key): bool => str_starts_with($key, 'g.'), ARRAY_FILTER_USE_BOTH)))
        ->toBe(['g.a.b.c', 'g.a.b.b.c', 'g.a.c'])
        ->and($keys['g.a.b.c'])->toBe('Clinics — AB — C');
});

it('lets a Sheets or Airtable mapping bind the longest cell key a grid can make', function (): void {
    $rule = ['config.mapping.columns.*.field_key' => SubscriptionConfigRules::rulesFor(ConnectorProviderKey::GoogleSheets)['config.mapping.columns.*.field_key']];
    $bound = fn (string $key): bool => Validator::make(['config' => ['mapping' => ['columns' => [['header' => 'A', 'field_key' => $key]]]]], $rule)->passes();

    // A key of 150, a row value of 255 and a column value of 255, joined by two points.
    $longest = str_repeat('k', 150).'.'.str_repeat('r', 255).'.'.str_repeat('c', 255);

    expect($bound($longest))->toBeTrue()
        ->and($bound($longest.'x'))->toBeFalse();
});
