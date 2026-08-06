<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\UpdateFormShareRequest;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * The share surface's write (Increment I1, PRD Feature #3) — a form's public link name and its guest-access
 * toggle, mirroring {@see FormSaveResumeController} down to the toast.
 *
 * No `feature:` gate on the route, unlike save-and-resume: guest forms are not plan-gated anywhere in the
 * entitlement catalog today, and adding a gate here would be inventing a pricing decision rather than
 * enforcing one. The tier that governs REACH — a custom domain on the public link — is gated on the domains
 * surface instead, which is where it was decided.
 *
 * The toast distinguishes the two outcomes the author cares about, because they are not the same event: a
 * form that is now collecting is worth a different sentence from one that has merely been renamed.
 */
final class FormShareController extends Controller
{
    public function __construct(private readonly FormService $forms) {}

    public function update(UpdateFormShareRequest $request, Form $form): RedirectResponse
    {
        $wasOpen = $form->allow_guest_submissions;

        /** @var ?string $slug */
        $slug = $request->input('public_slug');
        $allowGuests = $request->boolean('allow_guest_submissions');

        /** @var ?User $actor */
        $actor = Auth::user();

        $this->forms->setShareSettings($form, $slug, $allowGuests, $actor);

        return back()->with('toast', [
            'type' => 'success',
            'message' => $this->message($wasOpen, $allowGuests),
        ]);
    }

    private function message(bool $wasOpen, bool $isOpen): string
    {
        if ($isOpen && ! $wasOpen) {
            return 'This form is now accepting responses from its public link.';
        }

        if (! $isOpen && $wasOpen) {
            return 'Public responses are now closed. The link no longer opens this form.';
        }

        return 'Sharing settings saved.';
    }
}
