<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\FormAutomationAction;
use App\Exceptions\Entitlements\FeatureGateException;
use App\Http\Controllers\Concerns\ReadsKeywordFilter;
use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\FormMetadataRequest;
use App\Http\Requests\Forms\StoreFormAutomationRequest;
use App\Http\Requests\Forms\UpdateDataSharingRequest;
use App\Http\Requests\Forms\UpdateFormAutomationRequest;
use App\Http\Requests\Forms\UpdateOcrScanningRequest;
use App\Models\Form;
use App\Models\FormAutomation;
use App\Models\ScopeNode;
use App\Models\User;
use App\Services\Automations\FormAutomationPresenter;
use App\Services\Automations\FormAutomationService;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\FormPresenter;
use App\Services\Forms\FormService;
use App\Services\Scoping\ScopeNodePresenter;
use App\Support\Entitlements\FeatureAdmission;
use App\Support\Forms\FormListFacets;
use App\Support\Forms\FormListFolders;
use App\Support\Search\ListEmptyReason;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

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
        $folders = FormListFolders::load();
        $folder = $folders->parse($request->query('folder'));
        $rows = $presenter->list($user, $terms);

        // Each filter is counted with the OTHER one applied and never its own, so every chip and every folder
        // keeps showing its own total while it is the one selected — a chip that reported 0 for the thing you
        // are not looking at would make the bar unusable as a way back (JR3; the folder half is M131's).
        $facets = FormListFacets::counts(FormListFolders::apply($rows, $folder));
        $folderProp = $folders->present(FormListFacets::apply($rows, $facet), $user);
        $forms = FormListFacets::apply(FormListFolders::apply($rows, $folder), $facet);

        return Inertia::render('forms/Index', [
            'forms' => $forms,
            // The scope picker's options (G10b2). Empty unless the viewer holds `scopes.manage` — assigning
            // a form to a node is a grant-equivalent act, so both the control and its data are gated on the
            // same permission the route stacks on top of can:update,form.
            'scopes' => $user->can('viewAny', ScopeNode::class) ? $scopes->pickerOptions() : [],
            // ⚠️ THE CLAMPED STRING, NOT THE REQUEST'S. `SearchTerms::raw()` is what the server actually
            // acted on, so a 300-character paste re-renders as the 200 that ran; echoing the input back
            // would put a box on screen disagreeing with the list beneath it (J1e).
            'filters' => ['applied' => ['q' => $terms->raw(), 'state' => $facet, 'folder' => $folder], 'facets' => $facets],
            // The folder filter's options with their counts, and who may create or manage folders (M131, `D79`).
            'folders' => $folderProp,
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
            // M131 widened it again, for the folder: an empty folder says "no matches".
            'empty_reason' => ListEmptyReason::for($forms !== [], ! $terms->isEmpty() || $facet !== null || $folder !== null),
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

    /**
     * The Scanning settings section (M129): whether this form accepts scans of its printed paper.
     *
     * ⚠️ ON THIS CONTROLLER RATHER THAN ITS OWN, AND ONLY FOR A REASON OUTSIDE IT: a new controller is a new
     * `use` line in `routes/tenant.php`, which shifts every line of that file a document cites. The setting
     * still keeps its own route and its own FormRequest, which is the rule `D63` and four docblocks protect.
     */
    public function updateOcrScanning(UpdateOcrScanningRequest $request, Form $form): RedirectResponse
    {
        $enabled = $request->boolean('allow_ocr_single');

        /** @var User $user */
        $user = $request->user();
        $this->forms->setOcrScanning($form, $enabled, $user);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $enabled ? 'This form now accepts scans of its paper copies.' : 'This form no longer accepts scans.',
        ]);
    }

    /**
     * The Data sharing settings section (M133, `R-5da4a30f`): whether other forms of the workspace may use this
     * form's answers as their choices, and which questions. On this controller for {@see self::updateOcrScanning()}'s
     * `use`-line reason; its own route and FormRequest all the same.
     */
    public function updateDataSharing(UpdateDataSharingRequest $request, Form $form): RedirectResponse
    {
        $enabled = $request->boolean('enabled');

        /** @var User $user */
        $user = $request->user();
        $this->forms->setDataSharing($form, $enabled, $request->fieldKeys(), $user);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $enabled ? 'Other forms can now use answers from this form.' : 'This form no longer shares its answers.',
        ]);
    }

    public function archive(Request $request, Form $form): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->forms->archive($form, $user);

        return back()->with('toast', ['type' => 'success', 'message' => 'Form archived.']);
    }

    /**
     * Add an automation to the form (M132, `R-b7bc5149`). A web address (`D83`) also needs the plan to include webhooks — the
     * 402 every other plan gate answers with — and its new secret is in this response and nowhere else, ever.
     */
    public function storeAutomation(StoreFormAutomationRequest $request, Form $form, FormAutomationService $automations, FormAutomationPresenter $presenter, EntitlementService $entitlements): JsonResponse
    {
        $action = $request->automationAction();

        if ($action === FormAutomationAction::Webhook && ! FeatureAdmission::admits($entitlements, 'webhooks')) {
            throw FeatureGateException::forKey('webhooks');
        }

        /** @var User $user */
        $user = $request->user();
        [$automation, $secret] = $automations->create($form, $request->automationName(), $action, $request->recipientList(), $request->webhookUrl(), $user);

        return response()->json(['data' => $presenter->item($automation, $user), 'secret' => $secret], 201);
    }

    /** Change an automation's name, its on/off state, or its addresses. Its action never changes. */
    public function updateAutomation(UpdateFormAutomationRequest $request, Form $form, FormAutomation $automation, FormAutomationService $automations, FormAutomationPresenter $presenter): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $presenter->item($automations->update($automation, $request->changes(), $user), $user)]);
    }

    public function destroyAutomation(Request $request, Form $form, FormAutomation $automation, FormAutomationService $automations): HttpResponse
    {
        /** @var User $user */
        $user = $request->user();
        $automations->delete($automation, $user);

        return response()->noContent();
    }

    /** Send one signed test request to a web-address automation, now, and say what came back. */
    public function testAutomation(Form $form, FormAutomation $automation, FormAutomationService $automations): JsonResponse
    {
        return response()->json(['data' => $automations->test($automation)]);
    }
}
