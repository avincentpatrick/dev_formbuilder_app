<?php

declare(strict_types=1);

namespace Tests\Feature\Ocr\Support;

use App\Services\Ocr\OcrPage;
use App\Services\Ocr\OcrSymbol;
use App\Services\Ocr\OcrWord;

/**
 * Lays out what a recognizer would read off a filled copy of a printed blank form (M128 tests; layout 2 since
 * M143, layout 3 since M144).
 *
 * It takes the SAME render model the PDF is typeset from (`BlankFormPrintPresenter::present()`) and places
 * its text where `resources/views/pdf/blank-form.blade.php` puts it: the running head with the layout word
 * and the checksum stamp, the boxed instruction banner with its worked example, each question's number and
 * label at the left margin with its key stamp at the right one, comb characters one per cell with the
 * captions under their groups, the options of a choice question SIDE BY SIDE with an X before each marked
 * label, written text in an open box, and a long-text box as tall as the presenter's line count with the
 * answer written one line per newline. Geometry is in page units (0–1 both ways), and a tilt rotates
 * everything about the page centre the way a phone held at an angle does.
 *
 * ⚠️ THIS FILE IS THE ONLY PLACE THE OCR TESTS SEE THE PAPER. A layout change that is not mirrored here leaves
 * every OCR test green while the reader reads a page that no longer exists — which is why `M143` rewrote it
 * FIRST and measured the suite red before touching the reader.
 *
 * It can hand the matcher {@see OcrPage}s directly, or emit a Cloud Vision `images:annotate` answer for the
 * whole page so the parser and the job are exercised end to end.
 */
final class PrintedPageTypesetter
{
    public const float WIDTH = 1000.0;

    public const float HEIGHT = 1414.0;

    /** The banner's first line, exactly as the template prints it. */
    public const string BANNER = 'THIS FORM IS READ BY A COMPUTER. Write in BLOCK CAPITALS. Where boxes are printed, write one letter or number in each box. Mark a choice with an X inside its box. Dates are day, month, year. If an answer needs more room, continue on another sheet and write the question number beside it.';

    private const float CHAR = 0.009;

    private const float LINE = 0.016;

    /** A comb cell's 20pt pitch, a group's 10pt gap, a 14pt choice box and the 18pt between options, on a 595pt page. */
    private const float PITCH = 0.0336;

    private const float GAP = 0.017;

    private const float BOX = 0.0235;

    private const float OPTION_GAP = 0.030;

    /** The content box: 16mm margins on a 210mm page. */
    private const float LEFT = 0.076;

    private const float RIGHT = 0.92;

    /** @var list<array{text: string, x0: float, y0: float, x1: float, y1: float, confidence: float}> */
    private array $words = [];

    private float $y = 0.03;

