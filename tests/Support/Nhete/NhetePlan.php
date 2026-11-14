<?php

declare(strict_types=1);

namespace Tests\Support\Nhete;

use JsonException;
use RuntimeException;

/**
 * The NHETE pre-work module (`M237`, `nhete-stress-test`) expanded into the one ordered plan every route builds from:
 * the builder's own HTTP routes, the XLSForm workbooks, the template and the browser walk.
 *
 * The fixture is `tests/fixtures/nhete/nhete-shape.json`, a SAME-SHAPE copy (`D221`): every screen, question, choice,
 * condition, count and text length of the review copy, with its wording replaced by placeholder letters. The review
 * copy's own words are never committed.
 *
 * ── WHAT "TODAY'S LAYOUT" MEANS ──────────────────────────────────────────────────────────────────────────────
 * The module asks for things the builder cannot draw yet, so the plan writes each as the nearest thing it CAN, and tags
 * the element with the workaround it stands in for (`workaround`), so a report can count them:
 *   - an agreement grid with a per-row comment → one choice question + one conditional comment per row (`per-row-grid`);
 *   - an indicator table row → a note (the row's details) + up to four dropdowns + a comment (`indicator-block`);
 *   - a parts table → a block of questions per part, shown by conditions (`parts-block`);
 *   - a tile → a level-2 heading + a paragraph (`tile`); a bullet list → paragraphs starting "• " (`bullets`);
 *   - "Research behind this" → always-open paragraphs with links (`research-open`);
 *   - a glossary word `[[term]]` → the bare word (`glossary-plain`), counted, never lost;
 *   - a word cap → a whole-answer pattern rule (`word-pattern`); "up to N" and "only answer" → expression rules;
 *   - a changing placeholder → a hidden calculation piped into the placeholder (`piped-placeholder`);
 *   - a note whose text pipes an answer → a label-only note, because note content blocks are not piped (`piped-note`).
 */
final class NhetePlan
{
    public const FIXTURE = 'tests/fixtures/nhete/nhete-shape.json';

    public const FOUR_REGIONS = ['ncr', 'mimaropa', 'car', 'barmm'];

    public const SMALL_AREAS = ['household', 'purok', 'barangay', 'city'];

    public const WORD_CAP = 1500;

    /** @var array<string, mixed> */
    private array $fixture;

    /** @var array<string, int> how many elements stand in for each workaround */
    private array $workarounds = [];

    /** @param  array<string, mixed>|null  $fixture  the decoded fixture; null reads {@see self::FIXTURE} */
    public function __construct(?array $fixture = null)
    {
        $this->fixture = $fixture ?? self::read(base_path(self::FIXTURE));
    }

    /** @return array<string, mixed> */
    public static function read(string $path): array
    {
        $raw = file_get_contents($path);
        if ($raw === false) {
            throw new RuntimeException("NHETE fixture not readable: {$path}");
        }

        try {
            $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("NHETE fixture is not JSON: {$e->getMessage()}", 0, $e);
        }

        return is_array($data) ? $data : throw new RuntimeException('NHETE fixture is not an object');
    }

    /** @return array<string, mixed> */
    public function fixture(): array
    {
        return $this->fixture;
    }

    public function title(): string
    {
        return (string) $this->fixture['title'];
    }

    public function confirmation(): string
    {
        return (string) $this->fixture['confirmation'];
    }

    /** @return array<string, int> */
    public function workarounds(): array
    {
        return $this->workarounds;
    }

    /** @return list<array<string, mixed>> the 35 indicators, in module order */
    public function indicators(): array
    {
        return array_values($this->fixture['indicators']);
    }

