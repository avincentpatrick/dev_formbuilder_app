<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldValidation;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\User;
use App\Support\Forms\CopyKeys;
use App\Support\Forms\OtherSpecify;
use Illuminate\Support\Str;

/**
 * M240 (`builder-copy-section`, `D224`, `D225`) — copies questions inside a draft, or a whole section with its
 * questions, so that every link BETWEEN the copied rows points at the copies and every link to anything outside the
 * copy stays where it was. One engine for the three ways in: a question's Duplicate, Duplicate on ticked questions,
 * and Duplicate section.
 *
 * Runs inside the caller's transaction, under its `forms`-row lock ({@see FormBuilderService}), on sources the caller
 * has already proved are rows of the draft. Two passes, the {@see SchemaTreeCloner} pattern:
 * 1. the section, then each question, with its new key ({@see CopyKeys}) and its key references re-pointed through
 *    the old ⇒ new map ({@see KeyReferenceRewriter}), recording old id ⇒ new id;
 * 2. every validation row of the sources, re-pointed through that id map — a rule comparing with a copied sibling
 *    compares with its copy, a rule comparing with an outside question keeps its id — with a fresh group per source
 *    group, so a copy's "All of" never shares an identity with its source's.
 *
 * ⛔ THE COPIES ARE PLACED HERE, NOT BY THE BUILDER. A builder-side placement would save the whole order through
 * {@see FormBuilderService::reorder()}, whose {@see FieldRevisionRecorder::recordMoves()} would then log a "Moved"
 * entry for questions below the copy that nobody moved. Positions are shifted through `toBase()`, so neither
 * `updated_at` (the content save token, M176) nor any history moves.
 */
final class DraftCopier
{
    private const SUFFIX = ' (copy)';

    public function __construct(
        private readonly KeyReferenceRewriter $rewriter = new KeyReferenceRewriter,
        private readonly RetiredFieldKeys $retired = new RetiredFieldKeys,
    ) {}

    /**
     * @param  list<FormField>  $sources  rows of `$draft`, in on-screen order; for a section copy, all of its questions
     * @param  ?FormSection  $section  the section to copy with them, or null to copy the questions where they stand
     */
    public function copy(Form $form, FormVersion $draft, User $user, array $sources, ?FormSection $section): DraftCopy
    {
        $taken = $this->takenKeys($form, $draft);
        $fieldMap = CopyKeys::plan(array_map(static fn (FormField $f): string => $f->key, $sources), $taken);
        $map = $fieldMap;
        $newSection = null;

        if ($section !== null) {
            // Fields and sections share one key space, so the section's key may not take a key just given to a copy.
            foreach ($fieldMap as $copyKey) {
                $taken[$copyKey] = true;
            }
            $map[$section->key] = CopyKeys::plan([$section->key], $taken)[$section->key];
            $newSection = $this->copySection($draft, $section, $map[$section->key], $map);
        }

        $sequences = $newSection !== null
            ? $this->appendedSequences($draft, $sources)
            : $this->placedSequences($draft, $sources);

        $idMap = [];
        $copies = [];

        foreach ($sources as $source) {
            $copy = $source->replicate(['created_by', 'updated_by', 'lineage_id']);
            $copy->key = $fieldMap[$source->key];

            if ($newSection !== null) {
                $copy->form_section_id = $newSection->id;
            }

            // M177: a choice question's link to its "Please specify" survives only when its partner is copied too —
            // the rewriter below then points it at the partner's copy. Otherwise two questions would claim one.
            $config = (array) $copy->config;
            $partner = $config[OtherSpecify::CONFIG_KEY] ?? null;
            if (is_string($partner) && ! isset($fieldMap[$partner])) {
                unset($config[OtherSpecify::CONFIG_KEY]);
            }
            $copy->config = $config;

            $this->rewriter->field($copy, $map);

            if ($newSection === null) {
                $copy->label = self::copyLabel($copy->label, 500);
            } else {
                // The section copy's questions keep their labels and their per-section order; only their place in the
                // draft-wide sequence is new.
                $copy->section_sequence = $source->section_sequence;
            }

            $copy->sequence = $sequences[$source->id];
            $copy->created_by = $user->id;
            $copy->updated_by = null;
            $copy->save();

            $idMap[$source->id] = $copy->id;
            $copies[] = ['source_id' => $source->id, 'copy' => $copy];
        }

        $this->copyValidations($idMap, $map);

        if ($newSection === null) {
            // A move does the same in the builder (`forgetSectionPlaces`): an imported form's per-section numbers would
            // otherwise sort each copy next to its source at runtime rather than where it was placed. Only the rows that
            // carry one: rewriting every question of the draft on every copy took a row lock on each and left a dead
            // version of each behind, and NHETE's copy-built form ran every later save about three times slower.
            FormField::query()->where('form_version_id', $draft->id)->whereNotNull('section_sequence')
                ->toBase()->update(['section_sequence' => null]);
        }

        return new DraftCopy($draft, $newSection, array_map(static function (array $pair): array {
            $pair['copy']->refresh();

            return $pair;
        }, $copies));
    }

