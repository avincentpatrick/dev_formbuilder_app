<?php

declare(strict_types=1);

namespace App\Services\Feedback;

use App\Enums\FeedbackParty;
use App\Enums\FeedbackSource;
use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Models\FeedbackComment;
use App\Models\FeedbackReport;
use App\Models\User;
use App\Policies\FeedbackReportPolicy;
use App\Services\Audit\AuditLogPresenter;
use App\Support\Search\KeywordFilter;
use App\Support\Search\ListEmptyReason;
use App\Support\Search\SearchTerms;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use LogicException;

/**
 * Read model for the TENANT-side feedback pages (Increment I7a, PRD Feature #11): the workspace's list at
 * `/feedback` (Owner/Admin, `feedback.view`) and every member's own page at `/feedback/mine`. Mirrors
 * {@see AuditLogPresenter}: flat rows + `meta` + a filter catalog + a server-computed `empty_reason`.
 *
 * ── WHAT THESE PAGES MAY CHANGE, SINCE M179 ──────────────────────────────────────────────────────────────
 * Until M179 both pages were read-only: PRD Feature #11 gives the SUPPORT lifecycle — triage, Resolved,
 * Declined — to the platform support team, and a workspace that could mark its own report Resolved would be
 * resolving it in the operator's queue. That is still so. What M179 (`D151`) adds is the TESTERS' half: on a
 * report the support team marked Resolved, **It works** or **Still a problem**, and a comment on any report.
 * Every row says which of those this viewer may use (`actions`, `can_comment`), computed here from the member
 * graph and {@see FeedbackReportPolicy}, so neither page needs to know the lifecycle.
 *
 * RLS does the tenant scoping — the strict policy on `feedback_reports` means no query here can see another
 * tenant's rows even if a filter were wrong — so there is no `where('tenant_id', …)`, exactly as there is none
 * in the audit viewer. Within the workspace RLS hides nothing, which is why `mine()`'s two predicates matter.
 */
final class FeedbackPresenter
{
    private const PER_PAGE = 25;

    /** What a row's thread needs, eager-loaded so a page of rows costs a fixed number of queries. */
    private const THREAD = ['comments.author:id,name', 'confirmer:id,name'];

