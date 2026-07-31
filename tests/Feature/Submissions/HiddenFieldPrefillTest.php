<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\SubmissionSource;
use App\Exceptions\Submissions\SubmissionValidationException;
use App\Models\Form;
use App\Models\SubmissionAnswer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\SubmissionInboxPresenter;
use App\Services\Submissions\SubmissionPayload;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

// Hidden / prefilled fields end to end (Increment H7).
//
// The unit-level rules live in StructuralAnswerNormalizerTest (source authority + the byte cap) and
// StructuralValidationGateTest (the publish refusals). This file pins the parts that only exist once a real
// published version, a real submission and the read surfaces are involved: that a `fixed` value reaches
// storage without any client sending it, that a `url` value survives the whole pipeline, and that the two
// tenant-facing read models show hidden answers at all.
//
// EVERY field gets a DISTINCT `sequence` — `addFormField()` defaults it to 0 and Doc #26 §3.3 rule 1 as
// amended treats a positional tie as a rejection, so a shared 0 fails these for the wrong reason.
// EVERY `${` literal is SINGLE-quoted (PHP 8.3 removed `${var}` interpolation).

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/**
 * The persisted 1:1 answer document for a submission — read by id, the SubmissionPipelineTest convention.
 *
 * @return array<string, mixed>
 */
function storedAnswers(string $submissionId): array
{
    return SubmissionAnswer::query()->findOrFail($submissionId)->answers;
}

/**
 * A published form with one fixed-source hidden field, one link-sourced one, and a visible question whose
 * label pipes the link-sourced value — the H7 + H6b headline combination.
 */
function prefillForm(Tenant $tenant, User $owner): Form
{
    $form = app(FormService::class)->create($tenant, $owner, 'Signup');
    $version = $form->draftVersion;

    addFormField($version, $owner, 'campaign', FieldType::Hidden, 1, [
        'config' => ['prefill_source' => 'fixed'],
        'default_value' => 'newsletter',
    ]);
    addFormField($version, $owner, 'promo', FieldType::Hidden, 2, [
        'config' => ['prefill_source' => 'url', 'url_param' => 'promo-code'],
    ]);
    addFormField($version, $owner, 'full_name', FieldType::ShortText, 3, [
        'label' => 'Your name (offer '.'${promo}'.')',
    ]);

    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

it('records a fixed hidden value no channel ever sent', function (): void {
    $form = prefillForm($this->tenant, $this->owner);
    $version = $form->currentPublishedVersion;

    $result = app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $version,
        answers: ['full_name' => 'Maria'], // no `campaign` anywhere in the payload
        source: SubmissionSource::Manual,
        respondentUserId: $this->owner->id,
    ));

    expect(storedAnswers($result->submission->id))
        ->toHaveKey('campaign')
        ->and(storedAnswers($result->submission->id)['campaign'])->toBe('newsletter');
});

it('ignores a client attempt to steer a fixed hidden value', function (): void {
    // The property H20 was told to rely on: an authored literal cannot be moved by anything the caller
    // sends, on any channel — including this authenticated one.
    $form = prefillForm($this->tenant, $this->owner);

    $result = app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $form->currentPublishedVersion,
        answers: ['full_name' => 'Maria', 'campaign' => 'attacker-supplied'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->owner->id,
    ));

    expect(storedAnswers($result->submission->id)['campaign'])->toBe('newsletter');
});

it('stores a link-sourced hidden value the client supplied', function (): void {
    $form = prefillForm($this->tenant, $this->owner);

    $result = app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $form->currentPublishedVersion,
        answers: ['full_name' => 'Maria', 'promo' => 'SPRING'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->owner->id,
    ));

    expect(storedAnswers($result->submission->id)['promo'])->toBe('SPRING');
});

it('shows both hidden fields on the inbox detail, and pipes the link-sourced one into a label', function (): void {
    $form = prefillForm($this->tenant, $this->owner);

    $result = app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $form->currentPublishedVersion,
        answers: ['full_name' => 'Maria', 'promo' => 'SPRING'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->owner->id,
    ));

    $blocks = app(SubmissionInboxPresenter::class)->detail($this->owner, $result->submission)['blocks'];
    $rows = collect($blocks)->flatMap(fn (array $b): array => $b['fields'])->keyBy('key');

    // `SchemaValueFormatter::isDataField()` excludes only note/page_break, so a hidden answer is inbox data
    // like any other — pinned here because it is load-bearing for H7 and nothing asserted it before.
    expect($rows)->toHaveKeys(['campaign', 'promo', 'full_name'])
        ->and($rows['campaign']['value'])->toBe('newsletter')
        ->and($rows['promo']['value'])->toBe('SPRING')
        ->and($rows['full_name']['label'])->toBe('Your name (offer SPRING)');
});

it('presents a hidden field on the encode page, keyable only when link-sourced', function (): void {
    $form = prefillForm($this->tenant, $this->owner);

    $blocks = app(EncodeFormPresenter::class)->present($form, $form->currentPublishedVersion)['blocks'];
    $fields = collect($blocks)->flatMap(fn (array $b): array => $b['fields'])->keyBy('key');

    expect($fields)->toHaveKeys(['campaign', 'promo', 'full_name'])
        // Fixed: shown with its server-set value, never keyable.
        ->and($fields['campaign']['prefill'])->toBe('fixed')
        ->and($fields['campaign']['prefill_value'])->toBe('newsletter')
        ->and($fields['campaign']['supported'])->toBeFalse()
        // Link-sourced: this channel has no URL, so the keyer is the only possible source.
        ->and($fields['promo']['prefill'])->toBe('url')
        ->and($fields['promo']['prefill_value'])->toBeNull()
        ->and($fields['promo']['supported'])->toBeTrue()
        // Every other type is untouched by H7.
        ->and($fields['full_name']['prefill'])->toBeNull();
});

it('accepts a link-sourced hidden answer keyed through the encode channel', function (): void {
    $form = prefillForm($this->tenant, $this->owner);

    $result = app(SubmissionPipeline::class)->submit(new SubmissionPayload(
        version: $form->currentPublishedVersion,
        answers: ['full_name' => 'Maria', 'promo' => 'BATCH-42'],
        source: SubmissionSource::Manual,
        respondentUserId: $this->owner->id,
    ));

    expect(storedAnswers($result->submission->id)['promo'])->toBe('BATCH-42');
});

it('rejects an over-cap link-sourced value with a 422-shaped structural fault', function (): void {
    $form = prefillForm($this->tenant, $this->owner);

    try {
        app(SubmissionPipeline::class)->submit(new SubmissionPayload(
            version: $form->currentPublishedVersion,
            answers: ['full_name' => 'Maria', 'promo' => str_repeat('x', 2001)],
            source: SubmissionSource::Manual,
            respondentUserId: $this->owner->id,
        ));
        expect(false)->toBeTrue('expected a SubmissionValidationException');
    } catch (SubmissionValidationException $e) {
        // The slug lives in the field-error envelope, not the summary message — that envelope is what the
        // 422 body carries and what a client can act on.
        expect($e->fieldErrors()[0]['field'])->toBe('promo')
            ->and($e->fieldErrors()[0]['rule'])->toBe('prefill_too_long');
    }
});
