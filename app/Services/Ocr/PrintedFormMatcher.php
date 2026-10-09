<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use App\Enums\FieldType;
use App\Models\Form;
use App\Models\FormVersion;
use App\Services\Forms\BlankFormPrintPresenter;

/**
 * Maps what a provider read off a scan back onto the printed form it was printed from (M128 — single-form
 * OCR groundwork 1, `docs/ocr-pipeline-design.md` §3).
 *
 * ── THE LAYOUT IS OURS, SO IT IS READ FROM THE SAME MODEL THAT PRINTED IT ───────────────────────────
 * Since I12 this product prints the paper it reads (§2.5), so nothing here guesses at a layout. The
 * expected page comes from {@see BlankFormPrintPresenter::present()}, the very render model the PDF was
 * typeset from, never a second description of it that could drift. `CapabilityFlags`' argument holds
 * here too: one input shape, one extraction, nothing to drift.
 *
 * ── HOW A QUESTION IS FOUND ─────────────────────────────────────────────────────────────────────────
 * Each printed question carries its field key, small and right-aligned on its label line (§2.5.4). A
 * question is ANCHORED on the line whose right end reads as that key, or failing that, on the line whose
 * left part reads as its label — after the printed question number, since layout 2. Anchors are searched
 * in printed order, so two questions with similar labels cannot swap. A question's REGION is every line
 * below its anchor down to the next anchored question on the same page — a question never splits across
 * pages, because the template forbids it (`.q { page-break-inside: avoid }`).
 *
 * ── THE VERSION AND THE LAYOUT ARE READ OFF THE PAGE ────────────────────────────────────────────────
 * Every page's running head carries the first eight characters of its version's checksum (§2.5.5) and,
 * since layout 2 (`M143`), the word "Layout" and the layout number beside it. The scan is matched against
 * THAT version — a superseded one included, because paper in the field outlives a republish — and only
 * among the versions of the form the scan was uploaded to. When no stamp is read the current published
 * version is used and the result says so. {@see layoutOf()} tells a sheet printed from an older layout
 * from one whose running head was merely not read.
 *
 * Nothing here decides what a reviewer must look at; it reports what it read and how sure it was, and the
 * thresholds in `config/ocr.php` turn that into tiers.
 */
final class PrintedFormMatcher
{
    /**
     * Printed areas that hold no answer: they are anchors for the questions around them, and nothing more. Public because
     * the bake-off harness's answer template lists exactly the questions this class reads (M136).
     */
    public const array NOT_A_QUESTION = ['prose', 'page_break', 'omitted'];

    /** How many lines either side of a stamp read as its own line its label may sit (M149). */
    private const int STAMP_REACH = 2;

    public function __construct(
        private readonly BlankFormPrintPresenter $presenter,
        private readonly OcrLineBuilder $lines,
        private readonly OcrAnswerReader $reader,
    ) {}

    /**
     * Which of the form's versions the paper was printed from.
     *
     * @param  list<OcrPage>  $pages
     * @param  iterable<FormVersion>  $candidates  the form's published and superseded versions
     * @return array{version: FormVersion|null, stamp: string|null, matched_by: string}
     */
    public function resolveVersion(array $pages, iterable $candidates, ?FormVersion $fallback): array
    {
        $prefixes = [];
        foreach ($candidates as $version) {
            if (is_string($version->checksum) && strlen($version->checksum) >= 8) {
                $prefixes[strtolower(substr($version->checksum, 0, 8))] = $version;
            }
        }

        $tokens = $this->stampTokens($pages);

        foreach ($tokens as $token) {
            if (isset($prefixes[$token])) {
                return ['version' => $prefixes[$token], 'stamp' => $token, 'matched_by' => 'stamp'];
            }
        }

        // One character misread: accepted only when exactly ONE version is that close, so a near-miss can
        // never pick between two plausible versions.
        foreach ($tokens as $token) {
            $near = array_filter(array_keys($prefixes), fn (string $prefix): bool => $this->hamming($prefix, $token) === 1);
            if (count($near) === 1) {
                $prefix = array_values($near)[0];

                return ['version' => $prefixes[$prefix], 'stamp' => $token, 'matched_by' => 'stamp_near'];
            }
        }

        return ['version' => $fallback, 'stamp' => null, 'matched_by' => 'unconfirmed'];
    }

