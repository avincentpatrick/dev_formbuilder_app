<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\UpdateFormThemeRequest;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use Illuminate\Http\RedirectResponse;

/**
 * Choose a form's preset theme, or go back to the workspace brand (M131, `R-6017d6d8`) — the Theme section of
 * the form settings. Gated `can:update,form` alone (`D81`); the write is `FormService::setTheme()`.
 */
final class FormThemeController extends Controller
{
    public function __construct(private readonly FormService $forms) {}

    public function update(UpdateFormThemeRequest $request, Form $form): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $preset = $request->preset();
        $this->forms->setTheme($form, $preset, $user);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $preset === null ? 'Theme set back to your workspace brand.' : "Theme set to {$preset->label()}.",
        ]);
    }
}
