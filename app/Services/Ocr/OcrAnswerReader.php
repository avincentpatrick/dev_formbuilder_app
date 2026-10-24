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
 * (something written in which nothing usable was found), or `skipped`. `confidence` is the LOWEST character
 * confidence among what was used, on a 0–100 scale: one doubtful character is enough to make a reviewer look.
 * A tick is the exception: at least {@see MARK_SEEN} (M152).
 *
 * ⚠️ WHAT DOES NOT PARSE IS FILLED IN WITH THE NEAREST ANSWER THE BOX CAN HOLD, AND MARKED (`D117`, M167). A weight
 * written "72." read as `72 .`, a year read one digit short (`12 11 190`), "50 kg", a date whose groups were not
 * placed cleanly, two ticks on a one-answer question: until M167 each came back `unreadable` with no value, and
 * the reviewer typed it from nothing. The user: "its always better to have an initial value rather than user
 * will type everything from 0." Such a result is `read`, carries `repaired: true`, keeps the text exactly as
 * seen, and its confidence is capped at {@see SUBSTITUTION_CEILING} so it is never auto-accepted: the review
 * screen quotes what was seen beside it. Only an answer in which nothing usable was found stays `unreadable`.
 *
 * Every value is emitted in the shape `StructuralAnswerNormalizer` accepts for the type, so the review
 * screen can hand it to the pipeline unchanged.
 */
final class OcrAnswerReader
{
    /** OCR's usual letter-for-digit confusions, applied to a digit answer — combed, or in an open box since layout 3. */
    private const array DIGIT_LOOKALIKES = ['O' => '0', 'o' => '0', 'D' => '0', 'I' => '1', 'l' => '1', 'i' => '1'];

    /**
     * A recognised substitution makes the value something a reviewer should see, whatever its confidence — and so does a
     * value filled in as the nearest one the box can hold (`D117`).
     */
    private const int SUBSTITUTION_CEILING = 89;

    /**
     * The least likeness ({@see OcrText::similarity()}) at which a written word is taken as the nearest option (`D117`): a
     * yes/no read "YEB", a cascade level one letter off. Two letters swapped are two edits, so "Yse" (0.33) is not. Below it nothing usable was seen, and the answer stays unreadable.
     */
    private const float NEAREST_OPTION = 0.5;

    /** The words a yes/no answer is written as, with the value each means. */
    private const array YES_NO_WORDS = ['yes' => true, 'y' => true, 'oo' => true, 'true' => true, 'no' => false, 'n' => false, 'hindi' => false, 'false' => false];

    /** The date, time and datetime: groups of digits under captions, which {@see positional()} brings to a real value. */
    private const array POSITIONAL_VALUES = [FieldType::Date, FieldType::Time, FieldType::Datetime];

    /**
     * The least confidence of a tick seen in the box before an option's label, whatever glyph the recognizer took it for (M152,
     * `R-39a5388f`). Vision returns a hand-drawn tick as ☑, X, a Greek chi or 区 and is unsure of the GLYPH, which is not the
     * question: 16 of the bake-off's Round 1 ticks were read right and withheld for it. Inside the review band, never at auto:
     * a multi-select's other boxes may hold ticks Vision never returned (20 of Round 1's 22 blank tick questions), so a mark
     * alone cannot vouch for the whole answer. (`M152` gave Round 1's one wrong tick as that case; `M158` measured it as a mark
     * Vision DID return and the reader dropped, `R-b2d00ba2` — the floor stands on the 20.) Public so a test can hold it
     * between `config('ocr.confidence')`'s two thresholds.
     */
    public const int MARK_SEEN = 80;

    /**
     * How far right of a stray mark, as a fraction of the page's width, an option's label may begin and still own it (M158,
     * `R-b2d00ba2`): about a centimetre on A4. The paper prints a label 19pt after its box starts (3% of a 595pt page); a
     * phone photo shows the page smaller than the frame, so this is generous, and the nearest label with nothing between wins.
     */
    private const float ORPHAN_REACH = 0.05;

