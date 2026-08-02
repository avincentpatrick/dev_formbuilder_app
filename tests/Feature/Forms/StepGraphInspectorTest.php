<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormVersionStatus;
use App\Enums\GraphNoticeKind;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Forms\StepGraphInspector;
use App\Support\Forms\GraphNotice;
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

/**
 * Author a draft and return it WITHOUT publishing — the H21d1 shape. Everything above runs against a
 * published version, where `ExpressionValidationGate` has already refused anything that does not parse; a
 * draft carries whatever the author has typed so far, which is the state this class must now survive.
 */
function inspectorDraft(object $test, Closure $author): FormVersion
{
    $form = $test->forms->create($test->tenant, $test->user, 'Survey');
    $draft = $form->draftVersion;
    $author($draft, $test->user);

    return $draft->refresh();
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

/*
|--------------------------------------------------------------------------
| H21d1 — the same class, now run against an UNVALIDATED DRAFT.
|--------------------------------------------------------------------------
| The canvas reuses these notices while the author is still typing, so for the first time this class sees
| expressions that `ExpressionValidationGate` has never approved. `referencesIn()` already caught that (its
| docblock names H21d1 as the reason); `emptyAtOpen()` did not, and it reaches the parser through
| SemanticValidator → ExpressionEvaluator::evaluateBoolean() → ExpressionParser::parse(), which THROWS.
*/

it('survives a draft whose condition does not parse, instead of throwing', function (): void {
    $draft = inspectorDraft($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'gated', 1, ['relevant_expression' => '${gate} = = \'yes\'']);
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
    });

    // The contract is only that it comes back with something sayable. WHICH notices fire on a graph that
    // cannot be evaluated is not the point — the point is that a half-typed condition does not 500 the
    // builder, and the per-node syntax error is reported client-side, live, where the author is looking.
    expect($this->inspector->warnings($draft))->toBeArray();
});

it('keeps the three flash sentences byte-identical through the H21d1 restructure', function (): void {
    // `warnings()` stopped building its strings directly in H21d1 and became a grouping wrapper over
    // `notices()`. These are the sentences H21a shipped, pinned in FULL rather than by needle — the
    // existing tests above assert with `toContain`, which would not notice a dropped clause. (And Pest's
    // `toContain` takes VARARGS NEEDLES, so a second argument is a second needle, not a failure message —
    // the H17 lesson that turned a passing assertion into a vacuous one.)
    $forward = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'early', 1, ['relevant_expression' => '${answered_later} = \'yes\'']);
        $s2 = inspectorSection($draft->id, 'late', 2);
        addFormField($draft, $user, 'early_field', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
        addFormField($draft, $user, 'answered_later', FieldType::ShortText, 2, ['form_section_id' => $s2->id]);
    });

    expect($this->inspector->warnings($forward))->toBe([
        'Forward reference: “early” depends on “answered_later”, which comes later in the form. '
        .'That condition stays false until the respondent reaches the later question.',
    ]);

    $cycle = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'first', 1);
        $s2 = inspectorSection($draft->id, 'second', 2, ['relevant_expression' => '${later} = \'x\'']);
        addFormField($draft, $user, 'here', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
        addFormField($draft, $user, 'later', FieldType::ShortText, 2, [
            'form_section_id' => $s2->id,
            'relevant_expression' => '${here} = \'y\'',
        ]);
    });

    expect($this->inspector->warnings($cycle))->toBe([
        'Circular condition: “second” ⇄ “later”. '
        .'These conditions depend on each other, so the result depends on the order they settle in.',
    ]);

    $empty = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'gated', 1, ['relevant_expression' => '${gate} = \'yes\'']);
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
    });

    // TWO lines, and the assertion is written as an exact array precisely because of the second one: this
    // fixture — the one §4.1 uses — is ALSO a containment cycle (the section is gated on a field it holds),
    // which the existing needle-based test above could not see. It also pins the BANNER ORDER, which
    // `warnings()` now sets from an explicit list rather than from the order the enum's cases are declared.
    expect($this->inspector->warnings($empty))->toBe([
        'This form shows no questions at all until something is answered, so a respondent opening it sees an empty form.',
        'Circular condition: “gated” ⇄ “gate”. '
        .'These conditions depend on each other, so the result depends on the order they settle in.',
    ]);
});

