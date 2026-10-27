<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\FormVersionStatus;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Forms\VersionComparePresenter;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M189 (`version-diff-ui`, `D169`) — two versions of a form compared, question by question and setting by setting, opened
 * from the hub's Versions panel: Compare on a published version (against the one before it), Compare with live on the
 * draft. PRD Feature #8: *"draft vs. currently-published, or any two historical versions"*.
 *
 * View's gate (`can:view,form`). ⚠️ NOT `scopeBindings`: with two version parameters Laravel scopes `{to}` to `{from}`,
 * not to the form, and answers 500 — so both are checked here. A draft side must be the form's own draft.
 */
final class FormVersionCompareController extends Controller
{
    public function __invoke(Request $request, Form $form, FormVersion $from, FormVersion $to, VersionComparePresenter $presenter): Response
    {
        abort_unless($this->comparable($form, $from) && $this->comparable($form, $to), 404);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('forms/VersionCompare', $presenter->present($form, $from, $to, $user));
    }

    private function comparable(Form $form, FormVersion $version): bool
    {
        if ($version->form_id !== $form->id) {
            return false;
        }

        return $version->status !== FormVersionStatus::Draft || $version->id === $form->draft_version_id;
    }
}
