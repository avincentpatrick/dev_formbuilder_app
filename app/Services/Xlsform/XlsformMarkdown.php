<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

use App\Rules\ContentBlocks;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Block\HtmlBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\IndentedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\ListBlock;
use League\CommonMark\Extension\CommonMark\Node\Block\ListItem;
use League\CommonMark\Extension\CommonMark\Node\Block\ThematicBreak;
use League\CommonMark\Extension\CommonMark\Node\Inline\Code;
use League\CommonMark\Extension\CommonMark\Node\Inline\Emphasis;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Image;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\CommonMark\Node\Inline\Strong;
use League\CommonMark\Node\Block\Document;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Parser\MarkdownParser;

/**
 * Markdown in an XLSForm label, both ways (M244, `R-5957b273`, `R-7e134c37`, `R-24ec82a6`).
 *
 * ⛔ ODK AND KOBO RENDER MARKDOWN IN A LABEL; THIS PRODUCT SHOWED IT LITERALLY. `# Heading` and `**bold**` reached
 * respondents as typed, and a Kobo note kept in one long cell overflowed `form_fields.label` and failed the import.
 * This product's own form of formatted text is a note's content blocks (`app/Rules/ContentBlocks.php`), so:
 *
 *   - {@see self::toBlocks()} reads a note's markdown into content blocks: `#` a level-1 heading, `##` and deeper
 *     level 2; a paragraph's bold, italic, code and links as spans; a blockquote an info callout; `---` a divider;
 *     each list item a paragraph that starts with its marker (there is no list block); each line break a new
 *     paragraph, because a paragraph's text does not keep one. It keeps to every limit the rule sets (spans merged,
 *     split at 2,000 characters, at most 50 to a paragraph), drops raw HTML tags but keeps their text, drops a
 *     picture, and keeps an unsafe link's words without the link, reporting each so the import can warn.
 *   - {@see self::plainLabel()} takes inline formatting out of a question's label, hint or choice, which stay plain
 *     text, but keeps a heading's or a list's marker, because "1. Name" and "# of children" are the author's words.
 *   - {@see self::toMarkdown()} writes content back for the export, in the markdown ODK renders, escaping only what
 *     would otherwise be read as markup, and checking each paragraph reads back to itself.
 *
 * The parser is league/commonmark's CommonMark core, walked as a syntax tree; it renders nothing to HTML.
 */
final class XlsformMarkdown
{
    private const BULLET = '• ';

    private const NESTED_BULLET = '◦ ';

    private ?MarkdownParser $parser = null;

    /** @var list<array{kind: string, detail: string}> */
    private array $issues = [];

    /**
     * A note's markdown as content blocks.
     *
     * `formatted` says whether the text holds anything a plain label would lose: more than one paragraph of plain
     * words, a line break, or any formatting at all. `issues` lists what was not carried: `html` (tags dropped),
     * `image` (with its address) and `unsafe_link` (with its address).
     *
     * @return array{blocks: list<array<string, mixed>>, formatted: bool, issues: list<array{kind: string, detail: string}>}
     */
    public function toBlocks(string $markdown): array
    {
        $this->issues = [];
        $document = $this->parse($markdown);

        $blocks = [];
        foreach ($document->children() as $child) {
            array_push($blocks, ...$this->block($child, 0));
        }

        return ['blocks' => $blocks, 'formatted' => ! $this->isPlain($document), 'issues' => $this->issues];
    }

    /**
     * A label, hint or choice with its inline formatting taken out, or the text unchanged when it has none. Headings,
     * list markers and quotes keep their marker.
     */
    public function plainLabel(string $markdown): string
    {
        $document = $this->parse($markdown);
        if (! $this->hasInlineFormatting($document)) {
            return $markdown;
        }

        $lines = [];
        foreach ($document->children() as $child) {
            $lines[] = $this->plainBlock($child);
        }

        return trim(implode("\n", $lines));
    }

