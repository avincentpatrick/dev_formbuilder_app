<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Enums\SubmissionStatus;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Authorization\ResponseReadAccess;
use App\Services\Submissions\SchemaValueFormatter;
use App\Support\Forms\ShareableQuestions;
use Illuminate\Support\Facades\DB;

/**
 * A choice question that takes its choices from another form's answers (M133, `R-5da4a30f` — Connect project v1).
 *
 * ── THE LINK IS ONE CONFIG KEY, AND THE LIST IS NEVER STORED ──────────────────────────────────────
 * A `single_select` or `dropdown` links with `config.options_source = {form_id, field_key}`. Being config, the link
 * is part of the version snapshot: frozen per version, cloned by `SchemaTreeCloner`, covered by the checksum —
 * changing WHICH form a question reads needs a publish, which is right. The LIST is not: it is read live from the
 * source's responses and served beside the schema (`D60` = A), so a busy source form never moves a checksum every
 * offline device pins. One key mints one name, which closes KoboToolbox's trap of three names that must match.
 *
 * ── TWO KEYS, BOTH LIVE ───────────────────────────────────────────────────────────────────────────
 *   1. the SOURCE shares that question: `forms.data_sharing_enabled`, and the key among its shared questions
 *      ({@see ShareableQuestions::sharedKeys()} — still shareable in its current published version, never
 *      personal or sensitive, `D86`);
 *   2. the DESTINATION'S OWNER (`forms.owner_user_id`, not whoever is editing — Kobo's rule) can read the source's
 *      responses ({@see ResponseReadAccess::ownerCanRead()}).
 * {@see refusal()} answers both, and is asked at publish ({@see LinkedChoiceGate}) and on every serve of the list,
 * so withdrawing either key stops the list without a republish.
 */
final class LinkedChoiceService
{
    /** The two question types a link may feed. Multi-select and Likert are the remainder row's. */
    public const LINKABLE_TYPES = [FieldType::SingleSelect, FieldType::Dropdown];

    /** The most choices one list carries (v1). More is the remainder row's; the builder says so. */
    public const MAX_CHOICES = 1000;

    /**
     * The responses whose answers become choices: every one a respondent or a keyer finished, except those screened
     * out or archived. A draft is nobody's answer yet.
     *
     * @var list<SubmissionStatus>
     */
    public const EXCLUDED_STATUSES = [SubmissionStatus::Draft, SubmissionStatus::ScreenedOut, SubmissionStatus::Archived];

    public function __construct(
        private readonly ResponseReadAccess $access,
        private readonly SchemaValueFormatter $formatter,
    ) {}

    /**
     * The link a question's config holds, or null when it holds none or holds a malformed one.
     *
     * @param  array<string, mixed>|null  $config
     * @return array{form_id: string, field_key: string}|null
     */
    public static function linkOf(?array $config): ?array
    {
        $link = $config['options_source'] ?? null;
        if (! is_array($link)) {
            return null;
        }

        $formId = $link['form_id'] ?? null;
        $fieldKey = $link['field_key'] ?? null;

        return is_string($formId) && $formId !== '' && is_string($fieldKey) && $fieldKey !== ''
            ? ['form_id' => $formId, 'field_key' => $fieldKey]
            : null;
    }

    /**
     * Whether a question's config carries a link key at all, well-formed or not.
     *
     * @param  array<string, mixed>|null  $config
     */
    public static function declaresLink(?array $config): bool
    {
        return is_array($config) && array_key_exists('options_source', $config) && $config['options_source'] !== null;
    }

    /**
     * Why a link cannot serve right now, as a stable code, or null when both keys hold.
     *
     * @param  array{form_id: string, field_key: string}  $link
     */
    public function refusal(Form $destination, array $link): ?string
    {
        if ($link['form_id'] === (string) $destination->id) {
            return 'linked_choices_self';
        }

        $source = Form::query()->whereKey($link['form_id'])->first();
        if ($source === null) {
            return 'linked_choices_source_missing';
        }

        if (! in_array($link['field_key'], $this->sharedKeys($source), true)) {
            return 'linked_choices_not_shared';
        }

        if (! $this->access->ownerCanRead($destination, $source)) {
            return 'linked_choices_owner_cannot_read';
        }

        return null;
    }

    /**
     * The keys a source shares right now: none while its switch is off.
     *
     * @return list<string>
     */
    public function sharedKeys(Form $source): array
    {
        if ($source->data_sharing_enabled !== true || $source->current_published_version_id === null) {
            return [];
        }

        return ShareableQuestions::sharedKeys($this->publishedSnapshot($source), $source->dataSharingFieldKeys());
    }

    /**
     * The forms an author may link a question to, each with the questions it shares: sharing is on, this author can
     * read its responses, and it is not the form being edited. The owner's key is checked at publish, where it can
     * be stated against the form that will serve the list.
     *
     * @return list<array{id: string, title: string, questions: list<array{key: string, label: string}>}>
     */
    public function linkableSources(Form $destination, ?User $actor): array
    {
        if ($actor === null) {
            return [];
        }

        $sources = [];
        $candidates = Form::query()
            ->where('data_sharing_enabled', true)
            ->whereNotNull('current_published_version_id')
            ->whereKeyNot($destination->id)
            ->orderBy('title')
            ->get();

        foreach ($candidates as $source) {
            if (! $this->access->canRead($actor, $source)) {
                continue;
            }

            $keys = $this->sharedKeys($source);
            $questions = array_values(array_filter(
                ShareableQuestions::fromSnapshot($this->publishedSnapshot($source)),
                static fn (array $q): bool => in_array($q['key'], $keys, true),
            ));

            if ($questions === []) {
                continue;
            }

            $sources[] = [
                'id' => (string) $source->id,
                'title' => (string) $source->title,
                'questions' => array_map(static fn (array $q): array => ['key' => $q['key'], 'label' => $q['label']], $questions),
            ];
        }

        return $sources;
    }

