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
 * left part reads as its label. Anchors are searched in printed order, so two questions with similar
 * labels cannot swap. A question's REGION is every line below its anchor down to the next anchored
 * question on the same page — a question never splits across pages, because the template forbids it
 * (`.q { page-break-inside: avoid }`).
 *
 * ── THE VERSION IS READ OFF THE PAGE ────────────────────────────────────────────────────────────────
 * Every page's running head carries the first eight characters of its version's checksum (§2.5.5). The
 * scan is matched against THAT version — a superseded one included, because paper in the field outlives a
 * republish — and only among the versions of the form the scan was uploaded to. When no stamp is read the
 * current published version is used and the result says so.
 *
 * Nothing here decides what a reviewer must look at; it reports what it read and how sure it was, and the
 * thresholds in `config/ocr.php` turn that into tiers.
 */
final class PrintedFormMatcher
{
    /** Printed areas that hold no answer: they are anchors for the questions around them, and nothing more. */
    private const array NOT_A_QUESTION = ['prose', 'page_break', 'omitted'];

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
     * The extraction for one scan against one version.
     *
     * @param  list<OcrPage>  $pages
     * @return array{fields: array<string, array<string, mixed>>, counts: array<string, int>, pages: int}
     */
    public function match(Form $form, FormVersion $version, array $pages): array
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
        $this->addStatic($static, 'Scans of this form can be read automatically.');

        $lines = [];
        foreach ($pages as $index => $page) {
            array_push($lines, ...$this->lines->lines($page, $index));
        }

        $anchors = $this->anchor($rows, $lines);

        $fields = [];
        $counts = ['read' => 0, 'blank' => 0, 'unreadable' => 0, 'not_found' => 0, 'skipped' => 0];
        $thresholds = $this->thresholds();

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
     * Find each question's label line, in printed order. A question whose key and label are both missed is
     * left unanchored; the search for the next one starts where the last found one was.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<OcrLine>  $lines
     * @return array<int, array{line: OcrLine, by: string}|null>
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

            $anchors[$i] = ['line' => $found['line'], 'by' => $found['by']];
            $from = $found['at'] + 1;
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

    /** Whether the left part of a line reads as the question's label, its required-marker and flag aside. */
    private function startsWithLabel(OcrLine $line, string $label): bool
    {
        $left = array_filter($line->words, static fn (OcrWord $w): bool => $w->x0 < 0.6);
        $text = implode(' ', array_map(static fn (OcrWord $w): string => $w->text, $left));
        $text = str_replace(['(if applicable)', '*'], '', $text);

        return OcrText::similarity($text, $label) >= 0.85;
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
     * @param  array<int, array{line: OcrLine, by: string}|null>  $anchors
     */
    private function nextAnchor(array $anchors, int $i): ?OcrLine
    {
        foreach ($anchors as $j => $anchor) {
            if ($j > $i && $anchor !== null) {
                return $anchor['line'];
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
            $confidence = $read['confidence'] ?? 0;
            $tier = match (true) {
                $confidence >= $thresholds['auto'] => 'auto',
                $confidence >= $thresholds['review'] => 'review',
                default => 'manual',
            };
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
            'page' => $page,
            'anchored_by' => $anchoredBy,
        ];
    }

    /** @return array{auto: int, review: int} */
    private function thresholds(): array
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
