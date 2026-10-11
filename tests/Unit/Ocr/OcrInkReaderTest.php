<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Services\Ocr\OcrAnswerReader;
use App\Services\Ocr\OcrInkImage;
use App\Services\Ocr\OcrInkReader;
use App\Services\Ocr\OcrSymbol;
use App\Services\Ocr\OcrWord;

/*
|--------------------------------------------------------------------------
| M159 (`D112`) — reading a tick from the ink in its printed box, for the ticks Cloud Vision never returns.
|--------------------------------------------------------------------------
| Each case lays out a page the way the paper prints it — per option a box, then its label — with a tick drawn inside the
| boxes named ticked, and hands the reader the label words as the matcher would. The page's grey is paper (230), a box's thin
| printed walls are mid grey (160: a phone photo blurs them that light, and they must not count as ink), a tick is pen (30).
| The settings are `config/ocr.php`'s, copied: this file tests what the reader does with them, not what they are.
|
| ⚠️ Helpers are prefixed `ocrInk*`: Pest loads every test file into one process.
*/

const OCR_INK_SETTINGS = [
    'window_from' => 0.2, 'window_to' => 2.2, 'across' => 1.0, 'paper_quantile' => 0.9, 'dark_share' => 0.6,
    'margin' => 0.03, 'single_floor' => 0.03, 'multi_floor' => 0.02,
];

/** A page of grey rectangles on paper: [x0, y0, x1, y1, grey], none overlapping. */
function ocrInkImage(int $width, int $height, array $rects): OcrInkImage
{
    return new class($width, $height, $rects) implements OcrInkImage
    {
        /** @param  list<array{0: int, 1: int, 2: int, 3: int, 4: int}>  $rects */
        public function __construct(private readonly int $w, private readonly int $h, private readonly array $rects) {}

        public function width(): int
        {
            return $this->w;
        }

        public function height(): int
        {
            return $this->h;
        }

        public function histogram(int $x0, int $y0, int $x1, int $y1): array
        {
            [$x0, $y0, $x1, $y1] = [max(0, $x0), max(0, $y0), min($this->w, $x1), min($this->h, $y1)];
            $counts = array_fill(0, 256, 0);
            $covered = 0;
            foreach ($this->rects as [$rx0, $ry0, $rx1, $ry1, $grey]) {
                $area = max(0, min($x1, $rx1) - max($x0, $rx0)) * max(0, min($y1, $ry1) - max($y0, $ry0));
                $counts[$grey] += $area;
                $covered += $area;
            }
            $counts[230] += max(0, $x1 - $x0) * max(0, $y1 - $y0) - $covered;

            return $counts;
        }
    };
}

/**
 * A page of tick questions, one per line: per option a 28px box and then its label in 12×20px letters. `$ticked` names
 * "key:value" pairs whose box holds a tick. `$turns` 1 stores the page on its side, as Round 1's phone photos were — every
 * rectangle turned into the stored frame the reader measures in, text running up the page.
 *
 * @param  array<string, array{0: FieldType, 1: array<string, string>}>  $questions  key => [type, value => label text]
 * @param  list<string>  $ticked
 * @return array{0: array<string, array{type: FieldType, options: list<array{value: string, label: string, word: OcrWord}>}>, 1: OcrInkImage}
 */