    /**
     * Which LAYOUT the paper was printed with, read off the running head's "Layout N" beside the stamp (layout 2,
     * `M143`, closing `R-d6546409`). The checksum stamp identifies the schema, this identifies the template, and
     * a sheet from an older template cannot be read against the current one.
     *
     * ⚠️ `absent` NEEDS POSITIVE EVIDENCE. A sheet whose running head was not read at all says nothing about its
     * layout, and refusing it would refuse every badly photographed NEW sheet — so the token's absence counts as
     * old paper only when the stamp's own line was legibly read without it. A "Layout" word with no digit after it
     * is `garbled`; no stamp and no token is `none`. Both are a warning for the reviewer, never a refusal.
     *
     * @param  list<OcrPage>  $pages
     * @param  string|null  $stamp  the stamp token `resolveVersion()` matched, lower-cased 8-hex
     * @return array{layout: int|null, evidence: 'token'|'garbled'|'absent'|'none'}
     */
    public function layoutOf(array $pages, ?string $stamp): array
    {
        $garbled = false;
        $stampLineSeen = false;

        foreach ($pages as $index => $page) {
            foreach ($this->lines->lines($page, $index) as $line) {
                $keys = array_map(static fn (OcrWord $w): string => OcrText::key($w->text), $line->words);

                foreach ($keys as $i => $key) {
                    if ($key === 'layout' || (str_starts_with($key, 'layout') && strlen($key) > 6)) {
                        $digits = $key === 'layout' ? ($keys[$i + 1] ?? '') : substr($key, 6);
                        if (preg_match('/^\d{1,2}$/', $digits) === 1) {
                            return ['layout' => (int) $digits, 'evidence' => 'token'];
                        }
                        $garbled = true;
                    }
                }

                if ($stamp !== null) {
                    foreach ($keys as $i => $key) {
                        if ($key === $stamp || $key.($keys[$i + 1] ?? '') === $stamp) {
                            $stampLineSeen = true;
                        }
                    }
                }
            }
        }

        if ($garbled) {
            return ['layout' => null, 'evidence' => 'garbled'];
        }

        return ['layout' => null, 'evidence' => $stampLineSeen ? 'absent' : 'none'];
    }

