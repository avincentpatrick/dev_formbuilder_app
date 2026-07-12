<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Exceptions\Forms\PublishValidationException;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\Form;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\SubmissionGeoIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\SchemaValueFormatter;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment G5b1 — geopoint / geotrace / geoshape end-to-end through the
| Submission Pipeline: Stage-1 coercion, Stage-3 structural geometry checks,
| the PostGIS `submission_geo_index` projection (geometry(4326) + GiST), and
| the pre-publish gates. The PHP⇄TS validation parity itself is proven by the
| shared golden vectors (geo.json); this exercises the real PostGIS-backed
| pipeline the golden suite can't (geometry round-trip, RLS on the new table).
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->pipeline = app(SubmissionPipeline::class);
});

/** Build a form, populate its draft via $build, publish it, and return the published version. */
function g5Publish(Tenant $tenant, User $user, Closure $build): FormVersion
{
    $form = app(FormService::class)->create($tenant, $user, 'G5 Survey');
    $build($form->draftVersion, $user);

    return app(PublishService::class)->publish($form->refresh(), $user);
}

/**
 * The single geo-index row for a submission, joined to the geometry as WKT + SRID via raw SQL (the
 * geometry column is opaque through Eloquent). Runs as `meridian_app` under the current tenant GUC.
 *
 * @return object{field_key: string, geometry_type: string, captured_accuracy: ?string, wkt: string, srid: int}|null
 */
function geoRow(string $submissionId): ?object
{
    return DB::selectOne(
        'SELECT field_key, geometry_type, captured_accuracy, ST_AsText(geom) AS wkt, ST_SRID(geom) AS srid '
        .'FROM submission_geo_index WHERE submission_id = ?',
        [$submissionId],
    );
}

it('stores a geopoint envelope and projects a SRID-4326 point into submission_geo_index', function (): void {
    $version = g5Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'loc', FieldType::Geopoint, 0);
    });

    $envelope = ['type' => 'Point', 'coordinates' => [121.05, 14.6], 'accuracy' => 4.5];
    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['loc' => $envelope],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    // JSONB envelope is the source of truth.
    $answerDoc = SubmissionAnswer::query()->findOrFail($result->submission->id);
    expect($answerDoc->answers)->toEqual(['loc' => $envelope]);

    // The geometry projection round-trips lon-first with SRID 4326.
    $row = geoRow($result->submission->id);
    expect($row)->not->toBeNull()
        ->and($row->field_key)->toBe('loc')
        ->and($row->geometry_type)->toBe('Point')
        ->and((float) $row->captured_accuracy)->toBe(4.5)
        ->and($row->wkt)->toBe('POINT(121.05 14.6)')
        ->and((int) $row->srid)->toBe(4326);

    // Object-valued geo is NEVER routed through the scalar index.
    expect(SubmissionAnswerIndex::query()->where('submission_id', $result->submission->id)->count())->toBe(0);
});

it('projects a geotrace LineString and a geoshape Polygon with the right geometry types', function (): void {
    $version = g5Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'path', FieldType::Geotrace, 0);
        addFormField($draft, $user, 'area', FieldType::Geoshape, 1);
    });

    $result = $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: [
            'path' => ['type' => 'LineString', 'coordinates' => [[121.0, 14.6], [121.1, 14.7]]],
            'area' => ['type' => 'Polygon', 'coordinates' => [[[121.0, 14.6], [121.1, 14.6], [121.1, 14.7], [121.0, 14.6]]]],
        ],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    $rows = SubmissionGeoIndex::query()
        ->where('submission_id', $result->submission->id)
        ->get()
        ->keyBy('field_key');

    expect($rows)->toHaveCount(2)
        ->and($rows['path']->geometry_type)->toBe('LineString')
        ->and($rows['area']->geometry_type)->toBe('Polygon');

    $areaGeom = DB::selectOne('SELECT ST_GeometryType(geom) AS gt, ST_SRID(geom) AS srid FROM submission_geo_index WHERE field_key = ?', ['area']);
    expect($areaGeom->gt)->toBe('ST_Polygon')->and((int) $areaGeom->srid)->toBe(4326);
});

it('rejects an out-of-range geopoint at Stage 3 and writes nothing', function (): void {
    $version = g5Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'loc', FieldType::Geopoint, 0);
    });

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['loc' => ['type' => 'Point', 'coordinates' => [200.0, 14.6]]],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('semantic')
            ->and($e->fieldErrors()[0]['field'])->toBe('loc')
            ->and($e->fieldErrors()[0]['rule'])->toBe('geo_out_of_range');
    }

    expect(Submission::query()->count())->toBe(0)
        ->and(SubmissionGeoIndex::query()->count())->toBe(0);
});

