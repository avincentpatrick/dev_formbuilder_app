<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormStatus;
use App\Enums\FormVersionStatus;
use App\Enums\RequiredMode;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| submissions persistence RLS fuzz pack (Increment F1, DoD).
|--------------------------------------------------------------------------
| Runs as the NON-superuser meridian_app role, which OWNS these tables — so every isolation assertion
| is ALSO the proof that FORCE ROW LEVEL SECURITY is active. All three tables use the plain `strict`
| shape (submissions are ordinary mutable tenant records). Assertions use raw DB:: queries so what is
| proven is the DATABASE's enforcement, not the ORM scope.
*/

beforeEach(function (): void {
    TenantContext::flush();
});

/**
 * Seed one tenant with a published form (v1, one field) and a finalized manual submission carrying its
 * answer document and one typed index projection.
 *
 * @return array{tenant: Tenant, user: User, form: Form, version: FormVersion, field: FormField, submission: Submission, answer: SubmissionAnswer, index: SubmissionAnswerIndex}
 */
function seedSubmission(): array
{
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user = User::factory()->create();

    TenantContext::applyLocal($tenant->id, $user->id);

    $form = Form::create([
        'title' => 'Household Survey',
        'default_locale' => 'en',
        'owner_user_id' => $user->id,
        'created_by' => $user->id,
    ]);
    $version = FormVersion::create([
        'form_id' => $form->id,
        'version_number' => 1,
        'status' => FormVersionStatus::Draft,
        'title' => 'Household Survey',
        'schema_snapshot' => [],
    ]);
    $field = FormField::create([
        'form_version_id' => $version->id,
        'key' => 'age',
        'field_type' => FieldType::Integer,
        'label' => 'Age',
        'is_required' => RequiredMode::Required,
        'created_by' => $user->id,
    ]);
    $version->update(['status' => FormVersionStatus::Published, 'published_at' => now()]);
    $form->update(['current_published_version_id' => $version->id, 'status' => FormStatus::Published]);

    $submission = Submission::create([
        'form_id' => $form->id,
        'form_version_id' => $version->id,
        'status' => SubmissionStatus::Submitted,
        'source' => SubmissionSource::Manual,
        'submitted_at' => now(),
    ]);
    $answer = SubmissionAnswer::create([
        'submission_id' => $submission->id,
        'form_version_id' => $version->id,
        'answers' => ['age' => 30],
        'answers_schema_checksum' => str_repeat('a', 64),
    ]);
    $index = SubmissionAnswerIndex::create([
        'submission_id' => $submission->id,
        'form_version_id' => $version->id,
        'form_field_id' => $field->id,
        'field_key' => $field->key,
        'value_number' => 30,
    ]);

    return compact('tenant', 'user', 'form', 'version', 'field', 'submission', 'answer', 'index');
}

/**
 * A raw submissions insert row (bypasses Eloquent) for probing the write policies directly.
 *
 * @return array<string, mixed>
 */
function submissionRow(string $tenantId, string $formId, string $versionId, ?string $clientUuid = null): array
{
    return [
        'id' => Uuid::uuid7()->toString(),
        'tenant_id' => $tenantId,
        'form_id' => $formId,
        'form_version_id' => $versionId,
        'status' => 'submitted',
        'source' => 'guest',
        'client_submission_uuid' => $clientUuid,
    ];
}

it('runs as a non-superuser role with RLS enabled AND forced on every submissions table', function (): void {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    $role = DB::selectOne(
        'select rolsuper::int as is_super, rolbypassrls::int as bypass_rls '
        .'from pg_roles where rolname = current_user'
    );
    expect((int) $role->is_super)->toBe(0);
    expect((int) $role->bypass_rls)->toBe(0);

    foreach (['submissions', 'submission_answers', 'submission_answer_index'] as $table) {
        $meta = DB::selectOne(
            'select relrowsecurity::int as enabled, relforcerowsecurity::int as forced from pg_class where relname = ?',
            [$table]
        );
        expect((int) $meta->enabled)->toBe(1)->and((int) $meta->forced)->toBe(1);
    }
});

