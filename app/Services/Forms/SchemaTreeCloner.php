<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\FormVersion;

/**
 * Copies a source version's sections/fields/validations into a target version — same `key`s, brand-new
 * row ids — and **rewrites every intra-tree foreign key** (`form_section_id`, `form_field_id`,
 * `related_form_field_id`) to the new ids. Without the remap, a naive copy would leave the target's rows
 * pointing at the source (immutable) version's rows — a dangling cross-version reference §4 forbids.
 *
 * Used by both the publish transaction (clone the just-published version forward into a fresh draft) and
 * restore (repopulate the current draft from an old version). The target must be a draft in the current
 * tenant context — the draft_child RLS guard rejects the inserts otherwise.
 */
final class SchemaTreeCloner
{
    public function clone(FormVersion $source, FormVersion $target): void
    {
        $sectionIdMap = []; // source section id => new section id
        foreach ($source->sections()->get() as $section) {
            $new = $section->replicate();
            $new->form_version_id = $target->id;
            $new->save();
            $sectionIdMap[$section->id] = $new->id;
        }

        $fieldIdMap = []; // source field id => new field id
        foreach ($source->fields()->get() as $field) {
            $new = $field->replicate();
            $new->form_version_id = $target->id;
            $new->form_section_id = $field->form_section_id !== null
                ? ($sectionIdMap[$field->form_section_id] ?? null)
                : null;
            $new->save();
            $fieldIdMap[$field->id] = $new->id;
        }

        foreach ($source->validations()->get() as $validation) {
            $new = $validation->replicate();
            $new->form_version_id = $target->id;
            $new->form_field_id = $fieldIdMap[$validation->form_field_id] ?? $validation->form_field_id;
            $new->related_form_field_id = $validation->related_form_field_id !== null
                ? ($fieldIdMap[$validation->related_form_field_id] ?? null)
                : null;
            $new->save();
        }
    }
}
