<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Enums\FieldType;
use App\Services\Expressions\Coercion;

/**
 * Reads ONE printed question's answer out of the lines between its label and the next question's (M128).
 *
 * The layout it reads is `docs/ocr-pipeline-design.md` §2.5 (layout 3 since `M144`), and each area has its
 * own rule:
 *   - `comb`    — characters in a row of boxes. A captioned comb (a date's DD / MM / YYYY, a time, a
 *     duration, a cascading select's levels) is split into its groups by WHERE each character sits under
 *     the captions, never by counting, because §2.5.3 is explicit that a date must be parsed positionally.
 *     Since layout 3 (`D98`) every comb is captioned: a number and a phone moved to an open box.
 *   - `line`    — free text written in one open box (short text, email, url since layout 2; a phone and a
 *     number since layout 3, parsed out of the text with the same letter-for-digit fixes a comb got).
 *   - `ruled`   — free text in one taller box, sized from the question's `max_length` since layout 3; its
 *     lines are read top to bottom and joined with spaces. A box wall read as a bar is dropped from both.
 *   - `choices` — an X in the box before an option's label. Since layout 2 the options sit SIDE BY SIDE,
 *     several to a line, so each label is found as a span of words in printed order and the mark credited
 *     to it is the run of mark words immediately before that span — never a mark that belongs to an
 *     earlier label on the same line. A yes/no question is read here too, and also from a written YES or
 *     NO, which rescues a respondent who writes the word beside the boxes.
 * Grids, signature lines and the "not collected on paper" note make a version ineligible for OCR (§2), so
 * a version reaching this class never has one to read; they are reported as `skipped` if they do.
 *
 * ── WHAT A RESULT IS ───────────────────────────────────────────────────────────────────────────────
 * `state` is one of `read` (a value in the field's stored shape), `blank` (nothing written — not a failure,
 * §2.5.1: the blank form prints questions a respondent's own branching would have hidden), `unreadable`
 * (something written that does not parse for the type, or two boxes marked on a one-answer question), or
 * `skipped`. `confidence` is the LOWEST character confidence among what was used, on a 0–100 scale: one
 * doubtful character is enough to make a reviewer look.
 *
 * Every value is emitted in the shape `StructuralAnswerNormalizer` accepts for the type, so the review
 * screen can hand it to the pipeline unchanged.
 */
final class OcrAnswerReader
{
    /** OCR's usual letter-for-digit confusions, applied to a digit answer — combed, or in an open box since layout 3. */
    private const array DIGIT_LOOKALIKES = ['O' => '0', 'o' => '0', 'D' => '0', 'I' => '1', 'l' => '1', 'i' => '1'];

    /** A recognised substitution makes the value something a reviewer should see, whatever its confidence. */
    private const int SUBSTITUTION_CEILING = 89;

    /**
     * @param  array<string, mixed>  $row  one printed row from `BlankFormPrintPresenter::present()`
     * @param  array<string, mixed>  $config  the field's `config` from the version snapshot
     * @param  list<OcrLine>  $lines  the question's region, top to bottom
     * @param  list<string>  $static  normalised printed text that may sit in a region and is not an answer
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null}
     */
    public function read(array $row, ?FieldType $type, array $config, array $lines, array $static): array
    {
        return match ($row['area'] ?? null) {
            'comb' => $this->comb($row, $type, $config, $lines, $static),
            'line', 'ruled' => $this->ruled($type, $this->answerLines($lines, $static, [])),
            'choices' => $this->choices($row, $type, $lines, $static),
            default => ['state' => 'skipped', 'value' => null, 'text' => null, 'confidence' => null],
        };
    }

