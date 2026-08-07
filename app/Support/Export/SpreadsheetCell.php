<?php

declare(strict_types=1);

namespace App\Support\Export;

use App\Services\Submissions\SubmissionRowProjector;
use App\Support\Connectors\Providers\GoogleSheetsConnector;
use DateInterval;
use DateTimeInterface;

/**
 * Neutralises spreadsheet formula injection (CSV injection) on the way out — Increment I8c, closing the
 * one row `docs/security-threat-model.md` still marked **Open**.
 *
 * ── THE ATTACK ────────────────────────────────────────────────────────────────────────────────────────
 * A respondent types `=cmd|' /C calc'!A0` into a text answer. Nothing happens in the product — it is a
 * string, rendered as a string everywhere. Then a reviewer exports and opens the file, and Excel or
 * LibreOffice sees a leading `=` and executes it **on the reviewer's machine**, with the reviewer's
 * privileges, outside anything this application can defend. It is the rare vulnerability whose payload
 * travels through a system that is behaving perfectly.
 *
 * ── THE FIX, AND WHY IT IS THE BORING ONE ─────────────────────────────────────────────────────────────
 * A leading apostrophe tells every major spreadsheet "this is text". The alternatives are worse: escaping
 * or stripping the character silently corrupts the respondent's actual answer (`-5` is a real answer, and
 * so is `+1 555 0100`), and refusing the input at collection time would reject legitimate data because of
 * what some *other* program might do with it later. Prefixing changes what the FILE says, not what the
 * record holds, which is the correct place to draw the line.
 *
 * ── ⚠️ THE `is_numeric` GUARD IS NOT DEFENSIVE PADDING ───────────────────────────────────────────────
 * `-5`, `-0.5` and `+40` all begin with a dangerous character and are all ordinary numbers. Prefixing
 * them would turn every negative figure in an export into the literal text `'-5`, breaking sums, charts
 * and every downstream analysis a reviewer runs — a data-integrity regression shipped in the name of
 * security. A well-formed number cannot carry a formula, so it is passed through untouched.
 *
 * ── ⚠️ WHERE THIS MUST **NOT** BE APPLIED ─────────────────────────────────────────────────────────────
 * NOT in {@see SubmissionRowProjector}, even though it looks like the one
 * shared seam that would cover everything. That projector is shared with
 * {@see GoogleSheetsConnector}, which is **already structurally safe**:
 * it appends with `valueInputOption=RAW`, so Sheets stores what it is given and never evaluates it.
 * Sanitising there would double-protect a path that needs none and put a visible apostrophe in front of
 * every value in a tenant's live spreadsheet — a correctness bug in exchange for nothing.
 *
 * The right seam is the four openspout WRITERS, at the seven `Row::fromValues()` call sites. That is the
 * boundary where a value stops being data and becomes a spreadsheet cell.
 *
 * @phpstan-type CellValue bool|DateInterval|DateTimeInterface|float|int|string|null
 */
final class SpreadsheetCell
{
    /**
     * The characters a spreadsheet treats as the start of a formula.
     *
     * `=` and `@` are the direct forms; `+` and `-` because Excel accepts them as formula openers too
     * (`-2+3` evaluates). TAB and CR are here because they let a payload survive a naive line-oriented
     * CSV parser and re-emerge at the start of a cell — the OWASP list, not a guess.
     */
    private const DANGEROUS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Neutralise one cell value.
     *
     * Non-strings pass through untouched by type: an int, a float, a bool or a null cannot open a
     * formula, and coercing them to strings here would change how openspout types the cell.
     *
     * The parameter and return are openspout's own cell union rather than `mixed`, so a caller that
     * hands this something a spreadsheet cannot hold fails at the analyser rather than at the writer.
     *
     * @param  CellValue  $value
     * @return CellValue
     */
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return $value;
        }

        if (! in_array($value[0], self::DANGEROUS, strict: true)) {
            return $value;
        }

        // A real number is not a formula — see the class docblock on why this guard is load-bearing.
        if (is_numeric($value)) {
            return $value;
        }

        return "'".$value;
    }

    /**
     * Neutralise a whole row, preserving keys and arity.
     *
     * The array form exists so the call sites read `Row::fromValues(SpreadsheetCell::row($cells))` — one
     * visible wrapper per writer, rather than a `map` at each of seven sites where one could be forgotten.
     *
     * A `list`, matching what `Row::fromValues()` accepts — `array_map` over a list returns a list, so
     * the shape survives and the call sites need no re-indexing.
     *
     * @param  list<CellValue>  $cells
     * @return list<CellValue>
     */
    public static function row(array $cells): array
    {
        return array_map(self::safe(...), $cells);
    }
}
