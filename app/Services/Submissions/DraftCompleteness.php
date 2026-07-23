<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\FieldType;
use App\Models\FormField;
use App\Models\FormSection;
use Illuminate\Support\Collection;

/**
 * A cached, relevance-UNAWARE progress indicator for a draft (Increment H9a) — "Saved · N%". Pure and
 * static (no container / DB / expression engine), mirroring {@see AnswersContentChecksum}, because a draft
 * autosave deliberately skips Stage 3: computing a relevance-aware percentage would require running the full
 * semantic evaluator on every keystroke-batch and would prune answers the respondent is mid-way through.
 *
 * The percentage is `round(100 * answered / answerable)` over the Stage-1 NORMALIZED answers (which already
 * dropped empties + display/calculated keys), where a "unit" is:
 *   - each top-level respondent-answerable field (everything except `note` / `page_break` / `calculated`,
 *     and `hidden` — hidden fields are server/URL-sourced under H7, not respondent progress); OR
 *   - each repeatable SECTION, counted once (its member fields fold into that one unit — a repeat group is
 *     "answered" once it holds at least one non-empty instance).
 * An answerable field is "answered" when its key is present in the normalized answers (normalize stores only
 * non-empty values). A form with no answerable units yields 0 (never a divide-by-zero).
 */
final class DraftCompleteness
{
    /**
     * @param  Collection<int, FormField>  $fields
     * @param  Collection<int, FormSection>  $sections
     * @param  array<string, mixed>  $normalizedAnswers  the Stage-1 output ({@see StructuralAnswerNormalizer::normalize})
     */
    public static function of(Collection $fields, Collection $sections, array $normalizedAnswers): int
    {
        /** @var array<string, true> $repeatSectionIds */
        $repeatSectionIds = [];
        /** @var array<string, string> $repeatSectionKeys  section id => section key */
        $repeatSectionKeys = [];
        foreach ($sections as $section) {
            if ($section->is_repeatable === true) {
                $repeatSectionIds[$section->id] = true;
                $repeatSectionKeys[$section->id] = $section->key;
            }
        }

        $denominator = 0;
        $numerator = 0;

        foreach ($fields as $field) {
            // A field inside a repeatable section folds into that section's single unit (below), never counted
            // on its own.
            $sectionId = $field->form_section_id;
            if ($sectionId !== null && isset($repeatSectionIds[$sectionId])) {
                continue;
            }

            if (! self::isAnswerable($field->field_type)) {
                continue;
            }

            $denominator++;
            if (array_key_exists($field->key, $normalizedAnswers)) {
                $numerator++;
            }
        }

        foreach ($repeatSectionKeys as $sectionKey) {
            $denominator++;
            $instances = $normalizedAnswers[$sectionKey] ?? null;
            if (is_array($instances) && $instances !== []) {
                $numerator++;
            }
        }

        if ($denominator === 0) {
            return 0;
        }

        $percent = (int) round(100 * $numerator / $denominator);

        return max(0, min(100, $percent));
    }

    /** Everything the respondent can actually fill in — excludes display-only, server-computed, and hidden. */
    private static function isAnswerable(FieldType $type): bool
    {
        return match ($type) {
            FieldType::Note, FieldType::PageBreak, FieldType::Calculated, FieldType::Hidden => false,
            default => true,
        };
    }
}