    /**
     * @param  array<string, mixed>  $row  one printed row from `BlankFormPrintPresenter::present()`
     * @param  array<string, mixed>  $config  the field's `config` from the version snapshot
     * @param  list<OcrLine>  $lines  the question's region, top to bottom
     * @param  list<string>  $static  normalised printed text that may sit in a region and is not an answer
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true, labels?: list<array{value: string, label: string, word: OcrWord, page: int}>}
     *                                                                                                                                                                                    `labels`, on a question whose boxes were found, is each option's label as found — its first word and its
     *                                                                                                                                                                                    page (M159) — for the ink in its box to be measured beside it; nothing stores it
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
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true}
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

        // A date, a time, a datetime and a duration are digits under captions, and since layout 4 (`M148`) their gaps
        // print `/` and `:` — the paper's, never the answer's. A cascade level is a written word and keeps its hyphen.
        $positional = in_array($type, [FieldType::Date, FieldType::Time, FieldType::Datetime, FieldType::Duration], true);

        $answer = $this->answerLines($lines, $static, $captionLine === null ? [] : [$captionLine]);
        $symbols = $answer === [] ? [] : $this->combSymbols($answer[0], $positional);

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

        // A group can never hold more digits than it has boxes. More means something in a gap was read as a digit —
        // a separator misread as `1`, or a pen's mark — and the nearer group took it: `01` became `011`, November,
        // at the recognizer's own confidence (`M148`). M148 refused it; since `D117` the box gets the nearest real
        // value instead ({@see placedAgain()}), marked, and never at the recognizer's own confidence.
        if (in_array($type, self::POSITIONAL_VALUES, true) && ($parts === null || $this->overfills($parts, $groups))) {
            return $this->placedAgain($type, $parts, $symbols, $groups, $confidence);
        }

        // A cascade whose levels could not be placed: its written words, split where the boxes were left empty.
        if ($parts === null && $type === FieldType::CascadingSelect) {
            $parts = array_pad(explode(' ', $this->joinWithGaps($symbols)), count($groups), null);
        }

        if ($parts === null || ($positional && $this->overfills($parts, $groups))) {
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
     * The characters written in a comb row, left to right, without the box walls a recognizer reads as `|` — and,
     * for a positional comb, without the separators layout 4 prints in its gaps.
     *
     * @return list<OcrSymbol>
     */
    private function combSymbols(OcrLine $line, bool $dropSeparators): array
    {
        $symbols = [];
        foreach ($line->words as $word) {
            foreach ($word->symbols as $symbol) {
                if (OcrText::isBorderArtefact($symbol->text) || trim($symbol->text) === '') {
                    continue;
                }
                if ($dropSeparators && OcrText::isSeparator($symbol->text)) {
                    continue;
                }
                $symbols[] = $symbol;
            }
        }

        usort($symbols, static fn (OcrSymbol $a, OcrSymbol $b): int => $a->centerX() <=> $b->centerX());

        return $symbols;
    }

