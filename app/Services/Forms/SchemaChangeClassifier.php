<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\RequiredMode;
use App\Models\FormField;
use App\Models\FormVersion;

/**
 * Diffs a draft version's fields/sections (by stable `key`) against the currently-published version — or
 * against nothing, for a first-ever publish (form-versioning-schema-migration.md §5). The classification
 * is recorded on every publish (feeding `change_summary`); Phase 1 does not block or warn on it.
 */
final class SchemaChangeClassifier
{
    public function classify(FormVersion $draft, ?FormVersion $published): SchemaChangeClassification
    {
        if ($published === null) {
            return new SchemaChangeClassification(true, [], [], [], [], []);
        }

        $draftFields = $draft->fields()->get()->keyBy('key');
        $prevFields = $published->fields()->get()->keyBy('key');
        $draftSectionKeys = $draft->sections()->get()->pluck('key');
        $prevSectionKeys = $published->sections()->get()->pluck('key');

        /** @var list<string> $addedFieldKeys */
        $addedFieldKeys = $draftFields->keys()->diff($prevFields->keys())->sort()->values()->all();
        /** @var list<string> $removedFieldKeys */
        $removedFieldKeys = $prevFields->keys()->diff($draftFields->keys())->sort()->values()->all();

        $breaking = [];
        foreach ($draftFields->sortKeys() as $key => $field) {
            /** @var FormField|null $prev */
            $prev = $prevFields->get($key);
            if ($prev === null) {
                continue; // an added field — non-breaking, already in $addedFieldKeys
            }
            $change = $this->breakingChange($field, $prev);
            if ($change !== null) {
                $breaking[] = ['key' => (string) $key, 'change' => $change];
            }
        }

        /** @var list<string> $addedSectionKeys */
        $addedSectionKeys = $draftSectionKeys->diff($prevSectionKeys)->sort()->values()->all();
        /** @var list<string> $removedSectionKeys */
        $removedSectionKeys = $prevSectionKeys->diff($draftSectionKeys)->sort()->values()->all();

        return new SchemaChangeClassification(
            false,
            $addedFieldKeys,
            $removedFieldKeys,
            $breaking,
            $addedSectionKeys,
            $removedSectionKeys,
        );
    }

    /** The breaking-but-permitted change on a same-key field, or null if the change is non-breaking. */
    private function breakingChange(FormField $field, FormField $prev): ?string
    {
        if ($field->field_type !== $prev->field_type) {
            return "type {$prev->field_type->value} → {$field->field_type->value}";
        }
        if ($prev->is_required !== RequiredMode::Required && $field->is_required === RequiredMode::Required) {
            return 'became required';
        }
        if ($field->is_queryable !== $prev->is_queryable || $field->indexed_data_type !== $prev->indexed_data_type) {
            return 'indexing changed';
        }

        return null;
    }
}
