<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\AfterSubmit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\UpdateAfterSubmitRequest;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use Illuminate\Http\RedirectResponse;

/**
 * Set what a form offers a respondent after Submit (M248, `D232`). Its own route and controller, mirroring
 * {@see FormPageModeController}: a guarded {@see FormService::setAfterSubmit()} write behind `can:update,form`.
 */
final class FormAfterSubmitController extends Controller
{
    public function __construct(private readonly FormService $forms) {}

    public function update(UpdateAfterSubmitRequest $request, Form $form): RedirectResponse
    {
        $afterSubmit = $request->afterSubmit();

        /** @var User $user */
        $user = $request->user();

        $this->forms->setAfterSubmit($form, $afterSubmit, $user);

        return back()->with('toast', [
            'type' => 'success',
            'message' => match ($afterSubmit) {
                AfterSubmit::Another => 'Respondents can now submit another response.',
                AfterSubmit::Amend => 'Respondents can now change their answers until the form closes.',
                AfterSubmit::Once => 'Respondents can now send one response from each device.',
            },
        ]);
    }
}
