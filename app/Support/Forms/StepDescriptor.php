<?php

declare(strict_types=1);

namespace App\Support\Forms;

/**
 * One step in a projected step list — the PHP twin of the guest runtime's `RuntimeStep`
 * (`resources/public-runtime/composables/useFormRuntime.ts`).
 *
 * A step is a PROJECTION, not a row (Doc #27 §2): there is no `steps` table, no `pages` table, no edge
 * table and no `next_step_id` column anywhere in this system. The persisted model is
 * `forms → form_versions → form_sections → form_fields`, and the step list is computed from
 * `(sections ordered by sequence, filtered by relevance)`.
 *
 * Deliberately carries no title. Resolving a label needs a locale and, since H6b, template rendering — and
 * the guest store keeps titles out of the step model on purpose so navigation does not depend on typing.
 * A caller that wants one resolves it at the point of display.
 */
final readonly class StepDescriptor
{
    /**
     * @param  string  $key  a `form_sections.key`, or {@see StepProjection::LEAD_STEP_KEY}
     * @param  ?string  $sectionKey  null only for the synthetic lead step
     * @param  list<string>  $fieldKeys  in render order; NOT filtered by relevance (see {@see StepProjection})
     */
    public function __construct(
        public string $key,
        public ?string $sectionKey,
        public array $fieldKeys,
        public bool $isRepeat,
    ) {}
}
