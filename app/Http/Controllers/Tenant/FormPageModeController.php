<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\UpdatePageModeRequest;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use Illuminate\Http\RedirectResponse;

/**
 * Set a form's presentation mode — one page, or one section at a time (`D35`, UX §3.1, row `R-f1332829`).
 *
 * Its own route and controller rather than a field on {@see FormController::update}, mirroring
 * {@see FormScheduleController}: the write is a guarded {@see FormService::setSinglePageMode}, and the route
 * carries `can:update,form` ALONE — no `feature:` gate, for the reason {@see UpdatePageModeRequest} states
 * in full.
 *
 * ⚠️ This endpoint is what made the column reachable at all. Until this row it had no writer outside the
 * seeders, so the single-page branch of the guest runtime AND of manual encoding were both unreachable for
 * a real tenant despite being built and tested — `PublicFormPresenter` and `EncodeFormPresenter` have read
 * it all along. Named rather than `{@see}`-linked, so this docblock owes no runtime-unused import.
 */
final class FormPageModeController extends Controller
{
    public function __construct(private readonly FormService $forms) {}

    public function update(UpdatePageModeRequest $request, Form $form): RedirectResponse
    {
        $singlePage = $request->boolean('single_page_mode');

        /** @var User $user */
        $user = $request->user();

        $this->forms->setSinglePageMode($form, $singlePage, $user);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $singlePage
                ? 'This form now shows every section on one page.'
                : 'This form now shows one section at a time.',
        ]);
    }
}
