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
        $forms = $presenter->list($user, $terms);

        return Inertia::render('forms/Index', [
            'forms' => $forms,
            // The scope picker's options (G10b2). Empty unless the viewer holds `scopes.manage` — assigning
            // a form to a node is a grant-equivalent act, so both the control and its data are gated on the
            // same permission the route stacks on top of can:update,form.
            'scopes' => $user->can('viewAny', ScopeNode::class) ? $scopes->pickerOptions() : [],
            // ⚠️ THE CLAMPED STRING, NOT THE REQUEST'S. `SearchTerms::raw()` is what the server actually
            // acted on, so a 300-character paste re-renders as the 200 that ran; echoing the input back
            // would put a box on screen disagreeing with the list beneath it (J1e).
            'filters' => ['applied' => ['q' => $terms->raw()]],
            // ⚠️ WITHOUT THIS PROP THIS PAGE LIES, AND IT IS THE REASON `empty_reason` REACHED THE THREE
            // FILTER-LESS LISTS AT ALL. `forms/Index.vue`'s `#empty` slot was an unconditional "Create your
            // first form" — so the first `?q` matching nothing would have told a tenant with two hundred
            // forms that it had none, and offered to make one.
            'empty_reason' => ListEmptyReason::for($forms !== [], ! $terms->isEmpty()),
        ]);
    }

    public function store(FormMetadataRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->forms->create(
            $this->currentTenant(),
            $user,
            (string) $request->string('title'),
            $request->input('description'),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Form created.']);
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
