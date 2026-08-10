<?php

declare(strict_types=1);

namespace App\Services\Search\Arms;

use App\Enums\FormStatus;
use App\Enums\SearchEntity;
use App\Models\Form;
use App\Models\User;
use App\Services\Search\SearchArm;
use App\Services\Search\SearchArmResult;
use App\Support\Search\KeywordFilter;
use App\Support\Search\SearchTerms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/**
 * Forms, matched on title / description / slug (Increment J1b).
 *
 * ⚠️ THE VISIBILITY RULE IS `Form::scopeVisibleTo()`, WHICH IS THE POLICY'S, NOT ANALYTICS'.
 * Read that scope's docblock before changing anything here. The short version: `AnalyticsFormSet::visible()`
 * is the most search-shaped code already in the tree and is the wrong rule — it keys on `dashboard.org.view`,
 * which a **Viewer** holds while holding no `forms.*` key at all, so borrowing it would hand every Viewer the
 * title and description of every form in the tenant (including soft-deleted ones) through this arm, while
 * `/forms` correctly shows them nothing.
 *
 * ── ROLE OUTCOMES, STATED SO THEY ARE A DECISION RATHER THAN AN ARTEFACT ──────────────────────────────────
 *   Owner / Admin (`forms.edit.any`)  every non-archived form
 *   Form Editor                       only forms they hold an EDITOR grant on, directly or via a scope node
 *   Reviewer                          arm REFUSED — holds no `forms.*` key, so `viewAny` is false
 *   Viewer                            arm REFUSED — same reason
 *
 * A Reviewer or Viewer still sees form TITLES through the submissions arm, because the inbox has always
 * rendered `form_title` to them. That is pre-existing and this arm does not widen it: what they cannot do is
 * find a form by its DESCRIPTION, which no surface has ever shown them.
 */
final readonly class FormSearchArm implements SearchArm
{
    public function entity(): SearchEntity
    {
        return SearchEntity::Form;
    }

    public function allowed(User $user): bool
    {
        // The same gate `/forms` uses. Deliberately NOT a new `search.*` permission: the RBAC catalog is
        // closed at 29 keys, and a key whose audience is "every authenticated user" is the dormant-key
        // anti-pattern this repo has already been bitten by three times. `ApiAbilities` records the same
        // choice for `read:analytics` — map onto existing permissions rather than coining a thirtieth.
        return Gate::forUser($user)->allows('viewAny', Form::class);
    }

    public function search(User $user, SearchTerms $terms, int $limit): SearchArmResult
    {
        $rows = $this->builder($user, $terms)
            ->select(['forms.id', 'forms.title', 'forms.status', 'forms.updated_at'])
            ->orderByRaw(KeywordFilter::rankSql('forms.search_vector'), [$terms->tsQuery()])
            ->orderByDesc('forms.id')
            ->limit($limit + 1)
            ->get()
            ->map(fn (Form $form): array => [
                'id' => $form->id,
                'title' => $form->title,
                // `FormStatus` has no `label()` — unlike `SubmissionStatus`, which does. Ucfirst on the
                // backing value rather than adding one: a display helper on an enum is a change every
                // consumer inherits, and J1b has no second caller to justify it.
                'subtitle' => ucfirst($form->status->value),
                'url' => '/forms/'.$form->id.'/builder',
            ])
            ->all();

        // `SearchArmResult` takes a `list<...>`, and a mapped Eloquent collection's `all()` is only an
        // `array<int, ...>` as far as static analysis is concerned. `array_values` is what makes it a
        // provable list rather than a promised one.
        $rows = array_values($rows);

        return SearchArmResult::fromOverfetch($this->entity(), $rows, $limit);
    }

    public function count(User $user, SearchTerms $terms): int
    {
        return $this->builder($user, $terms)->count();
    }

    /**
     * The single source of both the rows and the count. Having exactly one of these is what makes the
     * count-leak guard structural rather than a convention someone can forget.
     *
     * `select()` is applied by the caller, not here, so `count()` does not carry a redundant column list —
     * and note that no path here ever selects `search_vector` itself: a bare `SELECT *` would hydrate a
     * multi-kilobyte lexeme blob onto every model and ship it to the browser in the page props.
     *
     * @return Builder<Form>
     */
    private function builder(User $user, SearchTerms $terms): Builder
    {
        return Form::query()
            ->visibleTo($user)
            ->tap(fn (Builder $q) => KeywordFilter::apply($q, $terms, 'forms.search_vector'))
            // A display filter, not a visibility rule — which is why it lives here and not in the scope.
            // It matches `/forms`, so a search result cannot offer a row the forms list refuses to show.
            ->where('forms.status', '!=', FormStatus::Archived->value);
    }
}
