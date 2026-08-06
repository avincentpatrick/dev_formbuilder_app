<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\UpdateFormScheduleRequest;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Forms\FormSchedule;
use Illuminate\Http\RedirectResponse;

/**
 * Set (or clear) a form's schedule + response cap (Increment H12a).
 *
 * Its own route and controller rather than a field on FormController::update, mirroring
 * {@see FormSaveResumeController}: the write is a guarded {@see FormService::setSchedule} behind
 * `can:update,form`. Ungated (all tiers) — scheduled forms carry no plan feature. Thin by design; all schedule
 * logic lives in the service + {@see FormSchedule}.
 */
final class FormScheduleController extends Controller
{
    public function __construct(private readonly FormService $forms) {}

    public function update(UpdateFormScheduleRequest $request, Form $form): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $this->forms->setSchedule(
            $form,
            $request->opensAt(),
            $request->closesAt(),
            $request->timezone(),
            $request->maxResponses(),
            $user,
        );

        return back()->with('toast', [
            'type' => 'success',
            'message' => 'Form schedule updated.',
        ]);
    }
}
