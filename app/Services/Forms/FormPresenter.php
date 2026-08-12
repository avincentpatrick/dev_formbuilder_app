<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FormStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\User;
use App\Services\Authorization\ResourceGrantResolver;
use App\Support\Forms\FormScheduleView;
use App\Support\Search\KeywordFilter;
use App\Support\Search\SearchTerms;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Read model for the forms list page (Increment D3). Presents every non-archived form in the current
 * tenant with its version history and the viewer's per-form abilities (resolved through FormPolicy).
 * Keeps the controller thin, mirroring TenantMembershipService::listMembers.
 */
final class FormPresenter
{
    public function __construct(private readonly ResourceGrantResolver $grants) {}

    /**
     * The rows for the forms list, optionally narrowed by a keyword.
     *
     * ⚠️ `$terms` IS OPTIONAL AND MUST STAY OPTIONAL. `FormListScopingTest` calls this with one argument and
     * passing UNEDITED is J1b's stated proof that extracting `Form::scopeVisibleTo()` moved nothing — a
     * proof that evaporates the moment the test has to be touched to accommodate a signature change. The
     * same reasoning made J1c's roster parameter optional.
     *
     * @return list<array<string, mixed>>
     */
    public function list(User $user, ?SearchTerms $terms = null): array
    {
        $terms ??= SearchTerms::parse(null);

        $forms = Form::query()
            ->with(['versions' => fn ($q) => $q->orderByDesc('version_number')])
            // Scope to what the viewer can actually author (Increment G10b). Until G10b this returned every
            // non-archived form in the tenant to anyone passing `viewAny`, with only the per-row `can`
            // flags differing — so a Form Editor saw the titles and version history of forms they hold no
            // grant on. Submissions always had a list twin (`Submission::scopeVisibleTo`); forms did not.
            //
            // J1b EXTRACTED that rule to `Form::scopeVisibleTo()` so global search shares it rather than
            // encoding it a third time — see the scope's docblock for why it must not be folded into
            // `AnalyticsFormSet::visible()`, which answers a different question and would show a Viewer
            // every form in the tenant. The proof this extraction moved nothing is that
            // `FormListScopingTest` passes unedited.
            ->visibleTo($user)
            // The keyword predicate, in the SAME composition `FormSearchArm::builder()` uses (J1e), so a
            // `?q=` on this page and the same word in global search cannot return different forms.
            // `FormListKeywordParityTest` asserts the two row sets are equal rather than trusting that.
            //
            // ⚠️ `KeywordFilter::apply()` emits its own closure group, and that is what makes the NEXT line
            // safe. The archived filter must AND with the keyword, never replace it or re-associate against
            // one branch of it; the trap is spelling either one as an `orWhere`.
            ->tap(fn (Builder $q) => KeywordFilter::apply($q, $terms, 'forms.search_vector'))
            // A DISPLAY filter, not a visibility rule — which is why it lives here and not in the scope, and
            // why `FormSearchArm` keeps its own copy: a search result must not offer a row this list refuses
            // to show. An archived form whose title matches `?q` therefore stays hidden, which is pinned.
            ->where('status', '!=', FormStatus::Archived->value)
            // ⚠️ RANK ONLY UNDER A QUERY, AND THE CONDITION IS THE POINT. `ts_rank` over an empty tsquery
            // scores every row 0.0, so an unconditional rank would leave the ordering to whatever the
            // planner did next and silently discard `updated_at` on the ordinary, unfiltered page — the
            // list's entire information design, changed by a feature nobody was using at the time.
            // `updated_at` stays behind it as the tiebreaker, which is the shape `FormSearchArm` uses (rank,
            // then a stable second key).
            ->when(
                ! $terms->isEmpty(),
                fn (Builder $q) => $q->orderByRaw(KeywordFilter::rankSql('forms.search_vector'), [$terms->tsQuery()])
            )
            ->orderByDesc('updated_at')
            ->get();

        // Resolve every scope node on this page in ONE query before the per-row `can()` checks below
        // start asking "does this user hold a grant here" (G10a). Without it each form with a node costs
        // an extra lookup per policy call — five per row — turning the list into an N+1.
        $this->grants->primeNodePaths($forms);

        // The card grid's counts (JR3), for the whole page at once. Same shape of defence as the line
        // above: gather in a constant number of queries BEFORE the per-row loop, never inside it.
        $metrics = FormListMetrics::for($forms, $user);

        return array_values($forms->map(fn (Form $form): array => $this->present($form, $user, $metrics))->all());
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Form $form, User $user, FormListMetrics $metrics): array
    {
        /** @var Collection<int, FormVersion> $versions */
        $versions = $form->versions;

        return [
            'id' => $form->id,
            'title' => $form->title,
            'description' => $form->description,
            'status' => $form->status->value,
            // The scope picker's current value (G10b2). The picker itself is gated on `scopes.manage`; this
            // is just the form's own column, already visible to anyone who can see the row.
            'scope_node_id' => $form->scope_node_id !== null ? (string) $form->scope_node_id : null,
            'current_version' => $this->versionNumber($versions, $form->current_published_version_id),
            'draft_version' => $this->versionNumber($versions, $form->draft_version_id),
            'updated_at' => $form->updated_at?->toIso8601String(),
            // The card's counts and its schedule/capacity block (JR3). Both were already computed for
            // ONE form on the hub and were never on this page — `description` above is the third of the
            // same kind, and the only one that was already on the wire with nothing rendering it.
            'stats' => $metrics->stats((string) $form->id),
            // Through the SAME shared view the guest runtime, the encode screen and the hub use, so the
            // list cannot invent a fifth answer to "is this form taking responses right now".
            'schedule' => FormScheduleView::present($form, $metrics->consumed($form)),
            // The identity chip's index (JR3). Derived rather than stored: `crc32` of the uuid is stable
            // for the life of the form, needs no column and no backfill, and being server-side means a
            // future PDF or digest paints the same form the same colour as this page does. Six because
            // the scale has six hues — see the payload note in `theme-overrides.css` for why six was the
            // ceiling. It carries no meaning: the status pill beside it is what encodes state.
            //
            // ⚠️ `sprintf('%u', …)` IS NOT DECORATION. `crc32()` returns a SIGNED int, and on a 32-bit
            // build any checksum above 2^31 comes back negative — `-5 % 6 + 1` is `-4`, which emits
            // `var(--mds-form-identity--4)`, a token that does not exist, so the card loses its edge and
            // the glyph falls back to body colour. Every environment here is 64-bit today, which is
            // exactly what makes it the kind of thing nobody would find.
            'identity' => ((int) sprintf('%u', crc32((string) $form->id)) % 6) + 1,
            'versions' => $versions->map(fn (FormVersion $v): array => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'status' => $v->status->value,
                'published_at' => $v->published_at?->toIso8601String(),
            ])->all(),
            'can' => [
                'edit' => $user->can('update', $form),
                'publish' => $user->can('publish', $form),
                'delete' => $user->can('delete', $form),
                // Manual-encode entry point (F4b) — the SubmissionPolicy folds in "form is published",
                // so this is false for a draft-only form and the row action stays hidden until publish.
                'encode' => $user->can('create', [Submission::class, $form]),
                // "Save as template" (G9a) — a read/derive of the form, so it gates on view, not update.
                'template' => $user->can('view', $form),
                // Per-form response statistics (I10c) — also a read/derive, so also `view`. Named separately
                // rather than reusing `can.template`: FormPolicy::view and ::update resolve to the same
                // predicate TODAY, and a row action riding on that coincidence would follow the wrong one the
                // day they diverge. It must match the route's own gate exactly: FormListScopingTest pins the
                // key here, and FormAnalyticsGateTest pins the route's refusals — neither alone would.
                'analytics' => $user->can('view', $form),
            ],
        ];
    }

    /**
     * @param  Collection<int, FormVersion>  $versions
     */
    private function versionNumber($versions, ?string $versionId): ?int
    {
        if ($versionId === null) {
            return null;
        }

        return $versions->firstWhere('id', $versionId)?->version_number;
    }
}
