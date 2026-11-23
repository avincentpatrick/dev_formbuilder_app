<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\ExplorerChartType;
use App\Models\ExplorerChart;
use App\Models\Submission;
use App\Models\User;
use App\Services\Explorer\ExplorerCatalog;
use App\Services\Explorer\Sources\ExplorerFormReach;

/**
 * Authorization for the data explorer (M236, ADR-0025 §D5, §D8).
 *
 * ── `viewAny` is the gate on the PAGE, and it is not the gate on the data ───────────────────────────────
 * It is the predicate `/analytics` uses — `dashboard.org.view` or `dashboard.form.view`, which every seeded
 * role holds — because the explorer is the same kind of surface and no new permission is coined for it.
 * What a reader may then chart is decided per data source by {@see ExplorerCatalog}: each
 * source asks for the permission its own page asks for, and narrows its rows to the forms the reader can
 * reach. Passing this gate therefore grants an empty workbench, never a row.
 *
 * ── A shared chart is readable, not editable ────────────────────────────────────────────────────────────
 * `is_shared` lets any member who can open the page OPEN a colleague's chart. It is run as the member
 * opening it, so sharing a chart over the audit trail with someone who cannot read the audit trail shows
 * them a refusal, not the trail. Changing or deleting it stays with its author — including from an Owner,
 * the same line `SavedReportViewPolicy` draws.
 *
 * ── Downloading follows the download rule of the data's own page ────────────────────────────────────────
 * M250 (`D234`, ADR-0025 §D10): only an agreement list downloads, and only for a reader who may download the form's
 * responses from the inbox (`submissions.export` and the form's reach, {@see SubmissionPolicy::export()}). A Viewer
 * reads a shared list on screen and cannot take it away as a file — the line the inbox already draws.
 *
 * Checks go through `$user->can()` rather than `hasPermissionTo()`, which throws on an unseeded catalog.
 */
final class ExplorerChartPolicy
{
    public function __construct(private readonly ExplorerFormReach $reach) {}

    /** Also the gate on the explorer's query routes, which have no model of their own. */
    public function viewAny(User $user): bool
    {
        return $user->can('dashboard.org.view') || $user->can('dashboard.form.view');
    }

    public function create(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function view(User $user, ExplorerChart $chart): bool
    {
        return $this->viewAny($user) && ($chart->is_shared || $this->owns($user, $chart));
    }

    public function update(User $user, ExplorerChart $chart): bool
    {
        return $this->viewAny($user) && $this->owns($user, $chart);
    }

    public function delete(User $user, ExplorerChart $chart): bool
    {
        return $this->update($user, $chart);
    }

    /** M250: a saved agreement list, as a spreadsheet — never any other chart (`explorer-export` is still open). */
    public function export(User $user, ExplorerChart $chart): bool
    {
        $definition = $chart->definition;
        $formId = $definition['form_id'] ?? null;

        if (! $this->view($user, $chart) || ($definition['chart'] ?? null) !== ExplorerChartType::Agreement->value || ! is_string($formId)) {
            return false;
        }

        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $formId) !== 1) {
            return false;
        }

        $form = $this->reach->form($user, $formId);

        return $form !== null && $user->can('export', [Submission::class, $form]);
    }

    private function owns(User $user, ExplorerChart $chart): bool
    {
        return $chart->user_id === $user->getKey();
    }
}
