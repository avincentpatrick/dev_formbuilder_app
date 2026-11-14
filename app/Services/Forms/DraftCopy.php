<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\FormField;
use App\Models\FormSection;
use App\Models\FormVersion;

/**
 * M240 — what one copy made: the new section (for a section copy), and each copied question beside the id of the
 * question it was copied from, in source order. The builder matches each copy to its source by that id, so a redo
 * can give every copy back the identity a later history entry names.
 */
final readonly class DraftCopy
{
    /**
     * @param  list<array{source_id: string, copy: FormField}>  $copies
     */
    public function __construct(
        public FormVersion $draft,
        public ?FormSection $section,
        public array $copies,
    ) {}
}
