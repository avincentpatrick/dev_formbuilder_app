<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Policies\SubmissionPolicy;
use App\Services\Analytics\AnswerValueAggregator;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Search\SearchService;
use App\Services\Submissions\SubmissionPipeline;
use App\Support\Search\SearchTerms;
use App\Support\Submissions\SubmissionReference;
use App\Support\Submissions\SubmissionReferenceIssuer;
use Database\Factories\SubmissionFactory;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A submission (data-dictionary §7). FKs to the immutable `form_version_id` it was collected against,
 * never to the live form rows. Mutable through its review lifecycle → the plain `strict` RLS shape.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_id
 * @property string $form_version_id
 * @property ?string $respondent_user_id
 * @property SubmissionStatus $status
 * @property SubmissionSource $source
 * @property ?string $client_submission_uuid
 * @property string $reference
 * @property ?string $locale
 * @property ?string $guest_contact_email
 * @property ?int $completeness_percent
 * @property Carbon|null $last_saved_at
 * @property Carbon|null $draft_expires_at
 * @property ?string $draft_current_step
 * @property ?string $validated_by
 * @property Carbon|null $validated_at
 * @property ?string $returned_reason
 * @property ?string $remarks
 * @property Carbon|null $submitted_at
 * @property Carbon|null $finalized_at
 * @property Carbon|null $pii_erased_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
