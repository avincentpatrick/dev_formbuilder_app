<?php

declare(strict_types=1);

namespace App\Services\Xlsform;

/**
 * An imported workbook's languages, read as locale codes (M244, `R-49262cca`).
 *
 * ⛔ ODK AND KOBO NAME A LANGUAGE; THIS PRODUCT KEYS IT BY CODE. A Kobo workbook says `default_language: English`
 * and heads its columns `label::English (en)`. The import copied both as written, so the form's locale became
 * `English` and the guest page carried `<html lang="English">`, which axe refuses (`html-lang-valid`) and a screen
 * reader cannot use. Every other part of the product reads a locale as a code (`Intl.DisplayNames` in the language
 * switcher, `forms.default_locale` is `varchar(10)`), so the language is resolved to a code here, once, for the form
 * and for every translation column.
 *
 * ⚠️ AND THE DEFAULT LANGUAGE CHOOSES THE BASE TEXT. In ODK the `default_language` names which `label::X` column is
 * the label when there is no bare `label` column, which is how Kobo exports. The parser read only the bare column, so
 * such a workbook stored every label as `''` and the text under the key `English`; it displayed only because the
 * locale was also `English`. Resolving the locale alone would have blanked every label, so {@see self::text()} picks
 * the base column too.
 *
 * Resolution order for one language as written: `Name (code)` takes the code; then a fixed table of names, kept to
 * names with one unambiguous code (Bikol and Bisaya are left out: each names more than one language); then a bare
 * code. Anything else is kept as written, with a warning.
 */
final class XlsformLanguages
{
    /** Language names (lower case, English and native spellings) whose code is unambiguous. */
    private const NAMES = [
        'english' => 'en',
        'filipino' => 'fil',
        'tagalog' => 'tl',
        'cebuano' => 'ceb',
        'ilocano' => 'ilo',
        'ilokano' => 'ilo',
        'hiligaynon' => 'hil',
        'waray' => 'war',
        'kapampangan' => 'pam',
        'pangasinan' => 'pag',
        'chavacano' => 'cbk',
        'french' => 'fr',
        'français' => 'fr',
        'francais' => 'fr',
        'spanish' => 'es',
        'español' => 'es',
        'espanol' => 'es',
        'portuguese' => 'pt',
        'português' => 'pt',
        'portugues' => 'pt',
        'arabic' => 'ar',
        'swahili' => 'sw',
        'kiswahili' => 'sw',
        'german' => 'de',
        'deutsch' => 'de',
        'italian' => 'it',
        'dutch' => 'nl',
        'russian' => 'ru',
        'turkish' => 'tr',
        'persian' => 'fa',
        'hindi' => 'hi',
        'bengali' => 'bn',
        'bangla' => 'bn',
        'urdu' => 'ur',
        'nepali' => 'ne',
        'indonesian' => 'id',
        'malay' => 'ms',
        'vietnamese' => 'vi',
        'thai' => 'th',
        'burmese' => 'my',
        'khmer' => 'km',
        'lao' => 'lo',
        'japanese' => 'ja',
        'korean' => 'ko',
        'chinese' => 'zh',
        'amharic' => 'am',
        'hausa' => 'ha',
        'yoruba' => 'yo',
        'igbo' => 'ig',
        'somali' => 'so',
    ];

    /** A BCP 47-shaped code: a two- or three-letter language, then any subtags. `_` is read as `-`. */
    private const CODE = '/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*$/';

    /** The longest code `forms.default_locale` (varchar 10) holds. */
    private const MAX_CODE_LENGTH = 10;

    /** The default language as the workbook writes it (the setting, or the first `label::X` column's X). */
    private readonly ?string $defaultWritten;

    /** Whether the workbook names a default language at all (the setting, or a `label::X`-only survey). */
    private readonly bool $hasDefault;

    /** @var array<string, true> languages already warned about, so each is named once */
    private array $warned = [];

    /**
     * @param  ?string  $defaultLanguage  the settings sheet's `default_language`, as written
     * @param  list<string>  $surveyHeaders  the survey sheet's headers, to find the base column when there is no setting
     */
    public function __construct(?string $defaultLanguage, array $surveyHeaders)
    {
        $default = $defaultLanguage !== null && trim($defaultLanguage) !== '' ? trim($defaultLanguage) : null;

        if ($default === null && ! $this->hasHeader($surveyHeaders, 'label')) {
            foreach ($surveyHeaders as $header) {
                if (str_starts_with(strtolower($header), 'label::')) {
                    $default = trim(substr($header, 7));
                    break;
                }
            }
        }

        $this->defaultWritten = $default !== '' ? $default : null;
        $this->hasDefault = $this->defaultWritten !== null;
    }

