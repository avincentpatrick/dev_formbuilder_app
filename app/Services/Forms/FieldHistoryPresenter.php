<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Enums\FieldRevisionKind;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormFieldRevision;
use App\Models\User;
use App\Support\Forms\OtherSpecify;
use Illuminate\Support\Carbon;

/**
 * A question's history as the right panel's History tab reads it — M188 (`R-3894369d`, `D167`, `D168`).
 *
 * Newest first, {@see self::PAGE} entries at a time. **One person's saves to the same settings, under
 * {@see self::MERGE_SECONDS} apart, read as one change** (`D167`: typing a label in bursts is one change), from the first
 * value to the last; a Put back, a type change and a move are never merged. Each entry says whether it can be put back,
 * and when not, why — a reason code the builder words:
 *
 * - `type_changed` / `moved` — `D168`: listed, never put back (change the type again; drag the question back);
 * - `other_type` — made while the question was another type, so its settings may not fit this one (`D168`);
 * - `other_specify` — the "Other, please specify" switch, which creates and deletes a question, so only the switch puts it
 *   back;
 * - `too_large` — a value too large to keep was left out of the history.
 */
final class FieldHistoryPresenter
{
    public const int PAGE = 50;

    public const int MERGE_SECONDS = 120;

    /** Raw entries read for one page; a page of merged changes can hold many saves. */
    private const int READ = 500;