class Submission extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<SubmissionFactory> */
    use HasFactory;

    use HasUuidv7;
    use SoftDeletes;

    /**
     * Mints the short handle (J2e). Every row needs one — `reference` is `NOT NULL` — and there are seven
     * Eloquent writers, only two of which are the production services. Putting the mint on the MODEL rather
     * than in {@see SubmissionPipeline} is what makes it an invariant instead of a
     * convention the eighth writer forgets: the factory, both seeders and every `Submission::factory()` call
     * in the suite are covered without touching any of them.
     *
     * ⚠️ TRAIT BOOT ORDER IS IRRELEVANT HERE, AND SAYING SO IS THE POINT — `booted()` runs after
     * `bootTraits()`, so this fires after both `BelongsToTenant`'s and `HasUuids`' own `creating` listeners.
     * It does not matter, because the reference is RANDOM rather than derived: it reads neither `id` (a
     * derivation from the uuid is precisely the defect J2e removes — see {@see SubmissionReference}) nor
     * `tenant_id` (uniqueness is enforced per tenant by `submissions_tenant_id_reference_unique`, not by
     * anything in PHP). This hook is order-independent by construction. "Put it after the tenant hook" is the
     * first thing a reader assumes it needs, so it is written down that it does not.
     *
     * The `=== null` guard keeps a deliberately `forceFill`ed reference — which is how a test pins a known
     * code, and how the retry in both writers gets a FRESH one (a re-run builds a new model, whose attribute
     * is null again).
     *
     * ⚠️ Anything that suppresses model events suppresses THIS, and now the failure is a hard 23502 rather
     * than something subtle. {@see DatabaseSeeder} carries the list.
     */
    protected static function booted(): void
    {
        static::creating(function (self $submission): void {
            // ⚠️ `getAttribute()`, NOT `$submission->reference`, and the reason is a real tension rather than
            // style. The `@property string $reference` annotation above is correct for every reader of a
            // PERSISTED row — the column is NOT NULL — but it is exactly wrong for this one moment, when the
            // attribute has not been set yet. Written as `$submission->reference === null` the analyser sees
            // `string === null`, calls the comparison always-false, and the increment ships a PHPStan error
            // rather than a guard. Reaching through `getAttribute()` states "unset" without lying about the
            // column's shape everywhere else.
            if ($submission->getAttribute('reference') === null) {
                $submission->reference = app(SubmissionReferenceIssuer::class)->issue();
            }
        });
    }

    /**
     * ⚠️ `reference` IS DELIBERATELY ABSENT. It is server-issued identity: making it mass-assignable would let
     * any `update()` reachable from a request payload rewrite the handle a respondent has already written
     * down. Same call as `forms.public_slug`'s share columns and `scope_nodes`' path columns; pinned by
     * `SubmissionReferenceIssuanceTest`.
     */
    protected $fillable = [
        'tenant_id',
        'form_id',
        'form_version_id',
        'respondent_user_id',
        'status',
        'source',
        'client_submission_uuid',
        'guest_token',
        'guest_ip',
        'guest_user_agent',
        'guest_contact_email',
        'device_id',
        'app_version',
        'locale',
        'completeness_percent',
        'last_saved_at',
        'draft_expires_at',
        'draft_current_step',
        'source_batch_id',
        'validated_by',
        'validated_at',
        'returned_reason',
        'remarks',
        'submitted_at',
        'finalized_at',
        'pii_erased_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => SubmissionStatus::class,
            'source' => SubmissionSource::class,
            'completeness_percent' => 'integer',
            'last_saved_at' => 'datetime',
            'draft_expires_at' => 'datetime',
            'validated_at' => 'datetime',
            'submitted_at' => 'datetime',
            'finalized_at' => 'datetime',
            'pii_erased_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Form, $this> */
    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function formVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class);
    }

    /** @return BelongsTo<User, $this> */
    public function respondent(): BelongsTo
    {
        return $this->belongsTo(User::class, 'respondent_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    /** @return HasOne<SubmissionAnswer, $this> */
    public function answers(): HasOne
    {
        return $this->hasOne(SubmissionAnswer::class);
    }

    /** @return HasMany<SubmissionAnswerIndex, $this> */
    public function answerIndex(): HasMany
    {
        return $this->hasMany(SubmissionAnswerIndex::class);
    }

    /**
     * THE countable-submission predicate (ADR-0011 §D2): a submission counts when its `status` is not
     * `draft` and it is not soft-deleted. Named once here and binding on H11's dashboard and H24a's
     * analytics alike, so the two surfaces cannot drift into two definitions of "a response" — the failure
     * that makes a dashboard and an inbox disagree in front of a customer.
     *
     * The `deleted_at IS NULL` half comes from the {@see SoftDeletes} global scope, not from this method —
     * which means **this scope only carries D2 when `Submission` is the query ROOT.** A query rooted on
     * `submission_answer_index` and joined back to `submissions` would inherit neither, and since that
     * table's FK cascade fires on HARD delete only, its rows outlive a soft-deleted submission. That is why
     * {@see AnswerValueAggregator} is deliberately rooted here and joins OUTWARD to
     * the index rather than the other way round: it makes D2 structural rather than remembered.
     *
     * Visibility is deliberately NOT embedded — {@see scopeVisibleTo()} is a different axis, and three of the
     * non-adopters below are public/guest paths that must not consult it.
     *
     * ── Deliberate non-adopters, so nobody "finishes the refactor" ───────────────────────────────────────
     * Three sites used to spell this predicate identically while answering a DIFFERENT question — "how many
     * responses have consumed the paid `max_responses` cap?" Since I9a they no longer spell it at all: they
     * adopt {@see scopeConsumesCapacity()}, which is the NAME this paragraph always said the other question
     * deserved. Binding a purchased capacity cap to the analytics definition of a response would mean a later
     * analytics change — excluding `returned`, say — silently changing what a customer bought. Keeping the
     * two scopes separate is what let `screened_out` diverge from `draft` in one of them and not the other.
     *
     * A further site, `ReconcileTenantUsageJob`, is a metering COUNT with no status predicate at all (drafts
     * fall out only incidentally, because their `submitted_at` is NULL) — a non-adopter of BOTH scopes. Same
     * decoupling argument: it is billing.
     *
     * ⚠️ `screened_out` IS countable and is deliberately NOT excluded here. It is a real row the tenant
     * received and the inbox lists it by default, so dropping it from analytics would make the tile disagree
     * with the list a reviewer is looking at — the exact §D2 failure this scope exists to prevent. It is also
     * the predicate `submissions_analytics_series_idx` is partial on, so changing it is a migration.
     *
     * The column is QUALIFIED. `status` is unqualified-ambiguous the moment a caller joins `forms`, which
     * also has one — and H24a's scope-node breakdown does exactly that. An unqualified scope would work
     * everywhere it is used today and fail on the first join, which is the kind of latent break a shared
     * predicate must not carry.
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public function scopeCountable(Builder $query): Builder
    {
        return $query->where('submissions.status', '!=', SubmissionStatus::Draft->value);
    }

    /**
     * THE capacity predicate (Increment I9a): a submission consumed one of the form's paid `max_responses`
     * slots. The sibling of {@see scopeCountable()} and deliberately NOT the same predicate — see that
     * method's non-adopters paragraph for why the two questions are kept apart, and this scope for what
     * happens once they answer differently.
     *
     * A `draft` has not been finalized. A `screened_out` submission was finalized but the respondent was
     * shown no questions at all ({@see SubmissionStatus}) — charging a paid slot for a response that was
     * never solicited is the live bug `docs/workflow-branching-design.md:140` recorded and deferred.
     * Everything else — including `archived`, which is why `screened_out` must never be archivable —
     * consumed one.
     *
     * ⚠️ TWO SEPARATE NOT-EQUAL CONJUNCTS, NEVER `whereNotIn`, AND THAT IS NOT A STYLE PREFERENCE. (`!=` is
     * spelled here to match `scopeCountable()`; Postgres's grammar folds it to `<>` before planning, so the
     * two spellings reach the planner as the same node.) The hot
     * consumer is `FormAcceptanceGuard::assertCapacity()`, running under `lockForUpdate()` inside the
     * finalize transaction, and it is served by the partial index `submissions_form_finalized_idx`, whose
     * predicate is `WHERE status <> 'draft'`. Postgres will use a partial index only when it can PROVE the
     * index predicate from the query's clauses; a bare `status <> 'draft'` conjunct matches it verbatim.
     * `whereNotIn` compiles to a `ScalarArrayOpExpr` (`<> ALL (...)`), from which that implication is not
     * guaranteed to be established — which would silently turn the count into a scan on the ingest path,
     * under a lock, with no test failing. `ScreenedOutTest`'s "spells the capacity predicate as two
     * not-equal conjuncts, never as NOT IN" case pins the emitted SQL and its bindings for this reason.
     *
     * The column is QUALIFIED for the same reason `scopeCountable()`'s is: `forms` also has a `status`.
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public function scopeConsumesCapacity(Builder $query): Builder
    {
        return $query
            ->where('submissions.status', '!=', SubmissionStatus::Draft->value)
            ->where('submissions.status', '!=', SubmissionStatus::ScreenedOut->value);
    }

    /**
     * Restrict the inbox list to what `$user` may see (F7). RLS already scopes every query to the tenant;
     * this adds the role-level split that {@see SubmissionPolicy} enforces per-row: Owner/Admin/
     * Viewer (who hold `dashboard.org.view`) see all tenant submissions, so no extra predicate; Form Editor/
     * Reviewer see only forms they hold a grant on. A user with no grants gets an empty set.
     *
     * Since G10a the grant subquery comes from {@see ResourceGrantResolver}, the same object
     * {@see SubmissionPolicy} consults for single-row checks — so the list and the per-row check cannot
     * drift into "a row appears in the inbox but 403s when opened".
     *
     * I8a adds the RESPONDENT arm, mirroring {@see SubmissionPolicy::view()}'s new clause. It is the other
     * direction of the same invariant: without it a respondent could OPEN a submission the inbox never
     * lists, which is the drift the paragraph above exists to prevent, merely running the other way.
     *
     * ⚠️ THE `orWhere` IS WRAPPED, AND THE REASON IS NARROWER THAN IT LOOKS. Flat, the OR would associate
     * against every predicate already on the builder — the inbox composes status filters, date ranges and
     * form filters onto this scope — and `(A and B) or C` is not `A and (B or C)`; a "returned this week"
     * filter would additionally return every submission the user ever made, of any status.
     *
     * That does NOT happen when this is invoked as a scope: {@see Builder::callScope()} counts the wheres
     * before and after and calls `addNewWheresWithinGroup()`, so the framework already nests whatever a
     * local scope adds. Verified by mutation — flattening this leaves the whole suite green. The wrapper
     * stays anyway, because the protection is a property of the CALL MECHANISM rather than of this method:
     * it evaporates the moment anyone invokes `scopeVisibleTo($query, $user)` directly, or hoists the body
     * into a `where(fn ($q) => ...)` elsewhere. Correctness that depends on how you were called is worth
     * one closure to make unconditional.
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('dashboard.org.view')) {
            return $query;
        }

        return $query->where(function (Builder $scoped) use ($user): void {
            $scoped
                ->whereIn('form_id', app(ResourceGrantResolver::class)->grantedFormIdsQuery($user))
                ->orWhere('respondent_user_id', $user->getKey());
        });
    }

    /**
     * THE keyword predicate for a submission (Increment J1e) — reviewer remarks and returned reason (through
     * the generated `search_vector`), the parent form's title, its short `reference`, or its full id.
     *
     * ⚠️ THE TWO IDENTITY BRANCHES ARE EXACT MATCHES AS OF J2e, NOT PREFIXES. The `id::text LIKE 'xxxxxxxx%'`
     * arm that lived here is gone: under uuidv7 those eight characters are a millisecond timestamp's top 32
     * bits, so it returned every row created in the same ~49-day window. `submissions.reference` is the
     * replacement handle — see {@see SubmissionReference}.
     *
     * ⚠️ IT IS A SCOPE, AND IT IS SHARED, AND BOTH HALVES ARE THE POINT. It was `SubmissionSearchArm`'s
     * private builder until J1e gave the INBOX a keyword box; leaving it there would have meant two
     * encodings of "what does searching a submission mean", which is how a search result and the list it
     * links into start disagreeing. Being a scope rather than a static helper is what earns the
     * {@see Builder::callScope()} wrapping that {@see scopeVisibleTo()}'s docblock explains at length —
     * composing an OR onto a builder that already carries one is precisely the hazard here, since the inbox
     * stacks status/form/source filters on top.
     *
     * ⚠️ ANSWER TEXT IS NOT MATCHED — a user decision taken 2026-08-09, with the four supporting arguments in
     * `2026_08_11_000002_add_search_vector_to_submissions.php`. The short version: GDPR erasure has no writer
     * yet (`submissions.pii_erased_at` has zero writers), so a second store of answer text is one nothing can
     * scrub. Do not widen this scope without reading them.
     *
     * A NO-OP on empty terms, so the inbox can `->matchingKeyword($terms)` unconditionally on a bare visit.
     * The arms never reach it empty ({@see SearchService::results()} short-circuits), so
     * this costs the search path nothing.
     *
     * ⚠️ NO `ts_rank` ORDERING GOES WITH THIS. Most matches come from the form-title or reference branches,
     * which contribute NOTHING to `submissions.search_vector` — ranking on that vector would sort the
     * majority of results by a score of zero and produce an order that looks meaningful and is not. Both
     * callers order by `id DESC` (uuidv7 → recency), which is what the inbox has always shown.
     *
     * @param  Builder<Submission>  $query
     * @return Builder<Submission>
     */
    public function scopeMatchingKeyword(Builder $query, SearchTerms $terms): Builder
    {
        if ($terms->isEmpty()) {
            return $query;
        }

        return $query->where(function (Builder $match) use ($terms): void {
            $match->whereRaw(
                "submissions.search_vector @@ to_tsquery('simple', ?)",
                [$terms->tsQuery()]
            );

            // Scoped to the tenant only, NOT to `Form::scopeVisibleTo()`, and that is deliberate: a Reviewer
            // holds no `forms.*` key, so form visibility would be empty for them — and the form title is the
            // only label the inbox has ever shown them for their own submissions. Applying it would make a
            // reviewer's own work unfindable by the one word they know it by. The submission rows are still
            // bounded by `visibleTo`; the title only decides which of the rows they can ALREADY see match.
            $match->orWhereIn('submissions.form_id', function ($sub) use ($terms): void {
                $sub->select('id')
                    ->from('forms')
                    ->whereRaw("forms.search_vector @@ to_tsquery('simple', ?)", [$terms->tsQuery()]);
            });

            // The short handle (J2e). An EQUALITY on an indexed column, replacing the `id::text LIKE 'xxxx%'`
            // scan this scope carried until J2e — which matched a ~49-day uuidv7 timestamp window rather than
            // a row. `submissions_tenant_id_reference_unique` covers `(tenant_id, reference)`, and `text =`
            // measures leakproof on PG17 (`SearchIndexUsageTest`), so unlike `@@` and `ILIKE` this predicate
            // is at least ELIGIBLE to become an index qual under FORCE'd RLS.
            $reference = $terms->referenceCandidate();
            if ($reference !== null) {
                $match->orWhere('submissions.reference', $reference);
            }

            // A full uuid pasted out of a URL or a ticket. Always was an exact lookup; now it is spelled as
            // one instead of as a LIKE over a cast. ⚠️ Safe ONLY because `submissionId()` refuses anything
            // that is not strictly canonical — binding a non-uuid here raises 22P02, i.e. a 500 on a GET.
            $id = $terms->submissionId();
            if ($id !== null) {
                $match->orWhere('submissions.id', $id);
            }
        });
    }
}
