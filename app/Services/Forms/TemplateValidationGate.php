<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\PipingEligibility;
use App\Exceptions\Forms\PublishValidationException;
use App\Exceptions\Templates\TemplateSyntaxException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Services\Templates\TemplateParser;
use App\Services\Templates\TemplateScopeResolver;
use Illuminate\Support\Collection;

/**
 * The pre-publish TEMPLATE gate (`docs/piping-output-encoding-design.md` §6; Increment H6a) — the THIRD
 * gate in `PublishService` step 1, beside {@see StructuralValidationGate} (shape) and
 * {@see ExpressionValidationGate} (expressions). It checks that every template-bearing value parses under
 * `TemplateParser::TEMPLATE_VERSION` and that every hole in it resolves to a pipeable field the host may
 * legally reference, so a broken or dangling `${key}` is refused at PUBLISH (naming the field) rather than
 * rendering as a permanent blank on a respondent's form.
 *
 * It runs BEFORE the step 3+4 snapshot freeze, alongside the other two gates. Be precise about what that
 * buys: the whole publish is one `DB::transaction`, so a rejected template could not reach a COMMITTED
 * `schema_snapshot` from anywhere inside it — the transaction, not the ordering, is what guarantees the
 * outcome (Doc #26 §6 as amended by H6a; proven by mutation, since moving this call below the freeze
 * leaves every gate test green). What the ordering actually buys is that step 1 stays the single
 * validation phase and a doomed publish is never serialized. Nothing between the gate calls and the freeze
 * mutates draft content, so the placement is free.
 *
 * ── The nine template-bearing columns (§6's closed list for v1.0) ───────────────────────────────────
 * `form_fields.label` / `label_translations` / `hint` / `hint_translations` / `placeholder`,
 * `form_sections.label` / `label_translations` / `description` / `description_translations`, plus
 * `forms.confirmation_message` / `confirmation_message_translations`.
 *
 * `form_fields.default_value` is deliberately ABSENT: it already has an expression mode
 * (`default_value_is_expression`), and giving one column two sub-grammars is how ambiguity gets built in.
 * So are choice-option labels inside `form_fields.config.options[]` and
 * `form_field_validations.error_message` — both are respondent-facing text, both are excluded explicitly
 * rather than by oversight (§8).
 *
 * ── Multi-locale (§4) ───────────────────────────────────────────────────────────────────────────────
 * Each locale variant is independently a template and is independently validated; reference-set PARITY
 * across locales is NOT required, because different grammars legitimately need different references. The
 * enforceable rule is only that every hole in every variant resolves.
 *
 * ── Why it reads live rows rather than the snapshot ─────────────────────────────────────────────────
 * The snapshot does not exist yet at step 1 — that is the whole point of running before the freeze — so
 * this walks `$version->fields()`/`->sections()`, exactly as {@see ExpressionValidationGate} does.
 *
 * Parse/scope failures are re-wrapped into {@see PublishValidationException} so the existing
 * web-toast / api-422 surfacings fire, with the stable snake_case slug as the detail.
 */
final class TemplateValidationGate
{
    public function __construct(
        private readonly TemplateParser $parser,
        private readonly TemplateScopeResolver $scope,
    ) {}

