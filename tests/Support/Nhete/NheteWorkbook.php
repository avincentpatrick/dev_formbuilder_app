<?php

declare(strict_types=1);

namespace Tests\Support\Nhete;

use RuntimeException;

/**
 * The NHETE plan written as an XLSForm workbook (`M237`), in one of two dialects:
 *
 *   KOBO      what a Kobo or ODK author writes for this module: notes in markdown (headings, bold, links, bullets) in
 *             one cell however long, a `media::image` for each diagram, `count-selected()` for "up to two", `regex()`
 *             for a word cap, a `required` FORMULA for "required when", and the placeholder as a hint.
 *   MERIDIAN  the same module in what this product's importer is documented to take (`docs/xlsform-interop-spec.md`):
 *             plain-text notes split under the 500-character label column, `count(.)`, and no word cap or "required
 *             when", which XLSForm cannot express here — so a constraint stands in for "required when".
 *
 * Both come from the one {@see NhetePlan}, so a difference between their outcomes is the importer's, never the data's.
 */
final class NheteWorkbook
{
    public const KOBO = 'kobo';

    public const MERIDIAN = 'meridian';

    public const LABEL_MAX = 500;

    private const SURVEY_HEADERS = ['type', 'name', 'label', 'hint', 'required', 'relevant', 'constraint', 'constraint_message', 'calculation', 'appearance', 'media::image'];

    /** @var list<array<string, scalar|null>> */
    private array $choices = [];

    /** @var array<string, string> option-set signature → list name, so identical lists are written once */
    private array $lists = [];

    public function __construct(private readonly NhetePlan $plan, private readonly string $dialect) {}

