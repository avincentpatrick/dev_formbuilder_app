<?php

declare(strict_types=1);

namespace Tests\Feature\Ocr\Support;

use App\Services\Ocr\OcrPage;
use App\Services\Ocr\OcrSymbol;
use App\Services\Ocr\OcrWord;

/**
 * Lays out what a recognizer would read off a filled copy of a printed blank form (M128 tests).
 *
 * It takes the SAME render model the PDF is typeset from (`BlankFormPrintPresenter::present()`) and places
 * its text where `resources/views/pdf/blank-form.blade.php` puts it: the running head with its checksum
 * stamp, each question's label at the left margin with its key stamp at the right one, comb characters one
 * per cell with the captions under their groups, an X before a marked option's label, written text in a
 * ruled box. Geometry is in page units (0–1 both ways), and a tilt rotates everything about the page centre
 * the way a phone held at an angle does.
 *
 * It can hand the matcher {@see OcrPage}s directly, or emit a Cloud Vision `images:annotate` answer for the
 * whole page so the parser and the job are exercised end to end.
 */
final class PrintedPageTypesetter
{
    public const float WIDTH = 1000.0;

    public const float HEIGHT = 1414.0;

    private const float CHAR = 0.009;

    private const float LINE = 0.016;

    private const float PITCH = 0.022;

    /** @var list<array{text: string, x0: float, y0: float, x1: float, y1: float, confidence: float}> */
    private array $words = [];

    private float $y = 0.03;

    /**
     * @param  array<string, mixed>  $model  `BlankFormPrintPresenter::present()` output
     * @param  array<string, string|list<string>>  $answers  key => a comb or ruled string, a list of comb
     *                                                       group strings, or for choices the labels marked
     * @param  array{omit_keys?: list<string>, omit_labels?: list<string>, omit_captions?: bool,
     *               stamp?: string|null, confidence?: array<string, float>}  $options
     */
    public static function fromModel(array $model, array $answers, array $options = []): self
    {
        $page = new self;
        $omitKeys = $options['omit_keys'] ?? [];
        $omitLabels = $options['omit_labels'] ?? [];
        $omitCaptions = $options['omit_captions'] ?? false;
        $confidence = $options['confidence'] ?? [];
        $stamp = array_key_exists('stamp', $options) ? $options['stamp'] : ($model['schema_stamp'] ?? null);

        // Running head: title / vN at the left, the stamp at the right.
        $page->text($model['form_title'].' / v'.$model['version_number'], 0.06);
        if (is_string($stamp)) {
            $page->word($stamp, 0.86, $page->y);
        }
        $page->advance(2.0);

        $page->text((string) $model['form_title'], 0.06);
        $page->advance();
        $page->text('Version '.$model['version_number'].'. Please write in CAPITAL LETTERS, one character per box, and mark each choice with an X.', 0.06);
        $page->advance(1.6);

        foreach ($model['blocks'] as $block) {
            if (is_string($block['label'] ?? null) && $block['label'] !== '') {
                $page->text($block['label'], 0.06);
                $page->advance();
            }

            foreach ($block['fields'] as $row) {
                if (in_array($row['area'], ['page_break', 'omitted'], true)) {
                    continue;
                }
                if ($row['area'] === 'prose') {
                    $page->text((string) $row['label'], 0.06);
                    $page->advance();

                    continue;
                }

                $key = (string) $row['key'];
                $conf = $confidence[$key] ?? 0.98;

                if (! in_array($key, $omitLabels, true)) {
                    $page->text((string) $row['label'], 0.06);
                }
                if (! in_array($key, $omitKeys, true)) {
                    $page->word($key, 0.84, $page->y);
                }
                $page->advance();

                if (is_string($row['hint'] ?? null)) {
                    $page->text($row['hint'], 0.06);
                    $page->advance();
                }

                $answer = $answers[$key] ?? null;
                match ($row['area']) {
                    'comb' => $page->comb($row['comb'], $answer, $omitCaptions, $conf),
                    'choices' => $page->choices($row['options'], is_array($answer) ? $answer : [], $conf),
                    'ruled' => $page->ruled(is_string($answer) ? $answer : null, $conf),
                    default => $page->advance(),
                };
                $page->advance(0.6);
            }
        }

        $page->text('This is a blank copy of every question in version '.$model['version_number'].'.', 0.06);
        $page->advance();

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
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            if ($word === '') {
                continue;
            }
            $this->word($word, $x, $this->y, $confidence);
            $x += (mb_strlen($word) + 1) * self::CHAR;
        }

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
        $x = 0.07;
        $centers = [];

        foreach ($groups as $g => $group) {
            if ($g > 0) {
                $x += self::PITCH * 0.5; // the borderless spacer cell between groups
            }
            $start = $x;
            $chars = preg_split('//u', $parts[$g] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [];
            for ($c = 0; $c < $group['cells']; $c++) {
                $char = $chars[$c] ?? ' ';
                if ($char !== ' ') {
                    $this->words[] = ['text' => $char, 'x0' => $x + 0.004, 'y0' => $this->y, 'x1' => $x + 0.004 + self::CHAR, 'y1' => $this->y + self::LINE, 'confidence' => $confidence];
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
     * @param  list<array{value: string, label: string}>  $options
     * @param  list<string>  $marked  labels whose box holds an X
     */
    private function choices(array $options, array $marked, float $confidence): void
    {
        foreach ($options as $option) {
            if (in_array($option['label'], $marked, true)) {
                $this->words[] = ['text' => 'X', 'x0' => 0.066, 'y0' => $this->y, 'x1' => 0.066 + self::CHAR, 'y1' => $this->y + self::LINE * 0.8, 'confidence' => $confidence];
            }
            $this->text($option['label'], 0.09);
            $this->advance();
        }
    }

    private function ruled(?string $answer, float $confidence): void
    {
        if ($answer !== null) {
            $this->text($answer, 0.07, $confidence);
        }
        $this->advance(2.5);
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
