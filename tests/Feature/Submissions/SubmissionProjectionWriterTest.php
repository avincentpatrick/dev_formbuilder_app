<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\IndexedDataType;
use App\Enums\SubmissionSource;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswerIndex;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\SubmissionAnswerEditService;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I9c — SubmissionProjectionWriter: the derived query projections.
|--------------------------------------------------------------------------
| ⚠️ THIS FILE EXISTS BECAUSE THE CLASS DOCBLOCK CITED IT AND IT DID NOT. An adversarial review found
| `SubmissionProjectionWriter`'s "SubmissionProjectionWriterTest asserts counts for that reason" pointing at
| nothing — the fourth time this project has shipped a docblock naming a guard that was never written, and
| the citation is the load-bearing safety argument of a brand-new class. Writing the file was the fix.
|
| It covers the half `SubmissionAnswerEditTest` does not: the PostGIS `submission_geo_index`, which goes
| through RAW SQL on both the insert and the delete, and whose tenant scoping is delegated entirely to RLS.
| A duplicate geometry row silently double-counts a submission in every map layer and spatial filter, and —
| the whole point — every VALUE assertion still passes while it does. The assertions here are COUNTS.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->seed(RolePermissionSeeder::class);
    $this->tenant = inboxTenant('acme');
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->service = app(SubmissionAnswerEditService::class);
});

/** A published form with one indexed scalar and one geopoint — the two projection tables, one form. */
function projectionForm(User $owner, string $title): FormVersion
{
    $form = app(FormService::class)->create(test()->tenant, $owner, $title);
    $version = $form->draftVersion;

    addFormField($version, $owner, 'site_name', FieldType::ShortText, 0, [
        'is_queryable' => true,
        'indexed_data_type' => IndexedDataType::Text,
    ]);
    addFormField($version, $owner, 'location', FieldType::Geopoint, 1);

    app(PublishService::class)->publish($form->refresh(), $owner);

    return FormVersion::findOrFail($form->refresh()->current_published_version_id);
}

function submitWithGeo(FormVersion $version, array $answers): Submission
{
    return app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $version,
        answers: $answers,
        source: SubmissionSource::Manual,
        respondentUserId: null,
    ))->submission->refresh();
}

/** The raw geo rows for one submission — raw SQL, because the table has no Eloquent model. */
function geoRows(string $submissionId): array
{
    return DB::select(
        'SELECT field_key, geometry_type, ST_AsGeoJSON(geom) AS geojson FROM submission_geo_index WHERE submission_id = ?',
        [$submissionId],
    );
}

it('replaces the geo projection on an edit rather than appending to it', function (): void {
    $version = projectionForm($this->owner, 'Sites A');
    $submission = submitWithGeo($version, [
        'site_name' => 'North well',
        'location' => ['type' => 'Point', 'coordinates' => [120.9, 14.6]],
    ]);

    expect(geoRows($submission->id))->toHaveCount(1);

    $this->service->edit($submission, $version, [
        'site_name' => 'North well',
        'location' => ['type' => 'Point', 'coordinates' => [121.5, 15.2]],
    ], $this->owner);

    $rows = geoRows($submission->id);

    // ⚠️ THE COUNT IS THE ASSERTION. An implementation that inserted without purging leaves BOTH geometries
    // and passes any "the new coordinates are present" check — while the submission appears twice on every
    // map layer, at two different places, with no error anywhere.
    expect($rows)->toHaveCount(1);

    $geojson = json_decode((string) $rows[0]->geojson, true);
    expect($geojson['coordinates'][0])->toEqualWithDelta(121.5, 0.0001)
        ->and($geojson['coordinates'][1])->toEqualWithDelta(15.2, 0.0001);
});

