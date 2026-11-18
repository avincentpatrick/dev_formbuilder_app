<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\ExpressionKind;
use App\Enums\FieldType;
use App\Enums\ValidationRuleType;
use App\Exceptions\Expressions\ExpressionSyntaxException;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Services\Expressions\Coercion;
use App\Services\Expressions\ExpressionParser;
use App\Services\Validation\CalculationOrder;
use App\Services\Validation\StructuredRuleEvaluator;

/**
 * The pre-publish EXPRESSION gate (F3; the item F2 deferred). {@see StructuralValidationGate} checks
 * shape; this checks that every authored `relevant`/`constraint` expression parses and references only
 * known fields, so a syntactically-broken or dangling-reference expression is refused at PUBLISH (naming
 * the field) rather than surfacing as a submit-time failure. Also hardens the structured rows whose
 * `rule_value` is otherwise only exercised at submission — an uncompilable `pattern` regex or a
 * non-numeric `min_value`/`max_value` threshold — both of which would silently fail closed. Parse/reference
 * failures are re-wrapped into {@see PublishValidationException} so the existing web-toast / api-422
 * surfacings fire; a submission-time caller never reaches this (published expressions are pre-validated).
 */
final class ExpressionValidationGate
{
    public function __construct(private readonly ExpressionParser $parser) {}

    public function assertExpressionsResolve(FormVersion $version): void
    {
        $fields = $version->fields()->get();
        $sections = $version->sections()->get();
        $validations = $version->validations()->get();

        /** @var array<string, bool> $knownKeys */
        $knownKeys = [];
        /** @var array<string, string> $fieldKeyById */
        $fieldKeyById = [];
        // Object-valued field keys forbidden as expression operands: grids (G4b) and geo (G5b1). Both are
        // object-shaped with no valid scalar use — a reference would silently coerce to false and drift
        // between the engines. Value = the kind, so the right PublishValidationException is thrown.
        /** @var array<string, 'grid'|'geo'> $objectValuedKeys */
        $objectValuedKeys = [];
        foreach ($fields as $field) {
            $knownKeys[$field->key] = true;
            $fieldKeyById[$field->id] = $field->key;
            if ($field->field_type->isComposite()) {
                $objectValuedKeys[$field->key] = 'grid';
            } elseif ($field->field_type->isGeo()) {
                $objectValuedKeys[$field->key] = 'geo';
            }
        }

        // Section keys are referenceable too (grammar v2.0): `count(${roster})` counts a repeatable section's
        // instances.
        //
        // CORRECTED IN H21a (Doc #27 amendment A7): this comment used to claim field and section keys are
        // "globally unique per version, so there is no key collision". They are NOT. Uniqueness is two
        // INDEPENDENT per-table composite indexes — `form_sections` and `form_fields` each carry
        // `(tenant_id, form_version_id, key)` — and every application-level enforcer is table-scoped too
        // (`UpdateSectionRequest` / `UpdateFieldRequest` each `Rule::unique` their own table;
        // `FormBuilderService::uniqueKey()` plucks from one). Only `XlsformImportParser::sanitizeKeys()`
        // enforces a shared namespace, and it is one of five writers.
        //
        // A collision is therefore reachable, and it makes this union LOSSY — the later write wins. That is
        // harmless for THIS gate, whose only question is "is the key known", but it is not harmless
        // everywhere: `SemanticValidator::relevanceContext()` carries an explicit collision guard for exactly
        // this reason, because seeding a colliding section key would re-admit a pruned field's answer.
        foreach ($sections as $section) {
            $knownKeys[$section->key] = true;
        }

        // M113: every check below is CAPTURED rather than allowed to propagate, so one refusal names every
        // broken expression in the draft instead of only the first one the walk happened to meet.
        //
        // ⛔ THE THREE GATES ARE STILL SEQUENTIAL AND `PublishService` IS NOT EDITED. Collecting happens
        // INSIDE each gate, so `assertExpressionsResolve()` still throws once and the next gate in the
        // sequence is still unreachable when it does. That ordering is load-bearing for the TEMPLATE gate,
        // which joins a field to its section and would resolve a foreign-version miss to a real-looking
        // position — this gate never makes that join, but hoisting all three into one collector would.
        /** @var list<PublishValidationException> $violations */
        $violations = [];

        foreach ($fields as $field) {
            $violations[] = $this->capture(fn () => $this->check($field->relevant_expression, $knownKeys, $objectValuedKeys, ExpressionKind::Relevant, $field->key));
            $violations[] = $this->capture(fn () => $this->checkFormula($field, $knownKeys, $objectValuedKeys));
        }

        foreach ($sections as $section) {
            $violations[] = $this->capture(fn () => $this->check($section->relevant_expression, $knownKeys, $objectValuedKeys, ExpressionKind::Relevant, $section->key));
        }

        foreach ($validations as $validation) {
            $ownerKey = $fieldKeyById[$validation->form_field_id] ?? '(unknown)';

            if ($validation->expression !== null) {
                $violations[] = $this->capture(fn () => $this->check($validation->expression, $knownKeys, $objectValuedKeys, ExpressionKind::Constraint, $ownerKey));

                continue;
            }

            $violations[] = $this->capture(fn () => $this->assertRuleValue($validation->rule_type, (string) ($validation->rule_value ?? ''), $ownerKey));
        }

        $violations = array_values(array_filter([...$violations, ...$this->orderingViolations($version), ...$this->calculationCycleViolations($fields), ...$this->gridViolations($version)]));

        if ($violations !== []) {
            throw PublishValidationException::several($violations);
        }
    }

