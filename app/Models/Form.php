<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FormBotChallenge;
use App\Enums\FormScheduleState;
use App\Enums\FormStatus;
use App\Enums\ResourceCapacity;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use App\Policies\FormPolicy;
use App\Services\Analytics\AnalyticsFormSet;
use App\Services\Authorization\ResourceGrantResolver;
use App\Services\Forms\FormPresenter;
use Database\Factories\FormFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The durable, logical form record (data-dictionary §2). Deliberately thin — almost all content lives
 * versioned in form_versions/form_sections/form_fields. Strictly tenant-scoped (strict RLS).
 *
 * @property string $id
 * @property string $tenant_id
 * @property ?string $current_published_version_id
 * @property ?string $draft_version_id
 * @property string $title
 * @property ?string $description
 * @property FormStatus $status
 * @property ?string $public_slug
 * @property bool $allow_guest_submissions
 * @property bool $single_page_mode
 * @property bool $save_and_resume
 * @property FormBotChallenge $bot_challenge
 * @property ?int $guest_rate_limit_per_minute
 * @property ?string $confirmation_message
 * @property ?array<string, mixed> $confirmation_message_translations
 * @property array<string, mixed> $capability_flags
 * @property string $default_locale
 * @property array<int, string> $supported_locales
 * @property string $owner_user_id
 * @property ?string $scope_node_id
 * @property string $created_by
 * @property ?Carbon $published_at
 * @property ?Carbon $archived_at
 * @property ?Carbon $opens_at
 * @property ?Carbon $closes_at
 * @property string $timezone
 * @property ?int $max_responses
 * @property ?FormScheduleState $schedule_state
 * @property ?Carbon $created_at
 * @property ?Carbon $updated_at
 * @property ?Carbon $deleted_at
 */
