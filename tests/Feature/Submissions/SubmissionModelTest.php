<?php

declare(strict_types=1);

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\SubmissionAnswerIndex;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
});

/**
 * Establish a tenant + user context so BelongsToTenant auto-fills tenant_id on the factory graph.
 *
 * @return array{0: Tenant, 1: User}
 */
function bootSubmissionTenant(): array
{
    $tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha']);
    $user = User::factory()->create();
    TenantContext::applyLocal($tenant->id, $user->id);

    return [$tenant, $user];
}

it('round-trips the status and source enum casts', function (): void {
    bootSubmissionTenant();
    $version = FormVersion::factory()->create();

    $submission = Submission::factory()->forVersion($version)->create([
        'status' => SubmissionStatus::UnderReview,
        'source' => SubmissionSource::Guest,
    ]);

    $fresh = $submission->fresh();
    expect($fresh->status)->toBe(SubmissionStatus::UnderReview);
    expect($fresh->source)->toBe(SubmissionSource::Guest);
});

it('casts the answers document to an array, including repeat-group arrays', function (): void {
    bootSubmissionTenant();
    $version = FormVersion::factory()->create();
    $submission = Submission::factory()->forVersion($version)->create();

    $answer = SubmissionAnswer::factory()->forSubmission($submission)->create([
        'answers' => ['age' => 30, 'household_members' => [['name' => 'A'], ['name' => 'B']]],
        'attachment_refs' => [],
    ]);

    $fresh = $answer->fresh();
    expect($fresh->answers)->toBeArray()->toHaveKey('age');
    expect($fresh->answers['household_members'])->toHaveCount(2);
});

it('exposes the 1:1 answers relation and the hasMany index projection', function (): void {
    bootSubmissionTenant();
    $version = FormVersion::factory()->create();
    $submission = Submission::factory()->forVersion($version)->create();

    SubmissionAnswer::factory()->forSubmission($submission)->create();
    SubmissionAnswerIndex::factory()->count(2)->create([
        'submission_id' => $submission->id,
        'form_version_id' => $version->id,
    ]);

    expect($submission->answers)->toBeInstanceOf(SubmissionAnswer::class);
    expect($submission->answerIndex)->toHaveCount(2);
});

it('leaves respondent null for a guest submission and resolves it for a user', function (): void {
    [, $user] = bootSubmissionTenant();
    $version = FormVersion::factory()->create();

    $guest = Submission::factory()->forVersion($version)->guest()->create();
    expect($guest->respondent_user_id)->toBeNull();
    expect($guest->respondent)->toBeNull();

    $manual = Submission::factory()->forVersion($version)->create(['respondent_user_id' => $user->id]);
    expect($manual->respondent->is($user))->toBeTrue();
});

it('projects a typed numeric value and pins the index table name', function (): void {
    bootSubmissionTenant();

    $index = SubmissionAnswerIndex::factory()->create([
        'value_number' => 42.5,
        'value_text' => null,
    ]);

    expect($index->getTable())->toBe('submission_answer_index');
    expect($index->fresh()->value_number)->toBe(42.5);
});

it('creates a full submission via factories with tenant_id auto-filled', function (): void {
    [$tenant] = bootSubmissionTenant();

    $submission = Submission::factory()->create();

    expect($submission->exists)->toBeTrue();
    expect($submission->tenant_id)->toBe($tenant->id);
});