    /**
     * The locale code for one language as written, or null when it cannot be read as one.
     */
    public static function code(string $written): ?string
    {
        $written = trim($written);
        if ($written === '') {
            return null;
        }

        if (preg_match('/\(\s*([A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})*)\s*\)\s*$/u', $written, $match) === 1) {
            return self::normalize($match[1]);
        }

        $name = self::NAMES[mb_strtolower($written)] ?? null;
        if ($name !== null) {
            return $name;
        }

        return preg_match(self::CODE, $written) === 1 ? self::normalize($written) : null;
    }

    /**
     * The form's locale: the default language's code, or null to leave the form's own.
     *
     * @param  list<string>  $warnings
     */
    public function locale(array &$warnings): ?string
    {
        if (! $this->hasDefault || $this->defaultWritten === null) {
            return null;
        }

        $code = self::code($this->defaultWritten);
        if ($code === null) {
            $warnings[] = "The form's default language “{$this->defaultWritten}” is not a language this app can name by its code, so the form's language was left as it was.";
        }

        return $code;
    }

    /**
     * A row's text in `$prefix` (`label`, `hint`, `constraint_message`, `required_message`): the base text, and the
     * other languages keyed by code.
     *
     * The base is the bare column when it has text; otherwise the default language's column (as written, then by
     * code); otherwise the first language column with text. A language whose code cannot be read keeps its name as
     * the key, with one warning per language.
     *
     * @param  array<string, ?string>  $row
     * @param  list<string>  $warnings
     * @return array{0: ?string, 1: array<string, string>}
     */
    public function text(array $row, string $prefix, array &$warnings): array
    {
        $needle = strtolower($prefix);
        $bare = null;
        /** @var array<string, string> $byLanguage language as written → text */
        $byLanguage = [];

        foreach ($row as $header => $value) {
            if ($value === null || trim($value) === '') {
                continue;
            }
            $lower = strtolower((string) $header);
            if ($lower === $needle) {
                $bare = $value;
            } elseif (str_starts_with($lower, $needle.'::')) {
                $language = trim(substr((string) $header, strlen($needle) + 2));
                if ($language !== '') {
                    $byLanguage[$language] ??= $value;
                }
            }
        }

        $baseLanguage = null;
        if ($bare === null && $byLanguage !== []) {
            $baseLanguage = $this->baseLanguage(array_keys($byLanguage));
            $bare = $byLanguage[$baseLanguage];
        }

        $translations = [];
        foreach ($byLanguage as $language => $value) {
            if ($language === $baseLanguage) {
                continue;
            }
            $code = self::code($language);
            if ($code === null) {
                $this->warnUnreadable($language, $warnings);
                $code = $language;
            }
            $translations[$code] ??= $value;
        }

        return [$bare, $translations];
    }

    /**
     * Which of a row's language columns holds the base text.
     *
     * @param  non-empty-list<string>  $languages  as written
     */
    private function baseLanguage(array $languages): string
    {
        if ($this->defaultWritten !== null) {
            foreach ($languages as $language) {
                if (strcasecmp($language, $this->defaultWritten) === 0) {
                    return $language;
                }
            }

            $defaultCode = self::code($this->defaultWritten);
            if ($defaultCode !== null) {
                foreach ($languages as $language) {
                    if (self::code($language) === $defaultCode) {
                        return $language;
                    }
                }
            }
        }

        return $languages[0];
    }

    /**
     * @param  list<string>  $warnings
     */
    private function warnUnreadable(string $language, array &$warnings): void
    {
        if (isset($this->warned[$language])) {
            return;
        }
        $this->warned[$language] = true;
        $warnings[] = "The language “{$language}” is not one this app can name by its code; its translations were kept under that name.";
    }

    /**
     * @param  list<string>  $headers
     */
    private function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $header) {
            if (strcasecmp(trim($header), $name) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function normalize(string $code): ?string
    {
        $parts = explode('-', str_replace('_', '-', $code));
        $parts[0] = strtolower($parts[0]);
        $normalized = implode('-', $parts);

        return strlen($normalized) <= self::MAX_CODE_LENGTH ? $normalized : null;
    }
}