    /**
     * The form as today's builder can hold it: one section per screen, each with its ordered elements.
     *
     * @return list<array{key: string, label: string, stop: string, relevant: ?string, elements: list<array<string, mixed>>}>
     */
    public function sections(): array
    {
        $this->workarounds = [];
        $sections = [];
        foreach ($this->fixture['screens'] as $screen) {
            if (isset($screen['parts'])) {
                $sections[] = $this->partsSection($screen, (string) $screen['parts'], count($sections));

                continue;
            }

            $elements = [];
            foreach ($screen['items'] as $item) {
                array_push($elements, ...$this->expand($item));
            }
            $sections[] = [
                'key' => (string) $screen['key'],
                'label' => (string) $screen['label'],
                'stop' => (string) $screen['stop'],
                'relevant' => $screen['show_if'] ?? null,
                'elements' => $elements,
            ];
        }

        return $sections;
    }

    /** @return list<array<string, mixed>> every element of every section, in order */
    public function elements(): array
    {
        return array_merge(...array_map(static fn (array $s): array => $s['elements'], $this->sections()));
    }

    /** @return list<array<string, mixed>> */
    private function expand(array $item): array
    {
        if (isset($item['note'])) {
            return [$this->note((string) $item['note'], $item['blocks'], $item['show_if'] ?? null)];
        }
        if (isset($item['q'])) {
            return $this->question($item);
        }
        if (isset($item['grid'])) {
            return $this->perRowGrid($item);
        }
        if (isset($item['table'])) {
            return $this->indicatorTable((string) $item['table']);
        }

        throw new RuntimeException('Unknown NHETE item: '.json_encode($item));
    }

    // ── Notes ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<array<int, mixed>>  $blocks
     * @return array<string, mixed>
     */
    private function note(string $key, array $blocks, ?string $relevant, string $role = 'note'): array
    {
        $flat = implode(' ', array_map(static fn (array $b): string => is_string($b[1] ?? null) ? $b[1] : '', $blocks));
        if (str_contains($flat, '${')) {
            // Note content blocks are never piped (NoteContent.vue); only a content-less note's label is.
            $this->count('piped-note');

            return $this->element($key, 'note', self::plain($flat), [
                'relevant' => $relevant, 'role' => $role, 'content' => null,
            ]);
        }

        $content = [];
        foreach ($blocks as $block) {
            array_push($content, ...$this->contentFor($block));
        }
        $heading = null;
        foreach ($blocks as $block) {
            if (in_array($block[0], ['h', 'h2'], true)) {
                $heading = self::plain((string) $block[1]);
                break;
            }
        }

        return $this->element($key, 'note', mb_substr($heading ?? $key, 0, 500), [
            'relevant' => $relevant, 'role' => $role, 'content' => $content,
        ]);
    }

    /**
     * @param  array<int, mixed>  $block
     * @return list<array<string, mixed>>
     */
    private function contentFor(array $block): array
    {
        return match ($block[0]) {
            'h' => [['type' => 'heading', 'level' => 1, 'text' => self::plain((string) $block[1])]],
            'h2' => [['type' => 'heading', 'level' => 2, 'text' => self::plain((string) $block[1])]],
            'p' => [['type' => 'paragraph', 'spans' => $this->spans((string) $block[1])]],
            'callout' => [['type' => 'callout', 'tone' => 'info', 'spans' => $this->spans((string) $block[1])]],
            'tile' => $this->tile((string) $block[1], (string) $block[2]),
            'bullets' => $this->bullets($block[1]),
            'image' => [['type' => 'image', 'attachment_id' => null, 'alt' => mb_substr(self::plain((string) $block[1]), 0, 300)]],
            'research' => $this->research($block[1], (string) $this->fixture['texts']['label_research']),
            default => throw new RuntimeException('Unknown NHETE block: '.$block[0]),
        };
    }

    /** @return list<array<string, mixed>> */
    private function tile(string $big, string $caption): array
    {
        $this->count('tile');

        return [
            ['type' => 'heading', 'level' => 2, 'text' => self::plain($big)],
            ['type' => 'paragraph', 'spans' => $this->spans($caption)],
        ];
    }