class Form extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<FormFactory> */
    use HasFactory;

    use HasUuidv7;
    use SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'current_published_version_id',
        'draft_version_id',
        'title',
        'description',
        'status',
        'public_slug',
        'allow_guest_submissions',
        'allow_manual_encoding',
        'allow_ocr_single',
        'allow_ocr_linelist',
        'allow_api_import',
        'allow_offline_sync',
        'single_page_mode',
        'save_and_resume',
        'opens_at',
        'closes_at',
        'timezone',
        'max_responses',
        'schedule_state',
        'default_locale',
        'supported_locales',
        'capability_flags',
        'theme',
        'owner_user_id',
        'scope_node_id',
        'created_by',
        'updated_by',
        'published_at',
        'archived_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => FormStatus::class,
            'allow_guest_submissions' => 'boolean',
            'allow_manual_encoding' => 'boolean',
            'allow_ocr_single' => 'boolean',
            'allow_ocr_linelist' => 'boolean',
            'allow_api_import' => 'boolean',
            'allow_offline_sync' => 'boolean',
            'single_page_mode' => 'boolean',
            'save_and_resume' => 'boolean',
            // I8b — deliberately NOT in $fillable. The two older share columns (public_slug,
            // allow_guest_submissions) are fillable for historical reasons that UpdateFormShareRequest's
            // docblock spends a paragraph apologising for; adding two more would hand every form editor
            // the ability to switch a public form's spam protection off as a side effect of an unrelated
            // update(). They are written by FormService::setShareSettings() through forceFill(), audited.
            'bot_challenge' => FormBotChallenge::class,
            'guest_rate_limit_per_minute' => 'integer',
            // Increment H6a — the confirmation template (Doc #26 §6.2). Deliberately NOT in $fillable:
            // the only writer is FormService::setConfirmationMessage()'s forceFill, so mass-assignment
            // can never reach a template-bearing column, and Form has no $hidden/$guarded — a fillable
            // column would serialize into every presenter payload by default.
            'confirmation_message_translations' => 'array',
            'supported_locales' => 'array',
            'capability_flags' => 'array',
            'theme' => 'array',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'max_responses' => 'integer',
            'schedule_state' => FormScheduleState::class,
        ];
    }

    /** @return HasMany<FormVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(FormVersion::class);
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function draftVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'draft_version_id');
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function currentPublishedVersion(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'current_published_version_id');
    }

    /** @return BelongsTo<ScopeNode, $this> */
    public function scopeNode(): BelongsTo
    {
        return $this->belongsTo(ScopeNode::class, 'scope_node_id');
    }

    /**
     * Per-instance access grants naming THIS form directly (Increment G10a — replaces `collaborators()`).
     * Note a user may also reach this form through a grant on its {@see scopeNode()}; only
     * {@see ResourceGrantResolver} answers "who can do what here" completely.
     *
     * @return MorphMany<ResourceGrant, $this>
     */
    public function grants(): MorphMany
    {
        return $this->morphMany(ResourceGrant::class, 'scopeable');
    }

    /**
     * The LIST twin of {@see FormPolicy::view()} — "whose form ROW may this user see".
     * Pinned in both directions by `FormVisibilityScopeTest`.
     *
     * Extracted in J1b from {@see FormPresenter::list()}, which had encoded it inline
     * since G10b. Global search needs the same rule, and this repo's own history says what happens when a
     * visibility rule is copied instead of shared: `SubmissionPolicy` keeps a docblock pinning its list twin
     * precisely because "a respondent who could open a row the inbox never lists is exactly the divergence
     * that pin exists to prevent."
     *
     * ⚠️ THIS IS NOT {@see AnalyticsFormSet}'s `visible()`, AND FOLDING THEM WOULD BE
     * A REAL AUTHORIZATION BUG RATHER THAN A TIDY-UP. That method answers a DIFFERENT question — "whose
     * SUBMISSIONS may this user aggregate" — and so keys on `dashboard.org.view` with ANY grant capacity, and
     * returns `Form::withTrashed()`. A **Viewer** holds `dashboard.org.view` and holds **no `forms.*` key at
     * all**, so under that rule a Viewer would see the title and description of every form in the tenant,
     * including SOFT-DELETED ones, through global search — while `/forms` correctly shows them nothing.
     * `AnalyticsFormSet` is the most search-shaped code already in the tree and therefore the obvious thing
     * to copy; do not.
     *
     * Editor capacity, not the null "any capacity" default: this is the authoring rule, so a bare reviewer
     * grant must not surface a form here with every `can` flag false. Routed through
     * {@see ResourceGrantResolver::grantedFormIdsQuery()} so it inherits the fail-closed empty set — a user
     * with no grants matches NOTHING, never an unconstrained query.
     *
     * Deliberately adds no `orWhere`, so it needs none of `Submission::scopeVisibleTo()`'s defensive closure;
     * do not cargo-cult one in. Archive/soft-delete filtering is the CALLER's, because "may I see this row"
     * and "does this list show archived rows" are different questions.
     *
     * @param  Builder<Form>  $query
     * @return Builder<Form>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->can('forms.edit.any')) {
            return $query;
        }

        $granted = app(ResourceGrantResolver::class)->grantedFormIdsQuery($user, ResourceCapacity::Editor);

        // ⚠️ `forms.edit.own` IS THE POLICY'S SECOND CONJUNCT AND IT IS LOAD-BEARING — an Editor grant
        // ALONE does not confer visibility. The first draft of this scope omitted it, and
        // `FormVisibilityScopeTest` is what found the divergence: a Reviewer or Viewer holding an Editor
        // grant was inside the scope's set and outside `FormPolicy::view()`'s, i.e. a list that offers a
        // row whose builder refuses to open. Nothing observed it in production only because BOTH live
        // callers — the `can:viewAny,Form` middleware on `/forms` and `FormSearchArm::allowed()` — refuse
        // those roles one layer up. That masking is exactly J1b's recorded mutation-survivor pattern, and
        // it is not a reason to leave the rule wrong.
        //
        // Fail closed INSIDE the subquery, where `grantedFormIdsQuery()` already keeps its own empty-set
        // guard: its OR is closure-grouped, so this conjunct ANDs with the whole group rather than
        // re-associating against one branch of it, and the outer `whereIn` shape stays identical on both
        // paths.
        if (! $user->can('forms.edit.own')) {
            $granted->whereRaw('false');
        }

        return $query->whereIn('forms.id', $granted);
    }
}
