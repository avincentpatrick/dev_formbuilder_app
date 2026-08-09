<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Submissions\SubmissionPdfRenderer;
use App\Support\Branding\BrandPalette;
use Dompdf\Dompdf;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Support\Str;

/**
 * Turns one published version into printable blank-form PDF bytes (Increment I12).
 * {@see BlankFormPrintPresenter} decides what the paper asks; this class decides only how it is
 * typeset and under what engine settings.
 *
 * ── THE ENGINE SETTINGS ARE REUSED, NOT RESTATED ────────────────────────────────────────────────
 * {@see SubmissionPdfRenderer::dompdfOptions()} is the single definition of this application's
 * dompdf security posture — `isRemoteEnabled`, `isPhpEnabled` and `isJavascriptEnabled` all false,
 * `chroot` pinned to the PDF view directory, the html5 parser on. That method is `public static`
 * precisely so it can be asserted through dompdf's own getters rather than by grepping for setter
 * names, and `SubmissionPdfRendererTest` already does exactly that.
 *
 * Declaring a second Options object here would FORK a security contract: the two would agree on the
 * day this file was written and would drift the first time one of them was tightened. The chroot
 * needs no widening either — this increment's templates live in the same `resources/views/pdf`
 * directory, which is also why they inherit that test's scan for raw-output Blade directives.
 *
 * ── WHAT THIS DOCUMENT DELIBERATELY DOES NOT DO ─────────────────────────────────────────────────
 * No images, no barcode, no QR. `ext-gd` is absent from the app container AND from all four CI jobs
 * (the H23a4 finding), so a raster would render on a developer's machine and throw in the pipeline.
 * The version identity that a scanning stage needs therefore travels as PRINTED TEXT — the form
 * title, `v{n}` and the first eight characters of the version checksum — repeated on every page by
 * a `position: fixed` block. That is also the only page-repeat mechanism available: dompdf's page
 * counters go through `page_text()`, which is its inline-PHP API, and `isPhpEnabled` is false.
 *
 * Tenant branding reaches the paper as COLOUR ONLY, on the same terms and for the same reasons as
 * the submission PDF (ADR-0014 §D8): dompdf implements CSS 2.1, so a `var(--mds-…)` custom property
 * is unparseable and `oklch()` likewise, and the palette is resolved to literal hexes in PHP.
 */
final class BlankFormPrintRenderer
{
    /** The submission PDF's paper size. Nothing locale-specific. */
    private const PAPER = 'a4';

    public function __construct(
        private readonly BlankFormPrintPresenter $presenter,
        private readonly ViewFactory $views,
    ) {}

    /** The rendered document, ready to stream. */
    public function render(Form $form, FormVersion $version): string
    {
        return $this->toPdf($this->html($form, $version));
    }

    /**
     * The HTML handed to dompdf.
     *
     * Public for the same reason {@see SubmissionPdfRenderer::html()} is: the escaping obligation
     * (`docs/piping-output-encoding-design.md` §5) is provable on the HTML and NOT on the byte
     * stream, because PDF text is Flate-compressed — grepping the bytes for `<script>` passes
     * whether or not the escaping works, which is a vacuous green. Everything on this page is
     * tenant-authored: the form title, its description, every section heading, every field label and
     * hint, and every option label on every choice list and grid axis.
     *
     * @param  array<string, string>|null  $brand
     */
    public function html(Form $form, FormVersion $version, ?array $brand = null): string
    {
        return $this->views->make('pdf.blank-form', [
            'model' => $this->presenter->present($form, $version),
            'brand' => $brand ?? BrandPalette::current(),
        ])->render();
    }

    /**
     * The download filename: the form's title slugged, its version, and the `.pdf` extension.
     *
     * Slugged rather than escaped, and that is a header-injection guard as much as a tidiness one —
     * a form title is tenant-authored free text and can contain a quote, a semicolon or a newline,
     * all of which are meaningful inside a `Content-Disposition` value. `Str::slug()` reduces it to
     * `[a-z0-9-]`, so there is nothing left to inject with. An empty result (a title that is
     * entirely non-latin, which slugs to '') falls back to a fixed stem rather than emitting
     * `-v2.pdf`.
     */
    public function filename(Form $form, FormVersion $version): string
    {
        $stem = Str::slug($form->title);

        return ($stem === '' ? 'blank-form' : $stem).'-v'.$version->version_number.'-blank.pdf';
    }

    private function toPdf(string $html): string
    {
        $dompdf = new Dompdf(SubmissionPdfRenderer::dompdfOptions());
        $dompdf->setPaper(self::PAPER);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->render();

        // `output()` returns null only when render() was never called, which cannot happen here.
        return (string) $dompdf->output();
    }
}
