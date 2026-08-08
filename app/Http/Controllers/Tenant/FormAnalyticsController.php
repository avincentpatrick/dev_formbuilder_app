<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\User;
use App\Services\Analytics\FormAnalyticsPresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Per-form response statistics (Increment I10c) — `docs/PRD.md:198`'s Form Owner/Editor view.
 *
 * A thin adapter, the same shape as {@see DashboardController}: every decision about WHAT IS COMPUTED lives in
 * {@see FormAnalyticsPresenter}, whose docblock records the five mechanisms that keep an UNGATED Phase-1
 * surface from drifting into a second `/analytics`.
 *
 * WHO MAY SEE IT is decided here and in the route, not there — worth stating because the presenter runs no
 * gate of its own. Its aggregates defend themselves (`AnalyticsFormSet::resolve()` intersects with the
 * caller's visible set), but `form.title` is returned verbatim for whatever User it is handed, so the route's
 * `can:view,form` is the ONLY thing between a form's name and someone who may not read it. Calling the
 * presenter from anywhere else means re-establishing that check first.
 *
 * ⚠️ `Request`, NOT a FormRequest, AND THAT IS ONE OF THE FIVE. Nothing here is read from the request, so
 * there is no input for a later change to widen: the axis, the granularity, the timezone and the window are
 * literals in the presenter. Typing this parameter as an `AnalyticsFilterRequest` would quietly hand the
 * page every knob `feature:advanced_analytics` exists to sell.
 */
final class FormAnalyticsController extends Controller
{
    public function __invoke(Request $request, Form $form, FormAnalyticsPresenter $presenter): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('forms/Analytics', $presenter->show($form, $user));
    }
}
