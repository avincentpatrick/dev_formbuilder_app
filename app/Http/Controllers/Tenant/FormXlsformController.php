<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Exceptions\Forms\FormException;
use App\Exceptions\Xlsform\XlsformImportException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\XlsformImportRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Xlsform\XlsformExporter;
use App\Services\Xlsform\XlsformImporter;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * XLSForm import/export for the Inertia builder (Increment G7). Session-authenticated, subdomain-scoped —
 * the builder cannot consume the bearer-token /api/v1 pair, so this is the browser-facing surface. Both
 * halves reuse the same services as the API controller.
 *
 * `export` streams a specific version (draft or published — {@see FormVersion} is scope-bound to {form}) as
 * an `.xlsx` workbook; gated `can:view,form`. `import` (Increment G7b) destructively replaces the current
 * draft's content with an uploaded workbook; gated `can:update,form` (a write), it surfaces the outcome as a
 * toast + a warnings banner and catches the domain failures into an error toast (the publish-controller shape).
 */
final class FormXlsformController extends Controller
{
    public function export(Form $form, FormVersion $version, XlsformExporter $exporter): StreamedResponse
    {
        return $exporter->stream($form, $version);
    }

    public function import(Form $form, XlsformImportRequest $request, XlsformImporter $importer): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        try {
            $result = $importer->import($form, $request->uploadedFile(), $user);
        } catch (XlsformImportException|FormException $e) {
            return back()->with('toast', ['type' => 'error', 'message' => $e->getMessage()]);
        }

        return back()
            ->with('toast', ['type' => 'success', 'message' => "Imported {$result->fieldCount} fields into the draft."])
            ->with('xlsformWarnings', $result->warnings);
    }
}