    // ── comb ──────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $config
     * @param  list<OcrLine>  $lines
     * @param  list<string>  $static
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null}
     */
    private function comb(array $row, ?FieldType $type, array $config, array $lines, array $static): array
    {
        $groups = $this->groups($row);
        $captions = array_values(array_filter(array_map(static fn (array $g): ?string => $g['caption'], $groups), static fn (?string $c): bool => $c !== null));

        $captionLine = null;
        if ($captions !== []) {
            foreach ($lines as $line) {
                if ($this->isCaptionLine($line, $captions)) {
                    $captionLine = $line;
                    break;
                }
            }
        }

        $answer = $this->answerLines($lines, $static, $captionLine === null ? [] : [$captionLine]);
        $symbols = $answer === [] ? [] : $this->combSymbols($answer[0]);

        if ($symbols === []) {
            return $this->blank();
        }

        $confidence = $this->confidenceOf($symbols);

        if ($captions === []) {
            $text = $this->joinWithGaps($symbols);

            return $this->parsed($type, $config, $text, null, $confidence);
        }

        $parts = $captionLine !== null
            ? $this->assignToCaptions($symbols, $captionLine, $groups)
            : $this->splitByCells($symbols, $groups);

        if ($parts === null) {
            return $this->unreadable($this->joinWithGaps($symbols), $confidence);
        }

        // What the reviewer is shown: the groups as read, with an empty group marked rather than closed up.
        $text = implode(' ', array_map(static fn (?string $p): string => $p ?? '?', $parts));

        return $this->parsed($type, $config, $text, $parts, $confidence);
    }

    /**
     * The groups a comb was printed with, from the presenter row.
     *
     * @param  array<string, mixed>  $row
     * @return list<array{cells: int, caption: string|null}>
     */
    private function groups(array $row): array
    {
        $out = [];
        foreach ((array) ($row['comb'] ?? []) as $group) {
            if (! is_array($group)) {
                continue;
            }
            $caption = $group['caption'] ?? null;
            $out[] = [
                'cells' => is_int($group['cells'] ?? null) ? $group['cells'] : 0,
                'caption' => is_string($caption) ? $caption : null,
            ];
        }

        return $out;
    }

    /**
     * A line is the caption row when every word on it is one of the comb's printed captions.
     *
     * @param  list<string>  $captions
     */
    private function isCaptionLine(OcrLine $line, array $captions): bool
    {
        $known = array_map(static fn (string $c): string => OcrText::key($c), $captions);

        foreach ($line->words as $word) {
            if (! in_array(OcrText::key($word->text), $known, true)) {
                return false;
            }
        }

        return $line->words !== [];
    }

    /**
     * The characters written in a comb row, left to right, without the box walls a recognizer reads as `|`.
     *
     * @return list<OcrSymbol>
     */
    private function combSymbols(OcrLine $line): array
    {
        $symbols = [];
        foreach ($line->words as $word) {
            foreach ($word->symbols as $symbol) {
                if (! OcrText::isBorderArtefact($symbol->text) && trim($symbol->text) !== '') {
                    $symbols[] = $symbol;
                }
            }
        }

        usort($symbols, static fn (OcrSymbol $a, OcrSymbol $b): int => $a->centerX() <=> $b->centerX());

        return $symbols;
    }

    /**
     * Characters joined, with a space wherever the gap to the next is well over the comb's own pitch — an
     * empty box between two words.
     *
     * @param  list<OcrSymbol>  $symbols
     */
    private function joinWithGaps(array $symbols): string
    {
        $pitch = $this->pitch($symbols);
        $text = '';
        $previous = null;

        foreach ($symbols as $symbol) {
            if ($previous !== null && $pitch > 0.0 && $symbol->centerX() - $previous->centerX() > 1.6 * $pitch) {
                $text .= ' ';
            }
            $text .= $symbol->text;
            $previous = $symbol;
        }

        return $text;
    }

    /**
     * The distance from one box to the next, taken as the MEDIAN gap between neighbouring characters —
     * robust to the one wide gap the space is.
     *
     * @param  list<OcrSymbol>  $symbols
     */
    private function pitch(array $symbols): float
    {
        $gaps = [];
        for ($i = 1, $n = count($symbols); $i < $n; $i++) {
            $gaps[] = $symbols[$i]->centerX() - $symbols[$i - 1]->centerX();
        }

        if ($gaps === []) {
            return 0.0;
        }

        sort($gaps);

        return $gaps[intdiv(count($gaps), 2)];
    }

    /**
     * Each character goes to the caption printed nearest below it — the positional parse §2.5.3 requires.
     * Captions repeat in a datetime (`MM` for month and minute), so they are matched to groups in printed
     * order, not by text alone.
     *
     * @param  list<OcrSymbol>  $symbols
     * @param  list<array{cells: int, caption: string|null}>  $groups
     * @return list<string|null>|null one string per group (null for an empty group), or null when the
     *                                caption row cannot be lined up with the groups
     */
    private function assignToCaptions(array $symbols, OcrLine $captionLine, array $groups): ?array
    {
        $centers = array_map(static fn (OcrWord $w): float => $w->centerX(), $captionLine->words);
        if (count($centers) !== count($groups)) {
            return $this->splitByCells($symbols, $groups);
        }

        $parts = array_fill(0, count($groups), '');
        foreach ($symbols as $symbol) {
            $best = 0;
            $bestDistance = INF;
            foreach ($centers as $i => $center) {
                $distance = abs($symbol->centerX() - $center);
                if ($distance < $bestDistance) {
                    $best = $i;
                    $bestDistance = $distance;
                }
            }
            $parts[$best] .= $symbol->text;
        }

        return array_values(array_map(static fn (string $p): ?string => $p === '' ? null : $p, $parts));
    }

    /**
     * The fallback when the caption row was not read: split by the printed cell counts, and only when the
     * characters fill every box exactly — anything else is ambiguous and left to a person.
     *
     * @param  list<OcrSymbol>  $symbols
     * @param  list<array{cells: int, caption: string|null}>  $groups
     * @return list<string|null>|null
     */
    private function splitByCells(array $symbols, array $groups): ?array
    {
        $total = array_sum(array_column($groups, 'cells'));
        if (count($symbols) !== $total) {
            return null;
        }

        $parts = [];
        $offset = 0;
        foreach ($groups as $group) {
            $slice = array_slice($symbols, $offset, $group['cells']);
            $parts[] = implode('', array_map(static fn (OcrSymbol $s): string => $s->text, $slice));
            $offset += $group['cells'];
        }

        return $parts;
    }

    // ── ruled ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<OcrLine>  $answer
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null}
     */
    private function ruled(?FieldType $type, array $answer): array
    {
        // A box wall the recognizer read as a bar is dropped the way `combSymbols()` drops it (layout 3,
        // `D98`): a number or a phone is now written in an open box, often up against its left edge, and a
        // leading `|` would make the number unreadable and would be STORED in the phone.
        $symbols = [];
        foreach ($answer as $line) {
            foreach ($line->words as $word) {
                foreach ($word->symbols as $symbol) {
                    if (! OcrText::isBorderArtefact($symbol->text)) {
                        $symbols[] = $symbol;
                    }
                }
            }
        }

        if ($symbols === []) {
            return $this->blank();
        }

        $text = implode(' ', array_map(static fn (OcrLine $l): string => OcrText::withoutBorderArtefacts($l->text()), $answer));

        return $this->parsed($type, [], $text, null, $this->confidenceOf($symbols));
    }

    // ── choices ───────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $row
     * @param  list<OcrLine>  $lines
     * @param  list<string>  $static
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null}
     */
    private function choices(array $row, ?FieldType $type, array $lines, array $static): array
    {
        $options = [];
        foreach ((array) ($row['options'] ?? []) as $option) {
            if (is_array($option) && is_string($option['value'] ?? null) && is_string($option['label'] ?? null)) {
                $options[] = ['value' => $option['value'], 'label' => $option['label']];
            }
        }

        // Layout 2 (`M143`): the options sit side by side, so one line carries several labels. Each option's
        // label is found as a span of words, in PRINTED order along the line, and the mark credited to it is
        // the run of mark words walking back from its span to the end of the previous one — so in
        // "X Female Male" the X is Female's, and in "Female X Male" it is Male's. The old reader matched the
        // TAIL of a line to one label and took every mark to its left, which credited the first case to Male.
        /** @var array<int, list<OcrSymbol>> $found option index => the mark's characters (empty: box seen, unmarked) */
        $found = [];
        foreach ($lines as $line) {
            if ($this->isStatic($line, $static)) {
                continue;
            }
            $words = $line->words;
            $end = 0;
            foreach ($options as $i => $option) {
                if (array_key_exists($i, $found)) {
                    continue;
                }
                $span = $this->labelSpan($words, $end, $option['label']);
                if ($span === null) {
                    continue;
                }
                $marks = $span['lead'] === null ? [] : [$span['lead']];
                for ($w = $span['start'] - 1; $w >= $end; $w--) {
                    if (! OcrText::isMark($words[$w]->text)) {
                        break;
                    }
                    $marks = [...$words[$w]->symbols, ...$marks];
                }
                $found[$i] = $marks;
                $end = $span['start'] + $span['length'];
            }
        }

        $marked = [];
        $markSymbols = [];
        foreach ($found as $i => $marks) {
            if ($marks !== []) {
                $marked[] = $options[$i];
                array_push($markSymbols, ...$marks);
            }
        }

        // ⚠️ A YES/NO IS TICK-BOX LAYOUT ONLY WHEN BOTH ITS LABELS ARE FOUND. A sheet printed before `M128`
        // gave it a write-in box, and a written "YES" reads exactly like the printed label "Yes" — so one
        // matching label proves nothing. The paper always prints both; anything less is read as writing,
        // which also rescues a respondent who writes the word beside the boxes.
        if ($type === FieldType::YesNo && count($found) < count($options)) {
            return $this->ruled($type, $this->answerLines($lines, $static, []));
        }

        // M133 (`R-5da4a30f`): a single choice or dropdown that takes its choices from another form prints no boxes — its
        // list is live, so the blank sheet gives it a write-in box — and what was written IS the answer (`D85`: the text
        // shown). Read as writing, so the reviewer sees it found rather than unreadable; publish refuses any other
        // choice question with no choices, so an empty list here can only mean a linked one.
        if ($options === [] && ($type === FieldType::SingleSelect || $type === FieldType::Dropdown)) {
            return $this->ruled($type, $this->answerLines($lines, $static, []));
        }

        if ($found === []) {
            return $this->blankOrUnreadable($this->answerLines($lines, $static, []));
        }

        if ($marked === []) {
            return $this->blank();
        }

        $confidence = $this->confidenceOf($markSymbols);
        $labels = implode(', ', array_map(static fn (array $o): string => $o['label'], $marked));

        if ($type === FieldType::MultiSelect) {
            return $this->found(array_map(static fn (array $o): string => $o['value'], $marked), $labels, $confidence);
        }

        if (count($marked) > 1) {
            return $this->unreadable($labels, $confidence);
        }

        $value = $marked[0]['value'];

        return $this->found($type === FieldType::YesNo ? Coercion::yesNoAnswer($value) : $value, $labels, $confidence);
    }

    /**
     * Where the option labelled `$label` sits on a line: the first span of words at or after `$from` that reads as
     * the label, longest span first so "Not marked" is not taken for "marked".
     *
     * A mark run together with the label's first word ("XFemale"), which a recognizer does when the pen comes
     * close to the text, is tried BEFORE the plain comparison at each position: "xfemale" is within the plain
     * tolerance of "female", and taking it that way would lose the mark.
     *
     * @param  list<OcrWord>  $words
     * @return array{start: int, length: int, lead: OcrSymbol|null}|null
     */
    private function labelSpan(array $words, int $from, string $label): ?array
    {
        $n = count($words);
        $labelWords = count(preg_split('/\s+/', trim($label)) ?: []);
        $longest = max(1, $labelWords + 1);

        for ($k = $from; $k < $n; $k++) {
            for ($length = min($longest, $n - $k); $length >= 1; $length--) {
                $slice = array_slice($words, $k, $length);
                $text = implode(' ', array_map(static fn (OcrWord $w): string => $w->text, $slice));

                $first = $slice[0]->symbols[0] ?? null;
                if ($first !== null && OcrText::isMark($first->text) && mb_strlen($slice[0]->text) > 1) {
                    $rest = mb_substr($text, 1);
                    if (OcrText::similarity($rest, $label) >= 0.8) {
                        return ['start' => $k, 'length' => $length, 'lead' => $first];
                    }
                }

                if (OcrText::similarity($text, $label) >= 0.8) {
                    return ['start' => $k, 'length' => $length, 'lead' => null];
                }
            }
        }

        return null;
    }

    // ── shared ────────────────────────────────────────────────────────────────────────────────────

    /**
     * The region's lines that are neither printed text nor an excluded line, top to bottom.
     *
     * @param  list<OcrLine>  $lines
     * @param  list<string>  $static
     * @param  list<OcrLine>  $exclude
     * @return list<OcrLine>
     */
    private function answerLines(array $lines, array $static, array $exclude): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (in_array($line, $exclude, true) || $this->isStatic($line, $static)) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Printed text the model predicts can sit inside a question's region: its hint, a note between
     * questions, the next section's heading, the footer. Compared on normal forms, as a substring (a hint
     * wraps across lines) or near enough to the whole string.
     *
     * @param  list<string>  $static
     */
    private function isStatic(OcrLine $line, array $static): bool
    {
        $text = OcrText::normal($line->text());
        if ($text === '') {
            return true;
        }

        foreach ($static as $printed) {
            if (strlen($text) >= 4 && str_contains($printed, $text)) {
                return true;
            }
            if (OcrText::similarity($text, $printed) >= 0.85) {
                return true;
            }
        }

        return false;
    }

    /**
     * Parse what was read into the field's stored shape.
     *
     * @param  array<string, mixed>  $config
     * @param  list<string|null>|null  $parts  the captioned groups, for the positional types
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null}
     */
    private function parsed(?FieldType $type, array $config, string $text, ?array $parts, int $confidence): array
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        if ($text === '') {
            return $this->blank();
        }

        return match ($type) {
            FieldType::Integer => $this->number($text, $confidence, false),
            FieldType::Decimal => $this->number($text, $confidence, true),
            FieldType::Date => $this->date($parts, $text, $confidence),
            FieldType::Time => $this->time($parts, $text, $confidence),
            FieldType::Datetime => $this->datetime($parts, $text, $confidence),
            FieldType::Duration => $this->duration($parts, $text, $confidence),
            FieldType::CascadingSelect => $this->cascade($parts, $config, $text, $confidence),
            FieldType::YesNo => $this->yesNo($text, $confidence),
            FieldType::Email => $this->found(strtolower(str_replace(' ', '', $text)), $text, $confidence),
            FieldType::Url => $this->found(strtoupper($text) === $text ? strtolower(str_replace(' ', '', $text)) : str_replace(' ', '', $text), $text, $confidence),
            default => $this->found($text, $text, $confidence),
        };
    }

    /** @return array{state: string, value: mixed, text: string|null, confidence: int|null} */
    private function number(string $text, int $confidence, bool $decimal): array
    {
        $raw = str_replace(' ', '', $text);
        $digits = strtr($raw, self::DIGIT_LOOKALIKES);
        if ($decimal) {
            $digits = str_replace(',', '.', $digits);
        }

        $pattern = $decimal ? '/^-?\d+(\.\d+)?$/' : '/^-?\d+$/';
        if (preg_match($pattern, $digits) !== 1) {
            return $this->unreadable($text, $confidence);
        }

        return $this->found($digits, $text, $digits === $raw ? $confidence : min($confidence, self::SUBSTITUTION_CEILING));
    }

    /**
     * @param  list<string|null>|null  $parts  DD, MM, YYYY
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null}
     */
    private function date(?array $parts, string $text, int $confidence): array
    {
        [$day, $month, $year] = $this->ints($parts, 3);
        if ($day === null || $month === null || $year === null || $year < 1000 || ! checkdate($month, $day, $year)) {
            return $this->unreadable($text, $confidence);
        }

        return $this->found(sprintf('%04d-%02d-%02d', $year, $month, $day), $text, $confidence);
    }

    /**
     * @param  list<string|null>|null  $parts  HH, MM
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null}
     */
    private function time(?array $parts, string $text, int $confidence): array
    {
        [$hour, $minute] = $this->ints($parts, 2);
        if ($hour === null || $minute === null || $hour > 23 || $minute > 59) {
            return $this->unreadable($text, $confidence);
        }

        return $this->found(sprintf('%02d:%02d', $hour, $minute), $text, $confidence);
    }

    /**
     * @param  list<string|null>|null  $parts  DD, MM, YYYY, HH, MM
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null}
     */
    private function datetime(?array $parts, string $text, int $confidence): array
    {
        [$day, $month, $year, $hour, $minute] = $this->ints($parts, 5);
        if ($day === null || $month === null || $year === null || $hour === null || $minute === null
            || $year < 1000 || ! checkdate($month, $day, $year) || $hour > 23 || $minute > 59) {
            return $this->unreadable($text, $confidence);
        }

        return $this->found(sprintf('%04d-%02d-%02dT%02d:%02d', $year, $month, $day, $hour, $minute), $text, $confidence);
    }

    /**
     * A duration is stored as a number of seconds (`docs/xlsform-interop-spec.md`'s `duration` row).
     *
     * @param  list<string|null>|null  $parts  HRS, MIN
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null}
     */
    private function duration(?array $parts, string $text, int $confidence): array
    {
        $raw = $parts ?? [];
        $hours = ($raw[0] ?? null) === null ? 0 : $this->int($raw[0]);
        $minutes = ($raw[1] ?? null) === null ? 0 : $this->int($raw[1]);
        if ($hours === null || $minutes === null || $minutes > 59) {
            return $this->unreadable($text, $confidence);
        }

        return $this->found($hours * 3600 + $minutes * 60, $text, $confidence);
    }

    /**
     * Each written level matched to an option at that level, under the option chosen one level up.
     *
     * @param  list<string|null>|null  $parts
     * @param  array<string, mixed>  $config
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null}
     */
    private function cascade(?array $parts, array $config, string $text, int $confidence): array
    {
        $levels = [];
        foreach ((array) ($config['levels'] ?? []) as $level) {
            $key = is_array($level) ? ($level['key'] ?? null) : $level;
            if (is_string($key)) {
                $levels[] = $key;
            }
        }

        $chosen = [];
        $parent = null;
        foreach ($levels as $i => $level) {
            $written = $parts[$i] ?? null;
            if ($written === null) {
                break; // a deeper level left empty ends the answer, as the screen control allows
            }

            $match = null;
            foreach ((array) ($config['options'] ?? []) as $option) {
                if (! is_array($option) || ($option['level'] ?? null) !== $level) {
                    continue;
                }
                if ($parent !== null && ($option['parent'] ?? null) !== $parent) {
                    continue;
                }
                $value = $option['value'] ?? null;
                $label = $option['label'] ?? null;
                if (is_string($value) && (OcrText::normal($written) === OcrText::normal($value)
                    || (is_string($label) && OcrText::normal($written) === OcrText::normal($label)))) {
                    $match = $value;
                    break;
                }
            }

            if ($match === null) {
                return $this->unreadable($text, $confidence);
            }

            $chosen[] = $match;
            $parent = $match;
        }

        return $chosen === [] ? $this->blank() : $this->found($chosen, $text, $confidence);
    }

    /** @return array{state: string, value: mixed, text: string|null, confidence: int|null} */
    private function yesNo(string $text, int $confidence): array
    {
        $word = OcrText::normal($text);

        return match ($word) {
            'yes', 'y', 'oo', 'true' => $this->found(true, $text, $confidence),
            'no', 'n', 'hindi', 'false' => $this->found(false, $text, $confidence),
            default => $this->unreadable($text, $confidence),
        };
    }

    /**
     * @param  list<string|null>|null  $parts
     * @return list<int|null>
     */
    private function ints(?array $parts, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $part = $parts[$i] ?? null;
            $out[] = $part === null ? null : $this->int($part);
        }

        return $out;
    }

    private function int(string $part): ?int
    {
        $digits = strtr(str_replace(' ', '', $part), self::DIGIT_LOOKALIKES);

        return preg_match('/^\d+$/', $digits) === 1 ? (int) $digits : null;
    }

    /** @param  list<OcrSymbol>  $symbols */
    private function confidenceOf(array $symbols): int
    {
        if ($symbols === []) {
            return 0;
        }

        return (int) round(100 * min(array_map(static fn (OcrSymbol $s): float => $s->confidence, $symbols)));
    }

    /**
     * @param  list<OcrLine>  $answer
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null}
     */
    private function blankOrUnreadable(array $answer): array
    {
        if ($answer === []) {
            return $this->blank();
        }

        $symbols = [];
        foreach ($answer as $line) {
            foreach ($line->words as $word) {
                array_push($symbols, ...$word->symbols);
            }
        }

        return $this->unreadable(implode(' ', array_map(static fn (OcrLine $l): string => $l->text(), $answer)), $this->confidenceOf($symbols));
    }

    /** @return array{state: string, value: mixed, text: string|null, confidence: int|null} */
    private function found(mixed $value, string $text, int $confidence): array
    {
        return ['state' => 'read', 'value' => $value, 'text' => $text, 'confidence' => $confidence];
    }

    /** @return array{state: string, value: mixed, text: string|null, confidence: int|null} */
    private function unreadable(string $text, int $confidence): array
    {
        return ['state' => 'unreadable', 'value' => null, 'text' => $text, 'confidence' => $confidence];
    }

    /** @return array{state: string, value: mixed, text: string|null, confidence: int|null} */
    private function blank(): array
    {
        return ['state' => 'blank', 'value' => null, 'text' => null, 'confidence' => null];
    }
}
