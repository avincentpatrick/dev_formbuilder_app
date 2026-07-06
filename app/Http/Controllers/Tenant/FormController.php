<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\FormMetadataRequest;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormPresenter;
use App\Services\Forms\FormService;
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
    use ResolvesTenant;

    public function __construct(private readonly FormService $forms) {}

    public function index(Request $request, FormPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('forms/Index', [
            'forms' => $presenter->list($user),
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
        $this->forms->updateMetadata($form, (string) $request->string('title'), $request->input('description'));

        return back()->with('toast', ['type' => 'success', 'message' => 'Form updated.']);
    }

    public function archive(Form $form): RedirectResponse
    {
        $this->forms->archive($form);

        return back()->with('toast', ['type' => 'success', 'message' => 'Form archived.']);
    }
}
