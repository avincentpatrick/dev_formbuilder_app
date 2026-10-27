<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldRevisionKind;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldRevision;
use App\Models\FormSection;
use App\Models\FormVersion;
use App\Models\User;

/**
 * Writes a question's history — M188 (`R-3894369d`, `D167`, `D168`; data-dictionary §42).
 *
 * Called from inside {@see FormBuilderService}'s own transactions, after their locks, with the question as it was and as
 * it is. A change records ONLY the settings whose value changed, so an unchanged re-send (an undo replayed, the edit
 * journal) writes nothing. The values are in the builder's own shape ({@see BuilderPresenter::field()}), which is what
 * the builder holds and what **Put back** sends back as an ordinary save.
 *
 * ⛔ NOT THE AUDIT LEDGER. `docs/audit-compliance-logging-spec.md` keeps draft edits out of `audits` on purpose; this is
 * the builder's own record for the people editing the form, and it writes no `audits` row.
 */
final class FieldRevisionRecorder
{
    /** The question's own settings a save can change, in the builder's names. The type changes only by conversion. */
    public const array COLUMNS = [
        'field_type', 'key', 'label', 'hint', 'placeholder', 'is_required', 'relevant_expression', 'appearance',
        'default_value', 'default_value_is_expression', 'is_pii', 'is_sensitive', 'is_queryable', 'indexed_data_type',
    ];

    /** A value larger than this is recorded as too large, and that change cannot be put back. */
    public const int MAX_VALUE_BYTES = 65536;

    public function __construct(private readonly BuilderPresenter $presenter) {}

    /**
     * The question as the builder holds it, without what only places it (id, token, order, section).
     *
     * @return array<string, mixed>
     */
    public function snapshot(FormField $field): array
    {
        $row = $this->presenter->field($field);
        unset($row['id'], $row['version'], $row['sequence'], $row['section_sequence'], $row['form_section_id']);
        $row['config'] = (array) $row['config'];

        return $row;
    }

    /**
     * Record a save, or a Put back of an earlier entry ({@see self::putBackOf()} decides which).
     *
     * @param  array<string, mixed>  $before  {@see self::snapshot()}, read under the save's lock
     * @param  array<string, mixed>  $after
     */
    public function recordEdit(Form $form, FormField $field, User $user, array $before, array $after, ?string $putBackOf = null): void
    {
        $putBack = $putBackOf !== null ? $this->putBackOf($form, $field, $putBackOf) : null;

        $this->write($form, $field, $user, $putBack !== null ? FieldRevisionKind::PutBack : FieldRevisionKind::Edited, self::changes($before, $after), $putBack);
    }

    /**
     * Record a change of question type, with every setting the conversion dropped or rewrote.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public function recordTypeChange(Form $form, FormField $field, User $user, array $before, array $after): void
    {
        $this->write($form, $field, $user, FieldRevisionKind::TypeChanged, self::changes($before, $after));
    }

    /**
     * Where every question of a draft sits: its section and its order. Read before and after a reorder or a section's
     * deletion, and compared by {@see self::recordMoves()}.
     *
     * @return array<string, array{section_id: ?string, sequence: int}> keyed by question id
     */
    public function positions(FormVersion $draft): array
    {
        $positions = [];
        foreach (FormField::query()->where('form_version_id', $draft->id)->orderBy('sequence')->orderBy('id')->get(['id', 'form_section_id', 'sequence']) as $row) {
            $positions[(string) $row->id] = ['section_id' => $row->form_section_id, 'sequence' => (int) $row->sequence];
        }

        return $positions;
    }

    /**
     * Record every question that MOVED between two {@see self::positions()} readings: one whose section changed, or one
     * outside the longest common subsequence of its section's order before and after — so a drag records the one dragged
     * question, never every row the drag renumbered.
     *
     * @param  array<string, array{section_id: ?string, sequence: int}>  $before
     * @param  array<string, array{section_id: ?string, sequence: int}>  $after
     * @param  array<string, string>  $sectionLabels  section id => label, read before the move (a deleted section's too)
     */
    public function recordMoves(Form $form, FormVersion $draft, User $user, array $before, array $after, array $sectionLabels): void
    {
        $moved = self::moved($before, $after);

        if ($moved === []) {
            return;
        }

        $orderBefore = self::orderBySection($before);
        $orderAfter = self::orderBySection($after);
        $fields = FormField::query()->where('form_version_id', $draft->id)->whereIn('id', array_keys($moved))->get();
        foreach ($fields as $field) {
            $id = (string) $field->id;
            $this->write($form, $field, $user, FieldRevisionKind::Moved, [[
                'setting' => 'position',
                'before' => self::place($orderBefore, $before, $id, $sectionLabels),
                'after' => self::place($orderAfter, $after, $id, $sectionLabels),
            ]]);
        }
    }

    /**
     * The questions that MOVED between two {@see self::positions()} readings: one whose section changed, or one outside the
     * longest common subsequence of its section's order before and after. Shared with M189's version comparison
     * ({@see VersionComparison}), which reads two versions' positions keyed by question key rather than by id.
     *
     * @param  array<string, array{section_id: ?string, sequence: int}>  $before
     * @param  array<string, array{section_id: ?string, sequence: int}>  $after
     * @return array<string, true>
     */
    public static function moved(array $before, array $after): array
    {
        $moved = [];
        foreach ($after as $id => $now) {
            if (isset($before[$id]) && $before[$id]['section_id'] !== $now['section_id']) {
                $moved[(string) $id] = true;
            }
        }

        $orderBefore = self::orderBySection($before);
        foreach (self::orderBySection($after) as $section => $ids) {
            $stayed = array_values(array_filter($ids, static fn (string $id): bool => ! isset($moved[$id]) && isset($before[$id])));
            $was = array_values(array_filter($orderBefore[$section] ?? [], static fn (string $id): bool => in_array($id, $stayed, true)));
            $kept = array_flip(self::longestCommonSubsequence($was, $stayed));
            foreach ($stayed as $id) {
                if (! isset($kept[$id])) {
                    $moved[$id] = true;
                }
            }
        }

        return $moved;
    }

