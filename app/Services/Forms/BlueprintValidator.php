<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Exceptions\Forms\FormException;
use App\Services\Xlsform\XlsformImportParser;

/**
 * Fail-closed structural validation of a schema blueprint BEFORE any row is written (Increment G9a) — the
 * same "parse/validate fully upfront" discipline {@see XlsformImportParser} applies to
 * an uploaded workbook. A blueprint reaches the materializer from a stored `form_templates.schema_blueprint`
 * (which the seed-drift test also runs through here), so this guards both platform content correctness and
 * instantiate-time defense against a corrupt/hand-edited blueprint. Throws {@see FormException::invalidBlueprint}.
 *
 * The rules mirror the invariants {@see SchemaSnapshotSerializer} guarantees on the way out: `sections`/`fields`
 * are lists, section and field `key`s are unique, every `section_key`/`related_field_key` resolves, and every
 * `field_type` is a real member of the current catalog (the catalog-drift guard, onboarding-content-plan §4).
 */
final class BlueprintValidator
{
    /**
     * @param  array<string, mixed>  $blueprint
     */
    public function validate(array $blueprint): void
    {
        $sections = $blueprint['sections'] ?? [];
        $fields = $blueprint['fields'] ?? [];

        if (! is_array($sections) || ! array_is_list($sections)) {
            throw FormException::invalidBlueprint('The blueprint "sections" must be a list.');
        }
        if (! is_array($fields) || ! array_is_list($fields)) {
            throw FormException::invalidBlueprint('The blueprint "fields" must be a list.');
        }

        $sectionKeys = [];
        foreach ($sections as $section) {
            $key = is_array($section) ? ($section['key'] ?? null) : null;
            if (! is_string($key) || $key === '') {
                throw FormException::invalidBlueprint('Every blueprint section needs a non-empty "key".');
            }
            if (isset($sectionKeys[$key])) {
                throw FormException::invalidBlueprint("Duplicate section key in blueprint: {$key}.");
            }
            $sectionKeys[$key] = true;
        }

        $fieldKeys = [];
        foreach ($fields as $field) {
            if (! is_array($field)) {
                throw FormException::invalidBlueprint('Every blueprint field must be an object.');
            }

            $key = $field['key'] ?? null;
            if (! is_string($key) || $key === '') {
                throw FormException::invalidBlueprint('Every blueprint field needs a non-empty "key".');
            }
            if (isset($fieldKeys[$key])) {
                throw FormException::invalidBlueprint("Duplicate field key in blueprint: {$key}.");
            }
            $fieldKeys[$key] = true;

            $type = $field['field_type'] ?? null;
            if (! is_string($type) || FieldType::tryFrom($type) === null) {
                throw FormException::invalidBlueprint("Unknown field_type in blueprint field \"{$key}\": ".var_export($type, true).'.');
            }

            $sectionKey = $field['section_key'] ?? null;
            if ($sectionKey !== null && ! isset($sectionKeys[$sectionKey])) {
                throw FormException::invalidBlueprint("Field \"{$key}\" references an unknown section_key: {$sectionKey}.");
            }
        }

        // Second pass: validation cross-field references resolve now that every field key is known.
        foreach ($fields as $field) {
            foreach (($field['validations'] ?? []) as $validation) {
                $relatedKey = is_array($validation) ? ($validation['related_field_key'] ?? null) : null;
                if ($relatedKey !== null && ! isset($fieldKeys[$relatedKey])) {
                    throw FormException::invalidBlueprint("A validation references an unknown related_field_key: {$relatedKey}.");
                }
            }
        }
    }
}