it('carries exactly one policy per command on each submissions table', function (): void {
    foreach (['submissions', 'submission_answers', 'submission_answer_index'] as $table) {
        foreach (['SELECT', 'INSERT', 'UPDATE', 'DELETE'] as $cmd) {
            $count = DB::table('pg_policies')
                ->where('tablename', $table)
                ->where('cmd', $cmd)
                ->count();
            expect($count)->toBe(1, "{$table} must have exactly one {$cmd} policy, found {$count}");
        }
    }
});

it('fails closed on the submissions tables with no tenant context', function (): void {
    seedSubmission();

    TenantContext::applyLocal(null);
    expect(DB::table('submissions')->count())->toBe(0);
    expect(DB::table('submission_answers')->count())->toBe(0);
    expect(DB::table('submission_answer_index')->count())->toBe(0);
});

it('isolates the submissions tables across tenants', function (): void {
    $s = seedSubmission();

    $other = Tenant::create(['name' => 'Bravo', 'slug' => 'bravo']);
    TenantContext::applyLocal($other->id, $s['user']->id);

    expect(DB::table('submissions')->count())->toBe(0);
    expect(DB::table('submission_answers')->count())->toBe(0);
    expect(DB::table('submission_answer_index')->count())->toBe(0);

    // … and cannot smuggle a write into the other tenant. (This throw aborts the test's transaction, so
    // it must be the final DB interaction in the test.)
    expect(fn () => DB::table('submissions')->insert(
        submissionRow($s['tenant']->id, $s['form']->id, $s['version']->id)
    ))->toThrow(QueryException::class);
});

it('cascades answers and the index projection when a submission is hard-deleted', function (): void {
    $s = seedSubmission();
    TenantContext::applyLocal($s['tenant']->id, $s['user']->id);

    // A raw delete bypasses SoftDeletes, firing the DB-level ON DELETE CASCADE the 1:1/child FKs declare.
    expect(DB::table('submissions')->where('id', $s['submission']->id)->delete())->toBe(1);
    expect(DB::table('submission_answers')->where('submission_id', $s['submission']->id)->exists())->toBeFalse();
    expect(DB::table('submission_answer_index')->where('submission_id', $s['submission']->id)->exists())->toBeFalse();
});

it('enforces client_submission_uuid idempotency per tenant but permits multiple NULLs', function (): void {
    $s = seedSubmission();
    TenantContext::applyLocal($s['tenant']->id, $s['user']->id);

    // Multiple NULL-uuid rows coexist (the partial index only covers non-NULL values).
    DB::table('submissions')->insert(submissionRow($s['tenant']->id, $s['form']->id, $s['version']->id));
    DB::table('submissions')->insert(submissionRow($s['tenant']->id, $s['form']->id, $s['version']->id));

    // Two rows with the SAME client uuid collide (throw last — aborts the test's transaction).
    $uuid = Uuid::uuid7()->toString();
    DB::table('submissions')->insert(submissionRow($s['tenant']->id, $s['form']->id, $s['version']->id, $uuid));
    expect(fn () => DB::table('submissions')->insert(
        submissionRow($s['tenant']->id, $s['form']->id, $s['version']->id, $uuid)
    ))->toThrow(QueryException::class);
});

it('enforces one index projection per (submission, field)', function (): void {
    $s = seedSubmission();
    TenantContext::applyLocal($s['tenant']->id, $s['user']->id);

    // Duplicating the seeded projection for the same (submission_id, form_field_id) violates the unique.
    expect(fn () => DB::table('submission_answer_index')->insert([
        'tenant_id' => $s['tenant']->id,
        'submission_id' => $s['submission']->id,
        'form_version_id' => $s['version']->id,
        'form_field_id' => $s['field']->id,
        'field_key' => $s['field']->key,
        'value_number' => 99,
    ]))->toThrow(QueryException::class);
});
