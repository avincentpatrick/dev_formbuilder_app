<?php

declare(strict_types=1);

use App\Services\Ocr\VisionDocumentParser;

/*
|--------------------------------------------------------------------------
| M128 — the Cloud Vision answer, re-stated provider-neutrally.
|--------------------------------------------------------------------------
| Two answer shapes (a photo's pixel `vertices`, a PDF's `normalizedVertices`) and one protobuf trap:
| a zero coordinate is OMITTED from the JSON, so a word touching the page edge arrives without its `x`.
*/

/** @return array<string, mixed> one word with explicit vertices */
function visionParserWord(string $text, array $vertices, float $confidence = 0.9, string $kind = 'vertices'): array
{
    $symbols = [];
    foreach (mb_str_split($text) as $char) {
        $symbols[] = ['text' => $char, 'confidence' => $confidence, 'boundingBox' => [$kind => $vertices]];
    }

    return ['confidence' => $confidence, 'boundingBox' => [$kind => $vertices], 'symbols' => $symbols];
}

it('normalises a photo\'s pixel boxes to the page and keeps each character\'s confidence', function (): void {
    $body = ['responses' => [['fullTextAnnotation' => ['pages' => [[
        'width' => 1000,
        'height' => 2000,
        'blocks' => [['paragraphs' => [['words' => [
            visionParserWord('AGE', [['x' => 100, 'y' => 200], ['x' => 300, 'y' => 200], ['x' => 300, 'y' => 240], ['x' => 100, 'y' => 240]], 0.87),
        ]]]]],
    ]]]]]];

    $pages = (new VisionDocumentParser)->pages($body);
    $word = $pages[0]->words[0];

    expect($pages)->toHaveCount(1)
        ->and($word->text)->toBe('AGE')
        ->and([$word->x0, $word->y0, $word->x1, $word->y1])->toBe([0.1, 0.1, 0.3, 0.12])
        ->and($word->confidence)->toBe(0.87)
        ->and($word->symbols)->toHaveCount(3)
        ->and($word->angle)->toBe(0.0);
});

it('reads an omitted coordinate as zero, so a word at the page edge is kept', function (): void {
    // Protobuf JSON drops a zero: the left edge arrives as {"y": 10}, with no "x" at all.
    $body = ['responses' => [['fullTextAnnotation' => ['pages' => [[
        'width' => 1000,
        'height' => 1000,
        'blocks' => [['paragraphs' => [['words' => [
            visionParserWord('EDGE', [['y' => 10], ['x' => 50, 'y' => 10], ['x' => 50, 'y' => 30], ['y' => 30]]),
        ]]]]],
    ]]]]]];

    $word = (new VisionDocumentParser)->pages($body)[0]->words[0];

    expect($word->x0)->toBe(0.0)->and($word->x1)->toBe(0.05);
});

it('reads every page of a PDF answer, with boxes already normalised, and the file\'s own page count', function (): void {
    $page = static fn (string $text): array => ['fullTextAnnotation' => ['pages' => [[
        'width' => 595,
        'height' => 842,
        'blocks' => [['paragraphs' => [['words' => [
            visionParserWord($text, [['x' => 0.1, 'y' => 0.2], ['x' => 0.3, 'y' => 0.2], ['x' => 0.3, 'y' => 0.25], ['x' => 0.1, 'y' => 0.25]], 0.9, 'normalizedVertices'),
        ]]]]],
    ]]]];
    $body = ['responses' => [['responses' => [$page('ONE'), $page('TWO')], 'totalPages' => 7]]];

    $parser = new VisionDocumentParser;
    $pages = $parser->pages($body);

    expect(array_map(static fn ($p): string => $p->words[0]->text, $pages))->toBe(['ONE', 'TWO'])
        ->and($pages[1]->words[0]->x0)->toBe(0.1)
        ->and($parser->totalPages($body))->toBe(7);
});

it('measures a tilted word\'s baseline in the page\'s real proportions', function (): void {
    // 100 px across and 10 px down on a 1000 x 1000 page: atan2(10, 100).
    $body = ['responses' => [['fullTextAnnotation' => ['pages' => [[
        'width' => 1000,
        'height' => 1000,
        'blocks' => [['paragraphs' => [['words' => [
            visionParserWord('TILT', [['x' => 100, 'y' => 100], ['x' => 200, 'y' => 110], ['x' => 198, 'y' => 130], ['x' => 98, 'y' => 120]]),
        ]]]]],
    ]]]]]];

    $word = (new VisionDocumentParser)->pages($body)[0]->words[0];

    expect(round($word->angle, 6))->toBe(round(atan2(10, 100), 6));
});

it('keeps a blank page as a page, and degrades a malformed answer to nothing read rather than an exception', function (): void {
    $parser = new VisionDocumentParser;

    $blank = $parser->pages(['responses' => [[]]]);
    $malformed = $parser->pages(['responses' => 'nonsense']);

    expect($blank)->toHaveCount(1)->and($blank[0]->words)->toBe([])
        ->and($malformed)->toHaveCount(1)->and($malformed[0]->words)->toBe([]);
});
