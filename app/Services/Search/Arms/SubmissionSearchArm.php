<?php

declare(strict_types=1);

namespace App\Services\Search\Arms;

use App\Enums\SearchEntity;
use App\Models\Submission;
use App\Models\User;
use App\Services\Search\SearchArm;
use App\Services\Search\SearchArmResult;
use App\Support\Search\KeywordFilter;
use App\Support\Search\SearchTerms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Submissions, matched on reviewer remarks, returned reason, their form's title, and a reference prefix
 * (Increment J1b).
 *
 * ⚠️ ANSWER TEXT IS NOT SEARCHABLE — a user decision taken 2026-08-09, with the four supporting arguments
 * recorded in `2026_08_11_000002_add_search_vector_to_submissions.php`. Do not add it here without reading
 * them; the short version is that GDPR erasure has no writer yet, so a second store of answer text is one
 * nothing can scrub, and the typed index it would use is empty for essentially every form alive.
 *
 * ── THE `orWhere` HAZARD, AND WHY THIS FILE IS WHERE IT WOULD BITE ───────────────────────────────────────
 * `Submission::scopeVisibleTo()` adds `(granted forms OR I am the respondent)`. Composing a keyword predicate
 * onto that flat gives `granted OR (respondent AND keyword)` — so a reviewer searching any word would ALSO
 * receive every submission they ever authored, on any form, in any status. Two things prevent it, and both
 * are deliberate:
 *   1. `visibleTo` is invoked AS A SCOPE (`->visibleTo($user)`), so `Builder::callScope()` wraps whatever it
 *      adds via `addNewWheresWithinGroup()`. Hoisting the body into a hand-rolled `where(fn ...)` here would
 *      lose that — which is exactly what the scope's own docblock warns about.
 *   2. `KeywordFilter::apply()` emits its OWN closure group unconditionally.
 * Belt and braces, on purpose: the scope's wrapper is verified to be redundant TODAY (mutating it leaves the
 * suite green), so it cannot be relied on alone to catch a future call-shape change.
 *
 * ── WHY THE FORM-TITLE ARM APPLIES NO FORM VISIBILITY ────────────────────────────────────────────────────
 * Matching on the parent form's title is scoped to the tenant only, NOT to `Form::scopeVisibleTo()`. That
 * looks like a hole and is the opposite: a Reviewer holds no `forms.*` key, so form visibility would be empty
 * for them, and the only label the inbox has ever shown them for their own submissions is the form title.
 * Applying it would make a reviewer's own work unfindable by the one word they know it by. The submission
 * rows returned are still bounded by `visibleTo` — the form title only decides WHICH of the rows they can
 * already see are considered a match.
 */
final readonly class SubmissionSearchArm implements SearchArm
{
    public function entity(): SearchEntity
    {
        return SearchEntity::Submission;
    }

    public function allowed(User $user): bool
    {
        return Gate::forUser($user)->allows('viewAny', Submission::class);
    }

    public function search(User $user, SearchTerms $terms, int $limit): SearchArmResult
    {
        $rows = $this->builder($user, $terms)
            ->with(['form:id,title'])
            ->select(['submissions.id', 'submissions.form_id', 'submissions.status', 'submissions.submitted_at'])
            ->orderByDesc('submissions.id')
            ->limit($limit + 1)
            ->get()
            ->map(fn (Submission $s): array => [
                'id' => $s->id,
                // The reference a support ticket quotes. `SearchTerms::uuidPrefix()` accepts the same shape,
                // so what is displayed is what can be pasted back in.
                'title' => mb_substr($s->id, 0, 8),
                'subtitle' => trim(($s->form?->title ?? 'Unknown form').' · '.$s->status->label()),
                'url' => '/submissions/'.$s->id,
            ])
            ->all();

        return SearchArmResult::fromOverfetch($this->entity(), $rows, $limit);
    }

    public function count(User $user, SearchTerms $terms): int
    {
        return $this->builder($user, $terms)->count();
    }

    /**
     * ⚠️ NO `ts_rank` ORDER BY HERE, AND THE ABSENCE IS DELIBERATE. Most matches in this arm come from the
     * form-title or reference clauses, which contribute nothing to `submissions.search_vector` — so ranking
     * on that vector would sort the majority of results by a score of zero and produce an order that looks
     * meaningful and is not. Recency (`id DESC`, uuidv7) is the honest ordering for a submission list and is
     * what the inbox already uses.
     *
     * @return Builder<Submission>
     */
    private function builder(User $user, SearchTerms $terms): Builder
    {
        return Submission::query()
            ->visibleTo($user)
            ->countable()
            ->where(function (Builder $match) use ($terms): void {
                $match->whereRaw(
                    "submissions.search_vector @@ to_tsquery('simple', ?)",
                    [$terms->tsQuery()]
                );

                $match->orWhereIn('submissions.form_id', function ($sub) use ($terms): void {
                    $sub->select('id')
                        ->from('forms')
                        ->whereRaw("forms.search_vector @@ to_tsquery('simple', ?)", [$terms->tsQuery()]);
                });

                $prefix = $terms->uuidPrefix();
                if ($prefix !== null) {
                    // A scan, and bounded to stay one: RLS plus `visibleTo` plus the eight-hex-character
                    // minimum, which makes a collision vanishingly unlikely. Deliberately NOT indexed —
                    // `submissions` has no reference column, and the right fix is a real short handle
                    // (filed for J2), not an index on a cast.
                    $match->orWhereRaw('submissions.id::text LIKE ?', [$prefix.'%']);
                }
            });
    }
}