    /**
     * Run one check, returning its refusal instead of letting it propagate.
     *
     * ⚠️ `null` MEANS THE CHECK PASSED, and the caller filters those out at the end rather than branching
     * here — so a helper that grows a second throw site needs no change at the call site. Only
     * {@see PublishValidationException} is caught: anything else is a fault in the gate itself and must
     * still reach the caller, because a gate that swallows its own bugs reports `passed` while blind.
     *
     * Copied deliberately from {@see StructuralValidationGate::capture()} rather than shared — the two
     * gates are independent and a common base class would couple their walks.
     *
     * @param  callable(): void  $check
     */
    private function capture(callable $check): ?PublishValidationException
    {
        try {
            $check();
        } catch (PublishValidationException $violation) {
            return $violation;
        }

        return null;
    }

    /**
     * @param  array<string, bool>  $knownKeys
     * @param  array<string, 'grid'|'geo'>  $objectValuedKeys  object-valued field keys forbidden as operands (grid G4b / geo G5b1)
     */
    private function check(?string $expression, array $knownKeys, array $objectValuedKeys, ExpressionKind $kind, string $ownerKey, bool $rowScope = false): void
    {
        if ($expression === null || trim($expression) === '') {
            return; // blank = no expression
        }

        try {
            $ast = $this->parser->parse($expression);
            $this->parser->assertReferencesResolve($ast, $knownKeys, $kind, $ownerKey, $rowScope);
        } catch (ExpressionSyntaxException $exception) {
            throw PublishValidationException::expressionInvalid($exception->fieldKey() ?? $ownerKey, $exception->slug());
        }

        // A grid (G4b) or geo (G5b1) value is object-shaped and has no valid scalar use — reject any
        // reference rather than let it silently coerce to false (and drift between the engines).
        if ($objectValuedKeys !== []) {
            foreach ($this->parser->referencedFieldKeys($ast) as $key) {
                $kindOfKey = $objectValuedKeys[$key] ?? null;
                if ($kindOfKey === 'geo') {
                    throw PublishValidationException::expressionReferencesGeo($ownerKey, $key);
                }
                if ($kindOfKey === 'grid') {
                    throw PublishValidationException::expressionReferencesComposite($ownerKey, $key);
                }
            }
        }
    }

    /** A calculated field's `config.calculated_formula` (grammar v2.0); null when not a calc / blank. */
    private function calculateFormula(FormField $field): ?string
    {
        if ($field->field_type !== FieldType::Calculated) {
            return null;
        }

        $formula = data_get($field->config, 'calculated_formula');

        return is_string($formula) ? $formula : null;
    }