    /** The label with " (copy)" appended, shortened first so the whole stays inside the column. */
    public static function copyLabel(string $label, int $max): string
    {
        return mb_substr($label, 0, $max - mb_strlen(self::SUFFIX)).self::SUFFIX;
    }

    /**
     * @return array<string, true>
     */
    private function takenKeys(Form $form, FormVersion $draft): array
    {
        $taken = $this->retired->everHeld($form);

        $draftKeys = FormField::query()->where('form_version_id', $draft->id)->pluck('key')
            ->merge(FormSection::query()->where('form_version_id', $draft->id)->pluck('key'));

        foreach ($draftKeys as $key) {
            $taken[(string) $key] = true;
        }

        return $taken;
    }

    /**
     * @param  array<string, string>  $map
     */
    private function copySection(FormVersion $draft, FormSection $section, string $key, array $map): FormSection
    {
        // Right after its source: every later section moves down one.
        FormSection::query()->where('form_version_id', $draft->id)->where('sequence', '>', $section->sequence)
            ->toBase()->increment('sequence');

        $copy = $section->replicate();
        $copy->key = $key;
        $this->rewriter->section($copy, $map);
        $copy->label = self::copyLabel($copy->label, 255);
        $copy->sequence = $section->sequence + 1;
        $copy->save();

        return $copy;
    }

    /**
     * A section copy's questions: appended to the draft's one sequence line, in source order. They sit in a section of
     * their own, so their position among other sections' questions does not matter.
     *
     * @param  list<FormField>  $sources
     * @return array<string, int> source id ⇒ the copy's sequence
     */
    private function appendedSequences(FormVersion $draft, array $sources): array
    {
        $next = (int) FormField::query()->where('form_version_id', $draft->id)->max('sequence') + 1;
        $out = [];

        foreach ($sources as $i => $source) {
            $out[$source->id] = $next + $i;
        }

        return $out;
    }

    /**
     * Copies of questions where they stand: each in its source's section, right after the last copied question of that
     * section, in source order. The draft's later rows move down to make room, working from the lowest-placed group up.
     * Each group's last position is read before any shift and lies below every group handled before it, so its shift
     * moves those groups' rows too — and the places already handed out to their copies move with them.
     *
     * @param  list<FormField>  $sources
     * @return array<string, int> source id ⇒ the copy's sequence
     */
    private function placedSequences(FormVersion $draft, array $sources): array
    {
        /** @var array<string, list<FormField>> $groups section id (or '' for the top level) ⇒ its sources */
        $groups = [];
        /** @var array<string, int> $lastOf section id ⇒ the position of its last copied question */
        $lastOf = [];
        foreach ($sources as $source) {
            $group = (string) $source->form_section_id;
            $groups[$group][] = $source;
            $lastOf[$group] = max($lastOf[$group] ?? $source->sequence, $source->sequence);
        }

        arsort($lastOf);

        $out = [];
        foreach ($lastOf as $groupKey => $last) {
            $group = $groups[$groupKey];
            $room = count($group);

            FormField::query()->where('form_version_id', $draft->id)->where('sequence', '>', $last)
                ->toBase()->increment('sequence', $room);

            foreach ($out as $sourceId => $placed) {
                $out[$sourceId] = $placed + $room;
            }

            foreach ($group as $i => $source) {
                $out[$source->id] = $last + 1 + $i;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, string>  $idMap  source id ⇒ copy id
     * @param  array<string, string>  $map  old key ⇒ new key
     */
    private function copyValidations(array $idMap, array $map): void
    {
        $groups = [];

        $rules = FormFieldValidation::query()->whereIn('form_field_id', array_keys($idMap))
            ->orderBy('form_field_id')->orderBy('sequence')->get();

        foreach ($rules as $rule) {
            $copy = $rule->replicate();
            $copy->form_field_id = $idMap[$rule->form_field_id];

            if ($rule->related_form_field_id !== null) {
                $copy->related_form_field_id = $idMap[$rule->related_form_field_id] ?? $rule->related_form_field_id;
            }

            if ($rule->logic_group !== null) {
                $copy->logic_group = $groups[$rule->form_field_id.'|'.$rule->logic_group] ??= (string) Str::uuid();
            }

            $this->rewriter->validation($copy, $map);
            $copy->save();
        }
    }
}