    /**
     * @param  Form  $form  the LOCKED form row — `forms.confirmation_message` is a form-level column, so
     *                      it is not frozen per version and its holes are validated against the version
     *                      being PUBLISHED (not the outgoing one, which is about to be superseded)
     *
     * @throws PublishValidationException
     */
    public function assertTemplatesResolve(FormVersion $version, Form $form): void
    {
        /** @var Collection<int, FormField> $fields */
        $fields = $version->fields()->get();
        /** @var Collection<int, FormSection> $sections */
        $sections = $version->sections()->get();

        [$sources, $sectionKeys] = $this->index($fields, $sections);
        $sectionById = $sections->keyBy('id');

        foreach ($fields as $field) {
            $section = $field->form_section_id !== null ? $sectionById->get($field->form_section_id) : null;
            $position = TemplateScopeResolver::fieldPosition($section?->sequence, $field->sequence);
            $repeat = $section !== null && $section->is_repeatable ? $section->key : null;

            $this->checkAll($field->label, $field->label_translations, $position, $repeat, $sources, $sectionKeys, $field->key, 'label');
            $this->checkAll($field->hint, $field->hint_translations, $position, $repeat, $sources, $sectionKeys, $field->key, 'hint');
            $this->checkAll($field->placeholder, null, $position, $repeat, $sources, $sectionKeys, $field->key, 'placeholder');
        }

        foreach ($sections as $section) {
            $position = TemplateScopeResolver::sectionPosition($section->sequence);
            $repeat = $section->is_repeatable ? $section->key : null;

            $this->checkAll($section->label, $section->label_translations, $position, $repeat, $sources, $sectionKeys, $section->key, 'label');
            $this->checkAll($section->description, $section->description_translations, $position, $repeat, $sources, $sectionKeys, $section->key, 'description');
        }

        // The confirmation screen (§6.2) renders AFTER the whole form, so every field precedes it — hence
        // the maximal position — and it is never inside a repeat instance, hence flat scope.
        $this->checkAll(
            $form->confirmation_message,
            $form->confirmation_message_translations,
            [PHP_INT_MAX, PHP_INT_MAX],
            null,
            $sources,
            $sectionKeys,
            'confirmation_message',
            'confirmation_message',
        );
    }

    /**
     * Build the source map (key ⇒ verdict + position + repeat scope) and the section-key set, once.
     *
     * @param  Collection<int, FormField>  $fields
     * @param  Collection<int, FormSection>  $sections
     * @return array{0: array<string, array{type: PipingEligibility, position: array{int, int}, repeat: ?string}>, 1: array<string, true>}
     */
    private function index(Collection $fields, Collection $sections): array
    {
        $sectionById = $sections->keyBy('id');

        /** @var array<string, true> $sectionKeys */
        $sectionKeys = [];
        foreach ($sections as $section) {
            $sectionKeys[$section->key] = true;
        }

        /** @var array<string, array{type: PipingEligibility, position: array{int, int}, repeat: ?string}> $sources */
        $sources = [];
        foreach ($fields as $field) {
            $section = $field->form_section_id !== null ? $sectionById->get($field->form_section_id) : null;

            $sources[$field->key] = [
                'type' => PipingEligibility::for($field->field_type),
                'position' => TemplateScopeResolver::fieldPosition($section?->sequence, $field->sequence),
                'repeat' => $section !== null && $section->is_repeatable ? $section->key : null,
            ];
        }

        return [$sources, $sectionKeys];
    }

    /**
     * Validate a base value and every locale variant of it — each independently a template (§4).
     *
     * @param  array<string, mixed>|null  $translations
     * @param  array{int, int}  $position
     * @param  array<string, array{type: PipingEligibility, position: array{int, int}, repeat: ?string}>  $sources
     * @param  array<string, true>  $sectionKeys
     */
    private function checkAll(
        ?string $base,
        ?array $translations,
        array $position,
        ?string $repeat,
        array $sources,
        array $sectionKeys,
        string $ownerKey,
        string $column,
    ): void {
        $this->check($base, $position, $repeat, $sources, $sectionKeys, $ownerKey, $column);

        foreach ($translations ?? [] as $locale => $variant) {
            if (! is_string($variant)) {
                continue; // a non-string variant is a shape problem, not a template one — §8 records it
            }

            $this->check($variant, $position, $repeat, $sources, $sectionKeys, $ownerKey, $column.'['.$locale.']');
        }
    }

    /**
     * @param  array{int, int}  $position
     * @param  array<string, array{type: PipingEligibility, position: array{int, int}, repeat: ?string}>  $sources
     * @param  array<string, true>  $sectionKeys
     */
    private function check(
        ?string $template,
        array $position,
        ?string $repeat,
        array $sources,
        array $sectionKeys,
        string $ownerKey,
        string $column,
    ): void {
        if ($template === null || trim($template) === '') {
            return; // blank = no template
        }

        try {
            $keys = $this->parser->holeKeys($template);
        } catch (TemplateSyntaxException $exception) {
            throw PublishValidationException::templateInvalid($ownerKey, $column, $exception->slug());
        }

        foreach ($keys as $key) {
            $violation = $this->scope->violationFor($key, $position, $repeat, $sources, $sectionKeys);

            if ($violation !== null) {
                throw PublishValidationException::templateInvalid($ownerKey, $column, $violation);
            }
        }
    }
}
