<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\WebhookEndpointStatus;
use App\Http\Controllers\Concerns\ReadsKeywordFilter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreWebhookRequest;
use App\Http\Requests\Tenant\UpdateWebhookRequest;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Entitlements\EntitlementService;
use App\Services\Webhooks\WebhookEndpointPresenter;
use App\Services\Webhooks\WebhookEndpointService;
use App\Services\Webhooks\WebhookTester;
use App\Support\Navigation\CrumbTrail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The webhook management UI (Increment H14) — the session-authed Inertia surface over the H13a/H13b engine.
 * A thin adapter: `index`/`show` render pages from {@see WebhookEndpointPresenter}; every mutation delegates to
 * {@see WebhookEndpointService} (the same service the /api/v1 controllers call) and returns a redirect + flash
 * so a domain exception on a web route stays a redirect, never JSON. Authorization is the route's `can:` policy
 * gate + `feature:webhooks`; there is NO `ability:` here (that is Sanctum token-scope middleware — a session
 * request carries no token; the `can:` gate is the authorization on this surface, per routes/tenant.php).
 *
 * The plaintext signing secret is surfaced exactly once, through a one-shot session flash (`newSecret`) the
 * shared prop bridge exposes for a single request — the destination page reveals + copies it, then it is gone.
 * `test` is a normal Inertia visit that flashes the synchronous ping result (`testResult`); the tester never
 * throws, so there is no error-render to trap.
 */
final class WebhookController extends Controller
{
    use ReadsKeywordFilter;

    public function __construct(private readonly WebhookEndpointService $service) {}

    /** The endpoints list, plan cap/quota summary, and create-modal option catalogs. */
    public function index(Request $request, WebhookEndpointPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('webhooks/Index', $presenter->index($user, $this->keyword($request)));
    }

    /** One endpoint's detail + its paginated delivery log + the edit-modal catalogs. */
    public function show(
        Request $request,
        WebhookEndpoint $webhookEndpoint,
        WebhookEndpointPresenter $presenter,
        EntitlementService $entitlements,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('webhooks/Show', [
            ...$presenter->show($user, $webhookEndpoint),
            'crumbs' => CrumbTrail::webhooks($user, $entitlements)->current($webhookEndpoint->name),
        ]);
    }

    /** Create an endpoint, then land on its Show page with the plaintext secret flashed for a one-time reveal. */
    public function store(StoreWebhookRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $endpoint = $this->service->create($request->validated(), $user);

        return to_route('webhooks.show', $endpoint)
            ->with('toast', ['type' => 'success', 'message' => 'Webhook endpoint created.'])
            ->with('newSecret', $this->secretPayload($endpoint));
    }

    /** Partial update, including the pause/re-enable status toggle (re-enable resets the breaker in the service). */
    public function update(UpdateWebhookRequest $request, WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        $this->service->update($webhookEndpoint, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Webhook endpoint updated.']);
    }

    /** Soft-delete an endpoint and return to the list. */
    public function destroy(WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        $this->service->delete($webhookEndpoint);

        return to_route('webhooks.index')
            ->with('toast', ['type' => 'success', 'message' => 'Webhook endpoint deleted.']);
    }

    /** Send a synthetic test.ping and flash the synchronous result for the Show page's result modal. */
    public function test(WebhookEndpoint $webhookEndpoint, WebhookTester $tester): RedirectResponse
    {
        $result = $tester->send($webhookEndpoint);

        return back()
            ->with('toast', $result['delivered']
                ? ['type' => 'success', 'message' => 'Test ping delivered.']
                : ['type' => 'error', 'message' => 'Test ping failed — see details.'])
            ->with('testResult', $result);
    }

    /** Rotate the signing secret; the new plaintext is flashed once, the old stays valid for the grace window. */
    public function rotateSecret(WebhookEndpoint $webhookEndpoint): RedirectResponse
    {
        $endpoint = $this->service->rotateSecret($webhookEndpoint);

        return back()
            ->with('toast', ['type' => 'success', 'message' => 'Signing secret rotated.'])
            ->with('newSecret', $this->secretPayload($endpoint));
    }

    /**
     * Re-enter the delivery pipeline for one delivery. Mirrors the /api/v1 guard, but a non-active endpoint
     * returns a toast (a 409 on a web route would render an error page) — the UI also pre-checks the status.
     */
    public function redeliver(WebhookEndpoint $webhookEndpoint, WebhookDelivery $webhookDelivery): RedirectResponse
    {
        abort_unless($webhookDelivery->webhook_endpoint_id === $webhookEndpoint->getKey(), 404);

        if ($webhookEndpoint->status !== WebhookEndpointStatus::Active) {
            return back()->with('toast', ['type' => 'error', 'message' => 'Re-enable the endpoint before redelivering.']);
        }

        $this->service->redeliver($webhookDelivery);

        return back()->with('toast', ['type' => 'success', 'message' => 'Delivery re-queued.']);
    }

    /**
     * The one-shot plaintext-secret flash payload (create + rotate). The plaintext transits the session for a
     * single request to the same authenticated user — never a durable prop, never re-served.
     *
     * @return array{id: string, name: string, secret: string}
     */
    private function secretPayload(WebhookEndpoint $endpoint): array
    {
        return [
            'id' => (string) $endpoint->id,
            'name' => $endpoint->name,
            'secret' => $endpoint->secret,
        ];
    }
}
