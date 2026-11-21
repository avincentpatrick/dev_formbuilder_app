<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\User;

/**
 * M242 (`R-ade0b598`, `D226`) — a renamed key carried into every row of the draft that names it, so a condition, a
 * formula, a formula default, a custom rule, a piped label or hint (and their translations) and the Other pair's link
 * follow the rename instead of dangling until publish refuses them. The renamed row is included: a rule on a question
 * may name the question's own key (`${age} > 0`).
 *
 * The engine is `M240`'s {@see KeyReferenceRewriter} with a one-entry map — token-aware, never a text replace. Text it
 * cannot read is left as written and REPORTED, so a reference is never left behind silently.
 *
 * ⛔ THE CALLER HOLDS THE LOCKS. {@see FormBuilderService} takes the `forms` row and then every section, question and
 * rule of the draft — publish's order — before a rename writes anything, so nothing here can interleave with another
 * save of a row it rewrites, or with a publish.
 *
 * ⛔ A REWRITTEN RULE MOVES ITS QUESTION'S TOKEN. The builder re-sends a question's whole rule list under the question's
 * token, so a rule rewritten here without the token moving would be silently put back by a tab holding the old copy.
 *
 * Each rewritten question other than the renamed one is recorded in its history as the renamer's edit; the renamed
 * question's own save records its change, own text included, because this runs before that save is recorded.
 *
 * The thank-you message is FORM-level, and the live version reads it, so it is rewritten only while no version is live
 * (`D227` A); on a live form it is reported as `left`.
 */
final class KeyRenameCarrier
{
    public function __construct(private readonly KeyReferenceRewriter $rewriter = new KeyReferenceRewriter) {}

    public function carry(Form $form, string $draftId, string $from, string $to, ?string $renamedFieldId, ?User $user): CarriedReferences
    {
        $map = [$from => $to];
        $recorder = app(FieldRevisionRecorder::class);
        $unread = [];

        $fields = FormField::query()->where('form_version_id', $draftId)->orderBy('sequence')->orderBy('id')->get();
        $labels = $fields->pluck('label', 'id');

        /** @var array<string, list<FormFieldValidation>> $rulesByOwner the rewritten rules, by question */
        $rulesByOwner = [];
        $rules = FormFieldValidation::query()->where('form_version_id', $draftId)->orderBy('sequence')->orderBy('id')->get();

        foreach ($rules as $rule) {
            if ($this->rewriter->validation($rule, $map) !== []) {
                $unread[] = ['kind' => 'field', 'id' => $rule->form_field_id, 'label' => (string) $labels->get($rule->form_field_id, ''), 'column' => 'constraint'];
            }

            if ($rule->isDirty()) {
                $rulesByOwner[$rule->form_field_id][] = $rule;
            }
        }

        $carriedFields = [];

        foreach ($fields as $field) {
            $before = clone $field;

            foreach ($this->rewriter->field($field, $map) as $column) {
                $unread[] = ['kind' => 'field', 'id' => $field->id, 'label' => $field->label, 'column' => $column];
            }

            $ownRules = $rulesByOwner[$field->id] ?? [];

            if (! $field->isDirty() && $ownRules === []) {
                continue;
            }

            // Read BEFORE the rules are saved: the snapshot reads the question's rules from the table.
            $recorded = $user !== null && $field->id !== $renamedFieldId ? $recorder->snapshot($before) : null;

            foreach ($ownRules as $rule) {
                $rule->save();
            }

            if ($user !== null) {
                $field->fill(['updated_by' => $user->id]);
            }

            $field->isDirty() ? $field->save() : $field->touch();

            if ($recorded !== null) {
                $field->refresh();
                $recorder->recordEdit($form, $field, $user, $recorded, $recorder->snapshot($field));
            }

            $carriedFields[] = $field;
        }

        $carriedSections = [];
        $sections = FormSection::query()->where('form_version_id', $draftId)->orderBy('sequence')->orderBy('id')->get();

        foreach ($sections as $section) {
            foreach ($this->rewriter->section($section, $map) as $column) {
                $unread[] = ['kind' => 'section', 'id' => $section->id, 'label' => $section->label, 'column' => $column];
            }

            if ($section->isDirty()) {
                $section->save();
                $carriedSections[] = $section;
            }
        }

        return new CarriedReferences($from, $to, $carriedFields, $carriedSections, $unread, $this->confirmation($form, $map, $user));
    }

    /**
     * @param  array<string, string>  $map
     * @return 'carried'|'left'|null
     */
    private function confirmation(Form $form, array $map, ?User $user): ?string
    {
        // The row as it stands: the caller holds its lock, and the route's copy can be a request old.
        $current = Form::query()->whereKey($form->id)->firstOrFail();
        $rewritten = $this->rewriter->confirmation($current, $map);

        if ($rewritten === null) {
            return null;
        }

        if ($current->current_published_version_id !== null) {
            return 'left';
        }

        app(FormService::class)->setConfirmationMessage($current, $rewritten['message'], $rewritten['translations'], null, $user);

        return 'carried';
    }
}