    private function assertRuleValue(?ValidationRuleType $ruleType, string $ruleValue, string $ownerKey): void
    {
        if ($ruleType === ValidationRuleType::Pattern && ! StructuredRuleEvaluator::isCompilablePattern($ruleValue)) {
            throw PublishValidationException::ruleValueInvalid($ownerKey, 'invalid_pattern');
        }

        $isThreshold = $ruleType === ValidationRuleType::MinValue || $ruleType === ValidationRuleType::MaxValue;
        if ($isThreshold && ! Coercion::isNumericLike($ruleValue)) {
            throw PublishValidationException::ruleValueInvalid($ownerKey, 'non_numeric_threshold');
        }
    }

    /**
     * A calculated question's formula — the one expression for which blank is NOT "no expression" (Increment
     * M122, `R-244d53dc`). {@see check()} skips a blank expression, which is right for relevance and for a
     * constraint, and was wrong here: a calculated question exists only to compute, so a blank formula
     * published clean and computed nothing in every submission. Reached three ways — adding a Calculated
     * question (its default config is empty), converting a note or hidden field to one, and importing an
     * XLSForm `calculate` row with no `calculation`.
     *
     * The blank test trims, exactly as `SemanticValidator`'s own skip does, so publish refuses precisely what
     * the runtime would otherwise have ignored. Every other type reaches {@see check()} with null, as before.
     *
     * ⚠️ APPENDED HERE, AND THE CALL SITE ABOVE WAS REPLACED IN PLACE, ON PURPOSE: this file is cited by line
     * from a design document and from the ledger, and a single inserted line in the field loop rots a ledger
     * citation past its ceiling.
     *
     * @param  array<string, bool>  $knownKeys
     * @param  array<string, 'grid'|'geo'>  $objectValuedKeys
     */
    private function checkFormula(FormField $field, array $knownKeys, array $objectValuedKeys): void
    {
        $formula = $this->calculateFormula($field);

        if ($field->field_type === FieldType::Calculated && ($formula === null || trim($formula) === '')) {
            throw PublishValidationException::calculatedFormulaMissing($field->key);
        }

        $this->check($formula, $knownKeys, $objectValuedKeys, ExpressionKind::Calculate, $field->key);
    }

    /**
     * Every expression that reads a question as a NUMBER when that question's answers never are one (Increment
     * M126, `R-87160c81`).
     *
     * ⛔ THE EXPRESSION IS CONSTANT, AND NEITHER ENGINE SAYS SO. An operand that is not numeric-like makes
     * `> < >= <=` false unless both sides are comparable ISO dates or times (`Temporal`, since `M134`), and
     * arithmetic on one is NaN. So `${remark} > 3` on a note and `${hobbies} > 2` on a multi-select published
     * clean and then never changed with the answer — and in a constraint, every answer was refused. {@see check()}
     * only resolves keys; `M123`'s arms in {@see StructuralValidationGate} judge structured rule rows, never an
     * expression.
     *
     * A use is read exactly as the conversion census reads it ({@see ExpressionKeyUse}, the one walker both
     * share), and judged by {@see ExpressionOperandJudge} against the question's `OperandKind` — for a date or a
     * time, against what it is compared WITH (`M134`, `D89`). A constraint's `.` names its own question. A question
     * whose answer is refused outright as an operand (grid, geo) is left to {@see check()}, so it is reported once.
     * An expression that does not parse is skipped for the same reason.
     *
     * ⚠️ APPENDED HERE, AND ITS CALL SITE REPLACED THE COLLECTION LINE IN PLACE, for the reason
     * {@see checkFormula()} gives: this file is cited by line from design documents and from the ledger. It reads
     * the version's rows itself, three queries at publish, so that no import line moves a cited line either.
     *
     * @return list<PublishValidationException>
     */
    private function orderingViolations(FormVersion $version): array
    {
        $fields = $version->fields()->get();
        $sections = $version->sections()->get();
        $validations = $version->validations()->get();

        /** @var array<string, FieldType> $typeByKey */
        $typeByKey = [];
        /** @var array<string, string> $keyById */
        $keyById = [];
        /** @var list<array{owner: string, expression: ?string, self: ?string}> $sites */
        $sites = [];

        foreach ($fields as $field) {
            $typeByKey[$field->key] = $field->field_type;
            $keyById[$field->id] = $field->key;
            $sites[] = ['owner' => $field->key, 'expression' => $field->relevant_expression, 'self' => null];
            $sites[] = ['owner' => $field->key, 'expression' => $this->calculateFormula($field), 'self' => null];

            // M246: a grid's own rules — read through the model, so that no import line moves a line this file is cited by.
            foreach ($field->gridRuleSites() as $gridSite) {
                $sites[] = ['owner' => $field->key, 'expression' => $gridSite['expression'], 'self' => null];
            }
        }

        foreach ($sections as $section) {
            $sites[] = ['owner' => $section->key, 'expression' => $section->relevant_expression, 'self' => null];
        }

        foreach ($validations as $validation) {
            $ownerKey = $keyById[$validation->form_field_id] ?? null;

            if ($ownerKey !== null) {
                $sites[] = ['owner' => $ownerKey, 'expression' => $validation->expression, 'self' => $ownerKey];
            }
        }

        $violations = [];

        foreach ($sites as $site) {
            if ($site['expression'] === null || trim($site['expression']) === '') {
                continue;
            }

            try {
                $ast = $this->parser->parse($site['expression']);
            } catch (ExpressionSyntaxException) {
                continue; // check() has already refused it
            }

            $keys = $this->parser->referencedKeys($ast);

            if ($site['self'] !== null && ! in_array($site['self'], $keys, true)) {
                $keys[] = $site['self'];
            }

            foreach ($keys as $key) {
                $type = $typeByKey[$key] ?? null;

                if ($type === null) {
                    continue; // check() has already refused an unknown key
                }

                $use = ExpressionKeyUse::of($ast, $key, $key === $site['self']);
                $violations[] = ExpressionOperandJudge::judge($site['owner'], $key, $type, $use, $site['self'], $typeByKey);
            }
        }

        return array_values(array_filter($violations));
    }