    /**
     * @return array{data: list<array<string, mixed>>, next: ?string}
     */
    public function present(Form $form, FormField $field, ?string $before = null): array
    {
        $query = FormFieldRevision::query()
            ->where('form_id', $form->id)
            ->where('lineage_id', $field->lineage_id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($before !== null) {
            $cursor = FormFieldRevision::query()->where('form_id', $form->id)->whereKey($before)->first(['id', 'created_at']);
            if ($cursor !== null) {
                $query->whereRaw('(created_at, id) < (?, ?)', [$cursor->created_at, $cursor->id]);
            }
        }

        $rows = $query->limit(self::READ + 1)->get();
        $more = $rows->count() > self::READ;
        $groups = $this->merge(array_values($rows->take(self::READ)->all()));

        $page = array_slice($groups, 0, self::PAGE);
        $next = null;
        if ($page !== [] && ($more || count($groups) > self::PAGE)) {
            $last = $page[count($page) - 1];
            $next = (string) $last['oldest']->id;
        }

        $names = $this->names($page);
        $putBackTimes = $this->putBackTimes($form, $page);

        return [
            'data' => array_map(fn (array $group): array => $this->entry($field, $group, $names, $putBackTimes), $page),
            'next' => $next,
        ];
    }

    /**
     * Newest-first rows into changes: a run of one person's `edited` saves touching the same settings, each under
     * {@see self::MERGE_SECONDS} older than the one after it, is one change.
     *
     * @param  list<FormFieldRevision>  $rows  newest first
     * @return list<array{newest: FormFieldRevision, oldest: FormFieldRevision, changes: list<array<string, mixed>>}>
     */
    private function merge(array $rows): array
    {
        $groups = [];
        $current = null;

        foreach ($rows as $row) {
            if ($current !== null && $this->joins($current['oldest'], $row)) {
                $current = self::group($current['newest'], $row, $this->combine($row->changes, $current['changes']));

                continue;
            }
            if ($current !== null) {
                $groups[] = $current;
            }
            $current = self::group($row, $row, $row->changes);
        }
        if ($current !== null) {
            $groups[] = $current;
        }

        // A run whose settings came back to where they started is no change at all.
        return array_values(array_filter($groups, static fn (array $group): bool => $group['changes'] !== []));
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return array{newest: FormFieldRevision, oldest: FormFieldRevision, changes: list<array<string, mixed>>}
     */
    private static function group(FormFieldRevision $newest, FormFieldRevision $oldest, array $changes): array
    {
        return ['newest' => $newest, 'oldest' => $oldest, 'changes' => $changes];
    }

    /** Whether an older row continues the change a newer one belongs to. */
    private function joins(FormFieldRevision $newer, FormFieldRevision $older): bool
    {
        return $newer->kind === FieldRevisionKind::Edited
            && $older->kind === FieldRevisionKind::Edited
            && $newer->user_id !== null
            && $newer->user_id === $older->user_id
            && $newer->field_type === $older->field_type
            && self::settings($newer->changes) === self::settings($older->changes)
            && $newer->created_at->diffInSeconds($older->created_at, true) < self::MERGE_SECONDS;
    }

    /**
     * An older change followed by a newer one, setting by setting: the older's before, the newer's after; a setting that
     * ends where it began drops out.
     *
     * @param  list<array<string, mixed>>  $older
     * @param  list<array<string, mixed>>  $newer
     * @return list<array<string, mixed>>
     */
    private function combine(array $older, array $newer): array
    {
        $byOlder = [];
        foreach ($older as $change) {
            $byOlder[(string) $change['setting']] = $change;
        }

        $combined = [];
        foreach ($newer as $change) {
            $was = $byOlder[(string) $change['setting']] ?? $change;
            $tooLarge = ($was['too_large'] ?? false) || ($change['too_large'] ?? false);
            if (! $tooLarge && json_encode($was['before']) === json_encode($change['after'])) {
                continue;
            }
            $combined[] = $tooLarge
                ? ['setting' => $change['setting'], 'before' => null, 'after' => null, 'too_large' => true]
                : ['setting' => $change['setting'], 'before' => $was['before'], 'after' => $change['after']];
        }

        return $combined;
    }

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return list<string>
     */
    private static function settings(array $changes): array
    {
        $settings = array_map(static fn (array $change): string => (string) $change['setting'], $changes);
        sort($settings);

        return $settings;
    }

    /**
     * @param  array{newest: FormFieldRevision, oldest: FormFieldRevision, changes: list<array<string, mixed>>}  $group
     * @param  array<string, string>  $names
     * @param  array<string, Carbon>  $putBackTimes
     * @return array<string, mixed>
     */
    private function entry(FormField $field, array $group, array $names, array $putBackTimes): array
    {
        $newest = $group['newest'];
        $reason = $this->refusal($field, $newest, $group['changes']);
        $putBackOf = $newest->put_back_of;

        return [
            'id' => $newest->id,
            'kind' => $newest->kind->value,
            'at' => $newest->created_at->toIso8601String(),
            'from' => $group['oldest']->created_at->toIso8601String(),
            'by' => $newest->user_id !== null ? ($names[$newest->user_id] ?? null) : null,
            'field_key' => $newest->field_key,
            'field_type' => $newest->field_type,
            'changes' => $group['changes'],
            'put_back_of_at' => $putBackOf !== null && isset($putBackTimes[$putBackOf]) ? $putBackTimes[$putBackOf]->toIso8601String() : null,
            'can_put_back' => $reason === null,
            'reason' => $reason,
        ];
    }

    /**
     * Why an entry cannot be put back, or null when it can (`D168`).
     *
     * @param  list<array<string, mixed>>  $changes
     */
    private function refusal(FormField $field, FormFieldRevision $entry, array $changes): ?string
    {
        if (! $entry->kind->canBePutBack()) {
            return $entry->kind->value;
        }
        if ($entry->field_type !== $field->field_type->value) {
            return 'other_type';
        }
        foreach ($changes as $change) {
            if ($change['setting'] === 'config.'.OtherSpecify::CONFIG_KEY) {
                return 'other_specify';
            }
            if ($change['too_large'] ?? false) {
                return 'too_large';
            }
        }

        return null;
    }

    /**
     * Who made each change, by the name a member sees — none for an account that is gone, or one this workspace can no
     * longer see (the builder says "A former member").
     *
     * @param  list<array{newest: FormFieldRevision, oldest: FormFieldRevision, changes: list<array<string, mixed>>}>  $groups
     * @return array<string, string>
     */
    private function names(array $groups): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $g): ?string => $g['newest']->user_id, $groups))));

        /** @var array<string, string> $names */
        $names = $ids === [] ? [] : User::query()->whereIn('id', $ids)->pluck('name', 'id')->all();

        return $names;
    }

    /**
     * When each entry a Put back names was made, so the entry can say which change it put back.
     *
     * @param  list<array{newest: FormFieldRevision, oldest: FormFieldRevision, changes: list<array<string, mixed>>}>  $groups
     * @return array<string, Carbon>
     */
    private function putBackTimes(Form $form, array $groups): array
    {
        $ids = array_values(array_unique(array_filter(array_map(static fn (array $g): ?string => $g['newest']->put_back_of, $groups))));
        if ($ids === []) {
            return [];
        }

        $times = [];
        foreach (FormFieldRevision::query()->where('form_id', $form->id)->whereIn('id', $ids)->get(['id', 'created_at']) as $row) {
            $times[(string) $row->id] = $row->created_at;
        }

        return $times;
    }
}
