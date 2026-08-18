<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Concerns\ReadsKeywordFilter;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\FormMetadataRequest;
use App\Models\Form;
use App\Models\ScopeNode;
use App\Models\User;
use App\Services\Forms\FormPresenter;
use App\Services\Forms\FormService;
use App\Services\Scoping\ScopeNodePresenter;
use App\Support\Forms\FormListFacets;
use App\Support\Search\ListEmptyReason;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Form CRUD + lifecycle (Increment D3). Authorization is the `can:` route middleware (FormPolicy
 * `viewAny`/`create`/`update`/`delete`); this controller stays thin and delegates to {@see FormService}.
 * Publishing and restoring live in {@see FormPublishController}. The interactive builder is Increment D4.
 */
final class FormController extends Controller
{
    use ReadsKeywordFilter;
    use ResolvesTenant;

    public function __construct(private readonly FormService $forms) {}

    public function index(Request $request, FormPresenter $presenter, ScopeNodePresenter $scopes): Response
    {
        /** @var User $user */
        $user = $request->user();

        $terms = $this->keyword($request);
        $facet = FormListFacets::parse($request->query('state'));
        $rows = $presenter->list($user, $terms);

        // Counted BEFORE the facet narrows the set, so every chip keeps showing its own total while one
        // of them is active — a chip that reported 0 for the thing you are not looking at would make the
        // bar unusable as a way back.
        $facets = FormListFacets::counts($rows);
        $forms = FormListFacets::apply($rows, $facet);

        return Inertia::render('forms/Index', [
            'forms' => $forms,
            // The scope picker's options (G10b2). Empty unless the viewer holds `scopes.manage` — assigning
            // a form to a node is a grant-equivalent act, so both the control and its data are gated on the
            // same permission the route stacks on top of can:update,form.
            'scopes' => $user->can('viewAny', ScopeNode::class) ? $scopes->pickerOptions() : [],
            // ⚠️ THE CLAMPED STRING, NOT THE REQUEST'S. `SearchTerms::raw()` is what the server actually
            // acted on, so a 300-character paste re-renders as the 200 that ran; echoing the input back
            // would put a box on screen disagreeing with the list beneath it (J1e).
            'filters' => ['applied' => ['q' => $terms->raw(), 'state' => $facet], 'facets' => $facets],
            // Presentational, not a filter, so it sits outside `filters` — it changes how the same rows are
            // drawn and nothing about which rows they are. In the URL rather than in a stored preference
            // (user decision, JR3): it is SSR-safe with no hydration guard, shareable, and it reuses this
            // page's own `router.get` round trip instead of introducing the app's first localStorage.
            'view' => $request->query('view') === 'table' ? 'table' : 'grid',
            // ⚠️ WITHOUT THIS PROP THIS PAGE LIES, AND IT IS THE REASON `empty_reason` REACHED THE THREE
            // FILTER-LESS LISTS AT ALL. `forms/Index.vue`'s `#empty` slot was an unconditional "Create your
            // first form" — so the first `?q` matching nothing would have told a tenant with two hundred
            // forms that it had none, and offered to make one.
            //
            // ⚠️ AND THE SECOND ARGUMENT MUST NAME EVERY FILTER, WHICH IS WHY THE FACET IS IN IT (JR3). It
            // was `! $terms->isEmpty()` alone; shipping the facet chips without widening it would have
            // reproduced that exact defect one filter over — a tenant clicking "Draft" with no drafts
            // would be told it had never made a form, and offered to make its first.
            'empty_reason' => ListEmptyReason::for($forms !== [], ! $terms->isEmpty() || $facet !== null),
        ]);
    }

    /**
     * Create a blank form and open it in the builder.
     *
     * ⚠️ **THE REDIRECT IS THE POINT, AND IT CHANGED IN J5c (user decision 2026-08-17).** This returned
     * `back()` — you named a form and landed exactly where you started, holding a toast. Its sibling
     * {@see FormTemplateController::instantiate()} has always redirected into the builder, so the product's
     * two ways of making a form ended in two different places. Onboarding plan §2 offers them as *two
     * equally-weighted choices*, and no amount of card layout makes them equal while one of them stops
     * short of the product.
     *
     * ⚠️ **`forms.builder` CARRIES `can:update,form`, AND THIS IS SAFE BY CONSTRUCTION RATHER THAN BY
     * INSPECTION.** {@see FormService::create()} writes the creator an explicit **Editor** `ResourceGrant`
     * in the same transaction, precisely so `forms.edit.own` resolves for them — so anyone who could reach
     * this method at all (`can:create,Form`) passes the builder's gate on the row they just made.
     * `FormRoutesTest` pins that chain end to end rather than trusting this paragraph.
     */
    public function store(FormMetadataRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $form = $this->forms->create(
            $this->currentTenant(),
            $user,
            (string) $request->string('title'),
            $request->input('description'),
        );

        return redirect()->route('forms.builder', $form)
            ->with('toast', ['type' => 'success', 'message' => 'Form created.']);
    }

    public function update(FormMetadataRequest $request, Form $form): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->forms->updateMetadata($form, (string) $request->string('title'), $request->input('description'), $user);

        return back()->with('toast', ['type' => 'success', 'message' => 'Form updated.']);
    }

    public function archive(Request $request, Form $form): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->forms->archive($form, $user);

        return back()->with('toast', ['type' => 'success', 'message' => 'Form archived.']);
    }
}