    /**
     * One page of this tenant's feedback (Owner/Admin).
     *
     * ⚠️ `q` MATCHES `remarks` AND `route`, NOT THE REPORTER'S NAME. The two matched columns are this
     * page's whole content — what was said and where — and both are rendered in the table. The reporter is
     * a `users` relation, so matching it means a subquery for one column, and the page already offers no
     * reporter filter to pair it with; adding one is a better answer than making the keyword box quietly
     * mean three things. Recorded in TESTING-GUIDE §17's per-list table as a decision, not an omission.
     *
     * `applyLike` rather than a tsvector: `feedback_reports` has no generated column, `remarks` is free
     * prose a tenant reads in dozens rather than thousands, and J1b measured that an index on `~~*` would
     * be unreachable under RLS regardless.
     *
     * M175 (`R-2ce2f274`): `q` also matches the page's TITLE and full ADDRESS — the title is now what the page
     * column shows — and `type` narrows by kind. M179: `source` narrows by where a report came from, and every
     * row carries the support team's note (`resolution_note` — until M179 only the reporter ever saw it) and
     * the thread.
     *
     * @param  array{status?: ?string, type?: ?string, source?: ?string, q?: ?SearchTerms}  $filters
     * @return array<string, mixed>
     */
    public function index(array $filters, User $viewer): array
    {
        $terms = $filters['q'] ?? SearchTerms::parse(null);

        $paginator = FeedbackReport::query()
            ->with(['user:id,name', 'resolver:id,name', ...self::THREAD])
            ->tap(fn (Builder $q) => KeywordFilter::applyLike($q, $terms, [
                'feedback_reports.remarks',
                'feedback_reports.route',
                'feedback_reports.page_title',
                'feedback_reports.page_url',
            ]))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['type'] ?? null, fn ($q, $v) => $q->where('type', $v))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->orderByDesc('submitted_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var Collection<int, FeedbackReport> $items */
        $items = collect($paginator->items());

        return [
            'data' => $items->map(fn (FeedbackReport $r): array => [
                'id' => (string) $r->getKey(),
                'route' => $r->route,
                'page_url' => $r->page_url,
                'page_title' => $r->page_title,
                // Null on a report sent before the kind existed — the list shows no kind rather than a guess.
                'type' => $r->type?->value,
                'type_label' => $r->type?->label(),
                'remarks' => $r->remarks,
                'status' => $r->status->value,
                'status_label' => $r->status->label(),
                'submitted_at' => $r->submitted_at->toIso8601String(),
                'resolved_at' => $r->resolved_at?->toIso8601String(),
                // Three states, not two — the `users` join-shape policy hides a REMOVED member's row from
                // a colleague, so a live non-null `user_id` can still resolve to null here. Rendering that
                // as "System" would misattribute a person's report to the software; the page renders
                // "Former member" instead. Same distinction AuditLogPresenter::actorLabel() draws.
                'reporter' => $r->user?->name,
                'has_screenshot' => $r->screenshot_attachment_id !== null,
                ...$this->threadFields($r, $viewer),
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
            'filters' => [
                'statuses' => FeedbackStatus::options(),
                'types' => FeedbackType::options(),
                'sources' => FeedbackSource::options(),
                'applied' => [
                    'status' => $filters['status'] ?? null,
                    'type' => $filters['type'] ?? null,
                    'source' => $filters['source'] ?? null,
                    // The CLAMPED string, so the box re-renders what actually ran (J1e).
                    'q' => $terms->raw(),
                ],
            ],
            // Server-computed, one prop, one meaning — the I2 rule. "Filtered to zero" inferred on the
            // client breaks the moment the server defaults or clamps anything. Shared with the other five
            // lists since J1e so the two strings cannot be misspelled apart.
            'empty_reason' => ListEmptyReason::for(
                $items->isNotEmpty(),
                ($filters['status'] ?? null) !== null || ($filters['type'] ?? null) !== null
                    || ($filters['source'] ?? null) !== null || ! $terms->isEmpty(),
            ),
        ];
    }

    /**
     * A member's Feedback page (M175, `R-9bfd832e`, `D139`; M179, `D150`): **My reports** and **Testing-round
     * comments** — two lists, never one with an `OR`.
     *
     * - **My reports** — the member's OWN reports sent with the Feedback button, paginated (its shape unchanged
     *   since M175). ⚠️ THE `user_id` PREDICATE IS THE WHOLE BOUNDARY: `feedback_reports` is strict TENANT
     *   isolation, so under RLS a member's query sees every colleague's report in the workspace, and
     *   `MyFeedbackTest`'s colleague case is what proves the predicate is here. `source = button` keeps the
     *   Owner's relayed reports — they are the relayer, not the reporter — out of their own list.
     * - **Testing-round comments** (`testing_round`) — every RELAYED report of the workspace, unpaginated (a
     *   round is dozens of items, read and checked together), open ones first: Resolved — waiting for a tester's
     *   **It works** — then Ongoing and New, then Confirmed and Declined. `open` counts what is not yet Confirmed
     *   or Declined; the page shows the tab only while it is above zero, so the view goes away by itself once
     *   the round is done (`D150`). The `source = relayed` predicate is this list's whole boundary.
     *
     * `view` is the tab the page opens on: the testing round when asked for (`?view=testing`, the link the
     * Feedback panel and the notifications use) or when the member has sent nothing themselves — and only while
     * the tab exists.
     *
     * Screenshots are NAMED (`has_screenshot`), never linked: ADR-0015 §D9 reads a feedback screenshot under
     * `feedback.view` on every route that serves it or on none, and most members do not hold it.
     *
     * @return array<string, mixed>
     */
    public function mine(User $viewer, int $page, ?string $view = null): array
    {
        $paginator = FeedbackReport::query()
            ->with(self::THREAD)
            ->where('user_id', $viewer->getKey())
            ->where('source', FeedbackSource::Button->value)
            ->orderByDesc('submitted_at')
            ->paginate(self::PER_PAGE, page: max(1, $page));

        /** @var Collection<int, FeedbackReport> $items */
        $items = collect($paginator->items());

        /** @var Collection<int, FeedbackReport> $relayed */
        $relayed = FeedbackReport::query()
            ->with(self::THREAD)
            ->where('source', FeedbackSource::Relayed->value)
            ->get()
            ->sortBy(fn (FeedbackReport $r): string => self::roundOrder($r->status).'|'.$r->submitted_at->format('YmdHisu').'|'.$r->reference)
            ->values();

        $open = $relayed->filter(static fn (FeedbackReport $r): bool => $r->status->isOpenForTesters())->count();

        return [
            'data' => $items->map(fn (FeedbackReport $r): array => $this->memberRow($r, $viewer))->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
            'empty_reason' => ListEmptyReason::for($items->isNotEmpty(), false),
            'testing_round' => [
                'open' => $open,
                'data' => $relayed->map(fn (FeedbackReport $r): array => $this->memberRow($r, $viewer))->all(),
            ],
            'view' => $open > 0 && ($view === 'testing' || $paginator->total() === 0) ? 'testing' : 'mine',
        ];
    }

    /**
     * How many of this workspace's relayed reports are still open — not Confirmed, not Declined (M179, `D150`) —
     * for the `feedback.testing_round_open` shared prop, so the Feedback panel can point at the testing round
     * while there is something to check. Null without a workspace, where no query runs (the `entitlements`
     * prop's fail-closed rule).
     */
    public function testingRoundOpen(): ?int
    {
        if (TenantContext::currentTenantId() === null) {
            return null;
        }

        return FeedbackReport::query()
            ->where('source', FeedbackSource::Relayed->value)
            ->whereNotIn('status', [FeedbackStatus::Confirmed->value, FeedbackStatus::Declined->value])
            ->count();
    }

    /**
     * @return array<string, mixed>
     */
    private function memberRow(FeedbackReport $r, User $viewer): array
    {
        return [
            'id' => (string) $r->getKey(),
            'type' => $r->type?->value,
            'type_label' => $r->type?->label(),
            'route' => $r->route,
            'page_url' => $r->page_url,
            'page_title' => $r->page_title,
            'remarks' => $r->remarks,
            'status' => $r->status->value,
            'status_label' => $r->status->label(),
            'submitted_at' => $r->submitted_at->toIso8601String(),
            'resolved_at' => $r->resolved_at?->toIso8601String(),
            'has_screenshot' => $r->screenshot_attachment_id !== null,
            ...$this->threadFields($r, $viewer),
        ];
    }

    /**
     * What every row on both pages carries since M179: where it came from, the support team's note, who confirmed
     * it, the thread, and what THIS viewer may do with it.
     *
     * @return array<string, mixed>
     */
    private function threadFields(FeedbackReport $r, User $viewer): array
    {
        $takesPart = $viewer->can('participate', $r);

        return [
            'source' => $r->source->value,
            'source_label' => $r->source_label,
            'reference' => $r->reference,
            'title' => $r->title,
            'resolution_note' => $r->resolution_note,
            'confirmed_at' => $r->confirmed_at?->toIso8601String(),
            // Null when nobody confirmed it — and when the confirming member has since left, which the page
            // reads as a former member rather than as nobody.
            'confirmer' => $r->confirmer?->name,
            'comments' => $r->comments->map(static fn (FeedbackComment $c): array => [
                'id' => (string) $c->getKey(),
                'author' => $c->author_kind === FeedbackParty::Support ? 'Support team' : ($c->author->name ?? 'Former member'),
                'author_kind' => $c->author_kind->value,
                'action' => $c->action?->value,
                'body' => $c->body,
                'created_at' => $c->created_at->toIso8601String(),
                'has_screenshot' => $c->screenshot_attachment_id !== null,
            ])->values()->all(),
            'actions' => $takesPart ? self::memberActions($r->status) : [],
            'can_comment' => $takesPart,
        ];
    }

    /**
     * The tester's moves from this status, as the page names them — straight from the member graph, so the
     * buttons and the server's guard cannot disagree.
     *
     * @return list<array{verb: string, label: string}>
     */
    private static function memberActions(FeedbackStatus $status): array
    {
        return array_map(static fn (FeedbackStatus $to): array => match ($to) {
            FeedbackStatus::Confirmed => ['verb' => 'confirm', 'label' => 'It works'],
            FeedbackStatus::Ongoing => ['verb' => 'reopen', 'label' => 'Still a problem'],
            default => throw new LogicException("The member graph offers {$to->value}, which no button names."),
        }, $status->allowedTransitions(FeedbackParty::Member));
    }

    /** The testing round's order: what waits for a tester first, what is done last. */
    private static function roundOrder(FeedbackStatus $status): string
    {
        return match ($status) {
            FeedbackStatus::Resolved => '0',
            FeedbackStatus::Ongoing => '1',
            FeedbackStatus::New => '2',
            FeedbackStatus::Confirmed => '3',
            FeedbackStatus::Declined => '4',
        };
    }
}