    /**
     * @param  list<array<string, mixed>>|null  $sections  a subset of the plan's sections; null writes them all
     * @return array<string, array{headers: list<string>, rows: list<array<string, scalar|null>>}>
     */
    public function sheets(?array $sections = null): array
    {
        $this->choices = [];
        $this->lists = [];
        $survey = [];
        foreach ($sections ?? $this->plan->sections() as $section) {
            $survey[] = ['type' => 'begin group', 'name' => $section['key'], 'label' => $section['label'], 'relevant' => $section['relevant'], 'appearance' => 'field-list'];
            foreach ($section['elements'] as $e) {
                array_push($survey, ...$this->rows($e));
            }
            $survey[] = ['type' => 'end group', 'name' => $section['key']];
        }

        return [
            'survey' => ['headers' => self::SURVEY_HEADERS, 'rows' => $survey],
            'choices' => ['headers' => ['list_name', 'name', 'label'], 'rows' => $this->choices],
            'settings' => ['headers' => ['form_title', 'form_id', 'default_language'], 'rows' => [['form_title' => $this->plan->title(), 'form_id' => 'nhete', 'default_language' => 'English']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $e
     * @return list<array<string, scalar|null>>
     */
    private function rows(array $e): array
    {
        return match ($e['type']) {
            'note' => $this->noteRows($e),
            'calculated' => [['type' => 'calculate', 'name' => $e['key'], 'label' => $e['label'], 'calculation' => $e['formula']]],
            default => [$this->questionRow($e)],
        };
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array<string, scalar|null>
     */
    private function questionRow(array $e): array
    {
        $type = match ($e['type']) {
            'single_select', 'dropdown' => 'select_one '.$this->listFor($e),
            'multi_select' => 'select_multiple '.$this->listFor($e),
            'long_text' => 'text',
            default => throw new RuntimeException("no XLSForm type for {$e['type']}"),
        };
        if ($e['other'] !== null) {
            $type .= ' or_other';
        }

        [$constraint, $message] = $this->constraint($e);
        $row = [
            'type' => $type,
            'name' => $e['key'],
            'label' => $e['label'],
            'hint' => $e['hint'],
            'required' => $e['required'] === 'required' ? 'yes' : null,
            'relevant' => $e['relevant'],
            'constraint' => $constraint,
            'constraint_message' => $message,
            'appearance' => match ($e['type']) {
                'dropdown' => 'minimal',
                'long_text' => 'multiline',
                default => null,
            },
        ];

        if ($e['required'] === 'conditional' && $this->dialect === self::KOBO) {
            // Kobo authors write "required when" as a formula in the required column.
            $row['required'] = implode(' or ', array_map(static fn (array $w): string => "\${{$w[0]}} = '{$w[1]}'", $e['required_when']));
        }
        if ($e['placeholder'] !== null && $this->dialect === self::KOBO) {
            // XLSForm has no placeholder column; Kobo authors use the hint.
            $row['hint'] = trim(($row['hint'] ?? '').' '.$e['placeholder']);
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>  $e
     * @return array{0: ?string, 1: ?string}
     */
    private function constraint(array $e): array
    {
        $parts = [];
        $messages = [];
        $kobo = $this->dialect === self::KOBO;
        if ($e['max_words'] !== null && $kobo) {
            $parts[] = "regex(., '^".NheteBuilderDriver::wordCapPattern((int) $e['max_words'])."$')";
            $messages[] = 'Please keep this to '.number_format((int) $e['max_words']).' words or fewer.';
        }
        if ($e['max_choices'] !== null) {
            $parts[] = $kobo ? "count-selected(.) <= {$e['max_choices']}" : "count(.) <= {$e['max_choices']}";
            $messages[] = "You can choose up to {$e['max_choices']}.";
        }
        if ($e['exclusive'] !== null) {
            $parts[] = $kobo
                ? "not(selected(., '{$e['exclusive']}') and count-selected(.) > 1)"
                : "not(selected(., '{$e['exclusive']}')) or count(.) = 1";
            $messages[] = 'No view or opinion cannot be combined with another answer.';
        }
        if ($e['required'] === 'conditional' && ! $kobo) {
            // XLSForm has no "required when" this product reads; a constraint is the stand-in to measure.
            $when = implode(' or ', array_map(static fn (array $w): string => "\${{$w[0]}} = '{$w[1]}'", $e['required_when']));
            $parts[] = "not({$when}) or . != ''";
            $messages[] = 'Please tell us more here.';
        }

        return $parts === [] ? [null, null] : ['('.implode(') and (', $parts).')', implode(' ', $messages)];
    }

    /**
     * @param  array<string, mixed>  $e
     * @return list<array<string, scalar|null>>
     */
    private function noteRows(array $e): array
    {
        $kobo = $this->dialect === self::KOBO;
        $text = $e['content'] === null ? (string) $e['label'] : $this->noteText($e['content'], $kobo);
        $image = null;
        foreach ($e['content'] ?? [] as $block) {
            if ($block['type'] === 'image') {
                $image = 'nhete-diagram.png';
            }
        }

        $base = ['type' => 'note', 'relevant' => $e['relevant']];
        if ($kobo) {
            return [[...$base, 'name' => $e['key'], 'label' => $text, 'media::image' => $image]];
        }

        $rows = [];
        foreach (self::chunks($text, self::LABEL_MAX) as $n => $chunk) {
            $rows[] = [...$base, 'name' => $n === 0 ? $e['key'] : "{$e['key']}_{$n}", 'label' => $chunk];
        }

        return $rows;
    }

    /** @param  list<array<string, mixed>>  $content */
    private function noteText(array $content, bool $markdown): string
    {
        $out = [];
        foreach ($content as $block) {
            $spans = fn (): string => implode('', array_map(static function (array $s) use ($markdown): string {
                $t = (string) $s['text'];
                if (! $markdown) {
                    return $t;
                }
                if (isset($s['link'])) {
                    return "[{$t}]({$s['link']})";
                }

                return ($s['bold'] ?? false) ? "**{$t}**" : $t;
            }, $block['spans'] ?? []));
            $out[] = match ($block['type']) {
                'heading' => $markdown ? str_repeat('#', (int) $block['level']).' '.$block['text'] : (string) $block['text'],
                'paragraph', 'callout' => $spans(),
                'image' => (string) $block['alt'],
                default => '',
            };
        }

        return implode($markdown ? "\n\n" : "\n", array_filter($out, static fn (string $s): bool => $s !== ''));
    }

    /** @return list<string> pieces of at most `$max` characters, broken at a sentence or a space */
    public static function chunks(string $text, int $max): array
    {
        $pieces = [];
        while (mb_strlen($text) > $max) {
            $cut = mb_strrpos(mb_substr($text, 0, $max), '. ');
            $cut = $cut === false || $cut < $max / 3 ? mb_strrpos(mb_substr($text, 0, $max), ' ') : $cut + 1;
            $cut = $cut === false ? $max : $cut;
            $pieces[] = trim(mb_substr($text, 0, $cut));
            $text = ltrim(mb_substr($text, $cut));
        }
        $pieces[] = $text;

        return array_values(array_filter($pieces, static fn (string $p): bool => $p !== ''));
    }

    /** @param  array<string, mixed>  $e */
    private function listFor(array $e): string
    {
        $signature = json_encode($e['options']);
        if (! isset($this->lists[$signature])) {
            $name = 'l_'.$e['key'];
            $this->lists[$signature] = $name;
            foreach ($e['options'] as [$value, $label]) {
                $this->choices[] = ['list_name' => $name, 'name' => $value, 'label' => $label];
            }
        }

        return $this->lists[$signature];
    }
}