    /**
     * Where one question sits, as its history records a move: its section's label (null at the top level) and its place
     * among that section's questions, from 1.
     *
     * @param  array<string, list<string>>  $order  {@see self::orderBySection()}
     * @param  array<string, array{section_id: ?string, sequence: int}>  $positions
     * @param  array<string, string>  $sectionLabels
     * @return array{section: ?string, position: ?int}
     */
    public static function place(array $order, array $positions, string $id, array $sectionLabels): array
    {
        $section = $positions[$id]['section_id'];
        $index = array_search($id, $order[(string) $section] ?? [], true);

        return [
            'section' => $section !== null ? ($sectionLabels[$section] ?? null) : null,
            'position' => is_int($index) ? $index + 1 : null,
        ];
    }

    /** @return array<string, string> section id => label, for one draft */
    public function sectionLabels(FormVersion $draft): array
    {
        /** @var array<string, string> $labels */
        $labels = FormSection::query()->where('form_version_id', $draft->id)->pluck('label', 'id')->all();

        return $labels;
    }

    /**
     * The settings whose value differs, in the builder's names: each column, each `config` key as `config.<key>`, and
     * the rules as one setting. A value over {@see self::MAX_VALUE_BYTES} is kept out and marked.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return list<array{setting: string, before: mixed, after: mixed, too_large?: bool}>
     */
    public static function changes(array $before, array $after): array
    {
        $pairs = [];
        foreach (self::COLUMNS as $column) {
            $pairs[$column] = [$before[$column] ?? null, $after[$column] ?? null];
        }

        /** @var array<string, mixed> $configBefore */
        $configBefore = (array) ($before['config'] ?? []);
        /** @var array<string, mixed> $configAfter */
        $configAfter = (array) ($after['config'] ?? []);
        $keys = array_values(array_unique([...array_keys($configBefore), ...array_keys($configAfter)]));
        sort($keys);
        foreach ($keys as $key) {
            $pairs['config.'.$key] = [$configBefore[$key] ?? null, $configAfter[$key] ?? null];
        }

        $pairs['validations'] = [array_values((array) ($before['validations'] ?? [])), array_values((array) ($after['validations'] ?? []))];

        $changes = [];
        foreach ($pairs as $setting => [$was, $now]) {
            if (self::canonical($was) === self::canonical($now)) {
                continue;
            }
            $tooLarge = strlen(self::canonical($was)) > self::MAX_VALUE_BYTES || strlen(self::canonical($now)) > self::MAX_VALUE_BYTES;
            $changes[] = $tooLarge
                ? ['setting' => (string) $setting, 'before' => null, 'after' => null, 'too_large' => true]
                : ['setting' => (string) $setting, 'before' => $was, 'after' => $now];
        }

        return $changes;
    }

    /**
     * The entry a Put back names — honoured only when it is this question's own, in this form. Anything else is recorded
     * as an ordinary edit rather than refused: the save itself is valid either way.
     */
    private function putBackOf(Form $form, FormField $field, string $revisionId): ?string
    {
        $exists = FormFieldRevision::query()
            ->whereKey($revisionId)
            ->where('form_id', $form->id)
            ->where('lineage_id', $field->lineage_id)
            ->exists();

        return $exists ? $revisionId : null;
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     */
    private function write(Form $form, FormField $field, User $user, FieldRevisionKind $kind, array $changes, ?string $putBackOf = null): void
    {
        if ($changes === []) {
            return;
        }

        $revision = new FormFieldRevision;
        $revision->forceFill([
            'form_id' => $form->id,
            'form_version_id' => $field->form_version_id,
            'lineage_id' => $field->lineage_id,
            'field_key' => $field->key,
            'field_type' => $field->field_type->value,
            'kind' => $kind,
            'changes' => $changes,
            'put_back_of' => $putBackOf,
            'user_id' => $user->id,
        ])->save();
    }

    /**
     * @param  array<string, array{section_id: ?string, sequence: int}>  $positions
     * @return array<string, list<string>> section id ('' for the top level) => question ids in order
     */
    public static function orderBySection(array $positions): array
    {
        uasort($positions, static fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);
        $order = [];
        foreach ($positions as $id => $position) {
            $order[(string) $position['section_id']][] = (string) $id;
        }

        return $order;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     * @return list<string>
     */
    private static function longestCommonSubsequence(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $length = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $length[$i][$j] = $a[$i] === $b[$j] ? $length[$i + 1][$j + 1] + 1 : max($length[$i + 1][$j], $length[$i][$j + 1]);
            }
        }

        $common = [];
        for ($i = 0, $j = 0; $i < $n && $j < $m;) {
            if ($a[$i] === $b[$j]) {
                $common[] = $a[$i];
                $i++;
                $j++;
            } elseif ($length[$i + 1][$j] >= $length[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $common;
    }

    /** One comparable text for a value: arrays with their keys sorted, so key order is never a change. */
    public static function canonical(mixed $value): string
    {
        $sort = static function (mixed $v) use (&$sort): mixed {
            if (! is_array($v)) {
                return $v;
            }
            if (! array_is_list($v)) {
                ksort($v);
            }

            return array_map($sort, $v);
        };

        return (string) json_encode($sort($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }
}
