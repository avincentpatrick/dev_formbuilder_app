<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Concerns\ResolvesTenant;
use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\SaveAsTemplateRequest;
use App\Models\Form;
use App\Models\FormTemplate;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\TemplateGalleryPresenter;
use App\Services\Forms\TemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Form-template gallery + instantiate + save-as (Increment G9a). Authorization is the route `can:` middleware
 * (create a form to instantiate; view a form to template it); this controller stays thin and delegates to
 * {@see TemplateService}. The onboarding gallery reads the platform (NULL-tenant) templates + the tenant's own
 * via the widened nullable-global SELECT policy.
 */
final class FormTemplateController extends Controller
{
    use ResolvesTenant;

    public function __construct(private readonly TemplateService $templates) {}

    public function index(Request $request, TemplateGalleryPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('forms/Templates', [
            'templates' => $presenter->list($user),
        ]);
    }

    public function instantiate(Request $request, FormTemplate $template): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $form = $this->templates->instantiate($template, $this->currentTenant(), $user);

        return redirect()->route('forms.builder', $form)
            ->with('toast', ['type' => 'success', 'message' => 'Form created from template.']);
    }

    public function storeFromForm(SaveAsTemplateRequest $request, Form $form): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->templates->saveAsTemplate($this->templateSource($form), $user, $request->validated());

        return back()->with('toast', ['type' => 'success', 'message' => 'Saved as a template.']);
    }

    /**
     * The version to capture: the current draft (the freshest working state the author sees in the builder),
     * falling back to the published version for a form with no draft (e.g. archived-then-restored edge).
     */
    private function templateSource(Form $form): FormVersion
    {
        $versionId = $form->draft_version_id ?? $form->current_published_version_id;

        return FormVersion::query()->whereKey($versionId)->firstOrFail();
    }
}
