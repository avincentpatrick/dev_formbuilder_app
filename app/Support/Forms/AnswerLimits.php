<?php

declare(strict_types=1);

namespace App\Support\Forms;

/**
 * A question's answer limits (M243, `R-1da063f4`): how many words a text answer may hold, how many choices a
 * multi-select may hold, and which of its choices must stand alone. They are field settings in `config`, not rule
 * rows, because a rule row can sit in an any/all group and so is never absolute, and the respondent's screen
 * enforces these as it is filled in: a live word count, a box that cannot take a third tick, an "only answer"
 * that clears and locks the rest.
 *
 * ⛔ THE TYPESCRIPT ENGINE HAS A TWIN, AND THE GOLDEN VECTORS HOLD THEM TOGETHER. `resources/public-runtime/engine/
 * words.ts` counts words with the same separator list, and `resources/public-runtime/lib/schema-mapping.ts` reads
 * the bounds with the same whole-number rule, pinned by `tests/golden/validation/answer_rules.json`.
 */
final class AnswerLimits
{
    public const MIN_WORDS = 'min_words';

    public const MAX_WORDS = 'max_words';

    public const MIN_CHOICES = 'min_choices';

    public const MAX_CHOICES = 'max_choices';

    public const EXCLUSIVE = 'exclusive';

    /** The largest limit an author may set. The save door holds it; a larger stored value is still read as written. */
    public const CEILING = 100000;

    /**
     * What separates two words: the Unicode White_Space set, written out.
     *
     * ⛔ NEVER `\s`. Under the `u` flag PCRE's `\s` matches U+0085 and U+180E and JavaScript's does not, and JavaScript's
     * matches U+FEFF and PCRE's does not (measured, PHP 8.4.14 / PCRE 10.44 and Node 24), so a count built on `\s` would
     * pass in the browser and be refused on submit. The TypeScript twin lists the same code points.
     */
    private const SEPARATORS = '/[\x{0009}-\x{000D}\x{0020}\x{0085}\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}]+/u';

    /** How many words a text holds: the number of maximal runs of anything that is not a separator. */
    public static function words(string $text): int
    {
        $pieces = preg_split(self::SEPARATORS, $text);
        if ($pieces === false) {
            return 0; // not valid UTF-8, which no JSON request can carry
        }

        return count(array_filter($pieces, static fn (string $piece): bool => $piece !== ''));
    }

    /**
     * A limit from `config`, or null when it is unset or not a whole number of at least 1. An int, a float with no
     * fraction, and a string of digits are read; anything else is no limit at all, in both engines, so the two never
     * read one stored value two ways.
     *
     * @param  array<string, mixed>|null  $config
     */
    public static function bound(?array $config, string $key): ?int
    {
        $raw = $config[$key] ?? null;

        $value = match (true) {
            is_int($raw) => $raw,
            is_float($raw) && is_finite($raw) && floor($raw) === $raw && $raw <= PHP_INT_MAX => (int) $raw,
            is_string($raw) && preg_match('/^[0-9]+$/', $raw) === 1 => (int) $raw,
            default => null,
        };

        return $value !== null && $value >= 1 ? $value : null;
    }

    /**
     * The "only answer" choices of a multi-select: value => its label (the value when it has none), in option order.
     *
     * @param  array<string, mixed>|null  $config
     * @return array<string, string>
     */
    public static function exclusiveOptions(?array $config): array
    {
        $out = [];
        $options = $config['options'] ?? null;
        if (! is_array($options)) {
            return $out;
        }

        foreach ($options as $option) {
            if (! is_array($option) || ($option[self::EXCLUSIVE] ?? null) !== true) {
                continue;
            }
            $value = $option['value'] ?? null;
            if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
                continue;
            }
            $label = $option['label'] ?? null;
            $out[(string) $value] = is_string($label) && $label !== '' ? $label : (string) $value;
        }

        return $out;
    }
}
