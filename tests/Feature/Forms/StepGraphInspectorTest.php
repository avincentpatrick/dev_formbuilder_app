<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Forms\StepGraphInspector;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
|--------------------------------------------------------------------------
| H21a — publish-time branching NOTICES (Doc #27 §6).
|--------------------------------------------------------------------------
| EVERY test here asserts that the publish SUCCEEDS. A test asserting a refusal would pin the opposite of
| §6: no new structural refusal is added by the whole of H21, because every one of these checks would run
| against the DRAFT, and `PublishService::publish()` step 9 clones the just-published structure forward into
| a fresh draft — so a refusal would make a form that is already live and collecting data UN-EDITABLE, with
| an error naming a rule its author wrote before the rule existed. There is no grandfather seam for publish
| gates; H5c's `legacyOverrides()` covers entitlements only.
|
| The SILENCE cases matter as much as the firing ones. A notice that fires on correct forms is the heuristic
| §6 rejects for unreachable sections, and it is what makes an author stop reading the banner.
|
| EVERY field gets a DISTINCT `sequence` — `addFormField()` defaults it to 0, and the forward-reference rule
| is positional, so an all-zero fixture would pass or fail for the wrong reason.
| EVERY `${` literal is SINGLE-quoted (PHP 8.3 removed `${var}` interpolation).
*/

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->forms = app(FormService::class);
    $this->publisher = app(PublishService::class);
    $this->inspector = app(StepGraphInspector::class);
});

function inspectorSection(string $versionId, string $key, int $sequence, array $extra = []): FormSection
{
    return FormSection::create(array_merge([
        'form_version_id' => $versionId,
        'key' => $key,
        'label' => ucfirst($key),
        'sequence' => $sequence,
    ], $extra));
}

/** Publish the draft the callback authored, and return the published version. */
function inspectorPublish(object $test, Closure $author): FormVersion
{
    $form = $test->forms->create($test->tenant, $test->user, 'Survey');
    $author($form->draftVersion, $test->user);

    $published = $test->publisher->publish($form->refresh(), $test->user);
    expect($published->status)->toBe(FormVersionStatus::Published);

    return $published;
}

it('says nothing about a form whose conditions are all straightforward', function (): void {
    $version = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'basics', 1);
        $s2 = inspectorSection($draft->id, 'details', 2, ['relevant_expression' => '${gate} = \'yes\'']);
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
        addFormField($draft, $user, 'detail', FieldType::ShortText, 2, ['form_section_id' => $s2->id]);
    });

    expect($this->inspector->warnings($version))->toBe([]);
});

it('stays silent on a chain, which is the case a naive cycle detector gets wrong', function (): void {
    // `gate → a → b`, the shape `chained_fixed_point` in `tests/golden/validation/relevance.json` pins as
    // CORRECT behaviour. A detector that cannot tell a chain from a cycle warns on correct forms, which is
    // worse than not warning at all — so this is the load-bearing negative test for the cycle notice.
    $version = inspectorPublish($this, function ($draft, $user): void {
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1);
        addFormField($draft, $user, 'a', FieldType::ShortText, 2, ['relevant_expression' => '${gate} = \'1\'']);
        addFormField($draft, $user, 'b', FieldType::ShortText, 3, ['relevant_expression' => '${a} = \'\'']);
    });

    expect($this->inspector->warnings($version))->toBe([]);
});

it('reports a section and a field that depend on each other', function (): void {
    $version = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'first', 1);
        $s2 = inspectorSection($draft->id, 'second', 2, ['relevant_expression' => '${later} = \'x\'']);
        addFormField($draft, $user, 'here', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
        addFormField($draft, $user, 'later', FieldType::ShortText, 2, [
            'form_section_id' => $s2->id,
            'relevant_expression' => '${here} = \'y\'',
        ]);
    });

    $warnings = $this->inspector->warnings($version);

    // The section is gated on a field it CONTAINS: the containment edge is what makes this a cycle at all,
    // and it is the commonest real one — iteration 0 prunes the field, the gate reads ABSENT forever, and
    // the respondent can never answer the field because it never renders.
    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('Circular condition');
    expect($warnings[0])->toContain('second');
    expect($warnings[0])->toContain('later');
});