    /**
     * The words of content blocks, one block to a line, for the label a note keeps beside its content.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public static function plainText(array $blocks): string
    {
        $lines = [];
        foreach ($blocks as $block) {
            $text = match ($block['type'] ?? null) {
                'heading' => (string) ($block['text'] ?? ''),
                'paragraph', 'callout' => self::spanText($block['spans'] ?? []),
                default => '',
            };
            if (trim($text) !== '') {
                $lines[] = trim($text);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * `$text` cut to at most `$limit` characters at a word boundary, ending `…` when it was cut.
     */
    public static function cut(string $text, int $limit): string
    {
        if (mb_strlen($text) <= $limit) {
            return $text;
        }

        $head = mb_substr($text, 0, $limit - 1);
        $space = mb_strrpos($head, ' ');
        if ($space !== false && $space > (int) ($limit / 2)) {
            $head = mb_substr($head, 0, $space);
        }

        return rtrim($head).'…';
    }

    /**
     * Content blocks as the markdown ODK renders in a note's label. A callout is a quote (its tone is not kept), and a
     * picture is left out, since the workbook cannot carry its file. Each paragraph is checked to read back to itself;
     * one that would not is written as plain words.
     *
     * @param  list<array<string, mixed>>  $blocks
     */
    public function toMarkdown(array $blocks): string
    {
        $out = [];
        foreach ($blocks as $block) {
            $type = $block['type'] ?? null;
            if ($type === 'heading') {
                $text = trim((string) ($block['text'] ?? ''));
                if ($text !== '') {
                    $level = ($block['level'] ?? 1) === 2 ? 2 : 1;
                    $out[] = str_repeat('#', $level).' '.str_replace('#', '\\#', $this->escape($text, false));
                }
            } elseif ($type === 'paragraph' || $type === 'callout') {
                $spans = is_array($block['spans'] ?? null) ? $this->normalize($this->edgeSpacesOutside($block['spans'])) : [];
                if ($spans === []) {
                    continue;
                }
                $line = $this->renderSpans($spans);
                if (! $this->readsBackAs($line, $spans)) {
                    $line = $this->escape(self::spanText($spans), true);
                }
                $out[] = $type === 'callout' ? '> '.$line : $line;
            } elseif ($type === 'divider') {
                $out[] = '---';
            }
        }

        return implode("\n\n", $out);
    }

    // ── Reading ──────────────────────────────────────────────────────────────────────────────────

    private function parse(string $markdown): Document
    {
        if ($this->parser === null) {
            $environment = new Environment(['html_input' => 'allow', 'allow_unsafe_links' => true]);
            $environment->addExtension(new CommonMarkCoreExtension);
            $this->parser = new MarkdownParser($environment);
        }

        return $this->parser->parse($this->odkStrong($markdown));
    }

    /**
     * `**Note: **text` read the way ODK renders it: bold. ODK's own markdown is a pattern match that takes any `**…**`
     * (or `__…__`) pair on a line, while CommonMark refuses a pair with a space just inside it and leaves the stars as
     * literal characters, which is the defect this class exists to end. Kobo authors write it so often (the NHETE review
     * copy's "Watch out: " labels among them) that the edge spaces are moved outside the pair before parsing.
     */
    private function odkStrong(string $markdown): string
    {
        return (string) preg_replace_callback(
            // The opening pair starts a line or follows a space or bracket, so a closing `**` is never paired with the next.
            '/(?:^|(?<=[\s(\[]))(\*\*|__)([ \t]*)([^*_\n]+?)([ \t]*)\1(?![*_])/um',
            static fn (array $m): string => $m[2].$m[1].$m[3].$m[1].$m[4],
            $markdown,
        );
    }