    /**
     * Whether any group was read with more characters than it has printed cells.
     *
     * @param  list<string|null>  $parts
     * @param  list<array{cells: int, caption: string|null}>  $groups
     */
    private function overfills(array $parts, array $groups): bool
    {
        foreach ($parts as $i => $part) {
            if ($part !== null && mb_strlen(str_replace(' ', '', $part)) > ($groups[$i]['cells'] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The nearest real value for a date, a time or a datetime whose groups could not be placed, or were read with more
     * characters than they have boxes (`D117`). The candidates, in order — the first that makes a value that exists as
     * it stands wins:
     *   1. the groups as placed, each over-full one cut back to its boxes ({@see cutBack()});
     *   2. every way of cutting the characters read into the printed groups ({@see splits()}) — and, when there is one
     *      character more than there are boxes, the same with each character left out in turn.
     * When none exists as it stands, the first that can be brought to one is ({@see nearest()}).
     *
     * @param  list<string|null>|null  $parts
     * @param  list<OcrSymbol>  $symbols
     * @param  list<array{cells: int, caption: string|null}>  $groups
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true}
     */
    private function placedAgain(FieldType $type, ?array $parts, array $symbols, array $groups, int $confidence): array
    {
        $text = $this->joinWithGaps($symbols);
        $cells = array_column($groups, 'cells');

        $candidates = $parts === null ? [] : $this->cutBack($parts, $cells);
        $characters = implode('', array_map(static fn (OcrSymbol $s): string => $s->text, $symbols));
        $splits = $this->splits($characters, $cells);
        if ($splits === [] && mb_strlen($characters) === array_sum($cells) + 1) {
            for ($i = 0, $n = mb_strlen($characters); $i < $n; $i++) {
                array_push($splits, ...$this->splits(mb_substr($characters, 0, $i).mb_substr($characters, $i + 1), $cells));
            }
        }
        array_push($candidates, ...$splits);

        foreach ($candidates as $candidate) {
            $value = $this->exact($type, $candidate);
            if ($value !== null) {
                return $this->repaired($value, $text, $confidence);
            }
        }

        foreach ($candidates as $candidate) {
            $value = $this->nearest($type, $candidate);
            if ($value !== null) {
                return $this->repaired($value, $text, $confidence);
            }
        }

        return $this->unreadable($text, $confidence);
    }

    /**
     * The groups as placed, each over-full one cut back to its boxes from the side that faces a neighbouring group: a gap's
     * character goes to the NEARER group (`M148`), so the first group's extra is at its end and the last group's at its
     * start. A middle group is cut at its end first, then at its start.
     *
     * @param  list<string|null>  $parts
     * @param  list<int>  $cells
     * @return list<list<string|null>>
     */
    private function cutBack(array $parts, array $cells): array
    {
        $variants = [[]];
        $last = count($parts) - 1;

        foreach ($parts as $i => $part) {
            $part = $part === null ? null : str_replace(' ', '', $part);
            $boxes = $cells[$i] ?? 0;
            $extra = $part === null ? 0 : mb_strlen($part) - $boxes;

            $options = [$part];
            if ($part !== null && $extra > 0) {
                // How many characters go from the start: none for the first group, all for the last, any for a middle one.
                $leading = $i === 0 ? [0] : ($i === $last ? [$extra] : range(0, $extra));
                $options = array_map(static fn (int $drop): string => mb_substr($part, $drop, $boxes), $leading);
            }

            $next = [];
            foreach ($variants as $variant) {
                foreach ($options as $option) {
                    $next[] = [...$variant, $option];
                }
            }
            $variants = $next;
        }

        return $variants;
    }

    /**
     * Every way of cutting `$characters` into the printed groups, in order, each group holding one character at least and
     * no more than its boxes — longest first.
     *
     * @param  list<int>  $cells
     * @return list<list<string>>
     */
    private function splits(string $characters, array $cells): array
    {
        $length = mb_strlen($characters);
        if ($cells === []) {
            return $length === 0 ? [[]] : [];
        }
        if ($length < count($cells) || $length > array_sum($cells)) {
            return [];
        }

        $out = [];
        $rest = array_slice($cells, 1);
        for ($take = min($cells[0], $length); $take >= 1; $take--) {
            foreach ($this->splits(mb_substr($characters, $take), $rest) as $tail) {
                $out[] = [mb_substr($characters, 0, $take), ...$tail];
            }
        }

        return $out;
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
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true}
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

        // M184: a linked multiple choice keeps its lines apart, because each line written is one choice.
        $glue = $type === FieldType::MultiSelect ? ', ' : ' ';
        $text = implode($glue, array_map(static fn (OcrLine $l): string => OcrText::withoutBorderArtefacts($l->text()), $answer));

        return $this->parsed($type, [], $text, null, $this->confidenceOf($symbols));
    }

    // ── choices ───────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $row
     * @param  list<OcrLine>  $lines
     * @param  list<string>  $static
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true, labels?: list<array{value: string, label: string, word: OcrWord, page: int}>}
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
        /** @var array<int, OcrWord> $labelStarts option index => the first word of its label */
        $labelStarts = [];
        /** @var array<int, int> $labelPages option index => the page its label was found on */
        $labelPages = [];
        /** @var array<int, true> $taken spl_object_id of every word a label or its walk back used */
        $taken = [];
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
                for ($w = $span['start'] + $span['length'] - 1; $w >= $span['start']; $w--) {
                    $taken[spl_object_id($words[$w])] = true;
                }
                // ⚠️ A WORD LED BY A SYMBOL MARK DOES NOT END THE WALK (M158, `R-b2d00ba2`). Round 1's sheet 06 came back
                // `Male ☑ ☑Fe Female` — the tick, then the tick again run into the label's first letters — and the walk
                // stopped at "☑Fe", losing a tick Vision had returned. Only a SYMBOL leading a word counts: a word that
                // begins with an x or a v is writing.
                for ($w = $span['start'] - 1; $w >= $end; $w--) {
                    $word = $words[$w];
                    if (OcrText::isMark($word->text)) {
                        $marks = [...$word->symbols, ...$marks];
                    } else {
                        $lead = $this->leadingSymbolMarks($word);
                        if ($lead === []) {
                            break;
                        }
                        $marks = [...$lead, ...$marks];
                    }
                    $taken[spl_object_id($word)] = true;
                }
                $found[$i] = $marks;
                $labelStarts[$i] = $words[$span['start']];
                $labelPages[$i] = $line->page;
                $end = $span['start'] + $span['length'];
            }
        }

        // ⚠️ A MARK NO OPTION TOOK IS CREDITED TO THE LABEL JUST TO ITS RIGHT, ON WHICHEVER LINE IT LANDED (M158,
        // `R-b2d00ba2`). Round 1's sheet 09 came back with `✓ Fever ☑` on one line and `Cough Headache Rash None` on the
        // next: the ☑ before "Rash" sat 0.08 px past the line builder's tolerance, so no label followed it on its line.
        // Fixed here rather than in `OcrLineBuilder`, whose lines every other question type is read from.
        $regionWords = array_merge(...array_map(static fn (OcrLine $l): array => $l->words, $lines));
        $orphaned = false;
        foreach ($regionWords as $word) {
            if (isset($taken[spl_object_id($word)]) || ! OcrText::isMark($word->text)) {
                continue;
            }
            $i = $this->orphanOwner($word, $labelStarts, $regionWords);
            if ($i !== null) {
                $found[$i] = [...$found[$i], ...$word->symbols];
                $orphaned = true;
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
        // choice question with no choices, so an empty list here can only mean a linked one. M184 (`R-f655f9b1`): a
        // multiple choice links the same way, and its writing is a list — one choice per line or comma.
        if ($options === [] && in_array($type, [FieldType::SingleSelect, FieldType::Dropdown, FieldType::MultiSelect], true)) {
            return $this->ruled($type, $this->answerLines($lines, $static, []));
        }

        if ($found === []) {
            return $this->blankOrUnreadable($this->answerLines($lines, $static, []), $type, $options);
        }

        $labels = [];
        foreach ($labelStarts as $i => $word) {
            $labels[] = ['value' => $options[$i]['value'], 'label' => $options[$i]['label'], 'word' => $word, 'page' => $labelPages[$i]];
        }

        if ($marked === []) {
            return $this->blank() + ['labels' => $labels];
        }

        $confidence = max($this->confidenceOf($markSymbols), self::MARK_SEEN);
        // A mark placed by geometry rather than read before its label is shown to a reviewer, however sure the glyph.
        if ($orphaned) {
            $confidence = min($confidence, self::SUBSTITUTION_CEILING);
        }
        $text = implode(', ', array_map(static fn (array $o): string => $o['label'], $marked));

        if ($type === FieldType::MultiSelect) {
            return $this->found(array_map(static fn (array $o): string => $o['value'], $marked), $text, $confidence) + ['labels' => $labels];
        }

        $value = $marked[0]['value'];
        $value = $type === FieldType::YesNo ? Coercion::yesNoAnswer($value) : $value;

        // `D117`: two boxes ticked on a one-answer question fill in the first, in printed order, and the text names every tick
        // seen — the reviewer changes one choice rather than making it from nothing.
        if (count($marked) > 1) {
            return $this->repaired($value, $text, $confidence) + ['labels' => $labels];
        }

        return $this->found($value, $text, $confidence) + ['labels' => $labels];
    }

    /**
     * Where the option labelled `$label` sits on a line: the first span of words at or after `$from` that reads as
     * the label, longest span first so "Not marked" is not taken for "marked".
     *
     * A mark run together with the label's first word ("XFemale"), which a recognizer does when the pen comes
     * close to the text, is tried BEFORE the plain comparison at each position: "xfemale" is within the plain
     * tolerance of "female", and taking it that way would lose the mark.
     *
     * ⚠️ A WORD THAT IS WHOLLY A MARK IS NEVER PART OF A SPAN (M149), unless the label itself holds that character
     * ("N/A", an option called "X"). The comparison is on normal forms, which erase symbols, so "✓ Male" read as
     * "male" and the longest-first search took the tick into the label — at its start, losing it from the walk back,
     * or at the END of the label before it ("Female ✓ Male" made "Female ✓" one span). Only a letter mark (x, v)
     * survived, which is why an `X` fixture never showed it, and 37 of the bake-off's answered choices read blank.
     *
     * @param  list<OcrWord>  $words
     * @return array{start: int, length: int, lead: OcrSymbol|null}|null
     */
    private function labelSpan(array $words, int $from, string $label): ?array
    {
        $n = count($words);
        $labelWords = count(preg_split('/\s+/', trim($label)) ?: []);
        $longest = max(1, $labelWords + 1);
        // A letter mark belongs to the label only when it IS the label ("X"); a symbol, when the label holds it ("N/A").
        $foreignMark = static fn (OcrWord $w): bool => OcrText::isMark($w->text) && (OcrText::normal($w->text) !== ''
            ? OcrText::normal($w->text) !== OcrText::normal($label)
            : mb_stripos($label, trim($w->text)) === false);

        for ($k = $from; $k < $n; $k++) {
            for ($length = min($longest, $n - $k); $length >= 1; $length--) {
                $slice = array_slice($words, $k, $length);
                if (array_filter($slice, $foreignMark) !== []) {
                    continue;
                }
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

    /**
     * The symbol marks a word begins with — the tick a recognizer ran into the next word ("☑Fe") — or none.
     *
     * @return list<OcrSymbol>
     */
    private function leadingSymbolMarks(OcrWord $word): array
    {
        $lead = [];
        foreach ($word->symbols as $symbol) {
            if (! OcrText::isSymbolMark($symbol->text)) {
                break;
            }
            $lead[] = $symbol;
        }

        return $lead;
    }

    /**
     * Which found option a mark no label took belongs to: the one whose label begins nearest to the mark's right, at a
     * height the mark shares, within {@see ORPHAN_REACH}, with no word between them — or none.
     *
     * @param  array<int, OcrWord>  $labelStarts
     * @param  list<OcrWord>  $regionWords
     */
    private function orphanOwner(OcrWord $mark, array $labelStarts, array $regionWords): ?int
    {
        $owner = null;
        $nearest = INF;
        foreach ($labelStarts as $i => $start) {
            $gap = $start->x0 - $mark->x1;
            if ($start->x0 < $mark->centerX() || $gap > self::ORPHAN_REACH || ! $this->shareHeight($mark, $start)) {
                continue;
            }
            foreach ($regionWords as $other) {
                if ($other !== $mark && $other !== $start && $this->shareHeight($mark, $other)
                    && $other->centerX() > $mark->x1 && $other->centerX() < $start->x0) {
                    continue 2;
                }
            }
            if ($gap < $nearest) {
                $owner = $i;
                $nearest = $gap;
            }
        }

        return $owner;
    }

    /** Whether two words' boxes overlap in height — they sit on one printed line, whatever line the builder put them on. */
    private function shareHeight(OcrWord $a, OcrWord $b): bool
    {
        return min($a->y1, $b->y1) > max($a->y0, $b->y0);
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
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true}
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
            FieldType::MultiSelect => $this->writtenChoices($text, $confidence),
            FieldType::Email => $this->found(strtolower(str_replace(' ', '', $text)), $text, $confidence),
            FieldType::Url => $this->found(strtoupper($text) === $text ? strtolower(str_replace(' ', '', $text)) : str_replace(' ', '', $text), $text, $confidence),
            default => $this->found($text, $text, $confidence),
        };
    }

    /** @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true} */
    private function number(string $text, int $confidence, bool $decimal): array
    {
        $raw = str_replace(' ', '', $text);
        $digits = strtr($raw, self::DIGIT_LOOKALIKES);
        if ($decimal) {
            $digits = str_replace(',', '.', $digits);
        }

        $pattern = $decimal ? '/^-?\d+(\.\d+)?$/' : '/^-?\d+$/';
        if (preg_match($pattern, $digits) === 1) {
            return $this->found($digits, $text, $digits === $raw ? $confidence : min($confidence, self::SUBSTITUTION_CEILING));
        }

        // `D117`: the first number written, whatever was written with it — "72 ." is 72, "65 KILOS" is 65 — and for a whole
        // number its whole part. Unreadable only when no digit was written at all.
        if (preg_match('/-?\d+(?:[.,]\d+)?/', $digits, $number) !== 1) {
            return $this->unreadable($text, $confidence);
        }
        $value = str_replace(',', '.', $number[0]);

        return $this->repaired($decimal ? $value : explode('.', $value)[0], $text, $confidence);
    }

    /**
     * @param  list<string|null>|null  $parts  DD, MM, YYYY
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true}
     */
    private function date(?array $parts, string $text, int $confidence): array
    {
        return $this->positional(FieldType::Date, $parts, $text, $confidence);
    }

    /**
     * @param  list<string|null>|null  $parts  HH, MM
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true}
     */
    private function time(?array $parts, string $text, int $confidence): array
    {
        return $this->positional(FieldType::Time, $parts, $text, $confidence);
    }

    /**
     * @param  list<string|null>|null  $parts  DD, MM, YYYY, HH, MM
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true}
     */
    private function datetime(?array $parts, string $text, int $confidence): array
    {
        return $this->positional(FieldType::Datetime, $parts, $text, $confidence);
    }

    /**
     * A date, a time or a datetime from its groups: as written when that exists, and otherwise the nearest that does
     * (`D117`) — a year read short kept as read (`190` is 0190), a month over 12 swapped with a day that can be one, a day
     * past its month's end brought back to it, an hour or a minute past its last brought back to it, a group with nothing
     * read in it at its lowest (a missing year is this year's). Unreadable only when no group holds a digit.
     *
     * Groups that were not placed under captions — an answer in an open box, a sheet printed before layout 3 — are cut
     * wherever a character is not a digit, and the value is always marked: nothing placed them.
     *
     * @param  list<string|null>|null  $parts
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true}
     */
    private function positional(FieldType $type, ?array $parts, string $text, int $confidence): array
    {
        $placed = $parts !== null;
        $parts ??= preg_split('/[^\dOoDIli]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $value = $this->exact($type, $parts);
        if ($value !== null) {
            return $placed ? $this->found($value, $text, $confidence) : $this->repaired($value, $text, $confidence);
        }

        $value = $this->nearest($type, $parts);

        return $value === null ? $this->unreadable($text, $confidence) : $this->repaired($value, $text, $confidence);
    }

    /**
     * The value the groups make exactly as written, or null when they make none that exists.
     *
     * @param  list<string|null>|null  $parts
     */
    private function exact(FieldType $type, ?array $parts): ?string
    {
        if ($type === FieldType::Time) {
            [$hour, $minute] = $this->ints($parts, 2);

            return $hour === null || $minute === null || $hour > 23 || $minute > 59 ? null : sprintf('%02d:%02d', $hour, $minute);
        }

        [$day, $month, $year, $hour, $minute] = $this->ints($parts, 5);
        if ($day === null || $month === null || $year === null || $year < 1000 || ! checkdate($month, $day, $year)) {
            return null;
        }
        $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
        if ($type === FieldType::Date) {
            return $date;
        }

        return $hour === null || $minute === null || $hour > 23 || $minute > 59 ? null : sprintf('%sT%02d:%02d', $date, $hour, $minute);
    }

    /**
     * The nearest value that exists to what the groups hold, reading only their digits (`8)` is 8) — or null when no
     * group holds one.
     *
     * @param  list<string|null>|null  $parts
     */
    private function nearest(FieldType $type, ?array $parts): ?string
    {
        if ($type === FieldType::Time) {
            [$hour, $minute] = $this->looseInts($parts, 2);

            return $this->nearestTime($hour, $minute);
        }

        [$day, $month, $year, $hour, $minute] = $this->looseInts($parts, 5);
        $date = $this->nearestDate($day, $month, $year);
        if ($type === FieldType::Date) {
            return $date;
        }

        $time = $this->nearestTime($hour, $minute);
        if ($date === null && $time === null) {
            return null;
        }

        return ($date ?? now()->format('Y-m-d')).'T'.($time ?? '00:00');
    }

    private function nearestDate(?int $day, ?int $month, ?int $year): ?string
    {
        if ($day === null && $month === null && $year === null) {
            return null;
        }

        if ($month !== null && $month > 12 && $day !== null && $day <= 12) {
            [$day, $month] = [$month, $day];
        }
        $year = max(1, min(9999, $year ?? now()->year));
        $month = max(1, min(12, $month ?? 1));
        $day = max(1, min(31, $day ?? 1));
        while (! checkdate($month, $day, $year)) {
            $day--;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function nearestTime(?int $hour, ?int $minute): ?string
    {
        if ($hour === null && $minute === null) {
            return null;
        }

        return sprintf('%02d:%02d', min(23, $hour ?? 0), min(59, $minute ?? 0));
    }

    /**
     * A duration is stored as a number of seconds (`docs/xlsform-interop-spec.md`'s `duration` row). Not filled in by
     * `D117`: no answer control shows a duration, so `OcrAnswerCarry` lists it rather than carrying it.
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
     * Each written level matched to an option at that level, under the option chosen one level up — or, since `D117`,
     * to the nearest such option when a level matches none exactly. A level near none ends the answer at the levels
     * already matched, as the screen control allows a shorter answer.
     *
     * @param  list<string|null>|null  $parts
     * @param  array<string, mixed>  $config
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true}
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
        $repaired = false;
        foreach ($levels as $i => $level) {
            $written = $parts[$i] ?? null;
            if ($written === null) {
                break; // a deeper level left empty ends the answer, as the screen control allows
            }

            /** @var array<string, string> $offered value => label, at this level under the one chosen above */
            $offered = [];
            foreach ((array) ($config['options'] ?? []) as $option) {
                if (! is_array($option) || ($option['level'] ?? null) !== $level) {
                    continue;
                }
                if ($parent !== null && ($option['parent'] ?? null) !== $parent) {
                    continue;
                }
                $value = $option['value'] ?? null;
                if (is_string($value)) {
                    $label = $option['label'] ?? null;
                    $offered[$value] = is_string($label) ? $label : $value;
                }
            }

            $match = null;
            foreach ($offered as $value => $label) {
                if (OcrText::normal($written) === OcrText::normal((string) $value) || OcrText::normal($written) === OcrText::normal($label)) {
                    $match = (string) $value;
                    break;
                }
            }

            if ($match === null) {
                $repaired = true;
                $match = $this->nearestOption($written, $offered);
                if ($match === null) {
                    break;
                }
            }

            $chosen[] = $match;
            $parent = $match;
        }

        if ($chosen === []) {
            return $repaired ? $this->unreadable($text, $confidence) : $this->blank();
        }

        return $repaired ? $this->repaired($chosen, $text, $confidence) : $this->found($chosen, $text, $confidence);
    }

    /** @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true} */
    private function yesNo(string $text, int $confidence): array
    {
        $word = OcrText::normal($text);
        if (array_key_exists($word, self::YES_NO_WORDS)) {
            return $this->found(self::YES_NO_WORDS[$word], $text, $confidence);
        }

        // `D117`: a word one letter off — "YEB", "N0" — is the nearest of them.
        $words = array_keys(self::YES_NO_WORDS);
        $nearest = $this->nearestOption($text, array_combine($words, $words));

        return $nearest === null ? $this->unreadable($text, $confidence) : $this->repaired(self::YES_NO_WORDS[$nearest], $text, $confidence);
    }

    /**
     * The option whose value or label is likest to what was written, at {@see NEAREST_OPTION} or more — the first on a tie
     * — or null when none is that close.
     *
     * @param  array<string, string>  $offered  value => label
     */
    private function nearestOption(string $written, array $offered): ?string
    {
        $best = null;
        $likeness = self::NEAREST_OPTION;
        foreach ($offered as $value => $label) {
            $score = max(OcrText::similarity($written, (string) $value), OcrText::similarity($written, $label));
            if ($score > $likeness || ($best === null && $score >= $likeness)) {
                $best = (string) $value;
                $likeness = $score;
            }
        }

        return $best;
    }

    /**
     * Each group's digits, whatever else was read with them, or null for a group with no digit.
     *
     * @param  list<string|null>|null  $parts
     * @return list<int|null>
     */
    private function looseInts(?array $parts, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; $i++) {
            $digits = (string) preg_replace('/\D/', '', strtr((string) ($parts[$i] ?? ''), self::DIGIT_LOOKALIKES));
            $out[] = $digits === '' ? null : (int) substr($digits, 0, 9);
        }

        return $out;
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
     * Writing in a tick question whose printed labels were not found at all. Since `D117` it is taken as the option whose
     * label it is likest to, when one is close enough ({@see nearestOption()}): the whole of what was written is compared,
     * so a region that holds every label misread reads as near none of them, and stays unreadable.
     *
     * @param  list<OcrLine>  $answer
     * @param  list<array{value: string, label: string}>  $options
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired?: true}
     */
    private function blankOrUnreadable(array $answer, ?FieldType $type, array $options): array
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
        $text = implode(' ', array_map(static fn (OcrLine $l): string => $l->text(), $answer));
        $confidence = $this->confidenceOf($symbols);

        $nearest = $this->nearestOption($text, array_column($options, 'label', 'value'));
        if ($nearest === null) {
            return $this->unreadable($text, $confidence);
        }

        return $this->repaired(match ($type) {
            FieldType::MultiSelect => [$nearest],
            FieldType::YesNo => Coercion::yesNoAnswer($nearest),
            default => $nearest,
        }, $text, $confidence);
    }

    /**
     * M184 (`R-f655f9b1`): a linked multiple choice's write-in box, as the list its answer is — split on the commas and
     * semicolons written and on the line breaks {@see ruled()} kept as commas, each piece trimmed, each kept once.
     *
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null}
     */
    private function writtenChoices(string $text, int $confidence): array
    {
        $choices = [];
        foreach (preg_split('/[,;]/u', $text) ?: [] as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && ! in_array($piece, $choices, true)) {
                $choices[] = $piece;
            }
        }

        return $choices === [] ? $this->unreadable($text, $confidence) : $this->found($choices, $text, $confidence);
    }

    /** @return array{state: string, value: mixed, text: string|null, confidence: int|null} */
    private function found(mixed $value, string $text, int $confidence): array
    {
        return ['state' => 'read', 'value' => $value, 'text' => $text, 'confidence' => $confidence];
    }

    /**
     * A value filled in as the nearest one the box can hold (`D117`): read, marked, kept with the text exactly as seen, and
     * never at a confidence that would accept it unseen.
     *
     * @return array{state: string, value: mixed, text: string|null, confidence: int|null, repaired: true}
     */
    private function repaired(mixed $value, string $text, int $confidence): array
    {
        return ['state' => 'read', 'value' => $value, 'text' => $text, 'confidence' => min($confidence, self::SUBSTITUTION_CEILING), 'repaired' => true];
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