    /**
     * The extraction for one scan against one version.
     *
     * `$thresholds` defaults to `config/ocr.php`. The bake-off harness (M136) passes zero for both, so that no value is
     * withheld and it can apply every threshold it sweeps itself, through {@see tier()}.
     *
     * @param  list<OcrPage>  $pages
     * @param  array{auto: int, review: int}|null  $thresholds
     * @return array{fields: array<string, array<string, mixed>>, counts: array<string, int>, pages: int}
     */
    public function match(Form $form, FormVersion $version, array $pages, ?array $thresholds = null): array
    {
        $model = $this->presenter->present($form, $version);
        $snapshotFields = $this->snapshotFields($version);

        $rows = [];
        $static = [];
        foreach ((array) ($model['blocks'] ?? []) as $block) {
            if (! is_array($block)) {
                continue;
            }
            foreach (['label', 'description'] as $key) {
                $this->addStatic($static, $block[$key] ?? null);
            }
            foreach ((array) ($block['fields'] ?? []) as $row) {
                if (! is_array($row) || ! is_string($row['key'] ?? null)) {
                    continue;
                }
                $this->addStatic($static, $row['hint'] ?? null);
                if (in_array($row['area'] ?? null, self::NOT_A_QUESTION, true)) {
                    $this->addStatic($static, $row['label'] ?? null);

                    continue;
                }
                $rows[] = $row;
            }
        }
        $this->addStatic($static, '(if applicable)');
        $this->addStatic($static, 'Not collected on paper - record this in the app.');
        $this->addStatic($static, 'This is a blank copy of every question in version '.$version->version_number.'. Questions marked "(if applicable)" depend on earlier answers.');
        // All three sentences the footer chooses between (`R-6bbf9d73`): the footer lands inside the last
        // question's region, so a sentence unknown here reads as that question's answer. The instruction
        // banner is deliberately NOT here — it sits above every anchor, and its sample line normalises to the
        // same words as a real "X Yes" answer line, which `isStatic()`'s substring rule would then blank.
        $this->addStatic($static, 'Scans of this form can be read automatically.');
        $this->addStatic($static, 'Scanning is switched off for this form, so responses must be keyed in.');
        $this->addStatic($static, 'Scans of this form cannot be read automatically; responses must be keyed in.');

        $perPage = [];
        foreach ($pages as $index => $page) {
            $perPage[$index] = $this->lines->lines($page, $index);
        }

        $lines = [];
        foreach ($this->printedOrder($rows, $perPage) as $index) {
            array_push($lines, ...$perPage[$index]);
        }

        $anchors = $this->anchor($rows, $lines);

        $fields = [];
        $counts = ['read' => 0, 'blank' => 0, 'unreadable' => 0, 'not_found' => 0, 'skipped' => 0];
        $thresholds ??= self::configuredThresholds();

        foreach ($rows as $i => $row) {
            $key = (string) $row['key'];
            $snapshot = $snapshotFields[$key] ?? [];
            $type = FieldType::tryFrom((string) ($snapshot['field_type'] ?? ''));
            /** @var array<string, mixed> $config */
            $config = is_array($snapshot['config'] ?? null) ? $snapshot['config'] : [];

            $anchor = $anchors[$i] ?? null;
            if ($anchor === null) {
                $fields[$key] = $this->result($type, ['state' => 'not_found', 'value' => null, 'text' => null, 'confidence' => null], null, null, $thresholds);
                $counts['not_found']++;

                continue;
            }

            $region = $this->region($lines, $anchor['line'], $this->nextAnchor($anchors, $i));
            $read = $this->reader->read($row, $type, $config, $region, $static);
            $fields[$key] = $this->result($type, $read, $anchor['line']->page, $anchor['by'], $thresholds);
            $counts[$read['state']] = ($counts[$read['state']] ?? 0) + 1;
        }

        return ['fields' => $fields, 'counts' => $counts, 'pages' => count($pages)];
    }

    /**
     * The pages in the order they were printed, whatever order they were uploaded in (M152, `R-4aaf3b6f`).
     *
     * {@see anchor()} looks for each question only after the one before it, so a second sheet uploaded first had every
     * question on it looked for after the first sheet's, and all of them came back "not found" — measured on the bake-off's
     * fifteen forms with their pages reversed: thirty. The running head prints no page number (§2.5), so a page is placed by
     * the first question it holds when searched on its own. A page holding none (a blank back, an unread photo) keeps its
     * upload place after the rest; `usort` is stable, so ties do too.
     *
     * Each line keeps its UPLOAD index as its page, so a result still names the page the review screen shows under that number.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, list<OcrLine>>  $perPage  upload index => that page's lines
     * @return list<int> upload indexes, in printed order
     */
    private function printedOrder(array $rows, array $perPage): array
    {
        $first = [];
        foreach ($perPage as $index => $lines) {
            $first[$index] = PHP_INT_MAX;
            foreach ($this->anchor($rows, $lines) as $i => $anchor) {
                if ($anchor !== null) {
                    $first[$index] = $i;
                    break;
                }
            }
        }

        $order = array_keys($perPage);
        usort($order, static fn (int $a, int $b): int => $first[$a] <=> $first[$b]);

        return $order;
    }

