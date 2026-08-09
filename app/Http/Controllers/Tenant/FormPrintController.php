<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Enums\FormVersionStatus;
use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Forms\BlankFormPrintRenderer;
use App\Services\Submissions\SubmissionPdfRequestService;
use App\Services\Xlsform\XlsformExporter;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * Downloads one published version as a printable BLANK form (Increment I12) - the paper twin of the
 * guest runtime, and the artifact the OCR chain's filled scans are produced FROM.
 *
 * ── Shaped after the XLSForm export, deliberately ───────────────────────────────────────────────
 * Same route shape ({form}/versions/{version}/..., scope-bound), same gate (`can:view,form` - a read
 * and derive of the form's own structure), same delivery (a streamed file, no Inertia). And the same
 * absence of side effects: {@see XlsformExporter} writes no audit row and
 * meters no usage, because deriving a different rendering of rows the caller may already read is not
 * a new disclosure.
 *
 * That is the whole reason this is a GET with no job, where the submission PDF is a POST that
 * enqueues one. {@see SubmissionPdfRequestService} does quota pre-flight,
 * metering and an audit row because that document is STORED (an `attachments` row against the
 * tenant's storage ceiling) and is delivered by email. This one is stored nowhere, contains no
 * respondent data at all, and is bounded by the form's own size.
 */
final class FormPrintController extends Controller
{
    public function __invoke(Form $form, FormVersion $version, BlankFormPrintRenderer $renderer): Response
    {
        // ⚠️ A DRAFT IS REFUSED, AND IT IS NOT MERELY A POLICY.
        //
        // ADR-0013 makes a published version's content immutable, and printing paper against a shape
        // that can still change is exactly the drift that immutability exists to prevent: a stack
        // printed on Monday would not match the form a Tuesday edit produced, with nothing on either
        // artifact to say so.
        //
        // It is also a hard practical guard. A draft's `schema_snapshot` is literally `[]`
        // (FormService.php:80 and PublishService.php:112 both write it), so without this the request
        // would 200 with a document containing a title, a footer and NO QUESTIONS - a failure that
        // looks like an empty form rather than a refused one.
        //
        // A SUPERSEDED version prints. That is the case that matters for the OCR chain: if a stack
        // was printed from v2 and the form is now v3, the scans must be re-read against the layout
        // they were actually printed at, which means v2 has to stay printable.
        //
        // 404 rather than 403: `abort(404)` is what the rest of this codebase returns for a bound
        // model that is real but out of bounds for the route, and a draft version's existence is not
        // a fact worth confirming through a different status code.
        abort_if($version->status === FormVersionStatus::Draft, 404);

        return new Response($renderer->render($form, $version), 200, [
            'Content-Type' => 'application/pdf',
            // Built through HeaderUtils rather than by string concatenation. The filename is derived
            // from a tenant-authored title, and even though BlankFormPrintRenderer::filename() has
            // already slugged it down to [a-z0-9-], composing a header value by hand is the habit
            // that eventually meets a value that was not slugged.
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_ATTACHMENT,
                $renderer->filename($form, $version),
            ),
        ]);
    }
}
