<?php

declare(strict_types=1);

namespace App\Services\Search\Arms;

use App\Enums\FormStatus;
use App\Enums\SearchEntity;
use App\Models\Form;
use App\Models\User;
use App\Policies\FormPolicy;
use App\Services\Search\SearchArm;
use App\Services\Search\SearchArmResult;
use App\Support\Forms\FormHubLink;
use App\Support\Search\KeywordFilter;
use App\Support\Search\SearchTerms;
use Illuminate\Database\Eloquent\Builder;

/**
 * Forms, matched on title / description / slug (Increment J1b; re-aimed and widened in J2d).
 *
 * ⚠️ THE VISIBILITY RULE IS `Form::scopeReadableBy()` AS OF J2d, AND THE CHANGE IS DELIBERATE — WITH ONE
 * WRONG ANSWER RULED OUT FIRST. `AnalyticsFormSet::visible()` remains the wrong rule and always was: it keys
 * on `dashboard.org.view` ALONE and carries `withTrashed()`, so borrowing it would hand a reader the title of
 * every form in the tenant INCLUDING SOFT-DELETED ONES. `scopeReadableBy()` is not that scope — it is
 * byte-for-byte {@see FormPolicy::viewOverview()}, both conjuncts, with no `withTrashed()`, and it is the
 * exact question `GET /forms/{form}` asks. So every row this arm returns is a page its reader can open.
 *
 * ── WHY IT WIDENED (user decision, 2026-08-11) ────────────────────────────────────────────────────────────
 * Until J2d the arm gated on `viewAny,Form` = `forms.create | forms.edit.any | forms.edit.own`, so a
 * **Reviewer and a Viewer got no form results at all** — while J2b had opened the form hub to all five roles
 * and J2c had given them a per-form responses list. A reader who can open a form's hub from the inbox but
 * cannot find that form by name is the dead end this whole row exists to remove, arriving through the
 * feature built to remove it.
 *
 * It discloses nothing new: both roles already read every submission of every form they can reach, and the
 * inbox has always rendered `form_title` to them. What they gain is the DESCRIPTION as a matchable field,
 * scoped to forms they may open.
 *
 * ── ROLE OUTCOMES, STATED SO THEY ARE A DECISION RATHER THAN AN ARTEFACT ──────────────────────────────────
 *   Owner / Admin       every non-archived form (they hold `dashboard.org.view`)
 *   Viewer              every non-archived form — same reason, and consistent with the hub they can open
 *   Form Editor         only forms they hold a grant on, at ANY capacity
 *   Reviewer            only forms they hold a grant on — the arm no longer refuses them outright
 *
 * ⚠️ ANY CAPACITY, NOT `ResourceCapacity::Editor`. A Reviewer's grant is reviewer capacity, so an
 * editor-capacity check would refuse the single role this was widened for — the trap `scopeReadableBy()` and
 * `viewOverview()` both record. `scopeVisibleTo()` (the AUTHORING scope) has that check, which is precisely
 * why it is not used here.
 */
final readonly class FormSearchArm implements SearchArm
{
    public function entity(): SearchEntity
    {
        return SearchEntity::Form;
    }

    public function allowed(User $user): bool
    {
        // The same gate the arm's DESTINATION uses — `dashboard.form.view`, `viewOverview`'s first conjunct
        // and the class-level half of it. J2d moved this off `viewAny,Form` (the `/forms` LIST gate), which
        // refused a Reviewer and a Viewer outright; see the class docblock for why that was a dead end.
        //
        // Deliberately NOT a new `search.*` permission: the RBAC catalog is closed at 29 keys, and a key
        // whose audience is "every authenticated user" is the dormant-key anti-pattern this repo has already
        // been bitten by three times. `ApiAbilities` records the same choice for `read:analytics` — map onto
        // existing permissions rather than coining a thirtieth.
        //
        // ⚠️ ALL FIVE SHIPPED ROLES HOLD `dashboard.form.view`, so this method is now a fail-closed guard
        // rather than a live refusal; the real narrowing is `scopeReadableBy()` in `builder()`, which is
        // where a member with the key but no org-wide visibility and no grants gets an empty result rather
        // than a refused arm. Labelled as one, not presented as a rule a user can observe.
        //
        // ⚠️ THE PERMISSION DIRECTLY, NOT A NEW POLICY METHOD. `viewOverview` is per-form by nature and there
        // is no form here; minting a `viewOverviewAny` to wrap one `can()` would add a second place the rule
        // is written for no reader. `scopeReadableBy()` spells the same conjunct the same way.
        return $user->can('dashboard.form.view');
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
                // The HUB, not the builder (J2d). `/forms/{id}/builder` is `can:update,form` → `canEdit()`,
                // which refuses the two roles this arm was just widened for — a result they could find and
                // not open. `path()` rather than `pathsFor()` because reachability is already proven by the
                // scope this very query runs under: `readableBy` IS `viewOverview`, so a row cannot be
                // returned to a reader the hub would refuse. `SearchResultReachabilityTest` GETs this exact
                // URL as a Viewer and as a granted Reviewer — the two narrowest roles reaching this arm, and
                // the two the builder would have 403'd.
                'url' => FormHubLink::path($form->id),
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
            ->readableBy($user)
            ->tap(fn (Builder $q) => KeywordFilter::apply($q, $terms, 'forms.search_vector'))
            // A display filter, not a visibility rule — which is why it lives here and not in the scope.
            // It matches `/forms`, so a search result cannot offer a row the forms list refuses to show.
            ->where('forms.status', '!=', FormStatus::Archived->value);
    }
}