it('joins several forward references into ONE banner line, but keeps them separate as notices', function (): void {
    // The reason `GraphNotice` carries two strings. Three forward references are one banner line — it
    // appears once, above a page the author is leaving — and three notices, because on the canvas each one
    // hangs under a different node.
    $version = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'one', 1, ['relevant_expression' => '${late_a} = \'y\'']);
        $s2 = inspectorSection($draft->id, 'two', 2, ['relevant_expression' => '${late_b} = \'y\'']);
        $s3 = inspectorSection($draft->id, 'three', 3);
        addFormField($draft, $user, 'anchor', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
        addFormField($draft, $user, 'filler', FieldType::ShortText, 2, ['form_section_id' => $s2->id]);
        addFormField($draft, $user, 'late_a', FieldType::ShortText, 3, ['form_section_id' => $s3->id]);
        addFormField($draft, $user, 'late_b', FieldType::ShortText, 4, ['form_section_id' => $s3->id]);
    });

    $forward = array_values(array_filter(
        $this->inspector->notices($version),
        fn (GraphNotice $n): bool => $n->kind === GraphNoticeKind::ForwardReference,
    ));

    expect($forward)->toHaveCount(2);
    expect($forward[0]->nodes)->toBe(['one', 'late_a']);
    expect($forward[1]->nodes)->toBe(['two', 'late_b']);

    // …and exactly one banner line carrying both.
    $banner = array_values(array_filter(
        $this->inspector->warnings($version),
        fn (string $w): bool => str_starts_with($w, 'Forward reference'),
    ));
    expect($banner)->toHaveCount(1);
    expect($banner[0])->toContain('“one” depends on “late_a”');
    expect($banner[0])->toContain('“two” depends on “late_b”');
});

it('names EVERY member of a cycle, not just the one the walk entered it from', function (): void {
    // A cycle is a property of the SET. An author standing on either member needs to be told, so the canvas
    // needs both keys — and a notice that named only the entry point would attach to one node and leave its
    // partner looking innocent.
    $version = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'alpha', 1, ['relevant_expression' => '${y} = \'1\'']);
        $s2 = inspectorSection($draft->id, 'beta', 2, ['relevant_expression' => '${x} = \'1\'']);
        addFormField($draft, $user, 'x', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
        addFormField($draft, $user, 'y', FieldType::ShortText, 2, ['form_section_id' => $s2->id]);
    });

    $cycles = array_values(array_filter(
        $this->inspector->notices($version),
        fn (GraphNotice $n): bool => $n->kind === GraphNoticeKind::Cycle,
    ));

    expect($cycles)->not->toBeEmpty();
    expect(count($cycles[0]->nodes))->toBeGreaterThan(1);
});

it('gives the empty-at-open notice NO node, because emptiness belongs to the graph', function (): void {
    $version = inspectorPublish($this, function ($draft, $user): void {
        $s1 = inspectorSection($draft->id, 'gated', 1, ['relevant_expression' => '${gate} = \'yes\'']);
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1, ['form_section_id' => $s1->id]);
    });

    $empty = array_values(array_filter(
        $this->inspector->notices($version),
        fn (GraphNotice $n): bool => $n->kind === GraphNoticeKind::EmptyAtOpen,
    ));

    expect($empty)->toHaveCount(1);
    expect($empty[0]->nodes)->toBe([]);
    // The wire shape the canvas consumes — `fragment` is deliberately absent from it.
    expect($empty[0]->toArray())->toBe([
        'kind' => 'empty_at_open',
        'nodes' => [],
        'message' => 'This form shows no questions at all until something is answered, so a respondent opening it sees an empty form.',
    ]);
});

it('survives a draft whose FIELD condition does not parse either', function (): void {
    // The section-level arm above and this one reach `evaluateRelevance()` from two different loops in the
    // settle (sections are swept separately from fields), so one catch that covers only the first would
    // still let this through.
    $draft = inspectorDraft($this, function ($draft, $user): void {
        addFormField($draft, $user, 'gate', FieldType::ShortText, 1);
        addFormField($draft, $user, 'shown', FieldType::ShortText, 2, ['relevant_expression' => 'selected(${gate}']);
    });

    expect($this->inspector->warnings($draft))->toBeArray();
});
