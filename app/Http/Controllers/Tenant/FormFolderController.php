<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\StoreFormFolderRequest;
use App\Http\Requests\Forms\UpdateFormFolderRequest;
use App\Models\FormFolder;
use App\Models\User;
use App\Policies\FormFolderPolicy;
use App\Services\Forms\FormFolderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Create, rename and delete a forms-list folder (M131, `R-9e634897`). Authorization is the `can:` route
 * middleware over {@see FormFolderPolicy}; the writes are {@see FormFolderService}'s. Each answers
 * `back()`, so the forms list re-renders with its counts in one round trip.
 */
final class FormFolderController extends Controller
{
    public function __construct(private readonly FormFolderService $folders) {}

    public function store(StoreFormFolderRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $folder = $this->folders->create($request->folderName(), $user);

        return back()->with('toast', ['type' => 'success', 'message' => "Folder \"{$folder->name}\" created."]);
    }

    public function update(UpdateFormFolderRequest $request, FormFolder $formFolder): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $this->folders->rename($formFolder, $request->folderName(), $user);

        return back()->with('toast', ['type' => 'success', 'message' => 'Folder renamed.']);
    }

    public function destroy(Request $request, FormFolder $formFolder): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $unfiled = $this->folders->delete($formFolder, $user);

        $message = $unfiled === 0
            ? 'Folder deleted.'
            : 'Folder deleted. Its forms are now Unfiled.';

        return back()->with('toast', ['type' => 'success', 'message' => $message]);
    }
}
