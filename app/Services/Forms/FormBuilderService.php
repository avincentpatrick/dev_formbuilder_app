<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Exceptions\Forms\BuilderConflictException;
use App\Exceptions\Forms\FormException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The interactive builder's fine-grained mutation surface (Increment D4a; form-versioning-schema-migration
 * §3/§8). Every operation edits the form's CURRENT DRAFT version only — the draft_child RLS guard is the DB
 * backstop, and {@see self::assertDraftChild()} is the clean service-level guard that turns a write against a
 * published version into a 403 instead of a silent zero-row write.
 *
 * Concurrency model (§8):
 *  - CONTENT edits (updateField/updateSection) are optimistic: the client echoes the `updated_at` token it
 *    last read; a drift throws {@see BuilderConflictException} (→ 409) carrying the fresh row.
 *  - STRUCTURAL edits (add/delete/duplicate/reorder) are serialized by a `forms`-row FOR UPDATE lock and
 *    carry no token. Reorder writes via the query builder so it does NOT bump `updated_at` — a positional
 *    change must not spuriously 409 the next content PATCH on a moved row.
 *
 * Expressions (`relevant_expression`, validation `expression`) are persisted UNVALIDATED — the expression
 * engine is the deferred ADR-0004 work.
 */
final class FormBuilderService
{
    /** Microsecond-precision optimistic-concurrency token. The client always echoes back the exact string. */
    private const VERSION_FORMAT = 'Y-m-d\TH:i:s.uP';

    /** The optimistic-concurrency token for a content row (its `updated_at`, microsecond precision). */
    public static function rowVersion(Model $row): ?string
    {
        $updatedAt = $row->getAttribute('updated_at');

        return $updatedAt instanceof Carbon ? $updatedAt->format(self::VERSION_FORMAT) : null;
    }

    // ── Sections ────────────────────────────────────────────────────────────────

