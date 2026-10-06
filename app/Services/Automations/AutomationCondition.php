<?php

declare(strict_types=1);

namespace App\Services\Automations;

use App\Enums\ExpressionKind;
use App\Enums\FieldType;
use App\Exceptions\Expressions\ExpressionException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Services\Expressions\EvaluationContext;
use App\Services\Expressions\ExpressionEvaluator;
use App\Services\Expressions\ExpressionParser;
use App\Services\Submissions\SchemaValueFormatter;
use Illuminate\Validation\ValidationException;

/**
 * A form automation's condition (M142, `R-b65bafca`'s Filter, `D93` = A step 1): the automation runs only for a
 * response that matches it. Written in the form's own condition grammar — a question's show-if — so the builder's
 * condition editor writes it and the server's evaluator reads it.
 *
 * ── CHECKED ON SAVE AGAINST ONE VERSION, READ AT RUN TIME AGAINST THE RESPONSE'S ────────────────────────────────
 * An automation belongs to the FORM and an answer to a VERSION. On save the condition must parse and name only questions
 * of the form's PUBLISHED version — or its draft, before the first publish — the same version whose questions the panel
 * offers ({@see self::catalogueFor()}), so the builder and the hub agree (`D63`). At run time it is evaluated against the
 * response's stored answers, which are already relevance-pruned (`SubmissionPipeline`), the input the evaluator expects.
 * A question a later version drops reads as empty there — the evaluator's documented reading of an unknown key.
 */
final class AutomationCondition
{
    /** Numbers compare as numbers in the condition editor; the builder's `ConfigPanel` marks the same four. */
    private const array NUMERIC = [FieldType::Integer, FieldType::Decimal, FieldType::Calculated, FieldType::LikertScale];

    public function __construct(
        private readonly ExpressionParser $parser,
        private readonly ExpressionEvaluator $evaluator,
        private readonly SchemaValueFormatter $formatter,
    ) {}

    /**
     * The condition as stored: trimmed, or null for none.
     *
     * @throws ValidationException under `condition`, with the reason, when it does not parse or names an unknown question
     */
    public function normalize(Form $form, ?string $condition): ?string
    {
        $condition = $condition === null ? '' : trim($condition);

        if ($condition === '') {
            return null;
        }

        $known = [];
        foreach ($this->fieldsOf($form) as $field) {
            $known[$field->key] = true;
        }
        foreach ($this->sectionsOf($form) as $section) {
            $known[$section->key] = true;
        }

        try {
            $this->parser->assertReferencesResolve($this->parser->parse($condition), $known, ExpressionKind::Relevant);
        } catch (ExpressionException $e) {
            throw ValidationException::withMessages(['condition' => ['This condition can’t be used: '.$e->getMessage()]]);
        }

        return $condition;
    }

    /**
     * Does this response match the condition? `$now` is the response's own time, for `today()` and `now()`.
     *
     * @param  array<string, mixed>  $answers  the response's stored, relevance-pruned answers
     *
     * @throws ExpressionException when the condition cannot be read for these answers
     */
    public function matches(string $condition, array $answers, ?string $now): bool
    {
        return $this->evaluator->evaluateBoolean($condition, new EvaluationContext($answers, now: $now));
    }

    /**
     * The questions a condition may name, as the condition editor offers them (`ConditionCatalogue`): every question
     * that holds an answer, with its choices where it has a short list, and the repeating sections `count()` reads.
     *
     * @return array{fields: list<array{key: string, label: string, numeric: bool, options: list<array{value: string, label: string}>}>, repeatables: list<array{key: string, label: string}>}
     */
    public function catalogueFor(Form $form): array
    {
        $fields = [];
        foreach ($this->fieldsOf($form) as $field) {
            $type = $field->field_type;

            if (! $this->formatter->isDataField($type) || $type->isComposite() || $type->isGeo() || $type->isMedia()) {
                continue;
            }

            $options = $type->hasOptions() ? $this->formatter->options((array) $field->config) : [];

            $fields[] = [
                'key' => $field->key,
                'label' => $field->label !== '' ? $field->label : $field->key,
                'numeric' => in_array($type, self::NUMERIC, true),
                'options' => $options,
            ];
        }

        $repeatables = [];
        foreach ($this->sectionsOf($form) as $section) {
            if ($section->is_repeatable) {
                $repeatables[] = ['key' => $section->key, 'label' => $section->label !== '' ? $section->label : $section->key];
            }
        }

        return ['fields' => $fields, 'repeatables' => $repeatables];
    }

    /** The version a condition is written against: the published one, or the draft before the first publish. */
    private function versionIdOf(Form $form): ?string
    {
        return $form->current_published_version_id ?? $form->draft_version_id;
    }

    /** @return list<FormField> */
    private function fieldsOf(Form $form): array
    {
        $version = $this->versionIdOf($form);

        return $version === null ? [] : array_values(FormField::query()->where('form_version_id', $version)->orderBy('sequence')->orderBy('key')->get()->all());
    }

    /** @return list<FormSection> */
    private function sectionsOf(Form $form): array
    {
        $version = $this->versionIdOf($form);

        return $version === null ? [] : array_values(FormSection::query()->where('form_version_id', $version)->orderBy('sequence')->get()->all());
    }
}