    /**
     * Find each question's label line, in printed order. A question whose key and label are both missed is
     * left unanchored; the search for the next one starts where the last found one was.
     *
     * Each anchor is a pair (M149): `line`, below which the question's answer begins, and `top`, above which the
     * question before it ends. They are one line unless the key stamp was read as a line of its own — see below.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<OcrLine>  $lines
     * @return array<int, array{line: OcrLine, top: OcrLine, by: string}|null>
     */
    private function anchor(array $rows, array $lines): array
    {
        $anchors = [];
        $from = 0;

        foreach ($rows as $i => $row) {
            $found = null;
            $key = OcrText::key((string) $row['key']);
            $label = is_string($row['label'] ?? null) ? $row['label'] : '';

            // By key: the stamp is right-aligned, so read the line's right-hand words.
            for ($j = $from, $n = count($lines); $j < $n && $found === null; $j++) {
                if ($this->endsWithKey($lines[$j], $key)) {
                    $found = ['line' => $lines[$j], 'by' => 'key', 'at' => $j];
                }
            }

            // By label, only where the key was not read.
            for ($j = $from, $n = count($lines); $j < $n && $found === null && $label !== ''; $j++) {
                if ($this->startsWithLabel($lines[$j], $label)) {
                    $found = ['line' => $lines[$j], 'by' => 'label', 'at' => $j];
                }
            }

            if ($found === null) {
                $anchors[$i] = null;

                continue;
            }

            // M149: the stamp is smaller than the label and printed at the other margin, and on about half the
            // bake-off's photos it clustered as a line of its own a few thousandths of the page above its label.
            // Anchored on the stamp alone, the answer took the question's own label ("2. Age 29") and ran on to the
            // next label, keeping the next stamp. So the question spans both lines: its answer begins below the
            // LOWER of the two, and the question before it ends above the UPPER one.
            $start = $found['at'];
            $top = $found['at'];
            if ($found['by'] === 'key' && $label !== '') {
                $n = count($lines);
                for ($k = max($from, $found['at'] - self::STAMP_REACH); $k <= $found['at'] + self::STAMP_REACH && $k < $n; $k++) {
                    if ($k !== $found['at'] && $lines[$k]->page === $found['line']->page && $this->startsWithLabel($lines[$k], $label)) {
                        $start = max($start, $k);
                        $top = min($top, $k);
                        break;
                    }
                }
            }

            $anchors[$i] = ['line' => $lines[$start], 'top' => $lines[$top], 'by' => $found['by']];
            $from = $start + 1;
        }

        return $anchors;
    }

