<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Tenant\FormXlsformController;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Xlsform\XlsformExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * XLSForm export over /api/v1 (Increment G7 / docs/xlsform-interop-spec.md; endpoint pinned by
 * docs/architecture/technical-architecture.md §7.3). The programmatic, bearer-token surface for the
 * Kobo/ODK/SurveyCTO migration path; the import counterpart (`POST .../draft/xlsform-import`) lands in G7b.
 * A thin adapter over {@see XlsformExporter}, shared with the Inertia builder's {@see FormXlsformController}.
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
}