    /**
     * Every calculation that uses its own value, directly or through other calculations (M180, `R-39dbce5b`, `D149`).
     * Both engines work calculations out in dependency order ({@see CalculationOrder}), so such a calculation is never
     * worked out and stays blank in every submission — refused here, one refusal per loop, naming every calculation in
     * it. A calculation that only USES one of them is blank too, but is not reported: fixing the loop fixes it.
     *
     * ⚠️ APPENDED, AND THE CALL FOLDED INTO THE EXISTING `$violations` LINE, ON PURPOSE — this file is cited by line.
     * A formula that does not parse names nothing here; {@see check()} has already refused it.
     *
     * @param  iterable<FormField>  $fields
     * @return list<PublishValidationException>
     */
    private function calculationCycleViolations(iterable $fields): array
    {
        /** @var list<array{key: string, formula: ?string}> $calculations */
        $calculations = [];
        /** @var array<string, list<string>> $dependencies */
        $dependencies = [];
        foreach ($fields as $field) {
            if ($field->field_type !== FieldType::Calculated) {
                continue;
            }

            $formula = $this->calculateFormula($field);
            $formula = $formula === null || trim($formula) === '' ? null : $formula;
            $calculations[] = ['key' => $field->key, 'formula' => $formula];
            $dependencies[$field->key] = $formula === null ? [] : $this->references($formula);
        }

        $workedOut = array_flip(CalculationOrder::order($calculations, fn (string $formula): array => $this->references($formula)));

        /** @var array<string, true> $never */
        $never = [];
        foreach ($calculations as $calculation) {
            if ($calculation['formula'] !== null && ! isset($workedOut[$calculation['key']])) {
                $never[$calculation['key']] = true;
            }
        }

        // The calculations each one reaches through the others never worked out; a loop is the set that reach each other.
        $reach = [];
        foreach (array_keys($never) as $start) {
            $seen = [];
            $stack = [$start];
            while ($stack !== []) {
                $key = array_pop($stack);
                foreach ($dependencies[$key] ?? [] as $next) {
                    if (isset($never[$next]) && ! isset($seen[$next])) {
                        $seen[$next] = true;
                        $stack[] = $next;
                    }
                }
            }
            $reach[$start] = $seen;
        }

        $violations = [];
        $reported = [];
        foreach ($calculations as $calculation) {
            $key = $calculation['key'];
            if (! isset($never[$key]) || isset($reported[$key]) || ! isset($reach[$key][$key])) {
                continue;
            }

            $loop = [];
            foreach ($calculations as $other) {
                if (isset($reach[$key][$other['key']], $reach[$other['key']][$key])) {
                    $loop[] = $other['key'];
                    $reported[$other['key']] = true;
                }
            }

            $violations[] = PublishValidationException::calculationCycle($loop);
        }

        return $violations;
    }

