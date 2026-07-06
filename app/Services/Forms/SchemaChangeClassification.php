<?php

declare(strict_types=1);

namespace App\Services\Forms;

/**
 * The result of diffing a draft against the currently-published version (form-versioning-schema-migration.md
 * §5), keyed by the stable `key`. Feeds the auto-generated `form_versions.change_summary`. Phase 1 computes
 * and stores this; the interactive warning/diff UI is deferred to Phase 2/3, so nothing here blocks a publish.
 */
final readonly class SchemaChangeClassification
{
    /**
     * @param  list<string>  $addedFieldKeys  new field keys (non-breaking)
     * @param  list<string>  $removedFieldKeys  field keys dropped from the new version (breaking-but-permitted)
     * @param  list<array{key: string, change: string}>  $breakingChanges  same-key type/requiredness/indexing changes
     * @param  list<string>  $addedSectionKeys
     * @param  list<string>  $removedSectionKeys
     */
    public function __construct(
        public bool $isFirstPublish,
        public array $addedFieldKeys,
        public array $removedFieldKeys,
        public array $breakingChanges,
        public array $addedSectionKeys,
        public array $removedSectionKeys,
    ) {}

    public function hasBreakingChanges(): bool
    {
        return $this->removedFieldKeys !== [] || $this->breakingChanges !== [];
    }

    /** A plain-text, deterministic changelog auto-populated into change_summary. */
    public function changeSummary(): string
    {
        if ($this->isFirstPublish) {
            return 'Initial version.';
        }

        $lines = [];
        foreach ($this->addedSectionKeys as $key) {
            $lines[] = "Added section: {$key}";
        }
        foreach ($this->removedSectionKeys as $key) {
            $lines[] = "Removed section: {$key}";
        }
        foreach ($this->addedFieldKeys as $key) {
            $lines[] = "Added field: {$key}";
        }
        foreach ($this->removedFieldKeys as $key) {
            $lines[] = "Removed field: {$key}";
        }
        foreach ($this->breakingChanges as $change) {
            $lines[] = "Changed field {$change['key']}: {$change['change']}";
        }

        return $lines === [] ? 'No structural changes.' : implode("\n", $lines);
    }
}
