<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\ScopeNode;
use App\Models\Submission;
use App\Models\User;
use App\Policies\FormPolicy;
use App\Support\Forms\FormScheduleView;
use App\Support\Forms\FormTabSet;
use Illuminate\Support\Collection;

/**
 * Read model for the form hub — `GET /forms/{form}` (Increment J2b, PRD §3.7 "a form is a hub … rather than
 * a row that fans out into unlinked screens").
 *
 * A presenter rather than assembly in the controller, per the house rule `AppSettingsPresenter` and
 * `PreferencesController` both state: `scripts/controller-gate.php` caps a controller method at cyclomatic
 * complexity 10 and counts every `??` and `&&` toward it, and it does not scan `app/Services`.
 *
 * ── ⚠️ EVERY COUNT ON THIS PAGE IS VISIBILITY-SCOPED, AND NOT BY DEFENSIVENESS ─────────────────────────
 * The hub is reachable by all five roles ({@see FormPolicy::viewOverview()}), including the
 * two that `can:view,form` refuses. So the response totals go through {@see Submission::scopeVisibleTo()}
 * — the same predicate the inbox uses — rather than a bare `count()`. Today that yields the same number
 * for every role who can open the hub at all (an org-wide reader sees everything; a grant-holder's grant
 * covers this whole form), and the scope is there so it STAYS the same number as the visibility rule
 * evolves, instead of the hub quietly becoming the one surface that answers a different question from the
 * list it links to. That is ADR-0011 §D2's drift failure, which the analytics substrate exists to avoid.
 *
 * ── ⚠️ THE SHARE BLOCK IS ABSENT, NEVER EMPTY, FOR A ROLE THAT CANNOT EDIT ─────────────────────────────
 * It carries the guest-access toggle, the bot challenge and the per-form rate limit — settings, not facts —
 * so it is omitted for anyone without `update`. Absent rather than present-and-blank is the same rule J1's
 * search arms follow and ADR-0011 §D9's absent-not-locked doctrine: a key with an empty value is a claim,
 * an absent key is the truth. The Vue page keys the Share tab off its presence.
 *
 * ── THE TAB SET IS COMPUTED SERVER-SIDE, AND IT LIVES IN {@see FormTabSet} ─────────────────────────────
 * Each tab's gate is a policy question, so resolving them server-side keeps "which destinations exist for
 * this reader" in one place and makes it assertable in Pest rather than only in Vitest. A refused tab is
 * ABSENT from the array; nothing renders a disabled destination. It was a private method here until the
 * analytics page needed the identical strip — see that class for why one encoding rather than two.
 */
final class FormHubPresenter
{
    /** Rows in the "latest responses" panel — a taste, not a list; the Submissions tab is the list. */
    private const int RECENT_LIMIT = 5;

    public function __construct(private readonly FormSharePresenter $share) {}

