<?php

declare(strict_types=1);

namespace App\Services\Connectors;

use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Submissions\SubmissionRowProjector;
use App\Support\Mapping\ColumnMapping;
use Illuminate\Support\Collection;

/**
 * Everything a Sheets column can be bound to: a form's answer fields plus the submission metadata (H16b).
 *
 * ── IT READS THE PROJECTOR, NOT THE FORM SCHEMA ────────────────────────────────────────────────────────────
 * The keys offered here MUST be exactly the keys {@see SubmissionRowProjector} will produce at delivery time,
 * or the editor lets a tenant bind a column to something that is always blank — the failure being invisible
 * because a blank cell looks like an unanswered question. So this asks the projector rather than walking
 * `schema_snapshot` itself: `resolveColumns()` already drops non-data field types via `isDataField()`, unions
 * keys across versions, and renders piped headers, and every one of those is a rule the editor would
 * otherwise have to re-implement and eventually disagree with.
 *
 * `metaLabels()`'s own docblock names this UI as its reason for existing, so the metadata half is a read of a
 * seam H16a deliberately left.
 *
 * ── EVERY VERSION, NOT JUST THE PUBLISHED ONE ──────────────────────────────────────────────────────────────
 * Submissions FK to the immutable `form_version_id` they were collected against, so a form republished with a
 * renamed field has live submissions under BOTH keys. Offering only the current version's keys would make a
 * mapping that silently stops filling for the older rows still arriving from a cached guest runtime.
 *
 * ── ⚠️ AN ALL-FORMS RULE HAS NO ANSWER COLUMNS, AND THAT IS HONEST RATHER THAN A GAP ───────────────────────
 * A rule with `form_id = null` receives submissions from every form, and `ColumnMapping::project()` writes
 * `''` for a key the submission's own version does not define. So a spreadsheet fed by an all-forms rule
 * would have one populated column per form and blanks everywhere else — technically working, practically
 * unreadable. The catalog answers with metadata only in that case, which makes the editor say so instead of
 * offering fields most rows will never carry.
 */
final class MappableColumnCatalog
{
    public function __construct(private readonly SubmissionRowProjector $projector) {}

    /**
     * @return array{columns: list<array{key: string, label: string, group: string}>, scoped: bool}
     */
    public function for(?Form $form): array
    {
        $columns = [];

        if ($form !== null) {
            /** @var Collection<int, FormVersion> $versions */
            $versions = $form->versions()->orderByDesc('version_number')->get();

            [$fieldColumns] = $this->projector->resolveColumns($versions, $form->default_locale ?? 'en');

            foreach ($fieldColumns as $key => $label) {
                $columns[] = [
                    'key' => (string) $key,
                    // A field whose label is empty (or is a piped header with nothing to fill from — see
                    // `SubmissionRowProjector::header()`) would otherwise render as a blank option the tenant
                    // cannot tell apart from the next one. The key is the identity and always readable.
                    'label' => trim((string) $label) !== '' ? (string) $label : (string) $key,
                    'group' => 'Form fields',
                ];
            }
        }

        foreach (SubmissionRowProjector::metaLabels() as $key => $label) {
            $columns[] = ['key' => $key, 'label' => $label, 'group' => 'Submission details'];
        }

        return ['columns' => $columns, 'scoped' => $form !== null];
    }
}