    /**
     * The keys a formula names, or none when it does not parse.
     *
     * @return list<string>
     */
    private function references(string $formula): array
    {
        try {
            return $this->parser->referencedKeys($this->parser->parse($formula));
        } catch (ExpressionSyntaxException) {
            return [];
        }
    }

    /**
     * M246 (`grid-question-table`, `R-ffd10da2`) — a grid's own rules, and every grid cell any expression names.
     *
     * A grid's row and column conditions are expressions like any other, so each is checked as one ({@see check()}), its
     * per-row rules with `${.col}` allowed. Then every cell named anywhere — a question's or a section's condition, a
     * formula, a custom check, a grid's own rule — must be one the grid has, and (`D230`) one that is ALWAYS ASKED: its
     * row has no show-when, its column no show-when or needed-when, its row does not mark it "Not needed", and the grid's
     * rows are its own rather than copied in from a list or a form. A grid's own rules name their own row's cells as
     * `${.col}`, only a column to the left; never their own grid by its full path. A Default value's formula is worked out
     * before anything is answered, so it names no cell at all.
     *
     * ⚠️ APPENDED, AND FOLDED INTO THE EXISTING `$violations` LINE, ON PURPOSE — this file is cited by line.
     *
     * @return list<PublishValidationException>
     */
    private function gridViolations(FormVersion $version): array
    {
        $fields = $version->fields()->get();
        $sections = $version->sections()->get();
        $validations = $version->validations()->get();

        $knownKeys = [];
        $objectValuedKeys = [];
        $fieldByKey = [];
        $keyById = [];
        foreach ($fields as $field) {
            $knownKeys[$field->key] = true;
            $fieldByKey[$field->key] = $field;
            $keyById[$field->id] = $field->key;
            if ($field->field_type->isComposite()) {
                $objectValuedKeys[$field->key] = 'grid';
            } elseif ($field->field_type->isGeo()) {
                $objectValuedKeys[$field->key] = 'geo';
            }
        }
        foreach ($sections as $section) {
            $knownKeys[$section->key] = true;
        }

        /** @var list<array{owner: string, expression: ?string, grid: ?FormField, column: ?int, cells: bool}> $sites */
        $sites = [];
        $violations = [];
        foreach ($fields as $field) {
            $sites[] = ['owner' => $field->key, 'expression' => $field->relevant_expression, 'grid' => null, 'column' => null, 'cells' => true];
            $sites[] = ['owner' => $field->key, 'expression' => $this->calculateFormula($field), 'grid' => null, 'column' => null, 'cells' => true];
            if ($field->default_value_is_expression === true) {
                $sites[] = ['owner' => $field->key, 'expression' => is_string($field->default_value) ? $field->default_value : null, 'grid' => null, 'column' => null, 'cells' => false];
            }

            $columnIndex = [];
            foreach (self::gridColumns($field) as $index => $column) {
                $columnIndex[(string) ($column['value'] ?? '')] ??= $index;
            }
            foreach ($field->gridRuleSites() as $site) {
                $rowScope = $site['row_scope'];
                $violations[] = $this->capture(fn () => $this->check($site['expression'], $knownKeys, $objectValuedKeys, ExpressionKind::Relevant, $field->key, $rowScope));
                $sites[] = [
                    'owner' => $field->key,
                    'expression' => $site['expression'],
                    'grid' => $field,
                    'column' => $rowScope ? ($columnIndex[(string) $site['column']] ?? null) : null,
                    'cells' => true,
                ];
            }
        }
        foreach ($sections as $section) {
            $sites[] = ['owner' => $section->key, 'expression' => $section->relevant_expression, 'grid' => null, 'column' => null, 'cells' => true];
        }
        foreach ($validations as $validation) {
            $owner = $keyById[$validation->form_field_id] ?? null;
            if ($owner !== null) {
                $sites[] = ['owner' => $owner, 'expression' => $validation->expression, 'grid' => null, 'column' => null, 'cells' => true];
            }
        }

        foreach ($sites as $site) {
            if ($site['expression'] === null || trim($site['expression']) === '') {
                continue;
            }
            try {
                $ast = $this->parser->parse($site['expression']);
            } catch (ExpressionSyntaxException) {
                continue; // check() has refused it already
            }

            foreach ($this->parser->referencedCells($ast) as $cell) {
                $reason = $this->cellProblem($cell, $site, $fieldByKey);
                if ($reason !== null) {
                    $violations[] = PublishValidationException::gridCellReference($site['owner'], $cell->path(), $reason);
                }
            }

            foreach ($this->parser->referencedRowColumns($ast) as $column) {
                $grid = $site['grid'];
                $columns = $grid === null ? [] : array_map(
                    static fn (array $c): string => (string) ($c['value'] ?? ''),
                    self::gridColumns($grid),
                );
                $at = array_search($column, $columns, true);
                if ($site['column'] !== null && ($at === false || $at >= $site['column'])) {
                    $violations[] = PublishValidationException::gridCellReference($site['owner'], '.'.$column, $at === false
                        ? 'the grid has no such column'
                        : 'a column’s rules can only read this row’s cells to its left, which are decided first');
                }
            }
        }

        return array_values(array_filter($violations));
    }