function ocrInkPage(array $questions, array $ticked, int $turns = 0): array
{
    [$uw, $uh] = [1000, 1400];
    [$sw, $sh] = $turns === 1 ? [$uh, $uw] : [$uw, $uh];
    // An upright rectangle in the stored frame: (X, Y) upright is (Y, UW − X) on a page turned once.
    $place = static fn (int $x0, int $y0, int $x1, int $y1): array => $turns === 1 ? [$y0, $uw - $x1, $y1, $uw - $x0] : [$x0, $y0, $x1, $y1];

    $rects = [];
    $out = [];
    $y = 100;
    foreach ($questions as $key => [$type, $options]) {
        $x = 100;
        $list = [];
        foreach ($options as $value => $text) {
            foreach ([[$x, $y - 4, $x + 28, $y - 2], [$x, $y + 22, $x + 28, $y + 24], [$x, $y - 2, $x + 2, $y + 22], [$x + 26, $y - 2, $x + 28, $y + 22]] as $wall) {
                $rects[] = [...$place(...$wall), 160];
            }
            if (in_array("{$key}:{$value}", $ticked, true)) {
                $rects[] = [...$place($x + 6, $y + 2, $x + 22, $y + 18), 30];
            }

            // A tick run into the label's first letter ("☑No") sits in the box, before the label.
            $letters = [];
            $lx = $x + 36;
            if (str_starts_with($text, '☑')) {
                $letters[] = ['☑', $place($x + 8, $y, $x + 20, $y + 20)];
                $text = mb_substr($text, 1);
            }
            foreach (mb_str_split($text) as $char) {
                $letters[] = [$char, $place($lx, $y, $lx + 12, $y + 20)];
                $lx += 12;
            }
            $symbols = array_map(static fn (array $l): OcrSymbol => new OcrSymbol($l[0], 0.98, $l[1][0] / $sw, $l[1][1] / $sh, $l[1][2] / $sw, $l[1][3] / $sh), $letters);
            $word = new OcrWord(implode('', array_column($letters, 0)), 0.98, min(array_map(static fn (OcrSymbol $s): float => $s->x0, $symbols)), min(array_map(static fn (OcrSymbol $s): float => $s->y0, $symbols)), max(array_map(static fn (OcrSymbol $s): float => $s->x1, $symbols)), max(array_map(static fn (OcrSymbol $s): float => $s->y1, $symbols)), 0.0, $symbols);
            $list[] = ['value' => (string) $value, 'label' => $text, 'word' => $word];

            $x = $lx + 40;
        }
        $out[$key] = ['type' => $type, 'options' => $list];
        $y += 80;
    }

    return [$out, ocrInkImage($sw, $sh, $rects)];
}

/** Round 1's form: most boxes on a page are empty, which is what the reader judges a box against. */
function ocrInkQuestions(string $no = 'No'): array
{
    return [
        'sex' => [FieldType::SingleSelect, ['f' => 'Female', 'm' => 'Male']],
        'symptoms' => [FieldType::MultiSelect, ['fever' => 'Fever', 'cough' => 'Cough', 'fatigue' => 'Fatigue']],
        'consent' => [FieldType::YesNo, ['yes' => 'Yes', 'no' => $no]],
        'pain' => [FieldType::SingleSelect, ['none' => 'None', 'mild' => 'Mild', 'severe' => 'Severe']],
    ];
}

it('reads the ticked box of a one-answer question, a yes/no and a multi-select, and leaves an unticked one blank', function (int $turns): void {
    [$questions, $image] = ocrInkPage(ocrInkQuestions(), ['sex:m', 'symptoms:fever', 'symptoms:fatigue', 'consent:no'], $turns);

    $read = (new OcrInkReader(OCR_INK_SETTINGS))->read($questions, $image, $turns);

    expect($read['sex'])->toMatchArray(['state' => 'read', 'value' => 'm', 'text' => 'Male'])
        ->and($read['symptoms'])->toMatchArray(['state' => 'read', 'value' => ['fever', 'fatigue'], 'text' => 'Fever, Fatigue'])
        ->and($read['consent'])->toMatchArray(['state' => 'read', 'value' => false])
        ->and($read['pain'])->toMatchArray(['state' => 'blank', 'value' => null])
        // Offered for review, never accepted on the ink alone (`D112`).
        ->and($read['sex']['confidence'])->toBe(OcrAnswerReader::MARK_SEEN);
})->with(['upright' => 0, 'stored on its side, as a phone photo is' => 1]);

it('leaves a one-answer question blank when its two darkest boxes are too close to call, rather than guess', function (): void {
    [$questions, $image] = ocrInkPage(ocrInkQuestions(), ['sex:f', 'sex:m', 'symptoms:cough']);

    $read = (new OcrInkReader(OCR_INK_SETTINGS))->read($questions, $image, 0);

    expect($read['sex'])->toMatchArray(['state' => 'blank', 'value' => null])
        ->and($read['sex']['ink']['f'])->toBe($read['sex']['ink']['m'])
        ->and($read['symptoms']['value'])->toBe(['cough']);
});

it('measures from the label\'s first letter, never from a tick the recognizer ran into the word ("☑No")', function (): void {
    // Round 1's sheet 09 returned "☑No": the ☑ sits in the box, so a window measured from it would miss the box.
    [$questions, $image] = ocrInkPage(ocrInkQuestions('☑No'), ['consent:no']);

    $read = (new OcrInkReader(OCR_INK_SETTINGS))->read($questions, $image, 0);

    expect($read['consent'])->toMatchArray(['state' => 'read', 'value' => false]);
});
