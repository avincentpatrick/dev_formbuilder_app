<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Services\Expressions\ExpressionKeyRewriter;
use App\Services\Templates\TemplateParser;
use App\Support\Forms\OtherSpecify;

/**
 * M240 (`builder-copy-section`) — the one place that knows every column in which a question or a section names
 * another one BY KEY, and re-points each through an old ⇒ new map. It sets attributes and never saves; the caller
 * does. `D226`'s key rename reuses it with a one-entry map (M242, {@see KeyRenameCarrier}).
 *
 * Expressions, through {@see ExpressionKeyRewriter} (token-aware):
 * - a field's and a section's `relevant_expression`;
 * - a Calculated field's `config.calculated_formula`;
 * - `default_value`, but ONLY when `default_value_is_expression` — otherwise it is a literal answer;
 * - a validation row's `expression` (a custom constraint; `.` is the field itself and has no key).
 *
 * Templates, through {@see TemplateParser::rewriteKeys()} — exactly the columns `TemplateValidationGate` reads:
 * a field's `label`, `hint`, `placeholder` and the label and hint translations; a section's `label` and
 * `description` and their translations.
 *
 * A plain key: `config.other_specify_key`, the choice question's link to its "Please specify" partner.
 *
 * M242 — each of {@see field()}, {@see section()} and {@see validation()} returns the expression columns it could not
 * read ({@see ExpressionKeyRewriter::rewriteReporting()}), so a rename never leaves a reference behind silently. A copy
 * ignores it: its unread text is the source's, unchanged. Templates are scanned leniently and are never unread — a hole
 * the scanner cannot read is not a reference to any key. {@see confirmation()} rewrites the form's thank-you message,
 * which is FORM-level: a caller decides whether a live version reads it (`D227`).
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
     * @return list<string> the expression columns that held `${` but could not be read
     */
    public function field(FormField $field, array $map): array
    {
        if ($map === []) {
            return [];
        }

        $unread = [];
        $field->relevant_expression = $this->expression($field->relevant_expression, $map, 'relevant_expression', $unread);

        if ($field->default_value_is_expression) {
            $field->default_value = $this->expression($field->default_value, $map, 'default_value', $unread);
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
                $config['calculated_formula'] = $this->expression($config['calculated_formula'], $map, 'calculated_formula', $unread);
            }

            $partner = $config[OtherSpecify::CONFIG_KEY] ?? null;
            if (is_string($partner) && isset($map[$partner])) {
                $config[OtherSpecify::CONFIG_KEY] = $map[$partner];
            }

            // Only when it changed: an untouched config keeps its stored form, so the row is not dirtied by a round trip.
            if ($config !== $field->config) {
                $field->config = $config;
            }
        }

        return $unread;
    }

    /**
     * @param  array<string, string>  $map  old key ⇒ new key
     * @return list<string> the expression columns that held `${` but could not be read
     */
    public function section(FormSection $section, array $map): array
    {
        if ($map === []) {
            return [];
        }

        $unread = [];
        $section->relevant_expression = $this->expression($section->relevant_expression, $map, 'relevant_expression', $unread);
        $section->label = $this->template($section->label, $map) ?? '';
        $section->description = $this->template($section->description, $map);
        $section->label_translations = $this->translations($section->label_translations, $map);
        $section->description_translations = $this->translations($section->description_translations, $map);

        return $unread;
    }

    /**
     * @param  array<string, string>  $map  old key ⇒ new key
     * @return list<string> the expression columns that held `${` but could not be read
     */
    public function validation(FormFieldValidation $rule, array $map): array
    {
        if ($map === []) {
            return [];
        }

        $unread = [];
        $rule->expression = $this->expression($rule->expression, $map, 'expression', $unread);

        return $unread;
    }

    /**
     * M242 (`D227`) — the form's thank-you message and its translations, rewritten; null when nothing in them changes.
     * It never writes: the message's one writer is {@see FormService::setConfirmationMessage()}, which audits it.
     *
     * @param  array<string, string>  $map  old key ⇒ new key
     * @return array{message: ?string, translations: ?array<string, string>}|null
     */
    public function confirmation(Form $form, array $map): ?array
    {
        if ($map === []) {
            return null;
        }

        $message = $this->template($form->confirmation_message, $map);
        /** @var array<string, string>|null $translations */
        $translations = $this->translations($form->confirmation_message_translations, $map);

        if ($message === $form->confirmation_message && $translations === $form->confirmation_message_translations) {
            return null;
        }

        return ['message' => $message, 'translations' => $translations];
    }

    /**
     * @param  array<string, string>  $map
     * @param  list<string>  $unread
     */
    private function expression(?string $text, array $map, string $column, array &$unread): ?string
    {
        [$out, $skipped] = $this->expressions->rewriteReporting($text, $map);

        if ($skipped) {
            $unread[] = $column;
        }

        return $out;
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