    /**
     * @param  array<string, mixed>  $model  `BlankFormPrintPresenter::present()` output
     * @param  array<string, string|list<string>>  $answers  key => a comb, line or ruled string (a ruled one may
     *                                                       carry newlines, one per written line), a list of comb
     *                                                       group strings, or for choices the labels marked
     * @param  array{omit_keys?: list<string>, omit_labels?: list<string>, omit_captions?: bool, omit_numbers?: bool,
     *               stamp?: string|null, layout?: int|string|null, run_together?: list<string>,
     *               confidence?: array<string, float>}  $options
     *                                                   `layout` null prints no layout word (a layout-1 sheet);
     *                                                   a string prints it garbled. `run_together` names the keys
     *                                                   whose first marked option's X is read as one word with the
     *                                                   label ("XFemale"), or `runhead` for "Layout2".
     */
    public static function fromModel(array $model, array $answers, array $options = []): self
    {
        $page = new self;
        $omitKeys = $options['omit_keys'] ?? [];
        $omitLabels = $options['omit_labels'] ?? [];
        $omitCaptions = $options['omit_captions'] ?? false;
        $omitNumbers = $options['omit_numbers'] ?? false;
        $runTogether = $options['run_together'] ?? [];
        $confidence = $options['confidence'] ?? [];
        $stamp = array_key_exists('stamp', $options) ? $options['stamp'] : ($model['schema_stamp'] ?? null);
        $layout = array_key_exists('layout', $options) ? $options['layout'] : ($model['layout'] ?? null);

        // Running head: title / vN at the left; "Layout N" then the stamp at the right.
        $page->text($model['form_title'].' / v'.$model['version_number'], self::LEFT);
        if ($layout !== null) {
            if (in_array('runhead', $runTogether, true)) {
                $page->word('Layout'.$layout, 0.72, $page->y);
            } else {
                $page->word('Layout', 0.72, $page->y);
                $page->word((string) $layout, 0.78, $page->y);
            }
        }
        if (is_string($stamp)) {
            $page->word($stamp, 0.84, $page->y);
        }
        $page->advance(2.0);

        $page->text((string) $model['form_title'], self::LEFT);
        $page->advance();
        if (is_string($model['form_description'] ?? null) && $model['form_description'] !== '') {
            $page->paragraph($model['form_description'], self::LEFT);
        }
        $page->text('Version '.$model['version_number'].'.', self::LEFT);
        $page->advance(1.6);

        // The banner: its sentence, then the worked example — six filled cells, a marked box, an empty one.
        $page->paragraph(self::BANNER, self::LEFT);
        $page->text('Example:', self::LEFT);
        $x = self::LEFT + 0.07;
        foreach (['A', 'B', 'C', '1', '2', '3'] as $char) {
            $page->word($char, $x + 0.004, $page->y);
            $x += self::PITCH;
        }
        $x += self::OPTION_GAP;
        $page->word('X', $x, $page->y);
        $x = $page->textRun('marked', $x + self::BOX + 0.008, 0.99);
        $page->textRun('not marked', $x + self::OPTION_GAP + self::BOX + 0.008, 0.99);
        $page->advance(1.6);

        foreach ($model['blocks'] as $block) {
            if (is_string($block['label'] ?? null) && $block['label'] !== '') {
                $page->text($block['label'], self::LEFT);
                $page->advance();
            }

            foreach ($block['fields'] as $row) {
                if (in_array($row['area'], ['page_break', 'omitted'], true)) {
                    continue;
                }
                if ($row['area'] === 'prose') {
                    $page->paragraph((string) $row['label'], self::LEFT);

                    continue;
                }

                $key = (string) $row['key'];
                $conf = $confidence[$key] ?? 0.98;

                // The label line: "N." then the label at the left, the key stamp at the right.
                $x = self::LEFT;
                $number = $row['number'] ?? null;
                if ($number !== null && ! $omitNumbers) {
                    $x = $page->textRun($number.'.', $x, 0.99);
                }
                if (! in_array($key, $omitLabels, true)) {
                    $page->text((string) $row['label'], $x);
                }
                if (! in_array($key, $omitKeys, true)) {
                    $page->word($key, 0.84, $page->y);
                }
                $page->advance();

                if (is_string($row['hint'] ?? null)) {
                    $page->paragraph($row['hint'], self::LEFT);
                }

                $answer = $answers[$key] ?? null;
                match ($row['area']) {
                    'comb' => $page->comb($row['comb'], $answer, $omitCaptions, $conf),
                    'choices' => $page->choices($row['options'], is_array($answer) ? $answer : [], $conf, in_array($key, $runTogether, true)),
                    'line' => $page->line(is_string($answer) ? $answer : null, $conf),
                    'ruled' => $page->ruled(is_string($answer) ? $answer : null, (int) ($row['lines'] ?? 3), $conf),
                    default => $page->advance(),
                };
                $page->advance(0.6);
            }
        }

        // The footer: what the document is, then what the reader may expect of it — the three sentences
        // the template chooses between, verbatim.
        $page->paragraph('This is a blank copy of every question in version '.$model['version_number'].'. Questions marked "(if applicable)" depend on earlier answers.', self::LEFT);
        $compatible = ($model['ocr_compatible'] ?? false) === true;
        $accepts = ($model['accepts_scans'] ?? false) === true;
        $page->paragraph(match (true) {
            $compatible && $accepts => 'Scans of this form can be read automatically.',
            $compatible => 'Scanning is switched off for this form, so responses must be keyed in.',
            default => 'Scans of this form cannot be read automatically; responses must be keyed in.',
        }, self::LEFT);

        return $page;
    }