    public function addSection(Form $form): FormSection
    {
        return DB::transaction(function () use ($form): FormSection {
            $draft = $this->lockDraft($form);

            return FormSection::create([
                'form_version_id' => $draft->id,
                'key' => $this->uniqueKey($draft, 'section', 'form_sections'),
                'label' => 'New section',
                'sequence' => $this->nextSectionSequence($draft),
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateSection(Form $form, FormSection $section, array $data, ?string $expectedVersion): FormSection
    {
        $this->assertDraftChild($form, $section);
        $this->assertNoDrift($section, $expectedVersion);

        $section->fill([
            'key' => $data['key'],
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'is_repeatable' => (bool) ($data['is_repeatable'] ?? false),
            'min_instances' => $data['min_instances'] ?? null,
            'max_instances' => $data['max_instances'] ?? null,
            'relevant_expression' => $data['relevant_expression'] ?? null,
        ])->save();

        return $section->refresh();
    }

    public function deleteSection(Form $form, FormSection $section): void
    {
        DB::transaction(function () use ($form, $section): void {
            $this->lockDraft($form);
            $this->assertDraftChild($form, $section);
            // The FK is ON DELETE SET NULL — the section's fields become ungrouped, not deleted.
            $section->delete();
        });
    }

    // ── Fields ──────────────────────────────────────────────────────────────────

    public function addField(Form $form, User $user, FieldType $type, ?string $sectionId): FormField
    {
        return DB::transaction(function () use ($form, $user, $type, $sectionId): FormField {
            $draft = $this->lockDraft($form);
            $section = $this->resolveDraftSectionId($draft, $sectionId);

            return FormField::create([
                'form_version_id' => $draft->id,
                'form_section_id' => $section,
                'key' => $this->uniqueKey($draft, 'field', 'form_fields'),
                'field_type' => $type,
                'label' => $type->label(),
                'is_required' => RequiredMode::Optional,
                'config' => $this->defaultConfig($type),
                'sequence' => $this->nextFieldSequence($draft),
                'created_by' => $user->id,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateField(Form $form, FormField $field, User $user, array $data, ?string $expectedVersion): FormField
    {
        $this->assertDraftChild($form, $field);
        $this->assertNoDrift($field, $expectedVersion);

        return DB::transaction(function () use ($field, $user, $data): FormField {
            $field->fill([
                'key' => $data['key'],
                'label' => $data['label'],
                'hint' => $data['hint'] ?? null,
                'placeholder' => $data['placeholder'] ?? null,
                'is_required' => RequiredMode::from((string) $data['is_required']),
                'relevant_expression' => $data['relevant_expression'] ?? null,
                'appearance' => $data['appearance'] ?? null,
                'config' => $data['config'] ?? [],
                'default_value' => $data['default_value'] ?? null,
                'is_pii' => (bool) ($data['is_pii'] ?? false),
                'is_sensitive' => (bool) ($data['is_sensitive'] ?? false),
                'is_queryable' => (bool) ($data['is_queryable'] ?? false),
                'indexed_data_type' => ($data['is_queryable'] ?? false) ? ($data['indexed_data_type'] ?? null) : null,
                'updated_by' => $user->id,
            ])->save();

            $this->replaceValidations($field, $data['validations'] ?? []);

            return $field->refresh();
        });
    }

    public function deleteField(Form $form, FormField $field): void
    {
        DB::transaction(function () use ($form, $field): void {
            $this->lockDraft($form);
            $this->assertDraftChild($form, $field);
            $field->delete(); // cascades its validations
        });
    }

    public function duplicateField(Form $form, User $user, FormField $field): FormField
    {
        return DB::transaction(function () use ($form, $user, $field): FormField {
            $draft = $this->lockDraft($form);
            $this->assertDraftChild($form, $field);

            $copy = $field->replicate(['created_by', 'updated_by']);
            $copy->key = $this->copyKey($draft, $field->key);
            $copy->label = $field->label.' (copy)';
            $copy->sequence = $this->nextFieldSequence($draft);
            $copy->created_by = $user->id;
            $copy->updated_by = null;
            $copy->save();

            // Copy the source field's validation rows onto the new field (same version, new field id).
            foreach ($field->validations()->orderBy('sequence')->get() as $validation) {
                $newValidation = $validation->replicate();
                $newValidation->form_field_id = $copy->id;
                // A field→field comparison that pointed at the source keeps pointing at the same sibling.
                $newValidation->save();
            }

            return $copy->refresh();
        });
    }

    // ── Reorder (structural; serialized by the form-row lock, does NOT bump updated_at) ───────────

    /**
     * @param  list<array{id: string, sequence: int}>  $sections
     * @param  list<array{id: string, form_section_id: ?string, sequence: int, section_sequence: ?int}>  $fields
     */
    public function reorder(Form $form, array $sections, array $fields): void
    {
        DB::transaction(function () use ($form, $sections, $fields): void {
            $draft = $this->lockDraft($form);
            $validSections = FormSection::query()->where('form_version_id', $draft->id)->pluck('id')->flip();

            foreach ($sections as $row) {
                FormSection::query()->whereKey($row['id'])->where('form_version_id', $draft->id)
                    ->update(['sequence' => $row['sequence']]);
            }

            foreach ($fields as $row) {
                $targetSection = $row['form_section_id'];
                if ($targetSection !== null && ! $validSections->has($targetSection)) {
                    $targetSection = null; // never let a field point at a section from another version
                }

                FormField::query()->whereKey($row['id'])->where('form_version_id', $draft->id)->update([
                    'form_section_id' => $targetSection,
                    'sequence' => $row['sequence'],
                    'section_sequence' => $row['section_sequence'],
                ]);
            }
        });
    }

    // ── Guards & helpers ──────────────────────────────────────────────────────────

    /** Lock the form row (serializes structural edits, §3.4) and return its editable draft version. */
    private function lockDraft(Form $form): FormVersion
    {
        $locked = Form::query()->whereKey($form->id)->lockForUpdate()->firstOrFail();

        if ($locked->draft_version_id === null) {
            throw FormException::formHasNoDraft();
        }

        return FormVersion::query()->whereKey($locked->draft_version_id)->firstOrFail();
    }

    /** A section/field must belong to the form's current draft version — else it is immutable (§2/§4). */
    private function assertDraftChild(Form $form, FormSection|FormField $child): void
    {
        if ($form->draft_version_id === null || $child->form_version_id !== $form->draft_version_id) {
            throw FormException::childNotInDraft();
        }
    }

    private function assertNoDrift(FormSection|FormField $row, ?string $expectedVersion): void
    {
        if ($expectedVersion !== null && self::rowVersion($row) !== $expectedVersion) {
            throw new BuilderConflictException($row);
        }
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function replaceValidations(FormField $field, array $rows): void
    {
        $field->validations()->delete();

        $siblingIdByKey = FormField::query()
            ->where('form_version_id', $field->form_version_id)
            ->pluck('id', 'key');

        foreach ($rows as $index => $row) {
            $relatedKey = $row['related_field_key'] ?? null;

            FormFieldValidation::create([
                'form_version_id' => $field->form_version_id,
                'form_field_id' => $field->id,
                'related_form_field_id' => $relatedKey !== null ? ($siblingIdByKey[$relatedKey] ?? null) : null,
                'rule_type' => $row['rule_type'] ?? null,
                'operator' => $row['operator'] ?? null,
                'rule_value' => $row['rule_value'] ?? null,
                'expression' => $row['expression'] ?? null,
                'error_message' => $row['error_message'] ?? null,
                'sequence' => $index,
            ]);
        }
    }

    private function resolveDraftSectionId(FormVersion $draft, ?string $sectionId): ?string
    {
        if ($sectionId === null) {
            return null;
        }

        return FormSection::query()->whereKey($sectionId)->where('form_version_id', $draft->id)->exists()
            ? $sectionId
            : null;
    }

    /** @return array<string, mixed> */
    private function defaultConfig(FieldType $type): array
    {
        return match (true) {
            $type === FieldType::CascadingSelect => ['levels' => [], 'options' => []],
            $type->hasOptions() => ['options' => []],
            default => [],
        };
    }

    private function nextFieldSequence(FormVersion $draft): int
    {
        return (int) FormField::query()->where('form_version_id', $draft->id)->max('sequence') + 1;
    }

    private function nextSectionSequence(FormVersion $draft): int
    {
        return (int) FormSection::query()->where('form_version_id', $draft->id)->max('sequence') + 1;
    }

    /** A stable slug unique within the draft version (the composite unique index enforces it too). */
    private function uniqueKey(FormVersion $draft, string $base, string $table): string
    {
        $taken = DB::table($table)->where('form_version_id', $draft->id)->pluck('key')->flip();

        $n = 1;
        while ($taken->has($candidate = "{$base}_{$n}")) {
            $n++;
        }

        return $candidate;
    }

    private function copyKey(FormVersion $draft, string $sourceKey): string
    {
        $taken = FormField::query()->where('form_version_id', $draft->id)->pluck('key')->flip();

        $candidate = "{$sourceKey}_copy";
        $n = 2;
        while ($taken->has($candidate)) {
            $candidate = "{$sourceKey}_copy_{$n}";
            $n++;
        }

        return $candidate;
    }
}