it('rejects a malformed geo envelope at Stage 1 (structural)', function (): void {
    $version = g5Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'loc', FieldType::Geopoint, 0);
    });

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: ['loc' => ['type' => 'Point']], // no coordinates
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('structural')
            ->and($e->fieldErrors()[0]['field'])->toBe('loc')
            ->and($e->fieldErrors()[0]['rule'])->toBe('geo_malformed');
    }

    expect(Submission::query()->count())->toBe(0);
});

it('enforces required on an unanswered geopoint', function (): void {
    $version = g5Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'loc', FieldType::Geopoint, 0, ['is_required' => RequiredMode::Required]);
    });

    try {
        $this->pipeline->submit(new SubmissionPayload(
            version: $version,
            answers: [],
            source: SubmissionSource::Manual,
            respondentUserId: $this->user->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        expect($e->stage())->toBe('semantic')
            ->and($e->fieldErrors()[0]['field'])->toBe('loc')
            ->and($e->fieldErrors()[0]['rule'])->toBe('field_required');
    }
});

it('refuses to publish a geo field placed inside a repeatable section', function (): void {
    try {
        g5Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
            $section = FormSection::create([
                'form_version_id' => $draft->id,
                'key' => 'roster',
                'label' => 'Roster',
                'sequence' => 0,
                'is_repeatable' => true,
            ]);
            addFormField($draft, $user, 'loc', FieldType::Geopoint, 0, ['form_section_id' => $section->id]);
        });
        expect(false)->toBeTrue('expected a PublishValidationException');
    } catch (PublishValidationException $e) {
        expect($e->getMessage())->toContain('loc');
    }
});

it('refuses to publish an expression that references a geo field', function (): void {
    try {
        g5Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
            addFormField($draft, $user, 'loc', FieldType::Geopoint, 0);
            addFormField($draft, $user, 'note1', FieldType::ShortText, 1, [
                'relevant_expression' => "\${loc} = 'x'",
            ]);
        });
        expect(false)->toBeTrue('expected a PublishValidationException');
    } catch (PublishValidationException $e) {
        expect($e->getMessage())->toContain('loc');
    }
});

it('isolates submission_geo_index rows by tenant (RLS)', function (): void {
    $version = g5Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'loc', FieldType::Geopoint, 0);
    });

    $this->pipeline->submit(new SubmissionPayload(
        version: $version,
        answers: ['loc' => ['type' => 'Point', 'coordinates' => [121.05, 14.6]]],
        source: SubmissionSource::Manual,
        respondentUserId: $this->user->id,
    ));

    // A raw count bypasses the ORM tenant scope, so it proves DB-level FORCE RLS on the geometry table.
    $ownCount = static fn (): int => (int) DB::selectOne('SELECT count(*) AS c FROM submission_geo_index')->c;
    expect($ownCount())->toBe(1);

    $otherTenant = Tenant::create(['name' => 'Beta', 'slug' => 'beta', 'default_locale' => 'en']);
    $otherUser = User::factory()->create();
    enterTenant($otherTenant->id, $otherUser->id);
    expect($ownCount())->toBe(0); // the other tenant's session sees none of Alpha's geometry

    enterTenant($this->tenant->id, $this->user->id);
    expect($ownCount())->toBe(1);
});

it('keeps geo unsupported in the encode presenter (no renderer in G5b1)', function (): void {
    $version = g5Publish($this->tenant, $this->user, function (FormVersion $draft, User $user): void {
        addFormField($draft, $user, 'loc', FieldType::Geopoint, 0);
    });

    /** @var Form $form */
    $form = Form::query()->findOrFail($version->form_id);
    $presented = app(EncodeFormPresenter::class)->present($form, $version);
    $fields = collect($presented['blocks'])->flatMap(fn (array $b): array => $b['fields'])->keyBy('key');

    expect($fields['loc']['supported'])->toBeFalse();
});

it('summarises geo answers for the inbox / export', function (): void {
    $formatter = app(SchemaValueFormatter::class);

    expect($formatter->displayValue(FieldType::Geopoint, ['type' => 'Point', 'coordinates' => [121.05, 14.6], 'accuracy' => 4], []))
        ->toBe('14.6, 121.05 (±4 m)')
        ->and($formatter->displayValue(FieldType::Geotrace, ['type' => 'LineString', 'coordinates' => [[121.0, 14.6], [121.1, 14.7]]], []))
        ->toBe('Line — 2 points')
        ->and($formatter->displayValue(FieldType::Geoshape, ['type' => 'Polygon', 'coordinates' => [[[121.0, 14.6], [121.1, 14.6], [121.1, 14.7], [121.0, 14.6]]]], []))
        ->toBe('Area — 4 points');
});