    /** One word, its characters spread evenly across it. */
    public function word(string $text, float $x, float $y, float $confidence = 0.99): self
    {
        $width = max(1, mb_strlen($text)) * self::CHAR;
        $this->words[] = ['text' => $text, 'x0' => $x, 'y0' => $y, 'x1' => $x + $width, 'y1' => $y + self::LINE * 0.8, 'confidence' => $confidence];

        return $this;
    }

    /** Printed text: split into words at spaces, laid left to right from `$x` on the current line. */
    public function text(string $text, float $x, float $confidence = 0.99): self
    {
        $this->textRun($text, $x, $confidence);

        return $this;
    }

    /** The same as {@see text()}, returning the x where the run ended so something can follow it on the line. */
    public function textRun(string $text, float $x, float $confidence): float
    {
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $this->word($word, $x, $this->y, $confidence);
            $x += (mb_strlen($word) + 1) * self::CHAR;
        }

        return $x;
    }

    /** Printed text that wraps at the right margin, the way a long sentence does, and ends on a new line. */
    public function paragraph(string $text, float $x, float $confidence = 0.99): self
    {
        $start = $x;
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $width = (mb_strlen($word) + 1) * self::CHAR;
            if ($x > $start && $x + $width > self::RIGHT) {
                $this->advance();
                $x = $start;
            }
            $this->word($word, $x, $this->y, $confidence);
            $x += $width;
        }
        $this->advance();

        return $this;
    }

    public function advance(float $lines = 1.0): self
    {
        $this->y += self::LINE * 1.6 * $lines;

        return $this;
    }

    /** The page as the matcher receives it, optionally photographed at a tilt (radians). */
    public function page(float $tilt = 0.0): OcrPage
    {
        $words = [];
        foreach ($this->words as $word) {
            $words[] = $this->ocrWord($word, $tilt);
        }

        return new OcrPage(self::WIDTH, self::HEIGHT, $words);
    }

    /**
     * The same page as a Cloud Vision `images:annotate` answer, with PIXEL vertices (the photo shape) and
     * each word's vertices in text order, so a tilt reaches the parser as a real baseline angle.
     *
     * @return array<string, mixed>
     */
    public function visionImageAnswer(float $tilt = 0.0): array
    {
        $words = [];
        foreach ($this->words as $word) {
            $symbols = [];
            foreach ($this->symbolBoxes($word) as [$char, $x0, $y0, $x1, $y1]) {
                $symbols[] = [
                    'text' => $char,
                    'confidence' => $word['confidence'],
                    'boundingBox' => ['vertices' => $this->vertices($x0, $y0, $x1, $y1, $tilt)],
                ];
            }
            $words[] = [
                'confidence' => $word['confidence'],
                'boundingBox' => ['vertices' => $this->vertices($word['x0'], $word['y0'], $word['x1'], $word['y1'], $tilt)],
                'symbols' => $symbols,
            ];
        }

        return ['responses' => [[
            'fullTextAnnotation' => [
                'text' => implode(' ', array_column($this->words, 'text')),
                'pages' => [[
                    'width' => (int) self::WIDTH,
                    'height' => (int) self::HEIGHT,
                    'blocks' => [['paragraphs' => [['words' => $words]]]],
                ]],
            ],
        ]]];
    }

    /**
     * @param  list<array{cells: int, caption: string|null}>  $groups
     * @param  string|list<string>|null  $answer
     */
    private function comb(array $groups, mixed $answer, bool $omitCaptions, float $confidence): void
    {
        $parts = is_array($answer) ? $answer : [is_string($answer) ? $answer : ''];
        $x = self::LEFT + 0.004;
        $centers = [];

        foreach ($groups as $g => $group) {
            if ($g > 0) {
                $x += self::GAP; // the borderless spacer cell between groups
            }
            $start = $x;
            $chars = preg_split('//u', $parts[$g] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
            for ($c = 0; $c < $group['cells']; $c++) {
                $char = $chars[$c] ?? ' ';
                if ($char !== ' ') {
                    $this->words[] = ['text' => $char, 'x0' => $x + 0.008, 'y0' => $this->y, 'x1' => $x + 0.008 + self::CHAR, 'y1' => $this->y + self::LINE, 'confidence' => $confidence];
                }
                $x += self::PITCH;
            }
            $centers[] = [($start + $x) / 2, $group['caption']];
        }
        $this->advance();

        $hasCaptions = array_filter($centers, static fn (array $c): bool => $c[1] !== null) !== [];
        if ($hasCaptions && ! $omitCaptions) {
            foreach ($centers as [$center, $caption]) {
                if ($caption !== null) {
                    $this->word($caption, $center - mb_strlen($caption) * self::CHAR / 2, $this->y - self::LINE * 0.6);
                }
            }
            $this->advance(0.6);
        }
    }

    /**
     * Layout 2: the options side by side on one line, wrapping at the right margin; a marked option gets an X
     * in the box immediately before its label.
     *
     * @param  list<array{value: string, label: string}>  $options
     * @param  list<string>  $marked  labels whose box holds an X
     * @param  bool  $runTogether  read the first marked option's X and the label's first word as ONE word
     */
    private function choices(array $options, array $marked, float $confidence, bool $runTogether): void
    {
        $x = self::LEFT + 0.004;
        $joined = false;

        foreach ($options as $option) {
            $labelWidth = array_sum(array_map(static fn (string $w): float => (mb_strlen($w) + 1) * self::CHAR, preg_split('/\s+/', trim($option['label'])) ?: []));
            if ($x > self::LEFT + 0.004 && $x + self::BOX + 0.008 + $labelWidth > self::RIGHT) {
                $this->advance();
                $x = self::LEFT + 0.004;
            }

            $isMarked = in_array($option['label'], $marked, true);
            $labelX = $x + self::BOX + 0.008;

            if ($isMarked && $runTogether && ! $joined) {
                // The pen came close to the text: the recognizer reads "XFemale" as one word.
                $words = preg_split('/\s+/', trim($option['label'])) ?: [];
                $first = array_shift($words) ?? '';
                $this->words[] = ['text' => 'X'.$first, 'x0' => $x, 'y0' => $this->y, 'x1' => $labelX + (mb_strlen($first) + 1) * self::CHAR, 'y1' => $this->y + self::LINE * 0.8, 'confidence' => $confidence];
                if ($words !== []) {
                    $this->text(implode(' ', $words), $labelX + (mb_strlen($first) + 1) * self::CHAR);
                }
                $joined = true;
            } else {
                if ($isMarked) {
                    $this->word('X', $x + 0.004, $this->y, $confidence);
                }
                $this->text($option['label'], $labelX);
            }

            $x = $labelX + $labelWidth + self::OPTION_GAP;
        }

        $this->advance(1.2);
    }

    /** One open box (layout 2): the answer written on one line, read as free text. */
    private function line(?string $answer, float $confidence): void
    {
        if ($answer !== null) {
            $this->text($answer, self::LEFT + 0.004, $confidence);
        }
        $this->advance(1.6);
    }

    /**
     * The long-text box (layout 3): `$lines` tall — the presenter's count, three for a row that carries none
     * (the stylesheet's 60pt) — with the answer written from the top, one line per newline. An answer longer
     * than the box spills below it the way a pen does, still inside the question's region.
     */
    private function ruled(?string $answer, int $lines, float $confidence): void
    {
        $written = 0;
        if ($answer !== null) {
            foreach (explode("\n", $answer) as $line) {
                $this->text($line, self::LEFT + 0.004, $confidence);
                $this->advance();
                $written++;
            }
        }
        $this->advance(max(0, $lines - $written));
    }

    /**
     * @param  array{text: string, x0: float, y0: float, x1: float, y1: float, confidence: float}  $word
     * @return list<array{0: string, 1: float, 2: float, 3: float, 4: float}>
     */
    private function symbolBoxes(array $word): array
    {
        $chars = preg_split('//u', $word['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $step = ($word['x1'] - $word['x0']) / max(1, count($chars));
        $boxes = [];
        foreach ($chars as $i => $char) {
            $x0 = $word['x0'] + $i * $step;
            $boxes[] = [$char, $x0, $word['y0'], $x0 + $step, $word['y1']];
        }

        return $boxes;
    }

    /**
     * @param  array{text: string, x0: float, y0: float, x1: float, y1: float, confidence: float}  $word
     */
    private function ocrWord(array $word, float $tilt): OcrWord
    {
        $symbols = [];
        foreach ($this->symbolBoxes($word) as [$char, $x0, $y0, $x1, $y1]) {
            [$a, $b, $c, $d] = $this->rotatedBox($x0, $y0, $x1, $y1, $tilt);
            $symbols[] = new OcrSymbol($char, $word['confidence'], $a, $b, $c, $d);
        }
        [$x0, $y0, $x1, $y1] = $this->rotatedBox($word['x0'], $word['y0'], $word['x1'], $word['y1'], $tilt);

        return new OcrWord($word['text'], $word['confidence'], $x0, $y0, $x1, $y1, $tilt, $symbols);
    }

    /**
     * A box rotated by `$tilt` about the page centre in real proportions: its centre moves, its size stays —
     * the same approximation the line builder undoes.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function rotatedBox(float $x0, float $y0, float $x1, float $y1, float $tilt): array
    {
        $aspect = self::WIDTH / self::HEIGHT;
        $cx = (($x0 + $x1) / 2 - 0.5) * $aspect;
        $cy = ($y0 + $y1) / 2 - 0.5;
        $rx = $cx * cos($tilt) - $cy * sin($tilt);
        $ry = $cx * sin($tilt) + $cy * cos($tilt);
        $nx = $rx / $aspect + 0.5;
        $ny = $ry + 0.5;
        $hw = ($x1 - $x0) / 2;
        $hh = ($y1 - $y0) / 2;

        return [$nx - $hw, $ny - $hh, $nx + $hw, $ny + $hh];
    }

    /**
     * The four PIXEL corners of a rotated rectangle, top-left, top-right, bottom-right, bottom-left
     * relative to the text, which is the order Cloud Vision reports.
     *
     * @return list<array{x: int, y: int}>
     */
    private function vertices(float $x0, float $y0, float $x1, float $y1, float $tilt): array
    {
        $cx = ($x0 + $x1) / 2 * self::WIDTH;
        $cy = ($y0 + $y1) / 2 * self::HEIGHT;
        $hw = ($x1 - $x0) / 2 * self::WIDTH;
        $hh = ($y1 - $y0) / 2 * self::HEIGHT;

        // The centre itself moves with the page.
        $pcx = $cx - self::WIDTH / 2;
        $pcy = $cy - self::HEIGHT / 2;
        $mx = $pcx * cos($tilt) - $pcy * sin($tilt) + self::WIDTH / 2;
        $my = $pcx * sin($tilt) + $pcy * cos($tilt) + self::HEIGHT / 2;

        $out = [];
        foreach ([[-$hw, -$hh], [$hw, -$hh], [$hw, $hh], [-$hw, $hh]] as [$dx, $dy]) {
            $out[] = [
                'x' => (int) round($mx + $dx * cos($tilt) - $dy * sin($tilt)),
                'y' => (int) round($my + $dx * sin($tilt) + $dy * cos($tilt)),
            ];
        }

        return $out;
    }
}