    /**
     * @param  list<string>  $items
     * @return list<array<string, mixed>>
     */
    private function bullets(array $items): array
    {
        $this->count('bullets');

        return array_map(fn (string $item): array => ['type' => 'paragraph', 'spans' => $this->spans('• '.$item)], $items);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: ?string}>  $entries
     * @return list<array<string, mixed>>
     */
    private function research(array $entries, string $title): array
    {
        if ($entries === []) {
            return [];
        }
        $this->count('research-open');
        $blocks = [['type' => 'heading', 'level' => 2, 'text' => $title]];
        foreach ($entries as [$stance, $kind, $summary, $doi]) {
            $spans = [['text' => "{$stance} · {$kind}. ", 'bold' => true], ...$this->spans($summary)];
            if ($doi !== null) {
                $spans[] = ['text' => ' '];
                $spans[] = ['text' => 'Read the paper', 'link' => $doi];
            }
            $blocks[] = ['type' => 'paragraph', 'spans' => $spans];
        }

        return $blocks;
    }

    /**
     * `**bold**` becomes a bold span; `[[term]]` becomes the bare word (no glossary yet), counted.
     *
     * @return list<array<string, mixed>>
     */
    private function spans(string $text): array
    {
        $this->countGlossary($text);
        $text = self::unwrapGlossary($text);
        $spans = [];
        foreach (preg_split('/(\*\*[^*]+\*\*)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [] as $piece) {
            $spans[] = str_starts_with($piece, '**') && str_ends_with($piece, '**') && mb_strlen($piece) > 4
                ? ['text' => mb_substr($piece, 2, -2), 'bold' => true]
                : ['text' => $piece];
        }

        return $spans === [] ? [['text' => '']] : $spans;
    }

    // ── Questions ──────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function question(array $item): array
    {
        $key = (string) $item['q'];
        $field = $this->element($key, (string) $item['type'], self::plain((string) $item['label']), [
            'hint' => isset($item['hint']) ? self::plain((string) $item['hint']) : null,
            'required' => ($item['required'] ?? false) ? 'required' : 'optional',
            'relevant' => $item['show_if'] ?? null,
            'options' => isset($item['choices']) ? $this->options($item['choices']) : [],
            'other' => isset($item['other']) ? self::plain((string) $item['other']) : null,
            'other_words' => $item['other_words'] ?? null,
            'max_words' => $item['max_words'] ?? null,
            'max_choices' => $item['max_choices'] ?? null,
            'exclusive' => $item['exclusive'] ?? null,
            'role' => 'question',
        ]);
        $out = [$field];

        if (isset($item['box'])) {
            $on = array_map('strval', $item['box']['on']);
            $out[] = $this->element($key.'_text', 'long_text', self::plain((string) $item['box']['label']), [
                'required' => 'required',
                'relevant' => self::anyOf($key, $on),
                'max_words' => $item['box']['words'] ?? self::WORD_CAP,
                'role' => 'box',
            ]);
        }

        return $out;
    }

    /**
     * An agreement grid with a comment per row that opens on two of its columns. A grid's answers cannot drive a
     * condition (`ExpressionValidationGate`), so each row becomes its own choice question with its own comment.
     *
     * @param  array<string, mixed>  $item
     * @return list<array<string, mixed>>
     */
    private function perRowGrid(array $item): array
    {
        $grid = (string) $item['grid'];
        $options = $this->options($item['list']);
        $out = [];
        foreach ($item['rows'] as [$row, $rowLabel, $rowText]) {
            $this->count('per-row-grid');
            $key = "{$grid}_{$row}";
            $out[] = $this->element($key, 'single_select', self::plain("{$rowLabel} — {$item['question']}"), [
                'hint' => self::plain((string) $rowText),
                'required' => ($item['required'] ?? true) ? 'required' : 'optional',
                'options' => $options,
                'role' => 'grid_row',
                'grid' => $grid,
                'row_label' => self::plain((string) $rowLabel),
                'row_text' => self::plain((string) $rowText),
            ]);
            $out[] = $this->element("{$key}_why", 'long_text', self::plain((string) $item['comment']), [
                'required' => 'optional',
                'relevant' => self::anyOf($key, ['agree_changes', 'disagree']),
                'max_words' => self::WORD_CAP,
                'role' => 'grid_comment',
            ]);
        }

        return $out;
    }

    // ── Stop 8: the indicator tables and their parts tables ───────────────────────────────────────────────────

    /** @return list<array<string, mixed>> */
    private function indicatorTable(string $domain): array
    {
        $texts = $this->fixture['texts'];
        $out = [];
        foreach ($this->indicatorsIn($domain) as $ind) {
            $this->count('indicator-block');
            $p = strtolower((string) $ind['code']);
            $out[] = $this->element("{$p}_info", 'note', mb_substr("{$ind['code']} · ".self::plain((string) $ind['name']), 0, 500), [
                'content' => $this->indicatorContent($ind),
                'role' => 'ind_info',
                'indicator' => $ind['code'],
            ]);
            $out[] = $this->element("{$p}_use", 'dropdown', self::plain((string) $texts['table_use']), [
                'required' => 'required', 'options' => $this->options('use'), 'role' => 'ind_use', 'indicator' => $ind['code'],
            ]);
            if ($ind['cutoff_cell']) {
                $out[] = $this->element("{$p}_cut", 'dropdown', self::plain((string) $texts['table_cutoff']), [
                    'hint' => self::plain((string) $texts['table_cutoff_help']),
                    'required' => 'required', 'options' => $this->options('cutoff'), 'role' => 'ind_cut', 'indicator' => $ind['code'],
                ]);
            }
            $out[] = $this->element("{$p}_worry", 'dropdown', self::plain((string) $texts['table_worry']), [
                'required' => 'optional', 'options' => $this->options('worry'), 'role' => 'ind_worry', 'indicator' => $ind['code'],
            ]);
            $out[] = $this->element("{$p}_keep", 'dropdown', self::plain((string) $texts['table_keep']), [
                'required' => 'required', 'options' => $this->options('keep'), 'role' => 'ind_keep', 'indicator' => $ind['code'],
            ]);

            // The comment's placeholder names why it became required: a hidden calculation, piped into it.
            $this->count('piped-placeholder');
            $reasons = [];
            $when = [];
            if ($ind['cutoff_cell']) {
                $reasons[] = "if(\${{$p}_cut} = 'change', '".self::quote((string) $texts['ph_change'])." ', '')";
                $when[] = ["{$p}_cut", 'change'];
            }
            $reasons[] = "if(\${{$p}_worry} = 'other', '".self::quote((string) $texts['ph_other'])." ', '')";
            $when[] = ["{$p}_worry", 'other'];
            $reasons[] = "if(\${{$p}_keep} = 'close', '".self::quote((string) $texts['ph_close'])."', '')";
            $when[] = ["{$p}_keep", 'close'];
            $joined = 'concat('.implode(', ', $reasons).')';
            $out[] = $this->element("{$p}_ph", 'calculated', "{$ind['code']} comment placeholder", [
                'formula' => "if({$joined} = '', '".self::quote((string) $texts['ph_optional'])."', {$joined})",
                'calculated_type' => 'text',
                'role' => 'ind_ph',
                'indicator' => $ind['code'],
            ]);
            $out[] = $this->element("{$p}_comment", 'long_text', self::plain((string) $texts['table_comment']), [
                'placeholder' => "\${{$p}_ph}",
                'required' => 'conditional',
                'required_when' => $when,
                'max_words' => self::WORD_CAP,
                'role' => 'ind_comment',
                'indicator' => $ind['code'],
            ]);
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $ind
     * @return list<array<string, mixed>>
     */
    private function indicatorContent(array $ind): array
    {
        $t = $this->fixture['texts'];
        $labelled = fn (string $label, string $text): array => [
            'type' => 'paragraph',
            'spans' => [['text' => self::plain($label).' ', 'bold' => true], ...$this->spans($text)],
        ];
        $blocks = [
            ['type' => 'paragraph', 'spans' => $this->spans((string) $ind['definition'])],
            $labelled((string) $t['label_cutoff'], (string) $ind['cutoff']),
        ];
        if (! $ind['cutoff_cell']) {
            $blocks[] = ['type' => 'paragraph', 'spans' => [['text' => self::plain((string) $t['no_cutoff']), 'italic' => true]]];
        }
        if ($ind['strength'] !== null) {
            $blocks[] = $labelled((string) $t['label_strength'], (string) $ind['strength']);
        }
        // "More about this indicator" cannot collapse yet, so it is drawn open.
        $blocks[] = ['type' => 'heading', 'level' => 2, 'text' => self::plain((string) $t['label_more'])];
        $blocks[] = $labelled((string) $t['label_technical'], (string) $ind['technical']);
        foreach (['formula', 'used', 'made_of'] as $k) {
            if ($ind[$k] !== null) {
                $blocks[] = ['type' => 'paragraph', 'spans' => $this->spans((string) $ind[$k])];
            }
        }
        if ($ind['plain'] !== null) {
            $blocks[] = $labelled((string) $t['label_plain'], (string) $ind['plain']);
        }
        $blocks[] = $labelled((string) $t['label_source'], (string) $ind['source']);
        $blocks[] = ['type' => 'callout', 'tone' => 'warning', 'spans' => $this->spans((string) $ind['watch'])];

        return [...$blocks, ...$this->research($ind['research'], (string) $t['label_research'])];
    }

    /** @return array{key: string, label: string, stop: string, relevant: string, elements: list<array<string, mixed>>} */
    private function partsSection(array $screen, string $domain, int $position): array
    {
        $t = $this->fixture['texts'];
        $indicators = $this->indicatorsIn($domain);
        $kept = fn (array $ind): string => self::anyOf(strtolower((string) $ind['code']).'_keep', ['yes', 'close']);
        $sectionShown = implode(' or ', array_map(static fn (array $ind): string => '('.$kept($ind).')', $indicators));

        // "Shown only on the first parts table the person sees": no earlier domain had a kept indicator.
        $earlier = array_filter(['a', 'b', 'c', 'd'], static fn (string $d): bool => $d < $domain);
        $earlierKept = [];
        foreach ($earlier as $d) {
            foreach ($this->indicatorsIn($d) as $ind) {
                $earlierKept[] = '('.$kept($ind).')';
            }
        }

        $key = (string) $screen['key'];
        $elements = [
            $this->note("{$key}_intro", [['h', (string) $t['parts_heading']], ['p', (string) $t['parts_intro']], ['p', (string) $t['parts_promise']]], null),
            $this->note("{$key}_region", [['p', (string) $t['parts_region']]], self::anyOf('q0_4', self::FOUR_REGIONS)),
            $this->note("{$key}_national", [['p', (string) $t['parts_national']]], "\${q0_4} = 'whole'"),
            $this->note("{$key}_request", [['callout', (string) $t['parts_request']]], $earlierKept === [] ? null : 'not('.implode(' or ', $earlierKept).')'),
        ];

        foreach ($indicators as $ind) {
            $this->count('parts-block');
            $p = strtolower((string) $ind['code']);
            $indShown = $kept($ind);
            $elements[] = $this->note("{$p}_parts_head", [['h2', "{$ind['code']} · {$ind['name']}"]], $indShown, 'parts_head');
            foreach ($ind['parts'] as $n => $partText) {
                $q = "{$p}_p".($n + 1);
                $partKept = self::anyOf("{$q}_keep", ['yes', 'close']);
                $small = self::anyOf("{$q}_area", self::SMALL_AREAS);
                $elements[] = $this->element("{$q}_keep", 'dropdown', self::plain("{$partText}: {$t['parts_keep']}"), [
                    'required' => 'required', 'relevant' => $indShown, 'options' => $this->options('keep'),
                    'role' => 'part_keep', 'indicator' => $ind['code'],
                ]);
                $elements[] = $this->element("{$q}_close", 'long_text', self::plain((string) $t['parts_close_ph']), [
                    'required' => 'required', 'relevant' => "({$indShown}) and \${{$q}_keep} = 'close'",
                    'max_words' => self::WORD_CAP, 'role' => 'part_close', 'indicator' => $ind['code'],
                ]);
                $elements[] = $this->element("{$q}_area", 'dropdown', self::plain((string) $t['parts_area']), [
                    'required' => 'required', 'relevant' => "({$indShown}) and ({$partKept})", 'options' => $this->options('area'),
                    'role' => 'part_area', 'indicator' => $ind['code'],
                ]);
                $elements[] = $this->element("{$q}_cov_r", 'dropdown', self::plain((string) $t['parts_cov_region']), [
                    'hint' => self::plain((string) $t['parts_cov_region_help']),
                    'required' => 'required',
                    'relevant' => "({$indShown}) and ({$partKept}) and ({$small}) and (".self::anyOf('q0_4', self::FOUR_REGIONS).')',
                    'options' => $this->options('coverage'), 'role' => 'part_cov_r', 'indicator' => $ind['code'],
                ]);
                $elements[] = $this->element("{$q}_cov_n", 'dropdown', self::plain((string) $t['parts_cov_national']), [
                    'hint' => self::plain((string) $t['parts_cov_national_help']),
                    'required' => 'required',
                    'relevant' => "({$indShown}) and ({$partKept}) and ({$small}) and \${q0_4} = 'whole'",
                    'options' => $this->options('coverage'), 'role' => 'part_cov_n', 'indicator' => $ind['code'],
                ]);
            }
        }

        return ['key' => $key, 'label' => (string) $screen['label'], 'stop' => (string) $screen['stop'], 'relevant' => $sectionShown, 'elements' => $elements];
    }

    /** @return list<array<string, mixed>> */
    private function indicatorsIn(string $domain): array
    {
        return array_values(array_filter($this->indicators(), static fn (array $i): bool => $i['domain'] === $domain));
    }

    // ── Helpers ────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function element(string $key, string $type, string $label, array $extra = []): array
    {
        return [
            'key' => $key,
            'type' => $type,
            'label' => $label,
            'hint' => null,
            'placeholder' => null,
            'required' => 'optional',
            'required_when' => [],
            'relevant' => null,
            'options' => [],
            'other' => null,
            'other_words' => null,
            'max_words' => null,
            'max_choices' => null,
            'exclusive' => null,
            'content' => null,
            'formula' => null,
            'calculated_type' => null,
            'role' => 'question',
            'indicator' => null,
            ...$extra,
        ];
    }

    /** @return list<array{0: string, 1: string}> */
    private function options(mixed $choices): array
    {
        $list = is_string($choices) ? $this->fixture['lists'][$choices] : $choices;

        return array_map(static fn (array $c): array => [(string) $c[0], self::plain((string) $c[1])], $list);
    }

    /** @param  list<string>  $values */
    public static function anyOf(string $key, array $values): string
    {
        return implode(' or ', array_map(static fn (string $v): string => "\${{$key}} = '{$v}'", $values));
    }

    /** Text as a plain label: `**` dropped, `[[term]]` → term. */
    public static function plain(string $text): string
    {
        return str_replace('**', '', self::unwrapGlossary($text));
    }

    private static function unwrapGlossary(string $text): string
    {
        return (string) preg_replace('/\[\[([^\]]+)\]\]/u', '$1', $text);
    }

    private static function quote(string $text): string
    {
        return str_replace("'", "\\'", self::plain($text));
    }

    private function countGlossary(string $text): void
    {
        $n = preg_match_all('/\[\[[^\]]+\]\]/u', $text);
        if ($n > 0) {
            $this->workarounds['glossary-plain'] = ($this->workarounds['glossary-plain'] ?? 0) + $n;
        }
    }

    private function count(string $workaround): void
    {
        $this->workarounds[$workaround] = ($this->workarounds[$workaround] ?? 0) + 1;
    }
}
