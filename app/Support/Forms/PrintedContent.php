<?php

declare(strict_types=1);

namespace App\Support\Forms;

/**
 * A note's content blocks as PAPER prints them (M245, `R-c083b711`): the blank printed form and a response's PDF.
 *
 * Until M245 both printed a note's label alone, while every screen showed its blocks (`D69`), so a keyer transcribing
 * paper saw one thing and the screen another. Now the paper prints what the screen shows, in place of the label:
 *   - headings, paragraphs and callouts as text, bold and italic kept;
 *   - a link as its words followed by its address in brackets, since paper cannot be clicked;
 *   - code and a glossary word as plain text — the glossary itself is printed at the end of the document;
 *   - a list with ASCII markers, `-` or `1.`, because the PDF fonts are WinAnsi and a bullet glyph is not ASCII;
 *   - a details block OPEN: its summary as a bold line, its blocks set in below, because paper cannot be opened;
 *   - a picture as "Picture: " and its description, because the paper carries no images (`BlankFormPrintRenderer`).
 *
 * Pure: no container, no database. Content is not piped (`D69`/`D70`), so a `${key}` in it prints as written.
 *
 * ⛔ THE SCAN READER MUST KNOW EVERY STRING THIS PRINTS. `PrintedFormMatcher` treats a printed line it does not know as
 * the nearest question's answer, so {@see lines()} returns exactly the text {@see blocks()} lays out, and
 * {@see glossaryLines()} the text of the glossary page.
 */
final class PrintedContent
{
    /** The prefix a picture's description prints with. ASCII, like everything this document contributes. */
    public const PICTURE = 'Picture: ';

    /** The heading of the glossary at the end of the paper form and the PDF. */
    public const GLOSSARY_TITLE = 'Glossary';

    /**
     * The view model of a note's content: what each block prints as. Read defensively, like every reader of a
     * snapshot: a block or a span outside the shape is skipped, never thrown on.
     *
     * @return list<array<string, mixed>>
     */
    public static function blocks(mixed $content): array
    {
        return self::blockList($content, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function blockList(mixed $content, bool $topLevel): array
    {
        if (! is_array($content) || ! array_is_list($content)) {
            return [];
        }

        $out = [];
        foreach ($content as $block) {
            if (! is_array($block)) {
                continue;
            }
            $printed = match ($block['type'] ?? null) {
                'heading' => self::heading($block),
                'paragraph', 'callout' => self::paragraph($block),
                'list' => self::list($block),
                'divider' => ['kind' => 'divider'],
                'image' => self::picture($block),
                'details' => $topLevel ? self::details($block) : null,
                default => null,
            };
            if ($printed !== null) {
                $out[] = $printed;
            }
        }

        return $out;
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>|null
     */
    private static function heading(array $block): ?array
    {
        $text = is_string($block['text'] ?? null) ? trim($block['text']) : '';

        return $text === '' ? null : ['kind' => 'heading', 'level' => ($block['level'] ?? 1) === 2 ? 2 : 1, 'text' => $text];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>|null
     */
    private static function paragraph(array $block): ?array
    {
        $runs = self::runs($block['spans'] ?? null);

        return $runs === [] ? null : ['kind' => ($block['type'] ?? null) === 'callout' ? 'callout' : 'paragraph', 'runs' => $runs];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>|null
     */
    private static function list(array $block): ?array
    {
        $items = [];
        foreach (is_array($block['items'] ?? null) ? $block['items'] : [] as $item) {
            $runs = is_array($item) ? self::runs($item['spans'] ?? null) : [];
            if ($runs !== []) {
                $items[] = $runs;
            }
        }

        return $items === [] ? null : ['kind' => 'list', 'ordered' => ($block['style'] ?? null) === 'numbered', 'items' => $items];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>|null
     */
    private static function picture(array $block): ?array
    {
        $alt = is_string($block['alt'] ?? null) ? trim($block['alt']) : '';

        return $alt === '' ? null : ['kind' => 'picture', 'text' => self::PICTURE.$alt];
    }

    /**
     * @param  array<array-key, mixed>  $block
     * @return array<string, mixed>|null
     */
    private static function details(array $block): ?array
    {
        $summary = is_string($block['summary'] ?? null) ? trim($block['summary']) : '';
        $inner = self::blockList($block['blocks'] ?? null, false);

        return $summary === '' && $inner === [] ? null : ['kind' => 'details', 'summary' => $summary, 'blocks' => $inner];
    }

    /**
     * A run of spans as printed runs: text with its bold and italic, a link's address after its words.
     *
     * @return list<array{text: string, bold: bool, italic: bool}>
     */
    private static function runs(mixed $spans): array
    {
        $runs = [];
        foreach (is_array($spans) ? $spans : [] as $span) {
            if (! is_array($span) || ! is_string($span['text'] ?? null) || $span['text'] === '') {
                continue;
            }
            $runs[] = ['text' => $span['text'], 'bold' => ($span['bold'] ?? false) === true, 'italic' => ($span['italic'] ?? false) === true];
            $link = $span['link'] ?? null;
            if (is_string($link) && $link !== '') {
                $runs[] = ['text' => ' ('.$link.')', 'bold' => false, 'italic' => false];
            }
        }

        return trim(implode('', array_column($runs, 'text'))) === '' ? [] : $runs;
    }

    /**
     * Every string {@see blocks()} prints, one to a line, in reading order — for the scan reader's printed-text set.
     * A list item is given with and without its marker, since a scan may read the marker on the item's line or not.
     *
     * @param  list<array<string, mixed>>  $blocks
     * @return list<string>
     */
    public static function lines(array $blocks): array
    {
        $lines = [];
        foreach ($blocks as $block) {
            switch ($block['kind'] ?? null) {
                case 'heading':
                case 'picture':
                    $lines[] = (string) $block['text'];
                    break;
                case 'paragraph':
                case 'callout':
                    $lines[] = self::text($block['runs']);
                    break;
                case 'list':
                    foreach ($block['items'] as $position => $runs) {
                        $text = self::text($runs);
                        $lines[] = $text;
                        $lines[] = ($block['ordered'] ? ($position + 1).'.' : '-').' '.$text;
                    }
                    break;
                case 'details':
                    if ($block['summary'] !== '') {
                        $lines[] = (string) $block['summary'];
                    }
                    array_push($lines, ...self::lines($block['blocks']));
                    break;
            }
        }

        return $lines;
    }

    /**
     * The glossary page's strings: its title, and each term with its meaning as printed.
     *
     * @param  list<array{term: string, definition: string}>  $entries
     * @return list<string>
     */
    public static function glossaryLines(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        return [self::GLOSSARY_TITLE, ...array_map(static fn (array $entry): string => $entry['term'].' - '.$entry['definition'], $entries)];
    }

    private static function text(mixed $runs): string
    {
        return is_array($runs) ? implode('', array_map(static fn (mixed $run): string => is_array($run) ? (string) ($run['text'] ?? '') : '', $runs)) : '';
    }
}
