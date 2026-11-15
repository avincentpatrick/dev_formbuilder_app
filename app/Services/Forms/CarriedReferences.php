<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\FormField;
use App\Models\FormSection;

/**
 * M242 (`R-ade0b598`) — what one renamed key carried: every question and section of the draft whose text named the old
 * key and now names the new one (the renamed row itself only when its OWN text named it), each text that named it but
 * could not be read and was left as written, and what happened to the form's thank-you message (`D227`).
 *
 * `confirmation` is `carried` when the message was rewritten (no version is live), `left` when it names the old key but
 * a live version reads it, and null when it does not name the old key at all.
 */
final readonly class CarriedReferences
{
    /**
     * @param  list<FormField>  $fields
     * @param  list<FormSection>  $sections
     * @param  list<array{kind: 'field'|'section', id: string, label: string, column: string}>  $unread
     * @param  'carried'|'left'|null  $confirmation
     */
    public function __construct(
        public string $from,
        public string $to,
        public array $fields,
        public array $sections,
        public array $unread,
        public ?string $confirmation,
    ) {}
}
