<?php

declare(strict_types=1);

use App\Models\SkeletonProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('runs the test suite against real PostgreSQL, never sqlite', function (): void {
    // RLS (the tenant-isolation backstop from Increment A) cannot be validated on sqlite,
    // so the entire test path must run on Postgres. Fail loudly if that ever regresses.
    expect(DB::connection()->getDriverName())->toBe('pgsql');
});

it('migrates and round-trips a client-generated UUIDv7 primary key', function (): void {
    $probe = SkeletonProbe::create(['note' => 'walking skeleton']);

    expect($probe->id)->toBeString()
        ->and(strlen($probe->id))->toBe(36);

    expect(SkeletonProbe::find($probe->id)?->note)->toBe('walking skeleton');
});