    /** Whether the right end of a line reads as `$key` (letters and digits only; the underscore is often lost). */
    private function endsWithKey(OcrLine $line, string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $tail = '';
        for ($w = count($line->words) - 1; $w >= 0 && $w >= count($line->words) - 4; $w--) {
            $word = $line->words[$w];
            if ($word->x0 < 0.5) {
                break; // the stamp sits at the right margin; the label is at the left
            }
            $tail = OcrText::key($word->text).$tail;
            if ($tail === $key) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the left part of a line reads as the question's label, its required-marker and flag aside.
     *
     * Since layout 2 (`M143`) every question is numbered, "2. Age", and the number is printed before the label
     * rather than inside it. It is compared both ways — with the leading number stripped and as read — because
     * a label that genuinely starts with a digit ("2nd visit") must still anchor when its printed number is lost.
     */
    private function startsWithLabel(OcrLine $line, string $label): bool
    {
        $left = array_filter($line->words, static fn (OcrWord $w): bool => $w->x0 < 0.6);
        $text = implode(' ', array_map(static fn (OcrWord $w): string => $w->text, $left));
        $text = str_replace(['(if applicable)', '*'], '', $text);
        $bare = (string) preg_replace('/^\s*\d{1,3}[.)]?\s*/', '', $text);

        return max(OcrText::similarity($text, $label), OcrText::similarity($bare, $label)) >= 0.85;
    }

    /**
     * The lines strictly below `$anchor` and above the next question's anchor, on the anchor's own page.
     *
     * @param  list<OcrLine>  $lines
     * @return list<OcrLine>
     */
    private function region(array $lines, OcrLine $anchor, ?OcrLine $next): array
    {
        $out = [];
        foreach ($lines as $line) {
            if ($line->page !== $anchor->page || ! $anchor->before($line)) {
                continue;
            }
            if ($next !== null && $next->page === $anchor->page && ! $line->before($next)) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    /**
     * Where the next found question begins: its upper line, stamp or label, so neither is read into this answer.
     *
     * @param  array<int, array{line: OcrLine, top: OcrLine, by: string}|null>  $anchors
     */
    private function nextAnchor(array $anchors, int $i): ?OcrLine
    {
        foreach ($anchors as $j => $anchor) {
            if ($j > $i && $anchor !== null) {
                return $anchor['top'];
            }
        }

        return null;
    }

    /**
     * One field's entry in the extraction: what was read, and how a reviewer should treat it.
     *
     * ⚠️ BELOW THE REVIEW THRESHOLD THE VALUE IS WITHHELD, NOT MERELY FLAGGED (`docs/ocr-pipeline-design.md`
     * §3): a wrong value clicked past is worse than an empty field. The text that was read stays, so the
     * reviewer can see what the machine saw.
     *
     * @param  array{state: string, value: mixed, text: string|null, confidence: int|null}  $read
     * @param  array{auto: int, review: int}  $thresholds
     * @return array<string, mixed>
     */
    private function result(?FieldType $type, array $read, ?int $page, ?string $anchoredBy, array $thresholds): array
    {
        $tier = null;
        $value = $read['value'];

        if ($read['state'] === 'read') {
            $tier = self::tier($read['confidence'] ?? 0, $thresholds);
            if ($tier === 'manual') {
                $value = null;
            }
        }

        return [
            'type' => $type?->value,
            'state' => $read['state'],
            'value' => $value,
            'text' => $read['text'],
            'confidence' => $read['confidence'],
            'tier' => $tier,
            // Counted from 1, as the review screen numbers its images and every fixture writes it (M152): a line's page is its
            // index in the upload, from 0, and the review note printed "(page 0)" beside the image labelled "Page 1".
            'page' => $page === null ? null : $page + 1,
            'anchored_by' => $anchoredBy,
        ];
    }

    /**
     * How a reviewer treats a value read at `$confidence`: `auto` at or above the auto threshold, `review` at or above
     * the review one, `manual` (withheld) below both. The one copy of the rule — the matcher and the bake-off harness's
     * threshold sweep both call it, so they cannot disagree about a boundary.
     *
     * @param  array{auto: int, review: int}  $thresholds
     */
    public static function tier(int $confidence, array $thresholds): string
    {
        return match (true) {
            $confidence >= $thresholds['auto'] => 'auto',
            $confidence >= $thresholds['review'] => 'review',
            default => 'manual',
        };
    }

    /** @return array{auto: int, review: int} */
    public static function configuredThresholds(): array
    {
        return [
            'auto' => (int) config('ocr.confidence.auto', 90),
            'review' => (int) config('ocr.confidence.review', 70),
        ];
    }

    /**
     * The version's own field entries by key — the type and config the presenter's rows do not carry.
     *
     * @return array<string, array<string, mixed>>
     */
    private function snapshotFields(FormVersion $version): array
    {
        $fields = [];
        foreach ((array) ($version->schema_snapshot['fields'] ?? []) as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                /** @var array<string, mixed> $field */
                $fields[$field['key']] = $field;
            }
        }

        return $fields;
    }

    /**
     * Candidate stamps: every word, and every pair of neighbouring words run together (a stamp can split),
     * that is eight characters of hexadecimal once lower-cased.
     *
     * @param  list<OcrPage>  $pages
     * @return list<string>
     */
    private function stampTokens(array $pages): array
    {
        $tokens = [];
        foreach ($pages as $index => $page) {
            foreach ($this->lines->lines($page, $index) as $line) {
                $words = array_map(static fn (OcrWord $w): string => strtolower($w->text), $line->words);
                foreach ($words as $w => $word) {
                    foreach ([$word, $word.($words[$w + 1] ?? '')] as $candidate) {
                        if (preg_match('/^[0-9a-f]{8}$/', $candidate) === 1) {
                            $tokens[] = $candidate;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($tokens));
    }

    private function hamming(string $a, string $b): int
    {
        if (strlen($a) !== strlen($b)) {
            return PHP_INT_MAX;
        }

        $distance = 0;
        for ($i = 0, $n = strlen($a); $i < $n; $i++) {
            if ($a[$i] !== $b[$i]) {
                $distance++;
            }
        }

        return $distance;
    }

    /** @param  list<string>  $static */
    private function addStatic(array &$static, mixed $text): void
    {
        if (is_string($text)) {
            $normal = OcrText::normal($text);
            if ($normal !== '') {
                $static[] = $normal;
            }
        }
    }
}
