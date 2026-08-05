<?php

declare(strict_types=1);

namespace App\Support\Mapping;

/**
 * The verdict {@see MappingDriftDetector} returns: whether the artifact's header row still matches the row a
 * {@see ColumnMapping} was authored against, and — when it does not — what changed.
 *
 * THE THREE AXES ARE REPORTED SEPARATELY BECAUSE THEY READ DIFFERENTLY TO A HUMAN. "You added a column" is a
 * five-second fix; "your columns are in a different order" is the one that would have silently written every
 * answer into the wrong place, and a tenant who is told only "the sheet changed" has to diff it themselves.
 * H16b renders these; the notification and the ledger excerpt use {@see summary()}.
 *
 * A drifted verdict deliberately carries no *suggested* re-mapping. Guessing that a renamed column is the same
 * column is exactly the inference that makes silent misalignment possible, and the whole decision here
 * (`ocr-pipeline-design.md:84`) is to ask a human instead.
 */
final readonly class MappingDrift
{
    /**
     * @param  list<string>  $added    normalized headers present now that the mapping never saw
     * @param  list<string>  $removed  normalized headers the mapping binds that are gone
     * @param  list<string>  $moved    normalized headers present in both, at a different column index
     */
    private function __construct(
        public bool $hasDrifted,
        public array $added,
        public array $removed,
        public array $moved,
    ) {}

    public static function unchanged(): self
    {
        return new self(false, [], [], []);
    }

    /**
     * @param  list<string>  $added
     * @param  list<string>  $removed
     * @param  list<string>  $moved
     */
    public static function drifted(array $added, array $removed, array $moved): self
    {
        return new self(true, $added, $removed, $moved);
    }

    /**
     * One line naming what changed, for the owner notification and the delivery ledger's excerpt.
     *
     * Headers are the TENANT'S OWN TEXT, echoed back to the tenant who wrote them — not third-party text, which
     * is why this may quote them where `ConnectorChannelDirectory::message()` deliberately refuses to echo a
     * provider's error string. They are still bounded, because a header can be as long as a spreadsheet cell.
     */
    public function summary(): string
    {
        if (! $this->hasDrifted) {
            return 'The column layout is unchanged.';
        }

        $parts = [];

        if ($this->added !== []) {
            $parts[] = 'added '.$this->quote($this->added);
        }

        if ($this->removed !== []) {
            $parts[] = 'removed '.$this->quote($this->removed);
        }

        if ($this->moved !== []) {
            $parts[] = 'moved '.$this->quote($this->moved);
        }

        // Reachable when the header row is unchanged as a SET and as an ORDER yet the digests disagree — a
        // stored fingerprint from a mapping whose headers were not stored alongside it. Saying so beats
        // rendering "The columns changed: " with nothing after the colon.
        if ($parts === []) {
            return 'The column layout no longer matches the one this rule was set up against.';
        }

        return 'The columns changed: '.implode('; ', $parts).'.';
    }

    /**
     * @param  list<string>  $headers
     */
    private function quote(array $headers): string
    {
        $shown = array_slice($headers, 0, 3);
        $quoted = implode(', ', array_map(
            static fn (string $header): string => '“'.mb_substr($header, 0, 60).'”',
            $shown,
        ));

        $extra = count($headers) - count($shown);

        return $extra > 0 ? $quoted.' and '.$extra.' more' : $quoted;
    }
}
