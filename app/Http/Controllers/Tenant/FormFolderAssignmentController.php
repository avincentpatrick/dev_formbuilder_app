<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\AssignFormFolderRequest;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use Illuminate\Http\RedirectResponse;

/**
 * File a form into a folder, or unfile it (M131, `R-9e634897`). Gated `can:update,form`: filing is an edit of
 * the form, so its editor files it. The write is {@see FormService::assignFolder()}, never mass assignment.
 */
final class FormFolderAssignmentController extends Controller
{
    public function __construct(private readonly FormService $forms) {}

    public function update(AssignFormFolderRequest $request, Form $form): RedirectResponse
    {
        $folderId = $request->validated('folder_id');

        /** @var User $user */
        $user = $request->user();
        $this->forms->assignFolder($form, $folderId === null ? null : (string) $folderId, $user);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $folderId === null ? 'Form moved to Unfiled.' : 'Form moved.',
        ]);
    }
}
