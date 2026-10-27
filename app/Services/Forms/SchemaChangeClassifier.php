<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\RequiredMode;
use App\Models\FormField;
use App\Models\FormVersion;

/**
 * Diffs a draft version's fields/sections (by stable `key`) against the currently-published version — or
 * against nothing, for a first-ever publish (form-versioning-schema-migration.md §5). The classification
 * is recorded on every publish (feeding `change_summary`). Publish does not block or warn on it (`D169`): M189's Compare
 * page marks the same changes, through {@see self::breakingCodeOf()}.
 */
final class SchemaChangeClassifier
{
    /** The codes {@see self::breakingCodeOf()} answers with. */
    public const string TYPE = 'type';

    public const string REQUIRED = 'required';

    public const string INDEXING = 'indexing';

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
        return match (self::breakingCodeOf(self::shape($prev), self::shape($field))) {
            self::TYPE => "type {$prev->field_type->value} → {$field->field_type->value}",
            self::REQUIRED => 'became required',
            self::INDEXING => 'indexing changed',
            default => null,
        };
    }

    /**
     * §5's breaking-but-permitted change on a same-key question, as a code, or null — over the snapshot's shape
     * ({@see SchemaSnapshotSerializer}), so the publish summary above and M189's Compare page ({@see VersionComparison})
     * apply ONE rule rather than two that drift.
     *
     * @param  array<string, mixed>  $prev
     * @param  array<string, mixed>  $now
     */
    public static function breakingCodeOf(array $prev, array $now): ?string
    {
        if (($now['field_type'] ?? null) !== ($prev['field_type'] ?? null)) {
            return self::TYPE;
        }
        $required = RequiredMode::Required->value;
        if (($prev['is_required'] ?? null) !== $required && ($now['is_required'] ?? null) === $required) {
            return self::REQUIRED;
        }
        if ((bool) ($now['is_queryable'] ?? false) !== (bool) ($prev['is_queryable'] ?? false)
            || ($now['indexed_data_type'] ?? null) !== ($prev['indexed_data_type'] ?? null)) {
            return self::INDEXING;
        }

        return null;
    }

    /** @return array{field_type: string, is_required: string, is_queryable: bool, indexed_data_type: ?string} */
    private static function shape(FormField $field): array
    {
        return [
            'field_type' => $field->field_type->value,
            'is_required' => $field->is_required->value,
            'is_queryable' => (bool) $field->is_queryable,
            'indexed_data_type' => $field->indexed_data_type?->value,
        ];
    }
}
