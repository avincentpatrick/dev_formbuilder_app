<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Authorization\ResponseReadAccess;
use App\Support\Forms\ShareableQuestions;

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

    public function __construct(private readonly ResponseReadAccess $access) {}

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

        $chosen = $source->data_sharing_field_keys;

        return ShareableQuestions::sharedKeys(
            $this->publishedSnapshot($source),
            is_array($chosen) ? array_values(array_map('strval', $chosen)) : null,
        );
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
