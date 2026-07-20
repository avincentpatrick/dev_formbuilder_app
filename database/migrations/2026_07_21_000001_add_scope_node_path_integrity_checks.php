<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Database-level integrity for the materialized path (Increment G10b).
 *
 * G10a could rely on `ScopeNode::booted()` alone, because re-parenting was rejected outright and `path`
 * therefore never changed after INSERT. G10b ships `ScopeNodeService::move()`, which re-paths a whole
 * subtree in ONE raw `UPDATE` — a channel that fires no model events and so cannot be covered by that
 * guard. These two CHECKs are what covers it, and they cover it for every writer: Eloquent, the query
 * builder, raw SQL, a future seeder, or a migration.
 *
 * The failure they exist to catch is not hypothetical. `path` is the authorization input — the resolver
 * decides access by prefix-matching it — so a subtly wrong prefix does not throw, does not fail a test
 * that only checks strings, and silently grants or revokes access across a branch.
 *
 * Both expressions are IMMUTABLE (string functions over the row's own columns), so they are legal in a
 * table CHECK.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. A path must END with its own id: '/…/{self}/'. This is the "self-inclusive" property the whole
        //    resolver rests on — it is what lets "granted on this node" and "granted on an ancestor" be one
        //    prefix comparison. A re-path that grafted the wrong prefix, or dropped the node's own segment,
        //    violates this on the very row it corrupted.
        DB::statement(<<<'SQL'
            ALTER TABLE scope_nodes ADD CONSTRAINT scope_nodes_path_self_suffix_chk
            CHECK (path LIKE ('%/' || id::text || '/'))
        SQL);

        // 2. `depth` must equal the path's own segment count, so the two can never disagree. A path has
        //    depth+2 slashes ('/a/' is depth 0, '/a/b/' is depth 1), and counting them is
        //    length(path) - length(path with slashes removed).
        //
        //    Second-order benefit, and the reason this is worth a constraint rather than a test: it forces
        //    any re-path to write `path` and `depth` in the SAME statement. A two-statement implementation
        //    raises 23514 on the first one — which is exactly the failure you want, loudly and immediately.
        DB::statement(<<<'SQL'
            ALTER TABLE scope_nodes ADD CONSTRAINT scope_nodes_depth_matches_path_chk
            CHECK (depth = (length(path) - length(replace(path, '/', '')) - 2))
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE scope_nodes DROP CONSTRAINT IF EXISTS scope_nodes_depth_matches_path_chk');
        DB::statement('ALTER TABLE scope_nodes DROP CONSTRAINT IF EXISTS scope_nodes_path_self_suffix_chk');
    }
};
