<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Enums\SubmissionSource;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use App\Models\Submission;
use App\Models\User;
use App\Policies\SubmissionPolicy;
use App\Services\Forms\FormPresenter;
use Illuminate\Support\Collection;

/**
 * Read model for the submissions inbox (Increment F7). The list mirrors {@see FormPresenter}
 * (flat rows + a `can` map, keeps the controller thin) but paginates — submissions grow unbounded, unlike the
 * forms list. Row-level visibility is delegated to {@see Submission::scopeVisibleTo()} so the query and
 * {@see SubmissionPolicy} enforce one visibility rule. The detail walks the submission's immutable
 * `form_version` schema, pairing each field with its stored answer through {@see SchemaValueFormatter}.
 */
final class SubmissionInboxPresenter
{
    private const PER_PAGE = 25;

    public function __construct(private readonly SchemaValueFormatter $formatter) {}

    /**
     * The paginated, filtered inbox list plus the filter option catalogs and the export capability.
     *
     * @param  array{form_id?: ?string, status?: ?string, source?: ?string}  $filters
     * @return array<string, mixed>
     */
    public function list(User $user, array $filters): array
    {
        $paginator = Submission::query()
            ->visibleTo($user)
            ->with(['form:id,title', 'respondent:id,name'])
            ->when($filters['form_id'] ?? null, fn ($q, $v) => $q->where('form_id', $v))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            // Hide in-progress drafts (H10) unless the status filter explicitly asks for them. The inbox is a
            // review surface for completed responses; a half-filled guest draft is not something a reviewer
            // acts on. Selecting the "Draft" status option surfaces them (the option is already in the catalog).
            ->when(empty($filters['status']), fn ($q) => $q->where('status', '!=', SubmissionStatus::Draft->value))
            ->when($filters['source'] ?? null, fn ($q, $v) => $q->where('source', $v))
            ->orderByDesc('id') // uuidv7 → recency without a dedicated submitted_at index
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        /** @var Collection<int, Submission> $items */
        $items = collect($paginator->items());

        return [
            'data' => $items->map(fn (Submission $s): array => [
                'id' => $s->id,
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
            ])->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
            ],
            'filters' => [
                'forms' => $this->formOptions($user),
                'statuses' => array_map(
                    fn (SubmissionStatus $s): array => ['value' => $s->value, 'label' => $s->label()],
                    SubmissionStatus::cases(),
                ),
                'sources' => array_map(
                    fn (SubmissionSource $s): array => ['value' => $s->value, 'label' => $s->label()],
                    SubmissionSource::cases(),
                ),
                'applied' => [
                    'form_id' => $filters['form_id'] ?? null,
                    'status' => $filters['status'] ?? null,
                    'source' => $filters['source'] ?? null,
                ],
            ],
            'can' => ['export' => $user->can('submissions.export')],
        ];
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
            'blocks' => $version !== null ? $this->answerBlocks($version, $answers) : [],
            'can' => ['review' => $user->can('review', $submission)],
        ];
    }

    /**
     * Group the version's answerable fields into their sections (ungrouped fields lead), pairing each with
     * its display value. Mirrors {@see EncodeFormPresenter}'s section walk but read-only.
     *
     * @param  array<string, mixed>  $answers
     * @return list<array<string, mixed>>
     */
    private function answerBlocks(FormVersion $version, array $answers): array
    {
        $sections = $version->sections()->orderBy('sequence')->get();
        $fields = $version->fields()->orderBy('sequence')->get()
            ->filter(fn (FormField $f): bool => $this->formatter->isDataField($f->field_type));

        /** @var Collection<string, Collection<int, FormField>> $bySection */
        $bySection = $fields->groupBy(fn (FormField $f): string => $f->form_section_id ?? '');

        $blocks = [];

        $ungrouped = $bySection->get('');
        if ($ungrouped !== null && $ungrouped->isNotEmpty()) {
            $blocks[] = ['id' => null, 'label' => null, 'fields' => $this->fieldRows($ungrouped, $answers)];
        }

        foreach ($sections as $section) {
            $sectionFields = $bySection->get($section->id);
            if ($sectionFields === null || $sectionFields->isEmpty()) {
                continue;
            }
            $blocks[] = [
                'id' => $section->id,
                'label' => $section->label,
                'fields' => $this->fieldRows($sectionFields, $answers),
            ];
        }

        return $blocks;
    }

    /**
     * @param  Collection<int, FormField>  $fields
     * @param  array<string, mixed>  $answers
     * @return list<array{key: string, label: string, value: string}>
     */
    private function fieldRows(Collection $fields, array $answers): array
    {
        return array_values($fields->map(fn (FormField $f): array => [
            'key' => $f->key,
            'label' => $f->label,
            'value' => $this->formatter->displayValue($f->field_type, $answers[$f->key] ?? null, $f->config ?? []),
        ])->all());
    }

    /** The forms that appear in this user's visible submissions — the useful set for the form filter. */
    /** @return list<array{value: string, label: string}> */
    private function formOptions(User $user): array
    {
        $formIds = Submission::query()->visibleTo($user)->distinct()->pluck('form_id');

        return array_values(Form::query()
            ->whereIn('id', $formIds)
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
