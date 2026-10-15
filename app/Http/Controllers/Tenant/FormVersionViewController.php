<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\FormVersionStatus;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Support\Navigation\CrumbTrail;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * M172 (`R-52f5ad93`, `D133`) — one published version of a form, opened READ-ONLY from the hub's Versions panel: the
 * questions exactly as that version asked them, drawn by the builder's own preview from the version's frozen snapshot.
 * The testers' request: *"there must also a way to check the older version for history"*. Until now an old version was
 * reachable only as the Print blank PDF, the XLSForm download or the API.
 *
 * The Print blank route's shape and gate (`scopeBindings`, `can:view,form`, 404 on a draft, whose snapshot is empty).
 * The runtime's input is built as the manual-entry page builds it — the snapshot without a list-backed cascade's
 * options (`R-d8c2ce84` carries showing those in a preview).
 */
final class FormVersionViewController extends Controller
{
    public function __invoke(Request $request, Form $form, FormVersion $version): Response
    {
        abort_if($version->status === FormVersionStatus::Draft, 404);

        /** @var User $user */
        $user = $request->user();

        return Inertia::render('forms/VersionView', [
            'version' => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'status' => $version->status->value,
                'published_at' => $version->published_at?->toIso8601String(),
            ],
            'schema' => [
                'form' => [
                    'id' => $form->id,
                    'title' => $version->title ?? $form->title,
                    'description' => $version->description ?? $form->description,
                    'single_page_mode' => $form->single_page_mode,
                    'default_locale' => $form->default_locale,
                    'supported_locales' => [$form->default_locale],
                ],
                'version' => [
                    'id' => $version->id,
                    'version_number' => $version->version_number,
                    'checksum' => $version->checksum,
                    'schema' => $version->schemaWithoutListOptions(),
                ],
            ],
            'hub_href' => "/forms/{$form->id}",
            'crumbs' => CrumbTrail::forms($user)->form($form)->current("Version {$version->version_number}"),
        ]);
    }
}
