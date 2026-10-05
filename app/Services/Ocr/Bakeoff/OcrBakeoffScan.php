<?php

declare(strict_types=1);

namespace App\Services\Ocr\Bakeoff;

/**
 * One scan as the bake-off saw it (M136): what was read off it, and the correct answers it is scored against.
 *
 * `status` is `read` (matched against a version), `not_read` (no provider answer — `--offline` with nothing cached),
 * `failed` (the provider refused the file, or the run's credential) or `not_eligible` (its version cannot be read
 * automatically). Only a `read` scan is scored.
 */
final readonly class OcrBakeoffScan
{
    /**
     * @param  list<string>  $files  the page files, in page order
     * @param  array<string, array<string, mixed>>  $fields  the matcher's fields, matched with zero thresholds so no
     *                                                       value is withheld
     * @param  array<string, mixed>|null  $expected  field key => the correct answer in stored shape, for the questions
     *                                               the answer sheet has a column for; null when it has no row for this scan
     */
    public function __construct(
        public string $name,
        public array $files,
        public string $status,
        public ?string $message = null,
        public ?string $condition = null,
        public ?int $versionNumber = null,
        public ?string $matchedBy = null,
        public array $fields = [],
        public ?array $expected = null,
    ) {}
}
