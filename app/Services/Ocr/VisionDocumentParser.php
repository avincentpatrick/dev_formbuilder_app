<?php

declare(strict_types=1);

namespace App\Services\Ocr;

/**
 * Turns a Cloud Vision `DOCUMENT_TEXT_DETECTION` answer into provider-neutral {@see OcrPage}s (M128).
 *
 * Two answer shapes reach it, and the difference is load-bearing:
 *   - `images:annotate` (a photo) — `responses[0].fullTextAnnotation`, one page, word boxes in PIXEL
 *     `vertices`;
 *   - `files:annotate` (a PDF) — `responses[0].responses[i].fullTextAnnotation`, one entry per page read,
 *     word boxes in `normalizedVertices` (0–1), plus `responses[0].totalPages`, which can exceed the pages
 *     actually read.
 *
 * ⚠️ PROTOBUF JSON OMITS A ZERO. A vertex at the left edge arrives as `{"y": 12}` with no `x` at all, so
 * every coordinate defaults to 0 rather than being required. A parser that insisted on both keys would drop
 * exactly the words that touch the page edge.
 *
 * Nothing here judges the reading. It only re-states it; the matcher decides what any of it means.
 */
final class VisionDocumentParser
{
    /**
     * Every page in an answer, in the order the provider returned them.
     *
     * @param  array<string, mixed>  $body
     * @return list<OcrPage>
     */
    public function pages(array $body): array
    {
        $first = $this->listAt($body, 'responses')[0] ?? [];

        $inner = $this->listAt($first, 'responses');
        $annotations = $inner === [] ? [$first] : $inner;

        $pages = [];
        foreach ($annotations as $annotation) {
            $text = $annotation['fullTextAnnotation'] ?? null;
            $page = is_array($text) ? ($this->listAt($text, 'pages')[0] ?? null) : null;

            // A page with nothing on it still counts as a page: a blank sheet is a legitimate scan, and
            // dropping it would renumber every page after it.
            $pages[] = $page === null ? new OcrPage(1.0, 1.0, []) : $this->page($page);
        }

        return $pages;
    }

    /**
     * How many pages the provider says the FILE has, which for a PDF can be more than it read. A photo has
     * one. Read for the "pages beyond the limit" warning rather than trusted for anything structural.
     *
     * @param  array<string, mixed>  $body
     */
    public function totalPages(array $body): int
    {
        $first = $this->listAt($body, 'responses')[0] ?? [];
        $total = $first['totalPages'] ?? null;

        return is_int($total) && $total > 0 ? $total : max(1, count($this->listAt($first, 'responses')));
    }

    /**
     * @param  array<string, mixed>  $page
     */
    private function page(array $page): OcrPage
    {
        $width = $this->number($page['width'] ?? null);
        $height = $this->number($page['height'] ?? null);
        $width = $width > 0.0 ? $width : 1.0;
        $height = $height > 0.0 ? $height : 1.0;

        $words = [];
        foreach ($this->listAt($page, 'blocks') as $block) {
            foreach ($this->listAt($block, 'paragraphs') as $paragraph) {
                foreach ($this->listAt($paragraph, 'words') as $word) {
                    $parsed = $this->word($word, $width, $height);
                    if ($parsed !== null) {
                        $words[] = $parsed;
                    }
                }
            }
        }

        return new OcrPage($width, $height, $words);
    }

    /**
     * @param  array<string, mixed>  $word
     */
    private function word(array $word, float $width, float $height): ?OcrWord
    {
        $symbols = [];
        foreach ($this->listAt($word, 'symbols') as $symbol) {
            $text = $symbol['text'] ?? null;
            if (! is_string($text) || $text === '') {
                continue;
            }
            [$x0, $y0, $x1, $y1] = $this->box($symbol, $width, $height);
            $symbols[] = new OcrSymbol($text, $this->confidence($symbol), $x0, $y0, $x1, $y1);
        }

        if ($symbols === []) {
            return null;
        }

        [$x0, $y0, $x1, $y1] = $this->box($word, $width, $height);
        $text = implode('', array_map(static fn (OcrSymbol $s): string => $s->text, $symbols));

        return new OcrWord($text, $this->confidence($word), $x0, $y0, $x1, $y1, $this->angle($word, $width, $height), $symbols);
    }

    /**
     * The normalised axis-aligned box of an element: its vertices' extremes, in 0–1 page units.
     *
     * @param  array<string, mixed>  $element
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function box(array $element, float $width, float $height): array
    {
        $points = $this->points($element, $width, $height);
        if ($points === []) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        $xs = array_column($points, 0);
        $ys = array_column($points, 1);

        return [min($xs), min($ys), max($xs), max($ys)];
    }

    /**
     * The angle of an element's top edge (its first vertex to its second), measured in the page's REAL
     * proportions. Vertices run top-left, top-right, bottom-right, bottom-left relative to the TEXT, so a
     * page photographed at a tilt reports that tilt here.
     *
     * @param  array<string, mixed>  $element
     */
    private function angle(array $element, float $width, float $height): float
    {
        $points = $this->points($element, $width, $height);
        if (count($points) < 2) {
            return 0.0;
        }

        $dx = ($points[1][0] - $points[0][0]) * $width;
        $dy = ($points[1][1] - $points[0][1]) * $height;

        return ($dx === 0.0 && $dy === 0.0) ? 0.0 : atan2($dy, $dx);
    }

    /**
     * The element's vertices as normalised `[x, y]` pairs. Pixel `vertices` are divided by the page size;
     * `normalizedVertices` are already 0–1. An omitted coordinate is 0 (see the class docblock).
     *
     * @param  array<string, mixed>  $element
     * @return list<array{0: float, 1: float}>
     */
    private function points(array $element, float $width, float $height): array
    {
        $box = $element['boundingBox'] ?? null;
        if (! is_array($box)) {
            return [];
        }

        $normalized = $this->listAt($box, 'normalizedVertices');
        if ($normalized !== []) {
            return array_map(fn (array $v): array => [$this->number($v['x'] ?? 0), $this->number($v['y'] ?? 0)], $normalized);
        }

        return array_map(
            fn (array $v): array => [$this->number($v['x'] ?? 0) / $width, $this->number($v['y'] ?? 0) / $height],
            $this->listAt($box, 'vertices'),
        );
    }

    /** @param  array<string, mixed>  $element */
    private function confidence(array $element): float
    {
        $value = $this->number($element['confidence'] ?? null);

        return max(0.0, min(1.0, $value));
    }

    private function number(mixed $value): float
    {
        return is_int($value) || is_float($value) ? (float) $value : 0.0;
    }

    /**
     * The list of arrays at `$key`, or `[]` — a malformed answer degrades to "nothing read there" rather
     * than to an exception, because a reading that cannot be parsed is reviewed by a person anyway.
     *
     * @param  array<array-key, mixed>  $source
     * @return list<array<string, mixed>>
     */
    private function listAt(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $entry) {
            if (is_array($entry)) {
                /** @var array<string, mixed> $entry */
                $out[] = $entry;
            }
        }

        return $out;
    }
}