    /**
     * Every linked question's list for one published version of a form — what the guest page and the encode page
     * show (`D60` = A: beside the schema, never inside it). Keyed by the linking question's key.
     *
     * A list whose link cannot serve right now ({@see refusal()}) is `available: false` and empty: the page says the
     * choices are not available, and an offline device keeps whatever it last fetched. `stamp` is a hash of the
     * list, because nothing records when a form's responses last changed — a plain answer edit moves no column on
     * `submissions` — so the list is its own version.
     *
     * ⚠️ THE SERVER NEVER CHECKS AN ANSWER AGAINST THIS LIST. A device may answer from a list fetched yesterday, and
     * the snapshot holds no typed options, so both engines skip the membership check — which is what keeps an
     * offline response submittable after the source changes. `tests/golden/validation/membership.json` pins it.
     *
     * @return array<string, array{stamp: string, available: bool, truncated: bool, options: list<array{value: string, label: string}>}>
     */
    public function listsFor(Form $destination, FormVersion $version): array
    {
        $snapshot = $version->schema_snapshot;
        $lists = [];
        $memo = [];

        foreach ((array) ($snapshot['fields'] ?? []) as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null)) {
                continue;
            }

            $type = FieldType::tryFrom((string) ($field['field_type'] ?? ''));
            $link = self::linkOf(is_array($field['config'] ?? null) ? $field['config'] : null);
            if ($type === null || $link === null || ! in_array($type, self::LINKABLE_TYPES, true)) {
                continue;
            }

            $memoKey = $link['form_id'].'|'.$link['field_key'];
            $lists[$field['key']] = $memo[$memoKey] ??= $this->listFor($destination, $link);
        }

        return $lists;
    }

    /**
     * @param  array{form_id: string, field_key: string}  $link
     * @return array{stamp: string, available: bool, truncated: bool, options: list<array{value: string, label: string}>}
     */
    private function listFor(Form $destination, array $link): array
    {
        $source = $this->refusal($destination, $link) === null ? Form::query()->whereKey($link['form_id'])->first() : null;
        if ($source === null) {
            return ['stamp' => hash('sha256', '[]'), 'available' => false, 'truncated' => false, 'options' => []];
        }

        $labels = $this->labelsFor($source, $link['field_key']);
        $texts = [];
        foreach ($this->distinctAnswers($source, $link['field_key']) as $raw) {
            $text = trim($labels[$raw] ?? $raw);
            if ($text !== '') {
                $texts[$text] = true;
            }
        }

        $texts = array_map('strval', array_keys($texts));
        usort($texts, static fn (string $a, string $b): int => [mb_strtolower($a), $a] <=> [mb_strtolower($b), $b]);

        $truncated = count($texts) > self::MAX_CHOICES;
        // `D85`: the answer saves the text shown, so a choice's value IS its label.
        $options = array_map(
            static fn (string $text): array => ['value' => $text, 'label' => $text],
            array_slice($texts, 0, self::MAX_CHOICES),
        );

        return [
            'stamp' => hash('sha256', (string) json_encode($options, JSON_UNESCAPED_UNICODE)),
            'available' => true,
            'truncated' => $truncated,
            'options' => $options,
        ];
    }

    /**
     * The distinct non-blank answers to one top-level question, as text — one more than the cap, so truncation is
     * known. Rooted on `submissions`, so a soft-deleted response is out, and filtered to finished statuses.
     *
     * @return list<string>
     */
    private function distinctAnswers(Form $source, string $key): array
    {
        $answers = DB::table('submissions')
            ->join('submission_answers', 'submission_answers.submission_id', '=', 'submissions.id')
            ->where('submissions.form_id', $source->id)
            ->whereNull('submissions.deleted_at')
            ->whereNotIn('submissions.status', array_map(static fn (SubmissionStatus $s): string => $s->value, self::EXCLUDED_STATUSES))
            ->whereRaw("jsonb_typeof(submission_answers.answers -> ?) IN ('string', 'number')", [$key])
            ->whereRaw("btrim(submission_answers.answers ->> ?) <> ''", [$key])
            ->selectRaw('DISTINCT btrim(submission_answers.answers ->> ?) AS answer', [$key]);

        return array_values(DB::query()->fromSub($answers, 'distinct_answers')
            ->orderByRaw('lower(answer), answer')
            ->limit(self::MAX_CHOICES + 1)
            ->pluck('answer')
            ->map(static fn (mixed $value): string => is_scalar($value) ? (string) $value : '')
            ->all());
    }

    /**
     * For a choice question, its stored values' labels in the source's current version (base language), so a
     * respondent sees "North" rather than `north`. Empty for any other question.
     *
     * @return array<string, string>
     */
    private function labelsFor(Form $source, string $key): array
    {
        $field = ShareableQuestions::find($this->publishedSnapshot($source), $key);
        if ($field === null || ! in_array(FieldType::tryFrom((string) ($field['field_type'] ?? '')), self::LINKABLE_TYPES, true)) {
            return [];
        }

        $labels = [];
        foreach ($this->formatter->options(is_array($field['config'] ?? null) ? $field['config'] : []) as $option) {
            $labels[$option['value']] = $option['label'];
        }

        return $labels;
    }

    /** @return array<string, mixed>|null */
    private function publishedSnapshot(Form $source): ?array
    {
        if ($source->current_published_version_id === null) {
            return null;
        }

        $snapshot = FormVersion::query()->whereKey($source->current_published_version_id)->value('schema_snapshot');

        return is_array($snapshot) ? $snapshot : null;
    }
}
