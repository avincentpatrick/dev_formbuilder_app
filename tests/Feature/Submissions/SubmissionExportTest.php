<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormCollaboratorCapacity;
use App\Enums\SubmissionStatus;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment F7 — streamed CSV/XLSX export keyed on the stable form_fields.key: column resolution +
| value formatting, cross-version union-by-key, XLSX round-trip, and export authorization.
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

/**
 * Parse a captured CSV string into rows of cells.
 *
 * @return list<list<string>>
 */
function csvRows(string $csv): array
{
    $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv); // strip openspout's Excel-friendly UTF-8 BOM
    $lines = array_values(array_filter(explode("\n", trim($csv)), fn (string $l): bool => $l !== ''));

    return array_map(fn (string $line): array => str_getcsv($line, escape: ''), $lines);
}

it('streams a CSV keyed on field labels with resolved values', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, [
        'full_name' => 'Ada Lovelace', 'color' => 'r', 'hobbies' => ['read', 'run'], 'subscribe' => true,
    ]);

    $csv = $this->actingAs($owner)
        ->get("http://acme.meridian.test/forms/{$form->id}/submissions/export?format=csv")
        ->assertOk()
        ->streamedContent();

    $rows = csvRows($csv);
    $header = $rows[0];
    $data = $rows[1];

    expect($header)->toContain('Submission ID', 'Status', 'Full name', 'Color', 'Hobbies', 'Subscribe');

    $col = fn (string $label): int => array_search($label, $header, true);
    expect($data[$col('Full name')])->toBe('Ada Lovelace')
        ->and($data[$col('Color')])->toBe('Red')
        ->and($data[$col('Hobbies')])->toBe('Reading; Running')
        ->and($data[$col('Subscribe')])->toBe('Yes')
        ->and($data[$col('Status')])->toBe('Submitted');
});

it('unions columns across versions so no field is dropped', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');

    $form = publishedInboxForm($tenant, $owner); // v1
    $v1 = FormVersion::findOrFail($form->current_published_version_id);

    // Publish a v2 that adds a `notes` field.
    addFormField($form->draftVersion, $owner, 'notes', FieldType::ShortText, 4);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh();

    // One submission against each version.
    $v1sub = Submission::factory()->forVersion($v1)->create([
        'status' => SubmissionStatus::Submitted, 'respondent_user_id' => $owner->id, 'submitted_at' => now(),
    ]);
    SubmissionAnswer::factory()->forSubmission($v1sub)->create(['answers' => ['full_name' => 'V1 Person', 'color' => 'b']]);
    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'V2 Person', 'notes' => 'A note']);

    $rows = csvRows($this->actingAs($owner)
        ->get("http://acme.meridian.test/forms/{$form->id}/submissions/export?format=csv")
        ->streamedContent());

    $header = $rows[0];
    expect($header)->toContain('Notes'); // the v2-only column exists

    $byName = collect($rows)->slice(1)->keyBy(fn (array $r): string => $r[array_search('Full name', $header, true)]);
    $notesCol = array_search('Notes', $header, true);
    expect($byName['V1 Person'][$notesCol])->toBe('')   // v1 submission has no `notes` → blank cell
        ->and($byName['V2 Person'][$notesCol])->toBe('A note');
});

it('scopes the export to the form\'s own submissions', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $formA = publishedInboxForm($tenant, $owner, 'Form A');
    $formB = publishedInboxForm($tenant, $owner, 'Form B');
    seedInboxSubmission($formA, $owner, SubmissionStatus::Submitted, ['full_name' => 'A1']);
    seedInboxSubmission($formA, $owner, SubmissionStatus::Submitted, ['full_name' => 'A2']);
    seedInboxSubmission($formB, $owner, SubmissionStatus::Submitted, ['full_name' => 'B1']);

    $rows = csvRows($this->actingAs($owner)
        ->get("http://acme.meridian.test/forms/{$formA->id}/submissions/export?format=csv")
        ->streamedContent());

    expect($rows)->toHaveCount(3); // header + 2 Form-A rows (Form B excluded)
});

it('applies the status filter to the export', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Pending']);
    seedInboxSubmission($form, $owner, SubmissionStatus::Approved, ['full_name' => 'Done']);

    $rows = csvRows($this->actingAs($owner)
        ->get("http://acme.meridian.test/forms/{$form->id}/submissions/export?format=csv&status=approved")
        ->streamedContent());

    expect($rows)->toHaveCount(2); // header + the one approved row
});

it('streams a valid XLSX that round-trips through the reader', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    makeActiveMember($owner, 'owner');
    $form = publishedInboxForm($tenant, $owner);
    seedInboxSubmission($form, $owner, SubmissionStatus::Submitted, ['full_name' => 'Ada Lovelace', 'color' => 'g']);

    $binary = $this->actingAs($owner)
        ->get("http://acme.meridian.test/forms/{$form->id}/submissions/export?format=xlsx")
        ->assertOk()
        ->streamedContent();

    $path = tempnam(sys_get_temp_dir(), 'mds').'.xlsx';
    file_put_contents($path, $binary);

    $reader = new XlsxReader;
    $reader->open($path);
    $rows = [];
    foreach ($reader->getSheetIterator() as $sheet) {
        foreach ($sheet->getRowIterator() as $row) {
            $rows[] = $row->toArray();
        }
        break;
    }
    $reader->close();
    @unlink($path);

    expect($rows[0])->toContain('Full name', 'Color')
        ->and($rows[1])->toContain('Ada Lovelace', 'Green');
});

it('forbids a Viewer from exporting and confines an Editor to their own forms', function (): void {
    $tenant = inboxTenant();
    $owner = User::factory()->create();
    enterTenant($tenant->id, $owner->id);
    $mine = publishedInboxForm($tenant, $owner, 'Mine');
    $other = publishedInboxForm($tenant, $owner, 'Other');

    // Viewer holds submissions.view but NOT submissions.export → 403.
    $viewer = User::factory()->create();
    enterTenant($tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');
    $this->actingAs($viewer)
        ->get("http://acme.meridian.test/forms/{$mine->id}/submissions/export?format=csv")
        ->assertForbidden();

    // Editor can export a form they collaborate on, but not one they don't.
    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    makeCollaborator($mine, $editor, FormCollaboratorCapacity::Editor);

    $this->actingAs($editor)
        ->get("http://acme.meridian.test/forms/{$mine->id}/submissions/export?format=csv")
        ->assertOk();
    $this->actingAs($editor)
        ->get("http://acme.meridian.test/forms/{$other->id}/submissions/export?format=csv")
        ->assertForbidden();
});
