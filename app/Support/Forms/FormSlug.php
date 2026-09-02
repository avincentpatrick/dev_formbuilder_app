<?php

declare(strict_types=1);

namespace App\Support\Forms;

use App\Models\Form;
use Illuminate\Support\Str;

/**
 * The one place a `forms.public_slug` is turned into a canonical value or resolved back from a URL (I1, M61).
 *
 * ⛔ **TWO OBLIGATIONS LIVE HERE AND THEY MUST NEVER BE MERGED.** {@see normalize()} and {@see suggest()} are
 * the WRITE side: they mint a canonical slug and de-duplicate it. {@see forLookup()} is the READ side and is
 * deliberately far weaker — it lowercases and does nothing else. Unifying them looks like tidying and is the
 * defect described in `forLookup()`'s own docblock.
 *
 * The write side has two callers with the same obligation: the XLSForm importer, which derives a slug from
 * the file's `form_id`/`form_title` settings, and the share surface, which suggests one from the form's title
 * so the author's editor opens on a value that will actually save. Keeping the de-collision loop in one place
 * is what stops the two from disagreeing about which suffix is free.
 *
 * ⚠️ {@see isTaken()} queries `withTrashed()` on purpose, and this is a fix rather than a nicety. The unique
 * index `forms_tenant_id_public_slug_unique` carries NO `deleted_at` predicate, so a soft-deleted form keeps
 * its slug reserved at the database level forever. A check that respected the `SoftDeletes` global scope
 * would report a trashed row's slug as free, the loop would return it, and the INSERT/UPDATE would die on a
 * 23505 the caller has no path to handle. The DB index is the authority; this predicate matches it.
 *
 * There is no `tenant_id` predicate anywhere here for the same reason no `Rule::unique` in this repo has one:
 * RLS on the connection (see EstablishTenantDatabaseContext) already scopes every query to the current
 * tenant, which is the other half of that composite index.
 */
final class FormSlug
{
    /** Slugs the share surface refuses, because `/f/{slug}` has a sibling literal segment. */
    public const RESERVED = ['resume'];

    /** Shortest slug the share request accepts — long enough not to collide by accident in a URL bar. */
    public const MIN_LENGTH = 3;

    /** Matches `forms.public_slug`'s column width. */
    public const MAX_LENGTH = 120;

    /**
     * The incoming `/f/{slug}` segment, reduced to the form the column actually holds (M61). Lowercase, and
     * NOTHING ELSE.
     *
     * ⛔ **DELIBERATELY NOT {@see normalize()}, AND THE DIFFERENCE IS A CROSS-FORM HAZARD RATHER THAN A STYLE
     * CHOICE.** `normalize()` is `Str::slug()`, which transliterates and re-hyphenates: under it
     * `/f/clinic_intake`, `/f/clinic-intake` with an en dash, and `/f/Clinic Intake` would all resolve to
     * `clinic-intake` — aliases the write side never reserved and {@see isTaken()} never de-duplicated. Two
     * distinct forms could then be reachable at one URL, with `->first()` choosing between them arbitrarily.
     * A lookup may only ever be MORE forgiving about case; never about identity.
     *
     * ⚠️ `Str::lower()` is `mb_strtolower()` with UTF-8 — Unicode case tables, locale-independent. Do not
     * "simplify" it to `strtolower()`, which is byte-wise and locale-sensitive.
     *
     * This is well-defined only because storage is lowercase, and storage is lowercase because
     * `UpdateFormShareRequest`'s regex refuses uppercase and `FormService::setShareSettings()` lowers what it
     * is handed. `forms_tenant_id_public_slug_unique` is case-SENSITIVE, so without that guarantee `Intake`
     * and `intake` would both be legal rows and this lookup would have two answers.
     */
    public static function forLookup(string $value): string
    {
        return Str::lower($value);
    }

    /**
     * Lowercase, hyphen-separated, ASCII. Returns null when nothing survives normalization (e.g. a title that
     * is entirely punctuation or non-transliterable script) — callers decide whether that is an error or a
     * reason to fall back, rather than being handed a silent empty string.
     */
    public static function normalize(string $value): ?string
    {
        $slug = Str::slug($value);

        if ($slug === '') {
            return null;
        }

        return Str::limit($slug, self::MAX_LENGTH, '');
    }

    /**
     * A slug this form could save right now: derived from `$source` (defaulting to the form's title), then
     * suffixed `-2`, `-3`, … until it is free within the tenant. Never returns null — an unusable source
     * falls back to `form`, which is itself de-duplicated.
     */
    public static function suggest(Form $form, ?string $source = null): string
    {
        $base = self::normalize($source ?? $form->title ?? '') ?? 'form';

        $candidate = $base;
        $n = 2;

        while (self::isTaken($candidate, $form->id)) {
            $candidate = $base.'-'.$n;
            $n++;
        }

        return $candidate;
    }

    /**
     * Is `$slug` already spoken for inside the current tenant, ignoring `$exceptFormId` (the form being
     * edited)? Counts soft-deleted forms — see the class docblock.
     */
    public static function isTaken(string $slug, ?string $exceptFormId = null): bool
    {
        $query = Form::query()->withTrashed()->where('public_slug', $slug);

        if ($exceptFormId !== null) {
            $query->whereKeyNot($exceptFormId);
        }

        return $query->exists();
    }
}
