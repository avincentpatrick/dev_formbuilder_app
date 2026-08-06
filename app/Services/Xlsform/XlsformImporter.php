<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Enums\FormStatus;
use App\Exceptions\Forms\FormException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\FormBuilderService;
use App\Services\Forms\PublishService;
use App\Services\Forms\RestoreService;
use App\Services\Forms\SchemaTreeCloner;
use App\Services\Xlsform\Dto\ImportPlan;
use App\Services\Xlsform\Dto\SettingsSpec;
use App\Services\Xlsform\Dto\XlsformImportResult;
use App\Support\Forms\FormSlug;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Imports an XLSForm `.xlsx` into an EXISTING form's current draft (Increment G7b /
 * docs/xlsform-interop-spec.md §5) — never creates a form. Reads + parses the workbook FIRST (upfront,
 * outside the transaction, so a bad file throws before anything is mutated), then destructively replaces the
 * draft's content exactly like {@see RestoreService}: lock the form, delete
 * validations → fields → sections, repopulate with EXPLICIT keys (never {@see FormBuilderService},
 * which mints synthetic keys) and a key→id FK remap.
 *
 * Import leaves an ordinary draft — it runs NO publish gates. The author reviews it and publishes through
 * the unchanged {@see PublishService}, where the structural + expression gates fire.
 */
final class XlsformImporter
{
    public function __construct(
        private readonly XlsformWorkbookReader $reader,
        private readonly XlsformImportParser $parser,
    ) {}

    public function import(Form $form, UploadedFile $file, User $user): XlsformImportResult
    {
        // Read + validate FULLY UPFRONT — may throw XlsformImportException before any DB mutation (§6).
        $plan = $this->parser->parse($this->reader->read($file->getRealPath() ?: $file->getPathname()));

        return DB::transaction(function () use ($form, $plan, $user): XlsformImportResult {
            // Same form-row lock as the other lifecycle transitions (§3.4).
            $locked = Form::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === FormStatus::Archived) {
                throw FormException::cannotImportOntoArchivedForm();
            }
            if ($locked->draft_version_id === null) {
                throw FormException::noDraftToPublish();
            }

            $draft = FormVersion::query()->whereKey($locked->draft_version_id)->firstOrFail();

            // Clear the current draft's content (validations → fields → sections), then repopulate.
            $draft->validations()->delete();
            $draft->fields()->delete();
            $draft->sections()->delete();

            $validationCount = $this->repopulate($draft, $plan, $user);
            $this->applySettings($locked, $draft, $plan->settings);

            return new XlsformImportResult(
                sectionCount: count($plan->sections),
                fieldCount: count($plan->fields),
                validationCount: $validationCount,
                warnings: $plan->warnings,
            );
        });
    }

    /**
     * Recreate sections → fields → validations with explicit keys (the {@see SchemaTreeCloner}
     * shape), remapping FKs by key→newId. Returns the validation-row count written.
     */
    private function repopulate(FormVersion $draft, ImportPlan $plan, User $user): int
    {
        $sectionIdByKey = [];
        foreach ($plan->sections as $section) {
            $row = FormSection::create([
                'form_version_id' => $draft->id,
                'key' => $section->key,
                'label' => $section->label,
                'label_translations' => $section->labelTranslations,
                'description' => $section->description,
                'description_translations' => $section->descriptionTranslations,
                'sequence' => $section->sequence,
                'is_repeatable' => $section->isRepeatable,
                'min_instances' => $section->minInstances,
                'max_instances' => $section->maxInstances,
                'relevant_expression' => $section->relevantExpression,
            ]);
            $sectionIdByKey[$section->key] = $row->id;
        }

        $fieldIdByKey = [];
        foreach ($plan->fields as $field) {
            $row = FormField::create([
                'form_version_id' => $draft->id,
                'form_section_id' => $field->sectionKey !== null ? ($sectionIdByKey[$field->sectionKey] ?? null) : null,
                'key' => $field->key,
                'field_type' => $field->fieldType,
                'config' => $field->config,
                'label' => $field->label,
                'label_translations' => $field->labelTranslations,
                'hint' => $field->hint,
                'hint_translations' => $field->hintTranslations,
                'placeholder' => $field->placeholder,
                'default_value' => $field->defaultValue,
                'default_value_is_expression' => $field->defaultValueIsExpression,
                'is_required' => $field->isRequired,
                'relevant_expression' => $field->relevantExpression,
                'appearance' => $field->appearance,
                'sequence' => $field->sequence,
                'section_sequence' => $field->sectionSequence,
                'created_by' => $user->id,
            ]);
            $fieldIdByKey[$field->key] = $row->id;
        }

        $validationCount = 0;
        foreach ($plan->fields as $field) {
            $fieldId = $fieldIdByKey[$field->key];
            foreach ($field->validations as $validation) {
                FormFieldValidation::create([
                    'form_version_id' => $draft->id,
                    'form_field_id' => $fieldId,
                    'expression' => $validation->expression,
                    'error_message' => $validation->errorMessage,
                    'error_message_translations' => $validation->errorMessageTranslations,
                    'sequence' => $validation->sequence,
                ]);
                $validationCount++;
            }
        }

        return $validationCount;
    }

    /**
     * Map the `settings` sheet onto `forms.*` (and keep the draft version's denormalized `title` in sync).
     * The product's versioning model owns `version_number` — settings never touches it (§4).
     */
    private function applySettings(Form $form, FormVersion $draft, SettingsSpec $settings): void
    {
        $changes = [];

        if ($settings->formTitle !== null && $settings->formTitle !== '') {
            $changes['title'] = $settings->formTitle;
            $draft->forceFill(['title' => $settings->formTitle])->save();
        }

        if ($settings->defaultLanguage !== null && $settings->defaultLanguage !== '') {
            $changes['default_locale'] = $settings->defaultLanguage;
        }

        $slug = $this->resolveSlug($form, $settings);
        if ($slug !== null) {
            $changes['public_slug'] = $slug;
        }

        if ($changes !== []) {
            $form->forceFill($changes)->save();
        }
    }

    /**
     * The `public_slug` to write, or null to leave it untouched: an explicit `form_id` (slugged + de-duped
     * against the per-tenant unique index), or a title-derived fallback only when the form has none yet.
     *
     * Normalization and the de-collision loop moved to {@see FormSlug} in Increment I1, so the importer and
     * the share surface cannot disagree about which suffix is free — and so both inherit that helper's
     * `withTrashed()` predicate, which matches the unique index a soft-deleted form still occupies.
     */
    private function resolveSlug(Form $form, SettingsSpec $settings): ?string
    {
        if ($settings->formId !== null && $settings->formId !== '') {
            $source = $settings->formId;
        } elseif ($form->public_slug === null || $form->public_slug === '') {
            $source = $settings->formTitle ?? $form->title;
        } else {
            return null; // keep the form's existing slug when the file names none
        }

        // A `form_id` that normalizes to nothing (all punctuation, say) still deserves the title fallback the
        // pre-I1 code gave it — FormSlug::suggest's own 'form' default is the last resort behind that.
        if (FormSlug::normalize($source) === null) {
            $source = $settings->formTitle ?? $form->title;
        }

        return FormSlug::suggest($form, $source);
    }
}
