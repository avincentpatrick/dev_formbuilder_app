<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\AttachmentKind;
use App\Enums\FieldType;
use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Jobs\Submissions\GeneratePdfJob;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Services\Forms\FormPresenter;
use App\Services\Templates\TemplateRenderer;
use App\Services\Templates\TemplateSources;
use App\Support\Search\ListEmptyReason;
use App\Support\Search\SearchTerms;
use Illuminate\Support\Collection;

/**
 * Read model for the submissions inbox (Increment F7). The list mirrors {@see FormPresenter}
 * (flat rows + a `can` map, keeps the controller thin) but paginates — submissions grow unbounded, unlike the
 * forms list. Row-level visibility is delegated to {@see Submission::scopeVisibleTo()} so the query and
 * {@see SubmissionPolicy} enforce one visibility rule. The detail walks the submission's immutable
 * `form_version` schema, pairing each field with its stored answer through {@see SchemaValueFormatter}.
 *
 * Increment H6a: this is the ONE surface in the product where a piping hole actually FILLS — it is the only
 * PHP renderer holding a label, the field catalog and the answer document together. A label authored as
 * "Age of ${child_name}" reads "Age of Maria" here, per Doc #26 §3.2/§3.4.
 *
 * Increment H6b made it repeat-aware and locale-aware (see {@see answerBlocks()}), which is what lets the
 * reviewer read the same question text, filled the same way, that the respondent answered.
 */
final class SubmissionInboxPresenter
{
    private const PER_PAGE = 25;

    public function __construct(
        private readonly SchemaValueFormatter $formatter,
        private readonly TemplateRenderer $templates,
    ) {}

