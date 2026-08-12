<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\User;
use App\Services\Connectors\ConnectionPresenter;
use App\Services\Connectors\ConnectionService;
use App\Services\Connectors\ConnectorChannelDirectory;
use App\Services\Connectors\SheetDestinationDirectory;
use App\Support\Connectors\ConnectorConnectOutcome;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Integrations UI (H15b) — the session-authed Inertia surface over the H15a connector framework. A thin
 * adapter: `index` renders from {@see ConnectionPresenter}, and the disconnect delegates to the SAME
 * {@see ConnectionService} the /api/v1 controller calls, returning a redirect + flash so a domain exception on
 * a web route stays a redirect, never JSON. Authorization is the route's `can:` policy gate +
 * `feature:native_connectors`; there is no `ability:` here (Sanctum token scope — a session carries no token).
 *
 * `index` also closes the OAuth loop. The provider callback lands on the CENTRAL domain, which cannot write
 * this host's session cookie, so the outcome arrives as `?connected=` / `?connect_error=` instead of a flash
 * (ADR-0009 §D5). This method converts it into a real flash and redirects to a clean URL — a plain
 * Post/Redirect/Get — so the toast fires exactly once through the bridge the whole app already uses, and a
 * refresh has no query string left to re-raise it from.
 *
 * `channels` is the one read-only JSON sidecar on this surface (the `scopes.grants` precedent). Every mutation
 * here is an Inertia visit; only this read is a fetch, because a picker must be able to load without navigating.
 */
final class ConnectionController extends Controller
{
    /** The provider catalog, connected workspaces + their rules, and the rule-modal option catalogs. */
    public function index(Request $request, ConnectionPresenter $presenter): Response|RedirectResponse
    {
        $toast = ConnectorConnectOutcome::toast(
            $this->query($request, 'connected'),
            $this->query($request, 'provider'),
            $this->query($request, 'connect_error'),
        );

        if ($toast !== null) {
            return to_route('integrations.index')->with('toast', $toast);
        }

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('integrations/Index', $presenter->index($user));
    }

    /**
     * The channel picker's source. Always 200 when authorized, carrying `error` in the body on failure rather
     * than a 5xx: the sidecar client discards a non-ok response along with its message, and a Slack outage must
     * degrade the picker to a manual channel id, never break the page hosting it.
     */
    public function channels(Connection $connection, ConnectorChannelDirectory $directory): JsonResponse
    {
        return response()->json($directory->list($connection));
    }

    /**
     * Read an existing spreadsheet's tabs and header row so a mapping can be authored against it (H16b).
     *
     * Same always-200 contract as {@see channels()} and for the same reason, plus one specific to this
     * surface: the tenant is mid-way through a rule form, and a 4xx here would lose everything they had
     * already typed into it.
     */
    public function inspectSheet(Request $request, Connection $connection, SheetDestinationDirectory $directory): JsonResponse
    {
        $validated = $request->validate([
            'reference' => ['required', 'string', 'max:2048'],
            'sheet_name' => ['nullable', 'string', 'max:100'],
        ]);

        return response()->json($directory->inspect(
            $connection,
            $validated['reference'],
            $validated['sheet_name'] ?? null,
        ));
    }

    /**
     * Create a spreadsheet for this grant, with the given headers in row 1 (H16b).
     *
     * ⚠️ A WRITE BEHIND A JSON SIDECAR, WHICH THE H15b DOCBLOCK ABOVE SAYS THIS SURFACE DOES NOT DO — so the
     * exception is argued rather than assumed. That rule exists because a domain exception on a tenant-web
     * route renders as a 302 (`bootstrap/app.php` keys its JSON branch on the `api/v1/*` PATH), which a fetch
     * client follows into HTML. {@see SheetDestinationDirectory} never throws, so the hazard cannot arise.
     *
     * The alternative — an Inertia visit — was rejected on behaviour, not taste: this produces a value the
     * OPEN FORM needs (the new id, its tab, its header row) and the rule is not saved yet, so a redirect
     * would have to round-trip the tenant's half-finished input through the session to survive.
     *
     * The write it performs is in the tenant's own Drive, never in our database: nothing here persists, and
     * the id only reaches `connection_subscriptions.config` when the tenant submits the rule.
     */
    public function createSheet(Request $request, Connection $connection, SheetDestinationDirectory $directory): JsonResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:200'],
            'headers' => ['required', 'array', 'min:1', 'max:200'],
            'headers.*' => ['present', 'string', 'max:255'],
        ]);

        return response()->json($directory->create(
            $connection,
            $validated['title'],
            array_values(array_map(strval(...), $validated['headers'])),
        ));
    }

    /** Revoke the grant locally and return to the list. Rules survive, paused (ADR-0009 §D7). */
    public function destroy(Connection $connection, ConnectionService $service): RedirectResponse
    {
        $label = $connection->external_account_label;

        $service->disconnect($connection);

        return to_route('integrations.index')->with('toast', [
            'type' => 'success',
            'message' => $label.' disconnected. Its delivery rules are paused until you reconnect.',
        ]);
    }

    /** A query parameter as a string, or null — `?connected[]=x` would otherwise reach the outcome map as an array. */
    private function query(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
