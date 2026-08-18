<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\ConnectionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreConnectionRuleRequest;
use App\Http\Requests\Tenant\UpdateConnectionRuleRequest;
use App\Models\Connection;
use App\Models\ConnectionSubscription;
use App\Models\User;
use App\Policies\ConnectionSubscriptionPolicy;
use App\Services\Connectors\ConnectionPresenter;
use App\Services\Connectors\ConnectionSubscriptionService;
use App\Services\Connectors\ConnectorTester;
use App\Services\Entitlements\EntitlementService;
use App\Support\Navigation\CrumbTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Delivery rules on a native connector (H15b) — the session-authed twin of the /api/v1 subscription controller,
 * delegating every mutation to the SAME {@see ConnectionSubscriptionService} and returning a redirect + flash.
 *
 * Only `store` binds a {@see Connection}: a rule is created ON a grant, but thereafter has its own identity and
 * its own page, so the rest of the routes are flat and gated by {@see ConnectionSubscriptionPolicy}.
 * That is deliberate rather than convenient — `disconnect()` soft-deletes the grant while keeping its rules, so
 * a nested `{connection}` binding would 404 exactly when a tenant is tidying up after disconnecting.
 *
 * `test` flashes its synchronous result under the SAME `testResult` key H14 introduced, in the same shape, so
 * the existing modal renders it unchanged.
 */
final class ConnectionRuleController extends Controller
{
    public function __construct(private readonly ConnectionSubscriptionService $service) {}

    /** One rule's detail + its paginated delivery log from the shared ledger + the edit-modal catalogs. */
    public function show(
        Request $request,
        ConnectionSubscription $connectionSubscription,
        ConnectionPresenter $presenter,
        EntitlementService $entitlements,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('integrations/RuleShow', [
            ...$presenter->ruleShow($user, $connectionSubscription),
            'crumbs' => CrumbTrail::integrations($user, $entitlements)->current($connectionSubscription->name),
        ]);
    }

    /** Create a rule on a grant, then land on its detail page. */
    public function store(StoreConnectionRuleRequest $request, Connection $connection): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $rule = $this->service->create($connection, $request->validated(), $user);

        return to_route('integrations.rules.show', $rule)
            ->with('toast', ['type' => 'success', 'message' => 'Delivery rule created.']);
    }

    /** Partial update, including the pause/resume toggle (re-enabling resets the breaker in the service). */
    public function update(UpdateConnectionRuleRequest $request, ConnectionSubscription $connectionSubscription): RedirectResponse
    {
        $this->service->update($connectionSubscription, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Delivery rule updated.']);
    }

    /** Soft-delete a rule and return to the list. */
    public function destroy(ConnectionSubscription $connectionSubscription): RedirectResponse
    {
        $this->service->delete($connectionSubscription);

        return to_route('integrations.index')
            ->with('toast', ['type' => 'success', 'message' => 'Delivery rule deleted.']);
    }

    /**
     * Send a synthetic test.ping through this rule and flash the synchronous result.
     *
     * A dead or disconnected grant is refused with a toast rather than a 409: on a web route a 409 renders an
     * error page, which is the wrong answer to "your workspace needs reconnecting" (H14's redeliver precedent).
     */
    public function test(ConnectionSubscription $connectionSubscription, ConnectorTester $tester): RedirectResponse
    {
        $connection = $connectionSubscription->grant()->withTrashed()->first();

        if (! $connection instanceof Connection || $connection->trashed() || $connection->status !== ConnectionStatus::Active) {
            return back()->with('toast', [
                'type' => 'error',
                'message' => 'Reconnect this workspace before sending a test message.',
            ]);
        }

        $result = $tester->send($connection, $connectionSubscription);

        return back()
            ->with('toast', $result['delivered']
                ? ['type' => 'success', 'message' => 'Test message delivered.']
                : ['type' => 'error', 'message' => 'Test message failed — see details.'])
            ->with('testResult', $result);
    }
}