    /**
     * Why a cell cannot be named here, or null when it can. `$cell` is the parser's cell node, typed loosely so that no
     * import line moves a line this file is cited by.
     *
     * @param  object{key: string, row: string, column: ?string}  $cell
     * @param  array{owner: string, expression: ?string, grid: ?FormField, column: ?int, cells: bool}  $site
     * @param  array<string, FormField>  $fieldByKey
     */
    private function cellProblem(object $cell, array $site, array $fieldByKey): ?string
    {
        if (! $site['cells']) {
            return 'a default value is worked out before anything is answered, so it cannot read a grid cell';
        }

        $grid = $fieldByKey[$cell->key] ?? null;
        if ($grid === null || ! $grid->field_type->isComposite()) {
            return '“'.$cell->key.'” is not a grid';
        }
        if ($site['grid'] !== null && $site['grid']->key === $grid->key) {
            return 'a grid’s own rules read its cells as “This row’s …” instead';
        }

        $config = (array) $grid->config;
        if (($config['rows_list'] ?? null) !== null || ($config['rows_source'] ?? null) !== null) {
            return 'that grid’s rows come from a list or another form, so they can change when it is published again';
        }
        if ($grid->field_type === FieldType::Matrix && $cell->column === null) {
            return 'a grid’s answer is a cell, so name its row and its column';
        }
        if ($grid->field_type === FieldType::LikertMatrix && $cell->column !== null) {
            return 'a Likert grid’s answer is one score per row, so name only the row';
        }

        $row = null;
        foreach ((array) ($config['rows'] ?? []) as $candidate) {
            if (is_array($candidate) && (string) ($candidate['value'] ?? '') === $cell->row) {
                $row = $candidate;
                break;
            }
        }
        if ($row === null) {
            return 'the grid has no row “'.$cell->row.'”';
        }
        if (is_string($row['relevant'] ?? null) && trim($row['relevant']) !== '') {
            return 'its row is shown only sometimes, and a rule elsewhere may read only a cell that is always asked';
        }
        if ($cell->column === null) {
            return null;
        }

        $column = null;
        foreach (self::gridColumns($grid) as $candidate) {
            if ((string) ($candidate['value'] ?? '') === $cell->column) {
                $column = $candidate;
                break;
            }
        }
        if ($column === null) {
            return 'the grid has no column “'.$cell->column.'”';
        }
        foreach (['relevant', 'needed_when'] as $rule) {
            if (is_string($column[$rule] ?? null) && trim($column[$rule]) !== '') {
                return 'its column is not asked in every row, and a rule elsewhere may read only a cell that is always asked';
            }
        }
        if (in_array($cell->column, (array) ($row['not_needed'] ?? []), true)) {
            return 'its row marks it “Not needed”, and a rule elsewhere may read only a cell that is always asked';
        }

        return null;
    }

    /**
     * A grid's column objects, in order — read here rather than through `MatrixColumns`, so that no import line moves a
     * line this file is cited by.
     *
     * @return list<array<array-key, mixed>>
     */
    private static function gridColumns(FormField $grid): array
    {
        return array_values(array_filter((array) (((array) $grid->config)['columns'] ?? []), is_array(...)));
    }
}
