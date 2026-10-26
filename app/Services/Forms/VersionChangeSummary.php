<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\FormVersion;
use Illuminate\Support\Collection;

/**
 * M172 (`R-52f5ad93`, `D133`) — what changed in each published version, in the questions' own words, for the hub's
 * Versions panel. The testers' request: *"there must also a way to check the older version for history"*.
 *
 * ⚠️ DERIVED FROM THE SNAPSHOTS WHEN READ, NOT FROM `change_summary`. That column is written at publish as plain text
 * naming KEYS ("Added field: hh_size"), and a published version's row is frozen (`PublishedVersionGuard`), so labels
 * cannot be added to it after the fact. Each published version's `schema_snapshot` already carries every question's
 * key, label, type and requiredness; comparing it with the previous published version's gives the same verdict in the
 * words an author used. The publisher's note — the text after the summary's blank line — is passed through as written.
 *
 * M189: a question is "changed" when ANY of its settings differs — its choices, rules, hint, translations — by
 * {@see VersionComparison::fingerprint()}, the Compare page's own reading. Until then only the label, type and Required were
 * compared, so a question whose choices changed read as unchanged. Where it sits is not a setting: a move alone is not listed.
 *
 * Reads the rows the caller already loaded: the hub loads every version whole, so this costs no query of its own.
 */
final class VersionChangeSummary
{
    /**
     * @param  Collection<int, FormVersion>  $versions  any order; drafts are skipped
     * @return array<string, array{first: bool, added: list<string>, removed: list<string>, changed: list<string>, note: string|null}>
     *                                                                                                                                 version id ⇒ what it changed
     */
    public function for(Collection $versions): array
    {
        $published = $versions
            ->filter(fn (FormVersion $version): bool => $version->published_at !== null)
            ->sortBy('version_number')
            ->values();

        $summaries = [];
        $previous = null;

        foreach ($published as $version) {
            $questions = $this->questions($version);

            $summaries[(string) $version->id] = $previous === null
                ? ['first' => true, 'added' => [], 'removed' => [], 'changed' => [], 'note' => $this->note($version)]
                : [
                    'first' => false,
                    'added' => array_values(array_map(fn (array $q): string => $q['label'], array_diff_key($questions, $previous))),
                    'removed' => array_values(array_map(fn (array $q): string => $q['label'], array_diff_key($previous, $questions))),
                    'changed' => array_values(array_map(
                        fn (array $q): string => $q['label'],
                        array_filter(array_intersect_key($questions, $previous), fn (array $q, string $key): bool => $q['fingerprint'] !== $previous[$key]['fingerprint'], ARRAY_FILTER_USE_BOTH),
                    )),
                    'note' => $this->note($version),
                ];

            $previous = $questions;
        }

        return $summaries;
    }

    /**
     * @return array<string, array{label: string, fingerprint: string}> key ⇒ its label, and its settings as one comparable text
     */
    private function questions(FormVersion $version): array
    {
        $snapshot = $version->schema_snapshot;
        $fields = is_array($snapshot['fields'] ?? null) ? $snapshot['fields'] : [];
        $questions = [];

        foreach ($fields as $field) {
            if (! is_array($field) || ! is_string($field['key'] ?? null)) {
                continue;
            }

            $label = is_string($field['label'] ?? null) && trim($field['label']) !== '' ? $field['label'] : $field['key'];
            $questions[$field['key']] = ['label' => $label, 'fingerprint' => VersionComparison::fingerprint($field)];
        }

        return $questions;
    }

    /** The publisher's own note: `PublishService` appends it to the generated summary after a blank line. */
    private function note(FormVersion $version): ?string
    {
        $parts = explode("\n\n", (string) $version->change_summary, 2);
        $note = trim($parts[1] ?? '');

        return $note === '' ? null : $note;
    }
}