    /**
     * The paginated, filtered inbox list plus the filter option catalogs and the export capability.
     *
     * ⚠️ `q` IS {@see Submission::scopeMatchingKeyword()}, THE SAME PREDICATE `SubmissionSearchArm` USES.
     * It was that arm's private builder until J1e; sharing it is what stops a global-search hit and the
     * inbox it links into from disagreeing about whether a row matches. Read the scope before widening what
     * a submission match means — in particular, answer text is deliberately not in it.
     *
     * ── $boundForm — THE SAME LIST, SCOPED TO ONE FORM BY THE ROUTE (Increment J2c) ────────────────────
     * `GET /forms/{form}/submissions` reuses this method wholesale rather than growing a second query, which
     * is J1e's audit-export finding applied before it could bite: the filter chain here and in
     * {@see SubmissionExporter::baseQuery()} is ALREADY spelled twice, and a third copy for the per-form page
     * would be the one that drifts. The parameter is OPTIONAL so every existing caller and every existing
     * test passes UNEDITED — the same house rule {@see FormPresenter::list()} states for its `$terms`.
     *
     * Two things change when it is set, and both are about not lying to the reader:
     *   - the bound form WINS over `?form_id=`, so the URL cannot narrow the page to a different form than
     *     the one whose name is in the heading and the breadcrumb;
     *   - `forms` is OMITTED from the filter catalog — absent, never an empty array (ADR-0011 §D9). The
     *     dropdown is meaningless on a page that is already one form, and an empty array would read to the
     *     client as "this reader may select nothing".
     *
     * @param  array{form_id?: ?string, status?: ?string, source?: ?string, q?: ?SearchTerms}  $filters
     * @return array<string, mixed>
     */
    public function list(User $user, array $filters, ?Form $boundForm = null): array
    {
        $terms = $filters['q'] ?? SearchTerms::parse(null);

        // ⚠️ NORMALISED INTO `$filters` RATHER THAN HELD BESIDE IT, AND THAT IS A CORRECTNESS CHOICE.
        // The bound form WINS over any `?form_id=`, so the URL cannot narrow the page to a different form
        // than the one whose name is in the heading. Writing it back into the array is what keeps
        // `hasAnyFilter()` HONEST: computed into a local instead, its `form_id` skip would read
        // `$filters['form_id']`, which `forForm()` never sets — so the skip would be dead code that
        // mutation-testing reports as surviving, and `empty_reason` would depend on a controller continuing
        // to omit a key rather than on a rule stated here. (Found exactly that way.)
        if ($boundForm !== null) {
            $filters['form_id'] = $boundForm->id;
        }

        $formId = $filters['form_id'] ?? null;

        $paginator = Submission::query()
            ->visibleTo($user)
            ->with(['form:id,title', 'respondent:id,name'])
            ->matchingKeyword($terms)
            ->when($formId, fn ($q, $v) => $q->where('form_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            // Hide in-progress drafts (H10) unless the status filter explicitly asks for them. The inbox is a
            // review surface for completed responses; a half-filled guest draft is not something a reviewer
            // acts on. Selecting the "Draft" status option surfaces them (the option is already in the catalog).
            //
            // Spelled as ADR-0011 §D2's countable predicate because D2's headline risk is exactly "a dashboard
            // and an inbox disagree in front of a customer", and this is the inbox. Note what it is, though: a
            // DISPLAY DEFAULT the user overrides, not an assertion that a submission is countable — so a future
            // change to Submission::scopeCountable() changes what a reviewer sees by default.
            ->when(empty($filters['status']), fn ($q) => $q->countable())
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->orderByDesc('id') // uuidv7 → recency without a dedicated submitted_at index
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var Collection<int, Submission> $items */
        $items = collect($paginator->items());

        // ⚠️ WHICH OF THESE ROWS MAY HAVE THEIR FORM LINKED, RESOLVED ONCE FOR THE PAGE.
        //
        // The row set is strictly WIDER than form readability, so "the row is listed" does not imply "its
        // form opens" — and an unconditional link is a 403 or a 404 dressed as navigation. Two real paths,
        // both reachable today: (1) `Submission::scopeVisibleTo()` has a **respondent arm**
        // (`respondent_user_id = me`) that `viewOverview` has no counterpart for, so a keyer whose grant was
        // revoked still sees rows they encoded and would be offered a link that 403s; (2) a SOFT-DELETED
        // form makes `formTitle()` render an em dash, and route-model binding excludes trashed rows — so
        // the inbox would have printed "—" as a live hyperlink to a 404.
        //
        // One query for the page rather than a policy call per row: `readableBy` is the same predicate the
        // route's gate composes, narrowed to the form ids actually on screen (at most PER_PAGE of them).
        // The alternative — `$user->can('viewOverview', $form)` per row — is the 25-grant-lookup shape the
        // `can.resume` note below already refuses.
        $linkableFormIds = $items->isEmpty()
            ? collect()
            : Form::query()
                ->readableBy($user)
                ->whereIn('forms.id', $items->pluck('form_id')->unique()->all())
                ->pluck('id')
                ->flip();

        return [
            'data' => $items->map(fn (Submission $s): array => [
                'id' => $s->id,
                // ⚠️ THE ID AS WELL AS THE TITLE (J2c), AND IT IS FREE. `form:id,title` is already eager-
                // loaded above, so this adds no query — and without it the global inbox can print a form's
                // name on every row while linking none of them, which is the dead end this whole row exists
                // to remove. `detail()` has carried `form_id` since F7; only the LIST row lacked it.
                'form_id' => $s->form_id,
                'form_title' => $this->formTitle($s),
                'status' => $s->status->value,
                'source' => $s->source->value,
                'source_label' => $s->source->label(),
                'respondent' => $this->respondentLabel($s),
                'submitted_at' => ($s->submitted_at ?? $s->created_at)?->toIso8601String(),
                // Draft progress (H10) — populated only on `status=draft` rows (promotion nulls the expiry);
                // the inbox renders "Saved · N%" and the expiry for a filtered draft view.
                'completeness_percent' => $s->completeness_percent,
                'last_saved_at' => $s->last_saved_at?->toIso8601String(),
                'draft_expires_at' => $s->draft_expires_at?->toIso8601String(),
                // Whether THIS viewer may pick this draft up (Increment I9b).
                //
                // ⚠️ EVALUATED ONLY FOR DRAFT ROWS, and that guard is a performance one rather than a
                // correctness one. `SubmissionPolicy::promote()` consults `ResourceGrantResolver` per row, so
                // an unguarded call would be 25 grant lookups on every page of the inbox — on a list where
                // drafts are HIDDEN by default, meaning the common page would pay for 25 answers it never
                // renders. Short-circuiting on the status keeps the ordinary inbox at zero extra queries and
                // pays only on the Draft-filtered view, which is the only place the button can appear.
                'can' => [
                    'resume' => $s->status === SubmissionStatus::Draft && $user->can('promote', $s),
                    // Whether THIS viewer may open the row's FORM (J2c). See `$linkableFormIds` above — the
                    // row being listed does not imply the form opens, so the client keys the link off this
                    // rather than off the presence of `form_id`.
                    'open_form' => $linkableFormIds->has($s->form_id),
                ],
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
            'filters' => [
                // Absent on the per-form page, never an empty array — see the `$boundForm` note above.
                ...($boundForm === null ? ['forms' => $this->formOptions($user)] : []),
                'statuses' => array_map(
                    fn (SubmissionStatus $s): array => ['value' => $s->value, 'label' => $s->label()],
                    SubmissionStatus::cases(),
                ),
                'sources' => array_map(
                    fn (SubmissionSource $s): array => ['value' => $s->value, 'label' => $s->label()],
                    SubmissionSource::cases(),
                ),
                'applied' => [
                    'form_id' => $formId,
                    'status' => $filters['status'] ?? null,
                    'source' => $filters['source'] ?? null,
                    'q' => $terms->raw(),
                ],
            ],
            // ⚠️ SERVER-COMPUTED (J1e), REPLACING A CLIENT-SIDE INFERENCE THAT WAS ALREADY WRONG ON THIS
            // PAGE. `Inbox.vue` used to pick its empty illustration from `selected.form_id || status ||
            // source`, which cannot see the one filter the SERVER applies on its own: `countable()` hides
            // in-progress drafts unless a status is chosen. So an inbox holding nothing but drafts rendered
            // "Responses appear here as forms are filled out" — telling a reviewer nothing had arrived while
            // the rows sat one dropdown away. That is exactly the failure the I2 rule names.
            'empty_reason' => ListEmptyReason::for($items->isNotEmpty(), $this->hasAnyFilter($filters, $terms, $boundForm)),
            'can' => ['export' => $user->can('submissions.export')],
        ];
    }

    /**
     * Whether the viewer narrowed anything.
     *
     * ⚠️ `countable()` IS NOT COUNTED HERE, AND THAT IS THE HONEST ANSWER RATHER THAN THE CONVENIENT ONE.
     * It is a display DEFAULT rather than a choice the viewer made, so a bare inbox holding only drafts is
     * genuinely `no_rows` from the reviewer's point of view: they have no completed responses. The hint
     * above the table already tells them drafts are hidden and how to see them, which is the right place
     * for that sentence — an empty state reading "no matching submissions" when they filtered nothing would
     * be a different lie from the one this prop fixes.
     *
     * ⚠️ AND A ROUTE-BOUND FORM IS NOT A FILTER EITHER (Increment J2c) — SAME RULE, SECOND APPLICATION.
     * On `/forms/{form}/submissions` the form is the PAGE, not something the reader narrowed to: they chose
     * nothing and cannot clear it. Counting it would make `empty_reason` permanently `no_matches`, so a
     * brand-new form — the single most likely form to have no responses — would greet its author with "No
     * matching submissions · try a different keyword, or clear the filters to see everything" over a list
     * with no filters to clear. That is the same shape of lie as the client-side inference this prop
     * replaced, arriving from the opposite direction, and no test would have caught it: every fixture that
     * seeds a submission passes either way.
     *
     * @param  array<string, mixed>  $filters
     */
    private function hasAnyFilter(array $filters, SearchTerms $terms, ?Form $boundForm = null): bool
    {
        foreach (['form_id', 'status', 'source'] as $key) {
            if ($key === 'form_id' && $boundForm !== null) {
                continue;
            }

            if (($filters[$key] ?? null) !== null) {
                return true;
            }
        }

        return ! $terms->isEmpty();
    }

    /**
     * One submission's metadata, review state, and answers rendered against its own version schema.
     *
     * @return array<string, mixed>
     */
    public function detail(User $user, Submission $submission): array
    {
        $submission->loadMissing(['form:id,title', 'formVersion:id,version_number', 'respondent:id,name,email', 'validator:id,name', 'answers']);
        $version = $submission->formVersion;
        $answers = $this->answersOf($submission);

        return [
            'submission' => [
                'id' => $submission->id,
                'form_id' => $submission->form_id,
                'form_title' => $this->formTitle($submission),
                'version_number' => $version?->version_number,
                'status' => $submission->status->value,
                'status_label' => $submission->status->label(),
                'source' => $submission->source->value,
                'source_label' => $submission->source->label(),
                'respondent' => $this->respondentLabel($submission),
                'locale' => $submission->locale,
                'submitted_at' => $submission->submitted_at?->toIso8601String(),
                'finalized_at' => $submission->finalized_at?->toIso8601String(),
                'review' => [
                    'validator' => $submission->validator?->name,
                    'validated_at' => $submission->validated_at?->toIso8601String(),
                    'returned_reason' => $submission->returned_reason,
                    'remarks' => $submission->remarks,
                ],
            ],
            'blocks' => $version !== null ? $this->answerBlocks($version, $answers, $submission->locale) : [],
            // Two SEPARATE abilities, never one flag. `review` decides a submission's outcome; `update`
            // (I9c) rewrites its answers, and they are held by different roles — a Reviewer has the first
            // and not the second, an Owner/Admin has both, a Form Editor has `update` on forms they
            // collaborate on. Collapsing them would hand a Reviewer the power to change the very answers
            // they are meant to be judging. Both run the real Gate, so the button is offered only where the
            // route would actually admit the caller.
            'can' => [
                'review' => $user->can('review', $submission),
                'update' => $user->can('update', $submission),
            ],
            'pdf' => $this->pdfArtifact($submission),
        ];
    }

    /**
     * The submission's current PDF, if one has been generated (Increment H17).
     *
     * ── AMENDED I3/I4 ───────────────────────────────────────────────────────────────────────────────
     * ~~This is the ONLY in-app surface for the artifact. There is no notification bell, no polling and no
     * broadcast anywhere in this application … `NotificationType::export_ready` is specified in the data
     * dictionary and remains unbuilt.~~ Two of those three claims are now false, and the first was already
     * false when I3 merged: {@see GeneratePdfJob} calls
     * `NotificationDispatcher::record()` with `NotificationType::ExportReady`, so an in-app row is written;
     * I4 built the bell and its ~60s poll. **There is still NO BROADCAST — Reverb is Track B.**
     *
     * This prop is NOT made redundant by the bell, and the split is worth stating: the bell announces that
     * the file EXISTS and links here (both go through `NotificationType::pathFor()`, so they cannot point
     * at different places), while this prop is what RENDERS it. A recipient who marked the notification
     * read, or silenced `export_ready` in Settings, must still find the artifact by reloading. H17's plain
     * flow — a toast on queue, an email with the link, this prop on reload — is therefore load-bearing
     * rather than a stopgap.
     *
     * @return array{id: string, generated_at: ?string, size_bytes: int}|null
     */
    private function pdfArtifact(Submission $submission): ?array
    {
        $attachment = Attachment::query()
            ->where('attachable_type', 'submission')
            ->where('attachable_id', $submission->getKey())
            ->where('kind', AttachmentKind::ExportArtifact)
            ->first();

        if ($attachment === null) {
            return null;
        }

        return [
            'id' => (string) $attachment->getKey(),
            'generated_at' => $attachment->updated_at?->toIso8601String(),
            'size_bytes' => (int) $attachment->size_bytes,
        ];
    }

    /**
     * Group the version's answerable fields into their sections (ungrouped fields lead), pairing each with
     * its display value. Mirrors {@see EncodeFormPresenter}'s section walk but read-only.
     *
     * ── Repeat groups (Increment H6b) ───────────────────────────────────────────────────────────────
     * A repeatable section emits ONE BLOCK PER STORED INSTANCE, not one block for the section. Before
     * H6b this surface was repeat-BLIND: it emitted a single row per member field and read
     * `$answers[$field->key]`, but a repeat member's answers live nested under the SECTION key, so every
     * member rendered as an em-dash — an answered roster read as an unanswered one. Piping made that
     * unfixable-in-place rather than merely wrong: Doc #26 §3.3 rule 2 lets a member's label name a
     * same-instance sibling, and there is no honest way to render N differently-filled labels into one
     * row. So each instance gets its own block, and its fields resolve against
     * `array_merge($base, $instance)` — the instance SHADOWS the flat document, the exact context
     * `SemanticValidator` builds for a repeat instance and the merge {@see TemplateRenderer}'s docblock
     * prescribes. That is what makes the reviewer read the same question text the respondent answered.
     *
     * One honesty note from H6b's mutation pass: the merge DIRECTION is written to match `SemanticValidator`
     * but is not observable here, and no test pins it. `form_fields.key` is unique per version, so a flat
     * key and an instance key can never collide and `array_merge` gives the same map either way. Reversing
     * the arguments is a green mutation. Recorded rather than papered over with a test that would pass for
     * the wrong reason — what IS pinned, and what reddens, is that the instance map is consulted at all.
     *
     * `id` is a stable unique key for the block, not a section id: an instance block carries
     * `"{sectionId}#{n}"` so a Vue `:key` over the list stays unique.
     *
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    private function answerBlocks(FormVersion $version, array $answers, ?string $locale): array
    {
        $sections = $version->sections()->orderBy('sequence')->get();
        $allFields = $version->fields()->orderBy('sequence')->get();

        // Piping sources are drawn from EVERY field, not just the displayed ones (H6a): `hidden` and
        // `calculated` are pipeable per Doc #26 §3.1 whether or not they have a row of their own.
        $sources = TemplateSources::fromFields($allFields);

        $fields = $allFields->filter(fn (FormField $f): bool => $this->formatter->isDataField($f->field_type));

        /** @var Collection<string, Collection<int, FormField>> $bySection */
        $bySection = $fields->groupBy(fn (FormField $f): string => $f->form_section_id ?? '');

        $blocks = [];

        $ungrouped = $bySection->get('');
        if ($ungrouped !== null && $ungrouped->isNotEmpty()) {
            $blocks[] = ['id' => null, 'label' => null, 'fields' => $this->fieldRows($ungrouped, $answers, $sources, $locale)];
        }

        foreach ($sections as $section) {
            $sectionFields = $bySection->get($section->id);
            if ($sectionFields === null || $sectionFields->isEmpty()) {
                continue;
            }

            if ($section->is_repeatable !== true) {
                $blocks[] = [
                    'id' => $section->id,
                    'label' => $this->templates->render($section->label, $sources, $answers, $locale),
                    'fields' => $this->fieldRows($sectionFields, $answers, $sources, $locale),
                ];

                continue;
            }

            $instances = $this->instancesOf($answers, $section->key);

            // An added-nothing repeat still shows its heading with no rows. Emitting nothing would hide
            // that the section exists; emitting em-dash rows (the pre-H6b behaviour) would claim it was
            // answered blank. Neither is true — it is empty, and the page says so.
            if ($instances === []) {
                $blocks[] = [
                    'id' => $section->id,
                    'label' => $this->templates->render($section->label, $sources, $answers, $locale),
                    'fields' => [],
                ];

                continue;
            }

            foreach ($instances as $index => $instance) {
                $scoped = array_merge($answers, $instance);
                $blocks[] = [
                    'id' => $section->id.'#'.$index,
                    // Numbered exactly as the respondent saw it — `RepeatGroup.vue`'s instance legend is
                    // `${label} ${index + 1}`, and the two must not disagree about which entry this is.
                    'label' => $this->templates->render($section->label, $sources, $scoped, $locale).' '.($index + 1),
                    'fields' => $this->fieldRows($sectionFields, $scoped, $sources, $locale),
                ];
            }
        }

        return $blocks;
    }

    /**
     * A repeatable section's stored instances — a list of per-instance field-key ⇒ value maps, the shape
     * {@see StructuralAnswerNormalizer} persists. Fail-closed: anything else is no instances at all.
     *
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    private function instancesOf(array $answers, string $sectionKey): array
    {
        $instances = $answers[$sectionKey] ?? null;

        if (! is_array($instances)) {
            return [];
        }

        return array_values(array_filter($instances, is_array(...)));
    }

    /**
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $answers  already scoped to one repeat instance where applicable
     * @param  array<string, array{type: FieldType, config: array<string, mixed>}>  $sources
     * @return list<array{key: string, label: string, value: string}>
     */
    private function fieldRows(Collection $fields, array $answers, array $sources, ?string $locale): array
    {
        return array_values($fields->map(fn (FormField $f): array => [
            'key' => $f->key,
            'label' => $this->templates->render($f->label, $sources, $answers, $locale),
            'value' => $this->formatter->displayValue($f->field_type, $answers[$f->key] ?? null, $f->config ?? [], $locale),
        ])->all());
    }

    /**
     * The forms this reader may open — the set the form filter offers.
     *
     * ⚠️ IT USED TO BE DERIVED FROM SUBMISSIONS, AND THAT MADE THE FILTER UNABLE TO EXPRESS THE ONE
     * QUESTION IT IS MOST OFTEN ASKED (Increment J2c). `Submission::visibleTo($user)->distinct()
     * ->pluck('form_id')` lists only forms that ALREADY HAVE a visible response, so a form with none was
     * not selectable at all — and "has anything come in yet?" is precisely what an author asks about a form
     * they just published. The old shape could answer every question except that one.
     *
     * It also read as correct, which is why it survived this long: every fixture that exercises the filter
     * seeds a submission first, so no test could distinguish the two implementations. `FormTabSetReachability
     * Test` measured the consequence from the outside — the Responses tab had to link to `?form_id=` on a
     * form the dropdown could not offer — and left the fix to this increment.
     *
     * Now {@see Form::scopeReadableBy()}, which is byte-for-byte {@see FormPolicy::viewOverview()}: the
     * dropdown offers exactly the forms whose hub the reader may already open. ⚠️ NOT
     * {@see Form::scopeVisibleTo()} — that is the AUTHORING scope and returns nothing for a Reviewer or a
     * Viewer, the two roles that live in this inbox, so it would empty the dropdown for them while every
     * Owner-fixtured test stayed green.
     *
     * Soft-deleted forms stay out, exactly as before — `readableBy` adds no `withTrashed()`. That is a
     * deliberate non-change rather than an oversight: it is a separate question from the one this fixes, and
     * `AnalyticsPresenter::formOptions()` answers it differently for its own stated reason.
     *
     * @return list<array{value: string, label: string}>
     */
    private function formOptions(User $user): array
    {
        return array_values(Form::query()
            ->readableBy($user)
            ->orderBy('title')
            ->get(['id', 'title'])
            ->map(fn (Form $f): array => ['value' => $f->id, 'label' => $f->title])
            ->all());
    }

    private function respondentLabel(Submission $submission): string
    {
        if ($submission->respondent !== null) {
            return $submission->respondent->name;
        }

        return $submission->source === SubmissionSource::Guest ? 'Guest' : '—';
    }

    /**
     * The submission's form title via a null-safe traversal (data_get returns mixed, tolerating an
     * unexpectedly-absent form), avoiding the relation-property nullability ambiguity.
     */
    private function formTitle(Submission $submission): string
    {
        $title = data_get($submission, 'form.title');

        return is_string($title) ? $title : '—';
    }

    /**
     * The submission's answer document, resolved null-safely (the 1:1 answer row is pipeline-guaranteed,
     * but data_get tolerates its absence rather than fataling).
     *
     * @return array<string, mixed>
     */
    private function answersOf(Submission $submission): array
    {
        $answers = data_get($submission, 'answers.answers');

        return is_array($answers) ? $answers : [];
    }
}
