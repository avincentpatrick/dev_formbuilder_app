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
 * A `single_select`, `multi_select` (M184) or `dropdown` links with `config.options_source = {form_id, field_key}`. Being config, the link
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
    /** The question types a link may feed — Multiple choice since M184 (`R-f655f9b1`). A Likert scale keeps its fixed points. */
    public const LINKABLE_TYPES = [FieldType::SingleSelect, FieldType::MultiSelect, FieldType::Dropdown];

    /**
     * The SOURCE questions whose stored values are mapped to their labels: the choice types {@see ShareableQuestions}
     * shares. Not {@see LINKABLE_TYPES} — that list is what a link may FEED, and a Multiple choice feeds but is not shared.
     */
    private const SOURCE_CHOICE_TYPES = [FieldType::SingleSelect, FieldType::Dropdown];

    /** The most choices one list carries (v1). More is the remainder row's; the builder says so. */
    public const MAX_CHOICES = 1000;

    /**
     * M186 (`R-ed7f3127`): the most distinct answers one narrowing set carries — every barangay in the country is 42,010.
     * Above it the set says `truncated` and carries none, and the question shows every choice (`D163`).
     */
    public const MAX_SET_VALUES = 50_000;

    /** M186: how a filter narrows — to the choices the other form has an answer for, or to those it has none for. */
    public const FILTER_MODES = ['present', 'absent'];

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
     * M184 (`R-596d6afd`): whether a Short text's suggestions are declared to come from another form, picked or not —
     * `config.suggestions_source = {kind: form, form_id, field_key}`.
     *
     * @param  array<string, mixed>|null  $config
     */
    public static function declaresSuggestionLink(?array $config): bool
    {
        $source = $config['suggestions_source'] ?? null;

        return is_array($source) && ($source['kind'] ?? null) === 'form';
    }

    /**
     * The link a Short text's suggestions take, or null when they come from no form or from a half-picked one.
     *
     * @param  array<string, mixed>|null  $config
     * @return array{form_id: string, field_key: string}|null
     */
    public static function suggestionLinkOf(?array $config): ?array
    {
        return self::declaresSuggestionLink($config) ? self::linkOf(['options_source' => $config['suggestions_source'] ?? null]) : null;
    }

    /**
     * Why a link cannot serve right now, as a stable code, or null when both keys hold. `$forFilters` (M186): the link
     * narrows choices rather than feeding them, so a cascade the source chose to share may serve it.
     *
     * @param  array{form_id: string, field_key: string}  $link
     */
    public function refusal(Form $destination, array $link, bool $forFilters = false): ?string
    {
        if ($link['form_id'] === (string) $destination->id) {
            return 'linked_choices_self';
        }

        $source = Form::query()->whereKey($link['form_id'])->first();
        if ($source === null) {
            return 'linked_choices_source_missing';
        }

        if (! in_array($link['field_key'], $this->sharedKeys($source, $forFilters), true)) {
            return 'linked_choices_not_shared';
        }

        if (! $this->access->ownerCanRead($destination, $source)) {
            return 'linked_choices_owner_cannot_read';
        }

        return null;
    }

    /**
     * The keys a source shares right now: none while its switch is off. A cascade only for a filter, and only chosen.
     *
     * @return list<string>
     */
    public function sharedKeys(Form $source, bool $forFilters = false): array
    {
        if ($source->data_sharing_enabled !== true || $source->current_published_version_id === null) {
            return [];
        }

        return ShareableQuestions::sharedKeys($this->publishedSnapshot($source), $source->dataSharingFieldKeys(), $forFilters);
    }

    /**
     * The forms an author may link a question to, each with the questions it shares: sharing is on, this author can
     * read its responses, and it is not the form being edited. The owner's key is checked at publish, where it can
     * be stated against the form that will serve the list.
     *
     * M186 (`R-ed7f3127`): each question says its type, and a cascade its levels (key and list), because a cascade the
     * source chose to share is offered to a FILTER only — the editors that take choices or suggestions leave it out.
     *
     * @return list<array{id: string, title: string, questions: list<array{key: string, label: string, field_type: string, levels?: list<array{key: string, list: ?string}>}>}>
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

            $snapshot = $this->publishedSnapshot($source);
            $keys = $this->sharedKeys($source, true);
            $questions = array_values(array_filter(
                ShareableQuestions::fromSnapshot($snapshot, true),
                static fn (array $q): bool => in_array($q['key'], $keys, true),
            ));

            if ($questions === []) {
                continue;
            }

            $sources[] = [
                'id' => (string) $source->id,
                'title' => (string) $source->title,
                'questions' => array_map(static function (array $q) use ($snapshot): array {
                    $question = ['key' => $q['key'], 'label' => $q['label'], 'field_type' => $q['field_type']];
                    if ($q['field_type'] === FieldType::CascadingSelect->value) {
                        $question['levels'] = self::levelsOf(ShareableQuestions::find($snapshot, $q['key'], true));
                    }

                    return $question;
                }, $questions),
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
     * M184 (`R-596d6afd`): a Short text whose suggestions come from another form is served the same list under its key —
     * the runtime offers it as suggestions, and the answer stays free text.
     *
     * @return array<string, array{stamp: string, available: bool, truncated: bool, options: list<array{value: string, label: string}>}>
     */
    public function listsFor(Form $destination, FormVersion $version): array
    {
        $snapshot = $version->schema_snapshot;

        return $this->listsForFields($destination, (array) ($snapshot['fields'] ?? []));
    }

    /**
     * {@see listsFor()} over any list of fields in the snapshot's shape (`key`, `field_type`, `config`) — a published
     * version's, or a DRAFT's rows for the builder preview, whose snapshot is empty until it is published (M184).
     *
     * @param  iterable<mixed>  $fields
     * @return array<string, array{stamp: string, available: bool, truncated: bool, options: list<array{value: string, label: string}>}>
     */
    public function listsForFields(Form $destination, iterable $fields): array
    {
        $lists = [];
        $memo = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null)) {
                continue;
            }

            $type = FieldType::tryFrom((string) ($field['field_type'] ?? ''));
            $config = is_array($field['config'] ?? null) ? $field['config'] : null;
            $link = match (true) {
                $type === FieldType::ShortText => self::suggestionLinkOf($config),
                in_array($type, self::LINKABLE_TYPES, true) => self::linkOf($config),
                default => null,
            };
            if ($link === null) {
                continue;
            }

            $memoKey = $link['form_id'].'|'.$link['field_key'];
            $lists[$field['key']] = $memo[$memoKey] ??= $this->listFor($destination, $link);
        }

        return $lists;
    }

    /**
     * Every linked question's choices for one published version, as their values — what a scan's written answer is
     * checked against before it reaches a review screen, because those screens draw the same live list
     * (`App\Services\Ocr\OcrAnswerCarry`, M184).
     *
     * @return array<string, list<string>>
     */
    public function valuesFor(Form $destination, FormVersion $version): array
    {
        return array_map(
            static fn (array $list): array => array_column($list['options'], 'value'),
            $this->listsFor($destination, $version),
        );
    }

    /**
     * M186 (`R-ed7f3127`): a well-formed filter — `{form_id, field_key, mode}` — or null when it is half picked or malformed.
     *
     * @return array{form_id: string, field_key: string, mode: string}|null
     */
    public static function filterOf(mixed $filter): ?array
    {
        if (! is_array($filter)) {
            return null;
        }

        $formId = $filter['form_id'] ?? null;
        $fieldKey = $filter['field_key'] ?? null;
        $mode = $filter['mode'] ?? null;

        return is_string($formId) && $formId !== '' && is_string($fieldKey) && $fieldKey !== '' && in_array($mode, self::FILTER_MODES, true)
            ? ['form_id' => $formId, 'field_key' => $fieldKey, 'mode' => (string) $mode]
            : null;
    }

    /**
     * Every filter a question declares, well-formed or not, each with the cascade level it narrows — null for a choice
     * question's own list: `config.levels[i].filter` on a cascade, `config.options_filter` on anything else.
     *
     * @param  array<string, mixed>|null  $config
     * @return list<array{level: int|null, filter: mixed}>
     */
    public static function declaredFilters(?FieldType $type, ?array $config): array
    {
        $declared = [];

        if ($type === FieldType::CascadingSelect) {
            foreach (array_values((array) ($config['levels'] ?? [])) as $index => $level) {
                if (is_array($level) && ($level['filter'] ?? null) !== null) {
                    $declared[] = ['level' => $index, 'filter' => $level['filter']];
                }
            }
        } elseif (($config['options_filter'] ?? null) !== null) {
            $declared[] = ['level' => null, 'filter' => $config['options_filter']];
        }

        return $declared;
    }

    /**
     * A cascade's levels as a filter compares them — each level's key and the list it names.
     *
     * @param  array<string, mixed>|null  $field  a snapshot's field entry
     * @return list<array{key: string, list: ?string}>
     */
    public static function levelsOf(?array $field): array
    {
        $levels = [];
        foreach (array_values((array) (is_array($field['config'] ?? null) ? ($field['config']['levels'] ?? []) : [])) as $level) {
            if (is_array($level)) {
                $levels[] = ['key' => (string) ($level['key'] ?? ''), 'list' => is_string($level['list'] ?? null) ? $level['list'] : null];
            }
        }

        return $levels;
    }

    /**
     * The level of a SOURCE cascade whose values a TARGET cascade level can be narrowed by, or null when none compares: a
     * Philippine places level only by the same level of another cascade on the places (`psgc:barangay` by `psgc:barangay`,
     * whatever either level is called), any other level by a source level with the same key.
     *
     * @param  list<array{key: string, list: ?string}>  $sourceLevels
     * @param  array<string, mixed>|null  $targetLevel
     */
    public static function comparableLevel(array $sourceLevels, ?array $targetLevel): ?int
    {
        if ($targetLevel === null) {
            return null;
        }

        $list = $targetLevel['list'] ?? null;
        foreach ($sourceLevels as $index => $level) {
            $same = PsgcLists::isPsgcList($list) ? $level['list'] === $list : $level['key'] !== '' && $level['key'] === (string) ($targetLevel['key'] ?? '');
            if ($same) {
                return $index;
            }
        }

        return null;
    }

    /**
     * M186 (`R-ed7f3127`): why a well-formed filter cannot narrow this question or level, as a stable code — one of
     * {@see refusal()}'s, or `choice_filter_not_comparable` when the values could never match: a Philippine places level
     * needs the same level of another cascade on the places, any other cascade level a source level with the same key (or
     * a single answer), and a choice question's list a single answer, never a cascade's path. Null when it can.
     *
     * @param  array{form_id: string, field_key: string, mode: string}  $filter
     * @param  array<string, mixed>|null  $targetLevel  the cascade level it narrows, or null for a choice question's list
     */
    public function filterProblem(Form $destination, array $filter, ?array $targetLevel): ?string
    {
        $link = ['form_id' => $filter['form_id'], 'field_key' => $filter['field_key']];
        $refusal = $this->refusal($destination, $link, true);
        if ($refusal !== null) {
            return $refusal;
        }

        $source = Form::query()->whereKey($link['form_id'])->first();
        $field = $source === null ? null : ShareableQuestions::find($this->publishedSnapshot($source), $link['field_key'], true);
        if ($field === null) {
            return 'linked_choices_not_shared';
        }

        $cascade = ($field['field_type'] ?? null) === FieldType::CascadingSelect->value;
        $comparable = match (true) {
            $targetLevel === null => ! $cascade,
            $cascade => self::comparableLevel(self::levelsOf($field), $targetLevel) !== null,
            default => ! PsgcLists::isPsgcList($targetLevel['list'] ?? null),
        };

        return $comparable ? null : 'choice_filter_not_comparable';
    }

    /**
     * M186 (`R-ed7f3127`): every narrowing set one published version's questions declare — served beside the schema with
     * the linked lists (`D60` = A), each set its own stamp. The page narrows the choices it SHOWS; the engines and the
     * server never check an answer against a set (`M133`'s rule), so a resumed or offline answer outside it still saves.
     *
     * @return list<array{field_key: string, level: int|null, stamp: string, available: bool, truncated: bool, values: list<string>}>
     */
    public function setsFor(Form $destination, FormVersion $version): array
    {
        $snapshot = $version->schema_snapshot;

        return $this->setsForFields($destination, (array) ($snapshot['fields'] ?? []));
    }

    /**
     * {@see setsFor()} over any list of fields in the snapshot's shape — a DRAFT's rows for the builder's Preview too.
     *
     * @param  iterable<mixed>  $fields
     * @return list<array{field_key: string, level: int|null, stamp: string, available: bool, truncated: bool, values: list<string>}>
     */
    public function setsForFields(Form $destination, iterable $fields): array
    {
        $sets = [];
        /** @var array<string, list<string>|false|null> $memo */
        $memo = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null)) {
                continue;
            }

            $type = FieldType::tryFrom((string) ($field['field_type'] ?? ''));
            $config = is_array($field['config'] ?? null) ? $field['config'] : null;

            foreach (self::declaredFilters($type, $config) as $declared) {
                $filter = self::filterOf($declared['filter']);
                if ($filter === null) {
                    continue; // half picked: the gate names it at publish, and the preview shows every choice
                }

                $target = $declared['level'] === null ? null : (array_values((array) ($config['levels'] ?? []))[$declared['level']] ?? null);
                $set = $this->setFor($destination, $filter, is_array($target) ? $target : null, $memo);
                // Each value cast, because the API document (Scramble) is inferred from this literal and reads a cast.
                $sets[] = [
                    'field_key' => (string) $field['key'],
                    'level' => $declared['level'] === null ? null : (int) $declared['level'],
                    'stamp' => (string) $set['stamp'],
                    'available' => (bool) $set['available'],
                    'truncated' => (bool) $set['truncated'],
                    'values' => self::strings($set['values']),
                ];
            }
        }

        return $sets;
    }

    /**
     * One filter's set as it reads right now: the distinct answers the source's finished responses hold — for a cascade
     * source, at the comparable level — and, for a choice-type source, their labels too, because a question fed by
     * another form's answers saves the label it showed (`D85`). Unavailable when either key refuses or no level compares.
     *
     * `$memo` keeps the read per source and level, so two questions narrowed by one source read it once.
     *
     * @param  array{form_id: string, field_key: string, mode: string}  $filter
     * @param  array<string, mixed>|null  $targetLevel  the cascade level it narrows, or null for a choice question's list
     * @param  array<string, list<string>|false|null>  $memo  each read's values; false when over the cap, null when it cannot be read
     * @return array{stamp: string, available: bool, truncated: bool, values: list<string>}
     */
    private function setFor(Form $destination, array $filter, ?array $targetLevel, array &$memo): array
    {
        $memoKey = $filter['form_id'].'|'.$filter['field_key'].'|'.($targetLevel === null ? '' : ((string) ($targetLevel['list'] ?? '')).'|'.((string) ($targetLevel['key'] ?? '')));
        $values = array_key_exists($memoKey, $memo) ? $memo[$memoKey] : ($memo[$memoKey] = $this->setValues($destination, $filter, $targetLevel));

        if ($values === null) {
            return ['stamp' => hash('sha256', '[]'), 'available' => false, 'truncated' => false, 'values' => []];
        }
        if ($values === false) {
            return ['stamp' => hash('sha256', 'truncated'), 'available' => true, 'truncated' => true, 'values' => []];
        }

        return ['stamp' => hash('sha256', (string) json_encode($values, JSON_UNESCAPED_UNICODE)), 'available' => true, 'truncated' => false, 'values' => $values];
    }

    /**
     * The values as a list of text.
     *
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        $list = [];
        foreach (is_array($values) ? $values : [] as $value) {
            $list[] = (string) (is_scalar($value) ? $value : '');
        }

        return $list;
    }

    /**
     * One filter's values as they read now, sorted — false when there are more than {@see MAX_SET_VALUES}, null when the
     * set cannot be read at all.
     *
     * @param  array{form_id: string, field_key: string, mode: string}  $filter
     * @param  array<string, mixed>|null  $targetLevel
     * @return list<string>|false|null
     */
    private function setValues(Form $destination, array $filter, ?array $targetLevel): array|false|null
    {
        $link = ['form_id' => $filter['form_id'], 'field_key' => $filter['field_key']];

        $source = $this->refusal($destination, $link, true) === null ? Form::query()->whereKey($link['form_id'])->first() : null;
        $field = $source === null ? null : ShareableQuestions::find($this->publishedSnapshot($source), $link['field_key'], true);
        if ($source === null || $field === null) {
            return null;
        }

        $index = null;
        if (($field['field_type'] ?? null) === FieldType::CascadingSelect->value) {
            $index = self::comparableLevel(self::levelsOf($field), $targetLevel);
            if ($index === null) {
                return null;
            }
        } elseif ($targetLevel !== null && PsgcLists::isPsgcList($targetLevel['list'] ?? null)) {
            return null; // a Philippine places level compares only with the same level of another cascade
        }

        $raw = $this->distinctAnswers($source, $link['field_key'], self::MAX_SET_VALUES + 1, $index);
        if (count($raw) > self::MAX_SET_VALUES) {
            return false;
        }

        $values = array_fill_keys($raw, true);
        if ($index === null) {
            foreach ($this->labelsFor($source, $link['field_key']) as $value => $label) {
                if (isset($values[(string) $value]) && trim($label) !== '') {
                    $values[trim($label)] = true;
                }
            }
        }

        $values = array_map('strval', array_keys($values));
        sort($values, SORT_STRING);

        return $values;
    }

    /**
     * One link's list as it reads right now — what a grid copies in when it is published (M185, `D161`): the same list, the
     * same consent and the same cap a linked question is served live. Unavailable when either key refuses.
     *
     * @param  array{form_id: string, field_key: string}  $link
     * @return array{stamp: string, available: bool, truncated: bool, options: list<array{value: string, label: string}>}
     */
    public function currentList(Form $destination, array $link): array
    {
        return $this->listFor($destination, $link);
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
        foreach ($this->distinctAnswers($source, $link['field_key'], self::MAX_CHOICES + 1) as $raw) {
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
     * The distinct non-blank answers to one top-level question, as text — at most `$limit`, one more than a cap so
     * truncation is known. Rooted on `submissions`, so a soft-deleted response is out, and filtered to finished statuses.
     * With `$level` (M186), a cascade's answer — the ordered list of the values picked — read at that level.
     *
     * @return list<string>
     */
    private function distinctAnswers(Form $source, string $key, int $limit, ?int $level = null): array
    {
        $answers = DB::table('submissions')
            ->join('submission_answers', 'submission_answers.submission_id', '=', 'submissions.id')
            ->where('submissions.form_id', $source->id)
            ->whereNull('submissions.deleted_at')
            ->whereNotIn('submissions.status', array_map(static fn (SubmissionStatus $s): string => $s->value, self::EXCLUDED_STATUSES));

        if ($level === null) {
            $answers->whereRaw("jsonb_typeof(submission_answers.answers -> ?) IN ('string', 'number')", [$key])
                ->whereRaw("btrim(submission_answers.answers ->> ?) <> ''", [$key])
                ->selectRaw('DISTINCT btrim(submission_answers.answers ->> ?) AS answer', [$key]);
        } else {
            $answers->whereRaw("jsonb_typeof(submission_answers.answers -> ?) = 'array'", [$key])
                ->whereRaw("btrim(submission_answers.answers -> ? ->> CAST(? AS integer)) <> ''", [$key, $level])
                ->selectRaw('DISTINCT btrim(submission_answers.answers -> ? ->> CAST(? AS integer)) AS answer', [$key, $level]);
        }

        return array_values(DB::query()->fromSub($answers, 'distinct_answers')
            ->orderByRaw('lower(answer), answer')
            ->limit($limit)
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
        if ($field === null || ! in_array(FieldType::tryFrom((string) ($field['field_type'] ?? '')), self::SOURCE_CHOICE_TYPES, true)) {
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