    /**
     * @return array<string, mixed>
     */
    public function show(Form $form, User $user): array
    {
        $canUpdate = $user->can('update', $form);

        /** @var Collection<int, FormVersion> $versions */
        $versions = $form->versions()->orderByDesc('version_number')->get();

        $payload = [
            'form' => [
                'id' => $form->id,
                'title' => $form->title,
                'description' => $form->description,
                'status' => $form->status->value,
                'scope_node_name' => $this->scopeNodeName($form),
                'current_version' => $this->versionNumber($versions, $form->current_published_version_id),
                'draft_version' => $this->versionNumber($versions, $form->draft_version_id),
                'updated_at' => $form->updated_at?->toIso8601String(),
            ],
            'stats' => $this->stats($form, $user),
            'schedule' => $this->schedule($form),
            'versions' => $versions->map(fn (FormVersion $v): array => [
                'id' => $v->id,
                'version_number' => $v->version_number,
                'status' => $v->status->value,
                'published_at' => $v->published_at?->toIso8601String(),
            ])->all(),
            'recent' => $this->recent($form, $user),
            'tabs' => FormTabSet::for($form, $user),
            'can' => [
                'edit' => $canUpdate,
                'publish' => $user->can('publish', $form),
                'encode' => $user->can('create', [Submission::class, $form]),
                // A read/derive of the form, so it gates on view like the XLSForm export does — NOT on
                // `viewOverview`. The two differ for exactly the roles this page was widened for, and a
                // Reviewer must not be offered a button that 403s.
                'template' => $user->can('view', $form),
            ],
        ];

        if ($canUpdate) {
            $payload['share'] = $this->share->present($form);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private function stats(Form $form, User $user): array
    {
        $visible = fn () => Submission::query()->where('form_id', $form->id)->visibleTo($user);

        $latest = $visible()->countable()->max('submitted_at');

        return [
            // `countable()` — the same display default the inbox applies, so "142 responses" here and the
            // inbox's own list cannot disagree about whether an unfinished draft is a response.
            'responses' => $visible()->countable()->count(),
            'drafts' => $visible()->where('submissions.status', SubmissionStatus::Draft->value)->count(),
            'last_response_at' => $latest !== null ? (string) $latest : null,
        ];
    }

    /**
     * The schedule + capacity block, through the SAME {@see FormScheduleView} the guest runtime and the
     * manual-encode screen already share, so the hub cannot invent a fourth answer to "is this form taking
     * responses right now". It hands back `acceptance` as one of `open` / `opens_soon` / `closed` /
     * `capacity_reached`, which is richer than a boolean and is vocabulary the app already renders.
     *
     * ⚠️ THE CAPACITY COUNT IS DELIBERATELY *NOT* VISIBILITY-SCOPED, unlike every count in {@see stats()}.
     * Capacity is a property of the form, not of the reader — a form that is full is full for everyone. A
     * `visibleTo()` here would let two roles disagree about whether the form is still accepting answers,
     * which is precisely the ADR-0011 §D2 drift the shared view exists to prevent. RLS still bounds it to
     * the tenant.
     *
     * ⚠️ AND IT IS `consumesCapacity()`, NOT `countable()` — a different predicate on purpose (I9a): a
     * `screened_out` respondent was finalized but shown no questions, so charging them a paid slot was the
     * bug that increment fixed. Copying `stats()`'s scope here would re-introduce it on this surface.
     * Uncapped forms pay for no count at all, matching `PublicFormPresenter::schedule()`.
     *
     * @return array{opens_at: ?string, closes_at: ?string, timezone: string, max_responses: ?int, acceptance: string, remaining: ?int}
     */
    private function schedule(Form $form): array
    {
        $consumed = $form->max_responses === null
            ? null
            : Submission::query()->where('form_id', $form->id)->consumesCapacity()->count();

        return FormScheduleView::present($form, $consumed);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recent(Form $form, User $user): array
    {
        return array_values(
            Submission::query()
                ->where('form_id', $form->id)
                ->visibleTo($user)
                ->countable()
                // uuidv7 → recency without needing a `submitted_at` index, the ordering the inbox uses.
                ->orderByDesc('id')
                ->limit(self::RECENT_LIMIT)
                ->get()
                ->map(fn (Submission $s): array => [
                    'id' => $s->id,
                    'status' => $s->status->value,
                    'source_label' => $s->source->label(),
                    'submitted_at' => ($s->submitted_at ?? $s->created_at)?->toIso8601String(),
                ])
                ->all()
        );
    }

    /**
     * The node's NAME, not its id — the row already carries the id and the hub has no picker on it (that is
     * the forms list's, gated on `scopes.manage`). A reader who cannot manage scopes can still legitimately
     * see which part of the hierarchy a form belongs to; it is printed on every row of the ledger already.
     */
    private function scopeNodeName(Form $form): ?string
    {
        if ($form->scope_node_id === null) {
            return null;
        }

        return ScopeNode::query()->whereKey($form->scope_node_id)->value('name');
    }

    /**
     * @param  Collection<int, FormVersion>  $versions
     */
    private function versionNumber(Collection $versions, ?string $versionId): ?int
    {
        if ($versionId === null) {
            return null;
        }

        return $versions->firstWhere('id', $versionId)?->version_number;
    }
}
