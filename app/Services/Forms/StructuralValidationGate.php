<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Exceptions\Forms\PublishValidationException;
use App\Models\FormVersion;

/**
 * The pre-publish structural gate (form-versioning-schema-migration.md §4). A draft that fails any check
 * is not publishable; the SPECIFIC violation is thrown (naming the field) so the builder can point at it,
 * rather than a generic failure. The `expression XOR rule_type` rule is omitted here because it is a hard
 * DB CHECK (a violating row cannot exist).
 */
final class StructuralValidationGate
{
    public function assertPublishable(FormVersion $version): void
    {
        $fields = $version->fields()->get();
        $sectionIds = $version->sections()->get()->pluck('id')->flip();
        $validations = $version->validations()->get();

        $fieldIds = $fields->pluck('id')->flip();
        $fieldKeyById = $fields->pluck('key', 'id');

        foreach ($fields as $field) {
            // Every field's section, when set, belongs to the same version.
            if ($field->form_section_id !== null && ! $sectionIds->has($field->form_section_id)) {
                throw PublishValidationException::sectionBelongsToForeignVersion($field->key);
            }
            // Every queryable field declares an indexed data type (the projection job needs it).
            if ($field->is_queryable && $field->indexed_data_type === null) {
                throw PublishValidationException::queryableFieldMissingType($field->key);
            }
        }

        // Every validation's owning + comparison field belongs to the same version.
        foreach ($validations as $validation) {
            $ownerKey = $fieldKeyById->get($validation->form_field_id, '(unknown)');

            if (! $fieldIds->has($validation->form_field_id)) {
                throw PublishValidationException::validationReferencesForeignVersion($ownerKey);
            }
            if ($validation->related_form_field_id !== null && ! $fieldIds->has($validation->related_form_field_id)) {
                throw PublishValidationException::validationReferencesForeignVersion($ownerKey);
            }
        }
    }
}
