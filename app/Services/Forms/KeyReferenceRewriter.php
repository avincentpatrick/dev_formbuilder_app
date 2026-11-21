<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Services\Expressions\ExpressionKeyRewriter;
use App\Services\Templates\TemplateParser;
use App\Support\Forms\GridRules;
use App\Support\Forms\OtherSpecify;

/**
 * M240 (`builder-copy-section`) — the one place that knows every column in which a question or a section names
 * another one BY KEY, and re-points each through an old ⇒ new map. It sets attributes and never saves; the caller
 * does. `D226`'s key rename reuses it with a one-entry map.
 *
 * Expressions, through {@see ExpressionKeyRewriter} (token-aware):
 * - a field's and a section's `relevant_expression`;
 * - a Calculated field's `config.calculated_formula`;
 * - `default_value`, but ONLY when `default_value_is_expression` — otherwise it is a literal answer;
 * - a validation row's `expression` (a custom constraint; `.` is the field itself and has no key);
 * - M246: a grid's own rules in `config` ({@see GridRules}) — its rows' and columns' conditions.
 *
 * Templates, through {@see TemplateParser::rewriteKeys()} — exactly the columns `TemplateValidationGate` reads:
 * a field's `label`, `hint`, `placeholder` and the label and hint translations; a section's `label` and
 * `description` and their translations.
 *
 * A plain key: `config.other_specify_key`, the choice question's link to its "Please specify" partner.
 *
 * NOT here, deliberately: `related_form_field_id` names a question by ID, so a copy re-points it through an id map
 * (and a rename needs nothing); a linked-choice `{form_id, field_key}` names another FORM and is refused for this one
 * (`LinkedChoiceService`); option labels, note content, grid rows and error messages are not piped.
 */
final class KeyReferenceRewriter
{
    public function __construct(
        private readonly ExpressionKeyRewriter $expressions = new ExpressionKeyRewriter,
        private readonly TemplateParser $templates = new TemplateParser,
    ) {}

    /**
     * @param  array<string, string>  $map  old key ⇒ new key
     */
    public function field(FormField $field, array $map): void
    {
        if ($map === []) {
            return;
        }

        $field->relevant_expression = $this->expressions->rewrite($field->relevant_expression, $map);

        if ($field->default_value_is_expression) {
            $field->default_value = $this->expressions->rewrite($field->default_value, $map);
        }

        $field->label = $this->template($field->label, $map) ?? '';
        $field->hint = $this->template($field->hint, $map);
        $field->placeholder = $this->template($field->placeholder, $map);
        $field->label_translations = $this->translations($field->label_translations, $map);
        $field->hint_translations = $this->translations($field->hint_translations, $map);

        /** @var array<string, mixed>|null $config */
        $config = $field->config;

        if (is_array($config)) {
            if (isset($config['calculated_formula']) && is_string($config['calculated_formula'])) {
                $config['calculated_formula'] = $this->expressions->rewrite($config['calculated_formula'], $map);
            }

            $partner = $config[OtherSpecify::CONFIG_KEY] ?? null;
            if (is_string($partner) && isset($map[$partner])) {
                $config[OtherSpecify::CONFIG_KEY] = $map[$partner];
            }

            // M246: a grid's own rules — its rows' and columns' conditions — name other questions too.
            $config = GridRules::mapExpressions($field->field_type, $config, fn (string $expression): ?string => $this->expressions->rewrite($expression, $map));

            $field->config = $config;
        }
    }

    /**
     * @param  array<string, string>  $map  old key ⇒ new key
     */
    public function section(FormSection $section, array $map): void
    {
        if ($map === []) {
            return;
        }

        $section->relevant_expression = $this->expressions->rewrite($section->relevant_expression, $map);
        $section->label = $this->template($section->label, $map) ?? '';
        $section->description = $this->template($section->description, $map);
        $section->label_translations = $this->translations($section->label_translations, $map);
        $section->description_translations = $this->translations($section->description_translations, $map);
    }

    /**
     * @param  array<string, string>  $map  old key ⇒ new key
     */
    public function validation(FormFieldValidation $rule, array $map): void
    {
        if ($map === []) {
            return;
        }

        $rule->expression = $this->expressions->rewrite($rule->expression, $map);
    }

    /**
     * @param  array<string, string>  $map
     */
    private function template(?string $text, array $map): ?string
    {
        return $text === null ? null : $this->templates->rewriteKeys($text, $map);
    }

    /**
     * @param  array<string, mixed>|null  $translations  locale ⇒ text
     * @param  array<string, string>  $map
     * @return array<string, mixed>|null
     */
    private function translations(?array $translations, array $map): ?array
    {
        if ($translations === null) {
            return null;
        }

        foreach ($translations as $locale => $text) {
            if (is_string($text)) {
                $translations[$locale] = $this->templates->rewriteKeys($text, $map);
            }
        }

        return $translations;
    }
}
