<?php

declare(strict_types=1);

use App\Support\Migrations\BrandRampRederivation;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-derives every stored tenant brand ramp under `BrandRampGenerator::VERSION` 2 (JR1, the Vivid re-skin).
 *
 * The engine's version constant exists so that a change to a target, ground or role is "an explicit
 * re-derivation rather than a silent repaint of every live tenant". JR1 moved the neutral ramp, and four of
 * the engine's six measurement grounds ARE neutral primitives — so this migration is the explicit half of
 * that bargain. Without it, every branded tenant keeps a `measurements` array certifying contrast against a
 * canvas and an ink the application no longer paints, and nothing anywhere would have failed.
 *
 * All the reasoning — why re-derive rather than transform, why `tenants` needs no privileged connection, why
 * a ramp with no input colour is left alone — lives on {@see BrandRampRederivation}, which is also where the
 * body lives so it can be effect-tested. A `migrate:fresh` database has no tenants at this point, so this
 * migration examines nothing there and only does real work against a live, populated one.
 *
 * ⚠️ This migration creates no table, so `scripts/migration-lint.php` SKIPS it entirely and its green here
 * is vacuous rather than evidence — the 2026_08_12_000001 note, verified the same way.
 *
 * ⚠️ REPLAYED ON A FUTURE ENGINE VERSION THIS WRITES THAT VERSION, NOT 2. It reads
 * `BrandRampGenerator::VERSION` rather than a frozen literal, deliberately: a re-derivation that wrote a
 * hard-coded 2 under a v3 engine would stamp rows with a version their tokens do not match, which is worse
 * than being ahead. Re-deriving forward is always correct; that is the whole property the class relies on.
 */
return new class extends Migration
{
    public function up(): void
    {
        (new BrandRampRederivation)(DB::connection());
    }

    /**
     * Deliberately a no-op.
     *
     * A v1 ramp is not recoverable from a v2 one — the tokens are the output of a lightness search against
     * grounds that no longer exist in the codebase, so "reversing" it would mean re-deriving against
     * hard-coded historical hexes and calling the result the original. Rolling this migration back leaves
     * v2 ramps in place, which is correct: they are measured against the tokens the reverted tree would
     * still be shipping unless the token files are reverted too, and that is a git operation, not a
     * database one.
     */
    public function down(): void
    {
        //
    }
};