it('drops the geo row when an edit clears the geo answer', function (): void {
    // `projectGeo` skips a null answer entirely, so ONLY the purge can remove a stale geometry. Without it a
    // cleared location keeps its old point forever — the one case where the map and the record disagree and
    // the record is the one that looks empty.
    $version = projectionForm($this->owner, 'Sites B');
    $submission = submitWithGeo($version, [
        'site_name' => 'South well',
        'location' => ['type' => 'Point', 'coordinates' => [120.9, 14.6]],
    ]);

    expect(geoRows($submission->id))->toHaveCount(1);

    $this->service->edit($submission, $version, ['site_name' => 'South well', 'location' => null], $this->owner);

    expect(geoRows($submission->id))->toHaveCount(0);
});

it('adds a geo row when an edit fills a location that was blank', function (): void {
    // The anti-vacuity half of the two cases above: a `rewrite()` that only ever PURGED would satisfy both.
    $version = projectionForm($this->owner, 'Sites C');
    $submission = submitWithGeo($version, ['site_name' => 'East well']);

    expect(geoRows($submission->id))->toHaveCount(0);

    $this->service->edit($submission, $version, [
        'site_name' => 'East well',
        'location' => ['type' => 'Point', 'coordinates' => [120.1, 16.0]],
    ], $this->owner);

    expect(geoRows($submission->id))->toHaveCount(1);
});

it('rewrites both tables together, so the scalar and geo projections cannot drift', function (): void {
    $version = projectionForm($this->owner, 'Sites D');
    $submission = submitWithGeo($version, [
        'site_name' => 'West well',
        'location' => ['type' => 'Point', 'coordinates' => [120.9, 14.6]],
    ]);

    $this->service->edit($submission, $version, [
        'site_name' => 'West well renamed',
        'location' => ['type' => 'Point', 'coordinates' => [119.0, 13.0]],
    ], $this->owner);

    expect(SubmissionAnswerIndex::where('submission_id', $submission->id)->count())->toBe(1)
        ->and(SubmissionAnswerIndex::where('submission_id', $submission->id)->value('value_text'))
        ->toBe('West well renamed')
        ->and(geoRows($submission->id))->toHaveCount(1);
});

it('leaves another submission\'s projections completely alone', function (): void {
    // `purge()` keys on `submission_id` and relies on RLS for the tenant bound. This is the same-tenant half:
    // a WHERE clause that lost its `submission_id` predicate would wipe the whole tenant's index and every
    // single-submission assertion above would still pass.
    $version = projectionForm($this->owner, 'Sites E');
    $keep = submitWithGeo($version, [
        'site_name' => 'Untouched',
        'location' => ['type' => 'Point', 'coordinates' => [100.0, 10.0]],
    ]);
    $edit = submitWithGeo($version, [
        'site_name' => 'Edited',
        'location' => ['type' => 'Point', 'coordinates' => [101.0, 11.0]],
    ]);

    $this->service->edit($edit, $version, [
        'site_name' => 'Edited again',
        'location' => ['type' => 'Point', 'coordinates' => [102.0, 12.0]],
    ], $this->owner);

    expect(SubmissionAnswerIndex::where('submission_id', $keep->id)->value('value_text'))->toBe('Untouched')
        ->and(geoRows($keep->id))->toHaveCount(1);
});

it('does not purge on the FINALIZE path — write(), not rewrite()', function (): void {
    // `SubmissionFinalizer` must call `write()`. If it were changed to `rewrite()` the behaviour would look
    // identical here (a fresh row has nothing to purge), so this pins the OTHER direction: two submissions
    // finalized in sequence must each keep their own projections, which a purge keyed on the wrong id
    // would break.
    $version = projectionForm($this->owner, 'Sites F');
    $first = submitWithGeo($version, [
        'site_name' => 'First',
        'location' => ['type' => 'Point', 'coordinates' => [100.0, 10.0]],
    ]);
    $second = submitWithGeo($version, [
        'site_name' => 'Second',
        'location' => ['type' => 'Point', 'coordinates' => [101.0, 11.0]],
    ]);

    expect(geoRows($first->id))->toHaveCount(1)
        ->and(geoRows($second->id))->toHaveCount(1)
        ->and(SubmissionAnswerIndex::where('submission_id', $first->id)->value('value_text'))->toBe('First')
        ->and(SubmissionAnswerIndex::where('submission_id', $second->id)->value('value_text'))->toBe('Second');
});
