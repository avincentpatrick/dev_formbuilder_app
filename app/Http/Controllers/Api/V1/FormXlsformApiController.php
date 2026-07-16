<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Xlsform\XlsformImportException;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\FormXlsformController;
use App\Http\Requests\Forms\XlsformImportRequest;
use App\Models\Form;
use App\Models\FormVersion;
use App\Models\User;
use App\Services\Xlsform\XlsformExporter;
use App\Services\Xlsform\XlsformImporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * XLSForm import/export over /api/v1 (Increment G7 / docs/xlsform-interop-spec.md; endpoints pinned by
 * docs/architecture/technical-architecture.md §7). The programmatic, bearer-token surface for the
 * Kobo/ODK/SurveyCTO migration path; a thin adapter over {@see XlsformExporter}/{@see XlsformImporter},
 * shared with the Inertia builder's {@see FormXlsformController}.
 *
 * `import` (Increment G7b) destructively replaces the form's current draft with the uploaded workbook and
 * returns the row counts + non-fatal warnings; the upfront parse failures ({@see XlsformImportException})
 * surface through the bootstrap/app.php render closure as the `{error:{code,details}}` envelope.
 */
final class FormXlsformApiController extends Controller
{
    /**
     * Export a form version as an XLSForm (`.xlsx`) workbook.
     */
    public function export(Form $form, FormVersion $version, XlsformExporter $exporter): StreamedResponse
    {
        return $exporter->stream($form, $version);
    }

    /**
     * Import an XLSForm (`.xlsx`) into the form's current draft (destructive replace).
     */
    public function import(Form $form, XlsformImportRequest $request, XlsformImporter $importer): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $result = $importer->import($form, $request->uploadedFile(), $user);

        return response()->json([
            'data' => [
                'section_count' => $result->sectionCount,
                'field_count' => $result->fieldCount,
                'validation_count' => $result->validationCount,
            ],
            'warnings' => $result->warnings,
        ]);
    }
}
