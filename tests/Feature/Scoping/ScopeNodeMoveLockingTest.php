<?php

declare(strict_types=1);

use App\Models\Tenant;
use App\Models\User;
use App\Services\Scoping\ScopeNodeService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The SHAPE of move()'s locking (Increment G10b).
|
| `RefreshDatabase` wraps each test in an uncommitted transaction, so a second connection cannot see the
| fixtures and genuine contention is unobservable here — that lives in ScopeNodeConcurrentMoveTest, which
| commits. What IS observable, and what actually guards the deadlock/staleness properties, is the sequence
| of statements move() issues. These assertions are fully deterministic and cannot flake.
|
| They are the reason a reviewer can trust the concurrency argument without running a race.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    $this->service = app(ScopeNodeService::class);
});

/** @return list<string> every SQL statement issued while $work ran, in order */
function sqlDuring(Closure $work): array
{
    $log = [];
    DB::listen(function (QueryExecuted $q) use (&$log): void {
        $log[] = strtolower($q->sql);
    });

    $work();

    return $log;
}

it('takes the tenant move lock BEFORE reading either node', function (): void {
    $destination = makeScopeNode(name: 'Region II');
    $node = makeScopeNode(makeScopeNode(name: 'Region I'), 'Province A');

    $log = sqlDuring(fn () => $this->service->move($node, $destination));

    $lockAt = firstIndexMatching($log, 'pg_advisory_xact_lock');
    $firstRead = firstIndexMatching($log, 'for update');

    // Order is the whole point: a lock taken AFTER the reads would leave the check-then-act window open,
    // which is exactly the race that made re-parenting unshippable in G10a.
    expect($lockAt)->not->toBeNull('move() must take the tenant advisory lock')
        ->and($firstRead)->not->toBeNull('move() must re-read the rows FOR UPDATE')
        ->and($lockAt)->toBeLessThan($firstRead);
});

it('re-reads both endpoints FOR UPDATE rather than trusting the passed-in models', function (): void {
    $destination = makeScopeNode(name: 'Region II');
    $node = makeScopeNode(makeScopeNode(name: 'Region I'), 'Province A');

    $log = sqlDuring(fn () => $this->service->move($node, $destination));

    // Two: the node and the target parent. Acting on the stale arguments instead is how a move computes
    // its new prefix from a path another transaction has already changed.
    expect(count(array_filter($log, fn (string $s): bool => str_contains($s, 'for update'))))->toBe(2);
});

it('takes only the tenant lock when re-rooting, since there is no target parent to lock', function (): void {
    $node = makeScopeNode(makeScopeNode(name: 'Region I'), 'Province A');

    $log = sqlDuring(fn () => $this->service->move($node, null));

    expect(count(array_filter($log, fn (string $s): bool => str_contains($s, 'for update'))))->toBe(1)
        ->and(firstIndexMatching($log, 'pg_advisory_xact_lock'))->not->toBeNull();
});

it('re-paths the subtree in exactly ONE statement, whatever its size', function (): void {
    $destination = makeScopeNode(name: 'Region II');
    $node = makeScopeNode(makeScopeNode(name: 'Region I'), 'Province A');

    // A 20-node subtree, built WIDE rather than deep so the fixture itself stays inside the depth cap.
    // Row-by-row would issue 20+ updates and — on a real hierarchy of tens of thousands of nodes — blow
    // past Postgres' 65535 bind cap while holding the tenant lock.
    foreach (range(1, 10) as $i) {
        $child = makeScopeNode($node, "City {$i}");
        makeScopeNode($child, "Barangay {$i}");
    }

    $log = sqlDuring(fn () => $this->service->move($node, $destination));
    $updates = array_values(array_filter($log, fn (string $s): bool => str_starts_with($s, 'update scope_nodes')));

    expect($updates)->toHaveCount(1)
        // Prefix-matched, so the statement cost is independent of how many descendants it rewrites.
        ->and($updates[0])->toContain('path like')
        // path and depth MUST move together — scope_nodes_depth_matches_path_chk rejects them drifting apart.
        ->and($updates[0])->toContain('depth')
        ->and($updates[0])->toContain('substr');
});

it('issues no write at all when the move is a no-op', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $child = makeScopeNode($root, 'Province A');

    $log = sqlDuring(fn () => $this->service->move($child, $root));

    expect(array_filter($log, fn (string $s): bool => str_starts_with($s, 'update scope_nodes')))->toBeEmpty();
});

it('writes nothing when the cycle check refuses the move', function (): void {
    $root = makeScopeNode(name: 'Region I');
    $descendant = makeScopeNode(makeScopeNode($root, 'Province A'), 'City X');

    $log = [];
    try {
        $log = sqlDuring(fn () => $this->service->move($root, $descendant));
    } catch (Throwable) {
        // expected
    }

    // The refusal must land BEFORE the re-path, not roll one back — a rolled-back re-path still took the
    // lock for its duration and still had a window where the tree was inconsistent.
    expect(array_filter($log, fn (string $s): bool => str_starts_with($s, 'update scope_nodes')))->toBeEmpty();
});

/** Index of the first statement containing $needle, or null. */
function firstIndexMatching(array $log, string $needle): ?int
{
    foreach ($log as $i => $sql) {
        if (str_contains($sql, $needle)) {
            return $i;
        }
    }

    return null;
}
