<?php

declare(strict_types=1);

namespace App\Services\Explorer;

use App\Enums\ExplorerBucket;
use App\Models\Form;
use App\Models\FormFolder;
use App\Models\User;
use App\Services\Explorer\Sources\ExplorerFormReach;
use App\Support\Explorer\ExplorerField;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Throwable;

/**
 * Names for the groups of a data-explorer result (ADR-0025 §D4).
 *
 * A grouped query returns keys — a form's id, `under_review`, `2026-11-01` — and a chart needs words. They
 * are resolved here, once, on the server, so the chart, its data table and anything that later exports the
 * result all print the same label for the same group.
 */
final class ExplorerLabels
{
    public const string EMPTY = '(empty)';

    /** The most choices a filter on an id field offers. */
    public const int DIRECTORY_LIMIT = 300;

    private const array WEEKDAYS = [1 => 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    public function __construct(private readonly ExplorerFormReach $reach) {}

    /**
     * The choices a filter on an id field offers — a reader cannot type a form's id, so the page needs the
     * names. Only what this reader could already see: the forms they can reach, the folders of the
     * workspace, and the members `users` row-level security shows them.
     *
     * Built for the page only. A chart's own run never calls this.
     *
     * @return array<string, string> id → name, by name
     */
    public function directory(string $lookup, User $user): array
    {
        return match ($lookup) {
            'forms' => Form::query()
                ->whereIn('forms.id', $this->reach->formIds($user))
                ->orderBy('forms.title')
                ->limit(self::DIRECTORY_LIMIT)
                ->pluck('forms.title', 'forms.id')
                ->all(),
            'folders' => FormFolder::query()->orderBy('name')->limit(self::DIRECTORY_LIMIT)->pluck('name', 'id')->all(),
            'users' => User::query()->orderBy('name')->limit(self::DIRECTORY_LIMIT)->pluck('name', 'id')->all(),
            default => [],
        };
    }

    /**
     * @param  list<string>  $keys  group keys as the query returned them; `''` stands for no value
     * @return array<string, string> key → label, for every key given
     */
    public function resolve(ExplorerField $field, ?ExplorerBucket $bucket, array $keys): array
    {
        $names = $field->lookup === null ? [] : $this->lookup($field->lookup, $keys);
        $labels = [];

        foreach ($keys as $key) {
            $labels[$key] = match (true) {
                $key === '' => self::EMPTY,
                $bucket !== null => $this->period($bucket, $key),
                $field->lookup !== null => $names[$key] ?? $this->missing($field->lookup),
                isset($field->options[$key]) => $field->options[$key],
                $field->headline => Str::headline($key),
                default => $key,
            };
        }

        return $labels;
    }

    /** A period's label from its key: an ISO date for a calendar period, a number for a weekday or an hour. */
    public function period(ExplorerBucket $bucket, string $key): string
    {
        if ($bucket === ExplorerBucket::Weekday) {
            return self::WEEKDAYS[(int) $key] ?? $key;
        }

        if ($bucket === ExplorerBucket::Hour) {
            return sprintf('%02d:00', (int) $key);
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $key, 'UTC');
        } catch (Throwable) {
            return $key;
        }

        if ($date === null) {
            return $key;
        }

        return match ($bucket) {
            ExplorerBucket::Day => $date->format('j M Y'),
            ExplorerBucket::Week => 'Week of '.$date->format('j M Y'),
            ExplorerBucket::Month => $date->format('M Y'),
            ExplorerBucket::Quarter => 'Q'.$date->quarter.' '.$date->format('Y'),
            default => $date->format('Y'),
        };
    }

    /**
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function lookup(string $directory, array $keys): array
    {
        $ids = array_values(array_filter($keys, static fn (string $key): bool => $key !== ''));

        if ($ids === []) {
            return [];
        }

        return match ($directory) {
            // `withTrashed`: a deleted form's responses are still counted, and the chart must be able to
            // name the form they belong to.
            'forms' => Form::withTrashed()->whereIn('id', $ids)->pluck('title', 'id')->all(),
            'folders' => FormFolder::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            // `users` is readable here for the reader and their active co-members only, which is the audit
            // page's rule too: anyone else is "Unknown user", never a bare id.
            'users' => User::query()->whereIn('id', $ids)->pluck('name', 'id')->all(),
            default => [],
        };
    }

    private function missing(string $directory): string
    {
        return match ($directory) {
            'forms' => 'Deleted form',
            'folders' => 'Deleted folder',
            default => 'Unknown user',
        };
    }
}
