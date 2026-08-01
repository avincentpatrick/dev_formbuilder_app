<?php

declare(strict_types=1);

namespace App\Services\Submissions;

use App\Models\Submission;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Carbon;

/**
 * Turns one submission into PDF bytes (Increment H17). {@see SubmissionPdfPresenter} decides what
 * the document says; this class decides only how it is typeset and under what engine settings.
 *
 * ── The dompdf configuration is a SECURITY contract, not a preference ───────────────────────────
 * `docs/piping-output-encoding-design.md` §5 pinned `isRemoteEnabled = false` and
 * `isPhpEnabled = false` for this surface BEFORE the engine was chosen, and that turns out to
 * cover almost the whole of dompdf's published advisory history: of the 20 advisories against
 * `dompdf/dompdf`, essentially every one is an SVG parse, a remote font fetch, a remote file
 * inclusion or an image-handling path — all of them reachable only with remote loading on.
 *
 * The version floor is therefore belt AND braces. `composer.json` requires `^3.1.6` because
 * 3.1.6 is the first release above every published advisory bound (the highest is `<3.1.6`), and
 * CI runs `composer audit` full-tree with no severity floor and no `--omit`, so a regression to a
 * looser constraint is a hard merge block rather than a silent downgrade.
 *
 * Three further settings, each deliberate:
 *   - `isJavascriptEnabled = false` — dompdf can emit PDF-native JavaScript actions; a document
 *     built from respondent input must not be able to.
 *   - `chroot` is the view directory, so even if a file reference were somehow constructed it
 *     cannot escape into the application or the tenant's storage.
 *   - `isHtml5ParserEnabled = true` — the masterminds/html5 parser, which has no advisories and
 *     recovers from malformed markup predictably rather than by guessing.
 *
 * ── What this class deliberately does NOT do ────────────────────────────────────────────────────
 * No images, no signatures, no logos. `ext-gd` is absent from the app container, so raster
 * embedding is unreachable regardless of configuration, and data-URI inlining of a respondent's
 * uploaded media is its own increment with its own threat model (an attacker-supplied image
 * decoder input is a different risk from an attacker-supplied string).
 */
final class SubmissionPdfRenderer
{
    /** Matches `TenantAwareJob`'s own paper size default posture: nothing locale-specific. */
    private const PAPER = 'a4';

    public function __construct(
        private readonly SubmissionPdfPresenter $presenter,
        private readonly ViewFactory $views,
    ) {}

    /** The rendered document, ready to store. */
    public function render(Submission $submission): string
    {
        return $this->toPdf($this->html($submission));
    }

    /**
     * The HTML handed to dompdf.
     *
     * Public because the output-encoding test asserts against it directly rather than against the
     * PDF byte stream: §5's obligation is that a markup answer is ESCAPED, and proving that on the
     * HTML is a real assertion, where grepping a compressed PDF for `<script>` would pass for the
     * wrong reason (PDF text is Flate-compressed, so the string would be absent either way — a
     * vacuous green of exactly the kind H6b's mutation pass caught).
     */
    public function html(Submission $submission): string
    {
        return $this->views->make('pdf.submission', [
            'model' => $this->presenter->present($submission),
            'submittedAt' => $this->stamp($submission->submitted_at),
            'generatedAt' => Carbon::now()->toDayDateTimeString().' UTC',
        ])->render();
    }

    /**
     * The engine settings §5 pins, as a value a test can interrogate.
     *
     * Public and static so `SubmissionPdfRendererTest` can assert the four flags through dompdf's
     * own getters rather than by grepping this file for the setter names. A source-text assertion
     * would keep passing if dompdf renamed a setter or changed a default; asking the Options object
     * what it actually holds does not.
     */
    public static function dompdfOptions(): Options
    {
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setIsHtml5ParserEnabled(true);
        $options->setChroot([resource_path('views/pdf')]);
        $options->setDefaultPaperSize(self::PAPER);

        return $options;
    }

    private function toPdf(string $html): string
    {
        $dompdf = new Dompdf(self::dompdfOptions());
        $dompdf->setPaper(self::PAPER);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        // `output()` returns null only when render() was never called, which cannot happen here.
        return (string) $dompdf->output();
    }

    private function stamp(?Carbon $at): string
    {
        return $at === null ? SubmissionPdfPresenter::BLANK : $at->toDayDateTimeString().' UTC';
    }
}
