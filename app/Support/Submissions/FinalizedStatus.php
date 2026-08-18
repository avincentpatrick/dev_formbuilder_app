<?php

declare(strict_types=1);

namespace App\Support\Submissions;

use App\Enums\SubmissionStatus;
use App\Models\FormField;
use App\Models\FormSection;
use App\Services\Validation\SemanticResult;
use App\Support\Forms\StepProjection;
use Illuminate\Support\Collection;

/**
 * The status a submission gets when it is FINALIZED — `submitted`, or `screened_out` when the respondent
 * was shown no questions at all. Increment I9a, discharging the consequence
 * `docs/workflow-branching-design.md:140` recorded and deferred: "an empty submit burns a capacity slot".
 *
 * ONE OBJECT BECAUSE THERE ARE TWO FINALIZE PATHS. `SubmissionPipeline::persist()` (fresh submit) and
 * `SubmissionDraftService::promote()` (draft → submitted) both decide this, and a status they disagree
 * about is a capacity figure that depends on which door the respondent came through. Same argument that
 * produced `SubmissionFinalizer` for the Stage-4 tail in H9a — except this decision has to be made BEFORE
 * that tail runs, because `FormAcceptanceGuard::assertCapacity()` counts the head row it is written onto.
 *
 * PURE AND STATIC — no container, no database, no expression engine — exactly like {@see StepProjection}
 * and `DraftCompleteness`. It consumes the masks a {@see SemanticResult} already carries; both call sites
 * have one in hand by the time they need this, so the determination costs no extra work.
 *
 * NOT A METHOD ON {@see StepProjection}, deliberately. That class is a form-shape primitive with a
 * TypeScript twin in the guest runtime and a shared parity fixture; teaching it about `SubmissionStatus`
 * would put a submission-lifecycle concept inside the one class whose whole job is to say what a
 * respondent would SEE. The dependency points one way, here.
 *
 * ⚠️ WHAT `screened_out` MEANS, because the name promises more than the predicate delivers: "was shown no
 * questions", not "was disqualified". {@see SubmissionStatus} carries the full argument, including why the
 * narrower "the answers CAUSED the emptiness" reading was rejected — read it before changing this.
 */
final class FinalizedStatus
{
    /**
     * @param  Collection<int, FormSection>  $sections  the version's sections
     * @param  Collection<int, FormField>  $fields  the version's fields
     * @param  SemanticResult  $result  this finalize's OWN Stage-3 result — never a cached or borrowed one
     */
    public static function for(Collection $sections, Collection $fields, SemanticResult $result): SubmissionStatus
    {
        $shownNothing = StepProjection::isEmpty(
            $sections,
            $fields,
            $result->sectionRelevance,
            $result->fieldRelevance,
            $result->repeatFieldRelevance,
        );

        return $shownNothing ? SubmissionStatus::ScreenedOut : SubmissionStatus::Submitted;
    }
}
