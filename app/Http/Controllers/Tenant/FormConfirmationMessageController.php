<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\UpdateConfirmationMessageRequest;
use App\Models\Form;
use App\Services\Forms\FormService;
use Illuminate\Http\RedirectResponse;

/**
 * Set (or clear) a form's confirmation message (Increment H6a,
 * `docs/piping-output-encoding-design.md` §6.2).
 *
 * Its own route and controller rather than a field on FormController::update, mirroring
 * {@see FormSaveResumeController}: the write is a guarded {@see FormService::setConfirmationMessage}. The
 * route stacks only `can:update,form` — confirmation copy carries no tier feature.
 *
 * A session-authed web route, not `/api/v1`, so no OpenAPI operation is generated and redocly's
 * `operation-summary` rule does not apply to it.
 */
final class FormConfirmationMessageController extends Controller
{
    public function __construct(private readonly FormService $forms) {}

    public function update(UpdateConfirmationMessageRequest $request, Form $form): RedirectResponse
    {
        /** @var array{confirmation_message?: ?string, confirmation_message_translations?: ?array<string, string>} $data */
        $data = $request->validated();

        $message = $data['confirmation_message'] ?? null;
        $message = $message !== null && trim($message) === '' ? null : $message;

        $this->forms->setConfirmationMessage($form, $message, $data['confirmation_message_translations'] ?? null);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $message === null
                ? 'Confirmation message reset to the default.'
                : 'Confirmation message saved.',
        ]);
    }
}