    /**
     * One markdown block as content blocks.
     *
     * @return list<array<string, mixed>>
     */
    private function block(Node $node, int $depth): array
    {
        if ($node instanceof Heading) {
            return $this->heading($node);
        }

        if ($node instanceof Paragraph) {
            return $this->paragraphs($this->runs($node), 'paragraph');
        }

        if ($node instanceof BlockQuote) {
            $blocks = [];
            foreach ($node->children() as $child) {
                foreach ($this->block($child, $depth) as $inner) {
                    if ($inner['type'] === 'paragraph') {
                        $blocks[] = ['type' => 'callout', 'tone' => 'info', 'spans' => $inner['spans']];
                    } elseif ($inner['type'] === 'heading') {
                        $blocks[] = ['type' => 'callout', 'tone' => 'info', 'spans' => [['text' => $inner['text'], 'bold' => true]]];
                    } else {
                        $blocks[] = $inner;
                    }
                }
            }

            return $blocks;
        }

        if ($node instanceof ListBlock) {
            return $this->listBlocks($node, $depth);
        }

        if ($node instanceof ThematicBreak) {
            return [['type' => 'divider']];
        }

        if ($node instanceof FencedCode || $node instanceof IndentedCode) {
            $blocks = [];
            foreach (preg_split('/\R/u', rtrim($node->getLiteral())) ?: [] as $line) {
                if (trim($line) !== '') {
                    array_push($blocks, ...$this->paragraphs([['text' => $line, 'bold' => false, 'italic' => false, 'code' => true, 'link' => null]], 'paragraph'));
                }
            }

            return $blocks;
        }

        if ($node instanceof HtmlBlock) {
            $this->issue('html', '');
            $blocks = [];
            foreach (preg_split('/\R/u', strip_tags($node->getLiteral())) ?: [] as $line) {
                if (trim($line) !== '') {
                    array_push($blocks, ...$this->paragraphs([$this->run(trim($line))], 'paragraph'));
                }
            }

            return $blocks;
        }

        return [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function heading(Heading $node): array
    {
        $text = trim($this->inlinePlain($node));
        if ($text === '') {
            return [];
        }

        if (mb_strlen($text) > ContentBlocks::MAX_HEADING_LENGTH) {
            return $this->paragraphs([['text' => $text, 'bold' => true, 'italic' => false, 'code' => false, 'link' => null]], 'paragraph');
        }

        return [['type' => 'heading', 'level' => $node->getLevel() === 1 ? 1 : 2, 'text' => $text]];
    }

    /**
     * Each list item as a paragraph starting with its marker; a nested list's items follow their parent's.
     *
     * @return list<array<string, mixed>>
     */
    private function listBlocks(ListBlock $list, int $depth): array
    {
        $data = $list->getListData();
        $ordered = $data->type === ListBlock::TYPE_ORDERED;
        $number = $data->start ?? 1;
        $delimiter = $data->delimiter === ListBlock::DELIM_PAREN ? ')' : '.';

        $blocks = [];
        foreach ($list->children() as $item) {
            if (! $item instanceof ListItem) {
                continue;
            }
            $marker = $ordered ? $number.$delimiter.' ' : ($depth === 0 ? self::BULLET : self::NESTED_BULLET);
            $number++;

            $first = true;
            foreach ($item->children() as $child) {
                if ($first && $child instanceof Paragraph) {
                    array_push($blocks, ...$this->paragraphs([$this->run($marker), ...$this->runs($child)], 'paragraph'));
                } elseif ($child instanceof ListBlock) {
                    array_push($blocks, ...$this->listBlocks($child, $depth + 1));
                } else {
                    if ($first) {
                        array_push($blocks, ...$this->paragraphs([$this->run(trim($marker))], 'paragraph'));
                    }
                    array_push($blocks, ...$this->block($child, $depth + 1));
                }
                $first = false;
            }
        }

        return $blocks;
    }

    /**
     * Runs split at each line break into paragraphs (or callouts), each within the rule's limits.
     *
     * @param  list<array{text: string, bold: bool, italic: bool, code: bool, link: ?string}|null>  $runs  null is a line break
     * @return list<array<string, mixed>>
     */
    private function paragraphs(array $runs, string $type): array
    {
        $blocks = [];
        $current = [];
        $flush = function () use (&$current, &$blocks, $type): void {
            foreach (array_chunk($this->normalize($current), ContentBlocks::MAX_SPANS) as $chunk) {
                $blocks[] = $type === 'callout' ? ['type' => 'callout', 'tone' => 'info', 'spans' => $chunk] : ['type' => 'paragraph', 'spans' => $chunk];
            }
            $current = [];
        };

        foreach ($runs as $run) {
            if ($run === null) {
                $flush();

                continue;
            }
            $current[] = $run;
        }
        $flush();

        return $blocks;
    }

    /**
     * A block's inline content as runs of text with their marks; a line break is null.
     *
     * @return list<array{text: string, bold: bool, italic: bool, code: bool, link: ?string}|null>
     */
    private function runs(Node $node, bool $bold = false, bool $italic = false, ?string $link = null): array
    {
        $runs = [];
        foreach ($node->children() as $child) {
            if ($child instanceof Text) {
                $runs[] = ['text' => $child->getLiteral(), 'bold' => $bold, 'italic' => $italic, 'code' => false, 'link' => $link];
            } elseif ($child instanceof Code) {
                $runs[] = ['text' => $child->getLiteral(), 'bold' => $bold, 'italic' => $italic, 'code' => true, 'link' => $link];
            } elseif ($child instanceof Newline) {
                $runs[] = null;
            } elseif ($child instanceof Strong) {
                array_push($runs, ...$this->runs($child, true, $italic, $link));
            } elseif ($child instanceof Emphasis) {
                array_push($runs, ...$this->runs($child, $bold, true, $link));
            } elseif ($child instanceof Link) {
                $url = trim($child->getUrl());
                $safe = $url !== '' && mb_strlen($url) <= ContentBlocks::MAX_LINK_LENGTH && ContentBlocks::linkIsSafe($url);
                if (! $safe) {
                    $this->issue('unsafe_link', $url);
                }
                array_push($runs, ...$this->runs($child, $bold, $italic, $safe ? $url : $link));
            } elseif ($child instanceof Image) {
                $this->issue('image', $child->getUrl());
            } elseif ($child instanceof HtmlInline) {
                $this->issue('html', '');
            } else {
                array_push($runs, ...$this->runs($child, $bold, $italic, $link));
            }
        }

        return $runs;
    }

    /**
     * @return array{text: string, bold: bool, italic: bool, code: bool, link: ?string}
     */
    private function run(string $text): array
    {
        return ['text' => $text, 'bold' => false, 'italic' => false, 'code' => false, 'link' => null];
    }

    /**
     * Runs as rule-shaped spans: neighbours with the same marks merged, empty text dropped, text split at the rule's
     * 2,000 characters, and only the marks that are on written. Accepts runs or stored spans.
     *
     * @param  array<int, mixed>  $runs
     * @return list<array<string, mixed>>
     */
    private function normalize(array $runs): array
    {
        /** @var list<array{text: string, bold: bool, italic: bool, code: bool, link: ?string}> $merged */
        $merged = [];
        foreach ($runs as $run) {
            if (! is_array($run)) {
                continue;
            }
            $text = str_replace(["\r\n", "\r", "\n"], ' ', (string) ($run['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            $link = is_string($run['link'] ?? null) && $run['link'] !== '' ? $run['link'] : null;
            $marks = ['bold' => ($run['bold'] ?? false) === true, 'italic' => ($run['italic'] ?? false) === true, 'code' => ($run['code'] ?? false) === true, 'link' => $link];

            $last = count($merged) - 1;
            if ($last >= 0 && $merged[$last]['bold'] === $marks['bold'] && $merged[$last]['italic'] === $marks['italic']
                && $merged[$last]['code'] === $marks['code'] && $merged[$last]['link'] === $marks['link']) {
                $merged[$last]['text'] .= $text;

                continue;
            }
            $merged[] = ['text' => $text, 'bold' => $marks['bold'], 'italic' => $marks['italic'], 'code' => $marks['code'], 'link' => $marks['link']];
        }

        $spans = [];
        $lastIndex = count($merged) - 1;
        foreach ($merged as $index => $piece) {
            // A paragraph's first and last words carry no edge spaces, as markdown would read them.
            $text = $index === 0 ? ltrim($piece['text']) : $piece['text'];
            $text = $index === $lastIndex ? rtrim($text) : $text;
            if ($text === '') {
                continue;
            }
            $length = mb_strlen($text);
            for ($offset = 0; $offset < $length; $offset += ContentBlocks::MAX_TEXT_LENGTH) {
                $span = ['text' => mb_substr($text, $offset, ContentBlocks::MAX_TEXT_LENGTH)];
                if ($piece['bold']) {
                    $span['bold'] = true;
                }
                if ($piece['italic']) {
                    $span['italic'] = true;
                }
                if ($piece['code']) {
                    $span['code'] = true;
                }
                if ($piece['link'] !== null) {
                    $span['link'] = $piece['link'];
                }
                $spans[] = $span;
            }
        }

        return $spans;
    }

    private function issue(string $kind, string $detail): void
    {
        foreach ($this->issues as $issue) {
            if ($issue['kind'] === $kind && $issue['detail'] === $detail) {
                return;
            }
        }
        $this->issues[] = ['kind' => $kind, 'detail' => $detail];
    }

    /** One paragraph of plain words, with no line break: nothing a plain label would lose. */
    private function isPlain(Document $document): bool
    {
        $children = iterator_to_array($document->children(), false);
        if (count($children) > 1) {
            return false;
        }
        if ($children === []) {
            return true;
        }
        if (! $children[0] instanceof Paragraph) {
            return false;
        }
        foreach ($children[0]->children() as $inline) {
            if (! $inline instanceof Text) {
                return false;
            }
        }

        return true;
    }

    private function hasInlineFormatting(Node $node): bool
    {
        foreach ($node->children() as $child) {
            if ($child instanceof Strong || $child instanceof Emphasis || $child instanceof Link
                || $child instanceof Code || $child instanceof HtmlInline || $child instanceof Image) {
                return true;
            }
            if ($this->hasInlineFormatting($child)) {
                return true;
            }
        }

        return false;
    }

    private function plainBlock(Node $node): string
    {
        if ($node instanceof Heading) {
            return str_repeat('#', $node->getLevel()).' '.$this->inlinePlain($node);
        }
        if ($node instanceof Paragraph) {
            return $this->inlinePlain($node);
        }
        if ($node instanceof BlockQuote) {
            $lines = [];
            foreach ($node->children() as $child) {
                $lines[] = '> '.$this->plainBlock($child);
            }

            return implode("\n", $lines);
        }
        if ($node instanceof ListBlock) {
            $data = $node->getListData();
            $number = $data->start ?? 1;
            $lines = [];
            foreach ($node->children() as $item) {
                $marker = $data->type === ListBlock::TYPE_ORDERED
                    ? $number.($data->delimiter === ListBlock::DELIM_PAREN ? ')' : '.')
                    : ($data->bulletChar ?? '-');
                $number++;
                $parts = [];
                foreach ($item->children() as $child) {
                    $parts[] = $this->plainBlock($child);
                }
                $lines[] = $marker.' '.implode("\n", $parts);
            }

            return implode("\n", $lines);
        }
        if ($node instanceof ThematicBreak) {
            return '---';
        }
        if ($node instanceof FencedCode || $node instanceof IndentedCode) {
            return rtrim($node->getLiteral());
        }
        if ($node instanceof HtmlBlock) {
            return trim(strip_tags($node->getLiteral()));
        }

        return '';
    }

    private function inlinePlain(Node $node): string
    {
        $text = '';
        foreach ($node->children() as $child) {
            if ($child instanceof Text || $child instanceof Code) {
                $text .= $child->getLiteral();
            } elseif ($child instanceof Newline) {
                $text .= "\n";
            } elseif ($child instanceof HtmlInline) {
                continue;
            } elseif ($child instanceof Link) {
                $words = $this->inlinePlain($child);
                $url = $child->getUrl();
                $text .= $url !== '' && $url !== $words ? $words.' ('.$url.')' : $words;
            } else {
                $text .= $this->inlinePlain($child);
            }
        }

        return $text;
    }

    private static function spanText(mixed $spans): string
    {
        if (! is_array($spans)) {
            return '';
        }
        $text = '';
        foreach ($spans as $span) {
            if (is_array($span) && is_string($span['text'] ?? null)) {
                $text .= $span['text'];
            }
        }

        return $text;
    }

    // ── Writing ──────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<array<string, mixed>>  $spans  normalized
     */
    private function renderSpans(array $spans): string
    {
        $line = '';
        foreach ($spans as $index => $span) {
            $text = (string) $span['text'];
            preg_match('/^(\s*)(.*?)(\s*)$/su', $text, $parts);
            [$lead, $core, $trail] = [$parts[1] ?? '', $parts[2] ?? $text, $parts[3] ?? ''];

            if ($core === '') {
                $line .= $text;

                continue;
            }

            $rendered = ($span['code'] ?? false) === true ? $this->codeSpan($core) : $this->escape($core, $index === 0 && $lead === '');
            if (($span['italic'] ?? false) === true) {
                $rendered = '*'.$rendered.'*';
            }
            if (($span['bold'] ?? false) === true) {
                $rendered = '**'.$rendered.'**';
            }
            if (is_string($span['link'] ?? null)) {
                // ODK's own markdown reads `[words](url)`; the `<url>` form CommonMark adds is kept for an address with
                // a bracket in it, which the plain form would cut short.
                $url = $span['link'];
                $rendered = '['.$rendered.']('.(strpbrk($url, '()<>') === false ? $url : '<'.str_replace(['<', '>'], ['%3C', '%3E'], $url).'>').')';
            }

            $line .= $lead.$rendered.$trail;
        }

        return $line;
    }

    /**
     * Spans with a marked span's edge spaces moved outside it, as plain text. Markdown cannot put a space just inside
     * `**…**`, and drops one inside a code span, so "a" + " *b*" is written as "a *b*" and reads back with the space
     * unmarked.
     *
     * @param  array<int, mixed>  $spans
     * @return list<array<string, mixed>>
     */
    private function edgeSpacesOutside(array $spans): array
    {
        $out = [];
        foreach ($spans as $span) {
            if (! is_array($span) || ! is_string($span['text'] ?? null)) {
                continue;
            }
            $marked = ($span['bold'] ?? false) === true || ($span['italic'] ?? false) === true
                || ($span['code'] ?? false) === true || is_string($span['link'] ?? null);
            if (! $marked || preg_match('/^(\s*)(.*?)(\s*)$/su', $span['text'], $parts) !== 1 || $parts[2] === '') {
                $out[] = $span;

                continue;
            }
            if ($parts[1] !== '') {
                $out[] = ['text' => $parts[1]];
            }
            $out[] = ['text' => $parts[2]] + $span;
            if ($parts[3] !== '') {
                $out[] = ['text' => $parts[3]];
            }
        }

        return $out;
    }

    private function codeSpan(string $text): string
    {
        preg_match_all('/`+/', $text, $runs);
        $longest = max([0, ...array_map('strlen', $runs[0])]);
        $fence = str_repeat('`', $longest + 1);
        $pad = str_starts_with($text, '`') || str_ends_with($text, '`') || (str_starts_with($text, ' ') && str_ends_with($text, ' ')) ? ' ' : '';

        return $fence.$pad.$text.$pad.$fence;
    }

    /**
     * Words with the characters markdown would read as markup escaped; at a line's start, also what would start a
     * heading, a quote, a list or a fence.
     */
    private function escape(string $text, bool $atLineStart): string
    {
        $escaped = (string) preg_replace('/([\\\\`*_\[\]<])/u', '\\\\$1', $text);
        $escaped = (string) preg_replace('/&(?=#?[A-Za-z0-9]+;)/u', '\\\\&', $escaped);

        if ($atLineStart) {
            $escaped = (string) preg_replace('/^([#>+=~|-])/u', '\\\\$1', $escaped);
            $escaped = (string) preg_replace('/^(\d{1,9})([.)])/u', '$1\\\\$2', $escaped);
        }

        return $escaped;
    }

    /**
     * @param  list<array<string, mixed>>  $spans  normalized
     */
    private function readsBackAs(string $line, array $spans): bool
    {
        $saved = $this->issues;
        $read = $this->toBlocks($line)['blocks'];
        $this->issues = $saved;

        return count($read) === 1 && ($read[0]['type'] ?? null) === 'paragraph' && ($read[0]['spans'] ?? null) === $spans;
    }
}
