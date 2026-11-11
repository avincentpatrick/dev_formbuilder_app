<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\ExplorerChart;
use App\Models\User;
use App\Services\Explorer\ExplorerCatalog;

/**
 * Authorization for the data explorer (M235, ADR-0025 §D5, §D8).
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
 * Checks go through `$user->can()` rather than `hasPermissionTo()`, which throws on an unseeded catalog.
 */
final class ExplorerChartPolicy
{
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

    private function owns(User $user, ExplorerChart $chart): bool
    {
        return $chart->user_id === $user->getKey();
    }
}
