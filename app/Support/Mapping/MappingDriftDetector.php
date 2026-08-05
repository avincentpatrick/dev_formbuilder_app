<?php

declare(strict_types=1);

namespace App\Support\Mapping;

/**
 * Compares a {@see ColumnMapping} against the header row an artifact carries RIGHT NOW (H16a), and reports
 * whether the mapping may still be applied.
 *
 * THE DIGEST DECIDES; THE THREE AXES ONLY EXPLAIN. The verdict's `hasDrifted` comes from
 * {@see ColumnFingerprint::matches()} alone, never from "added is empty and removed is empty and moved is
 * empty" — those are derived for the human-facing summary and are deliberately incapable of overruling the
 * digest. Inverting that would let any change the diff fails to characterise read as "unchanged", which is the
 * silent-misalignment failure this whole engine exists to prevent, reintroduced one level up.
 *
 * Stateless and dependency-free by construction. Both consumers reach it the same way: H16a's Sheets adapter
 * before every append, H19a's linelist before staging a batch.
 */
final class MappingDriftDetector
{
    /**
     * @param  list<string|null>  $currentHeaders  the header row as just read from the artifact
     */
    public function compare(ColumnMapping $mapping, array $currentHeaders): MappingDrift
    {
        $current = ColumnFingerprint::forHeaders($currentHeaders);

        if ($mapping->fingerprint->matches($current)) {
            return MappingDrift::unchanged();
        }

        $before = $mapping->fingerprint->headers;
        $after = $current->headers;

        return MappingDrift::drifted(
            added: $this->missingFrom($after, $before),
            removed: $this->missingFrom($before, $after),
            moved: $this->movedBetween($before, $after),
        );
    }

    /**
     * Headers in `$subject` that `$reference` does not contain.
     *
     * Duplicate labels are deliberately compared as a SET here rather than as a multiset: a spreadsheet with
     * two columns both headed "notes" has an authoring problem the diff should not try to narrate, and the
     * digest has already flagged the change either way.
     *
     * @param  list<string>  $subject
     * @param  list<string>  $reference
     * @return list<string>
     */
    private function missingFrom(array $subject, array $reference): array
    {
        $lookup = array_flip($reference);

        return array_values(array_unique(array_filter(
            $subject,
            static fn (string $header): bool => $header !== '' && ! isset($lookup[$header]),
        )));
    }

    /**
     * Headers present in both rows but at a different column index — the change that would misalign data while
     * looking, in a "which columns exist?" check, like nothing happened at all.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     * @return list<string>
     */
    private function movedBetween(array $before, array $after): array
    {
        $afterIndex = [];

        foreach ($after as $index => $header) {
            // First occurrence wins, so a duplicated label is compared against a stable position rather than
            // reporting itself as moved purely because it appears twice.
            if ($header !== '' && ! isset($afterIndex[$header])) {
                $afterIndex[$header] = $index;
            }
        }

        $moved = [];

        foreach ($before as $index => $header) {
            if ($header === '' || ! isset($afterIndex[$header])) {
                continue;
            }

            if ($afterIndex[$header] !== $index) {
                $moved[] = $header;
            }
        }

        return array_values(array_unique($moved));
    }
}
