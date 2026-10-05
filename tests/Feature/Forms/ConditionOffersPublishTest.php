<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\OperandKind;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\ExpressionValidationGate;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M134 (`R-910d2286`) — the condition editor offers a SUBSET of what publish accepts.
|--------------------------------------------------------------------------
| The editor filters its operators and its compared questions by the `operand_kinds` rows BuilderPresenter
| transmits from `OperandKind`. This file reads those rows from the REAL payload, builds every comparison they
| offer — and the obvious ones they withhold — and runs each through the real publish gate. An offer the gate
| refuses is the defect `R-910d2286` filed ("the editor builds what publish refuses"); a withheld comparison the
| gate accepts would mean the editor is narrower than it needs to be, which is allowed, so only the CONSTANT ones
| are asserted refused.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->forms = app(FormService::class);
    $this->gate = app(ExpressionValidationGate::class);
});

/** The first field type of a kind — the question an offer is tried on. */
function offerSubjectType(string $kind): FieldType
{
    foreach (FieldType::cases() as $type) {
        if (OperandKind::for($type)->value === $kind) {
            return $type;
        }
    }

    throw new LogicException("no field type has operand kind {$kind}");
}

/** A fixed value of the kind the editor's picker for that kind would produce. */
function offerLiteral(?string $literalInput): string
{
    return match ($literalInput) {
        'date' => "'2026-01-01'",
        'time' => "'09:00'",
        'datetime-local' => "'2026-01-01T09:00'",
        default => '5',
    };
}

/**
 * Publish-check each expression as the relevance of its own question, over one question per offered kind.
 *
 * @param  list<string>  $kinds
 * @param  list<string>  $expressions
 * @return list<array{0: string, 1: string}> [expression, refusal code] for every expression the gate refused
 */
function offerRefusals(object $test, array $kinds, array $expressions): array
{
    $form = $test->forms->create($test->tenant, $test->user, 'Survey');
    /** @var FormVersion $draft */
    $draft = $form->draftVersion;
    $sequence = 0;

    foreach ($kinds as $kind) {
        $type = offerSubjectType($kind);
        addFormField($draft, $test->user, "s_{$kind}", $type, $sequence++, $type === FieldType::Calculated ? ['config' => ['calculated_formula' => '1']] : []);
    }

    foreach ($expressions as $i => $expression) {
        addFormField($draft, $test->user, "shown_{$i}", FieldType::ShortText, $sequence++, ['relevant_expression' => $expression]);
    }

    try {
        $test->gate->assertExpressionsResolve($draft->refresh());

        return [];
    } catch (PublishValidationException $e) {
        return array_map(static fn (array $v): array => [$expressions[(int) substr((string) $v['field'], 6)], $v['code']], $e->violations());
    }
}

it('publishes every comparison the condition editor offers, read from the real payload', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Payload');
    $rows = app(BuilderPresenter::class)->present($form->refresh())['enums']['operand_kinds'];
    $offered = array_values(array_filter($rows, static fn (array $row): bool => $row['offered']));
    $expressions = [];

    foreach ($offered as $row) {
        $subject = '${s_'.$row['value'].'}';
        $expressions[] = "{$subject} = ''";
        $expressions[] = "{$subject} != ''";

        if ($row['equals']) {
            $expressions[] = "{$subject} = ".($row['literal_input'] === null ? "'a'" : offerLiteral($row['literal_input']));
            $expressions[] = "{$subject} != ".($row['literal_input'] === null ? "'a'" : offerLiteral($row['literal_input']));
        }

        if ($row['includes']) {
            $expressions[] = "selected({$subject}, 'a')";
        }

        if ($row['orders']) {
            $expressions[] = "{$subject} > ".offerLiteral($row['literal_input']);
            $expressions[] = "{$subject} <= ".offerLiteral($row['literal_input']);

            foreach ($row['orders_with'] as $other) {
                $expressions[] = "{$subject} < \${s_{$other}}";
            }
        }
    }

    expect($offered)->toHaveCount(9) // anti-vacuity: the payload offers the nine answerable kinds
        ->and(count($expressions))->toBeGreaterThan(40)
        ->and(offerRefusals($this, array_column($offered, 'value'), $expressions))->toBe([]);
});

it('withholds exactly the comparisons publish refuses as constant', function (): void {
    $form = $this->forms->create($this->tenant, $this->user, 'Payload');
    $rows = app(BuilderPresenter::class)->present($form->refresh())['enums']['operand_kinds'];
    $offered = array_values(array_filter($rows, static fn (array $row): bool => $row['offered']));
    $expressions = [];

    foreach ($offered as $row) {
        $subject = '${s_'.$row['value'].'}';

        if (! $row['equals'] && $row['value'] !== 'boolean') {
            $expressions[] = "{$subject} = 'a'";
        }

        if (! $row['orders']) {
            $expressions[] = "{$subject} > 5";
        }

        if ($row['literal_input'] !== null) {
            $expressions[] = "{$subject} > 5"; // a date or a time against a number
        }
    }

    $refusals = offerRefusals($this, array_column($offered, 'value'), $expressions);

    expect($expressions)->not->toBeEmpty()
        ->and(array_column($refusals, 0))->toEqualCanonicalizing($expressions);
});