it('reports two sections whose conditions reference each other', function (): void {
    $version = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'alpha', 1, ['relevant_expression' => '${y} = \'1\'']);
        $s2 = inspectorSection($draft->id, 'beta', 2, ['relevant_expression' => '${x} = \'1\'']);
        addFormField($draft, $user, 'x', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
        addFormField($draft, $user, 'y', FieldType::ShortText, 2, ['form_section_id' => $s2->id]);
    });

    expect($this->inspector->warnings($version))->not->toBeEmpty();
});

it('reports a forward reference without refusing the publish', function (): void {
    // §3.1 — a forward relevance reference is merely LATE, not useless: it evaluates false until the
    // respondent reaches the referenced field and true from then on. Legal, and warned.
    $version = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'early', 1, ['relevant_expression' => '${answered_later} = \'yes\'']);
        $s2 = inspectorSection($draft->id, 'late', 2);
        addFormField($draft, $user, 'early_field', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
        addFormField($draft, $user, 'answered_later', FieldType::ShortText, 2, ['form_section_id' => $s2->id]);
    });

    $warnings = $this->inspector->warnings($version);

    expect($warnings)->toHaveCount(1);
    expect($warnings[0])->toContain('Forward reference');
    expect($warnings[0])->toContain('answered_later');
});

it('stays silent on a positional tie rather than guessing at the order', function (): void {
    // The rule is the INVERSE of piping's: piping REFUSES when the source does not precede the host, while
    // this fires only when the host PROVABLY precedes the source. So a tie — two nodes at the same position,
    // which is exactly what an all-defaults fixture produces, since both `addFormField()` and the field
    // factory default `sequence` to 0 — is silent. Two fields at the same position have no defined render
    // order, so neither direction is provable and warning either way would be a guess.
    $version = inspectorPublish($this, function ($draft, $user): void {
        addFormField($draft, $user, 'mine', FieldType::ShortText, 0, ['relevant_expression' => '${other} = \'yes\'']);
        addFormField($draft, $user, 'other', FieldType::ShortText, 0);
    });

    $warnings = $this->inspector->warnings($version);

    expect(array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'Forward reference'))))->toBe([]);
});

it('does warn when a SECTION heading provably precedes the field it is gated on', function (): void {
    // The anti-vacuity twin of the tie case, and a correction to an assumption worth writing down: a section
    // sits at `[sequence, -1]` and a field at `[sequence, fieldSequence]`, so a section heading precedes
    // every field in a section of the SAME sequence. A template-materialized form that leaves every section
    // at sequence 0 therefore still gets an honest diagnosis rather than silence.
    $version = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'one', 0, ['relevant_expression' => '${other} = \'yes\'']);
        $s2 = inspectorSection($draft->id, 'two', 0);
        addFormField($draft, $user, 'mine', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
        addFormField($draft, $user, 'other', FieldType::ShortText, 2, ['form_section_id' => $s2->id]);
    });

    expect(array_values(array_filter($this->inspector->warnings($version), fn (string $w): bool => str_contains($w, 'Forward reference'))))
        ->not->toBeEmpty();
});

it('reports a form that shows nothing at all until something is answered', function (): void {
    // §4.1 — the decidable half of emptiness. The mid-fill empty graph is legitimate and specified; a form
    // that is empty the moment it OPENS is worth saying out loud.
    $version = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'gated', 1, ['relevant_expression' => '${gate} = \'yes\'']);
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
    });

    $warnings = $this->inspector->warnings($version);

    expect(array_filter($warnings, fn (string $w): bool => str_contains($w, 'shows no questions at all')))
        ->not->toBeEmpty();
});

it('does not call an H7 router form empty, because its entry condition is prefilled', function (): void {
    // §4.1 concedes this false positive in the same breath as the check: a form whose only entry condition
    // is a URL-prefilled `hidden` field is LEGITIMATELY empty under an empty context, because the prefill has
    // not happened yet. H7 shipped exactly this shape three increments ago, so without the suppression this
    // notice would fire on every one of them.
    $version = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'vip', 1, ['relevant_expression' => '${tier} = \'vip\'']);
        addFormField($draft, $user, 'tier', FieldType::Hidden, 1, [
            'config' => ['prefill_source' => 'url', 'url_param' => 'tier'],
        ]);
        addFormField($draft, $user, 'perk', FieldType::ShortText, 2, ['form_section_id' => $s1->id]);
    });

    $warnings = $this->inspector->warnings($version);

    expect(array_values(array_filter($warnings, fn (string $w): bool => str_contains($w, 'shows no questions at all'))))->toBe([]);
});
