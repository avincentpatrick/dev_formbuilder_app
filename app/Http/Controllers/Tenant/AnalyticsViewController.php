<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Api\V1\SavedReportViewController;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\SaveReportViewRequest;
use App\Models\SavedReportView;
use App\Models\User;
use App\Services\Analytics\SavedReportViewService;
use Illuminate\Http\RedirectResponse;

/**
 * Saved report views, written from the `/analytics` page (ADR-0011 §D8, Increment H24b2).
 *
 * Separate from {@see AnalyticsController} for cohesion rather than for the line budget: these three verbs
 * carry different policy gates (`create`/`update`/`delete` vs `viewAny`), a route-model binding the read
 * routes do not have, and one return type instead of two. The `ConnectionController` /
 * `ConnectionRuleController` split is the same shape.
 *
 * Named `AnalyticsViewController` and not `SavedReportViewController` because an
 * {@see SavedReportViewController} already exists — namespaces make the
 * collision legal, but one basename per concept keeps every `use` block unambiguous.
 *
 * Every verb returns `back()` with a flash. That is safe here rather than merely conventional: each request
 * originates from an Inertia visit on `/analytics?<filters>`, so the user lands back on the exact filter
 * state they were saving. The unique-name collision arrives as a `ValidationException` from
 * {@see SavedReportViewService}, which on this surface renders as a redirect with a field error on `name`
 * — not as the JSON 422 the API twin returns.
 */
final class AnalyticsViewController extends Controller
{
    public function __construct(private readonly SavedReportViewService $views) {}

    public function store(SaveReportViewRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->views->create(
            $user,
            $request->string('name')->toString(),
            $request->toQuery()->toArray(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Saved report created.']);
    }

    /**
     * A rename must not silently move the range — see
     * {@see SaveReportViewRequest::definitionOrNull()} for why the null is the whole point.
     */
    public function update(SaveReportViewRequest $request, SavedReportView $savedReportView): RedirectResponse
    {
        $this->views->update(
            $savedReportView,
            $request->has('name') ? $request->string('name')->toString() : null,
            $request->definitionOrNull(),
        );

        return back()->with('toast', ['type' => 'success', 'message' => 'Saved report updated.']);
    }

    public function destroy(SavedReportView $savedReportView): RedirectResponse
    {
        $this->views->delete($savedReportView);

        return back()->with('toast', ['type' => 'success', 'message' => 'Saved report deleted.']);
    }
}
