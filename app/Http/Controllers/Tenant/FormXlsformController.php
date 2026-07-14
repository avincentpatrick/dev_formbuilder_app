<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Xlsform\XlsformExporter;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * XLSForm import/export for the Inertia builder (Increment G7). Session-authenticated, subdomain-scoped —
 * the builder cannot consume the bearer-token /api/v1 pair, so this is the browser-facing surface (a toolbar
 * download; the import upload lands in G7b). Both halves reuse the same services as the API controller.
 *
 * `export` streams a specific version (draft or published — {@see FormVersion} is scope-bound to {form}) as
 * an `.xlsx` workbook; gated `can:view,form`, mirroring the submissions-export download precedent.
 */
final class FormXlsformController extends Controller
{
    public function export(Form $form, FormVersion $version, XlsformExporter $exporter): StreamedResponse
    {
        return $exporter->stream($form, $version);
    }
}
