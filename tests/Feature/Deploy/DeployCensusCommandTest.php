<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M176 (`R-a11cc78a`, `D144`) — a deploy proves the data survived its migration.
|--------------------------------------------------------------------------
| `deploy:census --write` counts every table's rows once the site is down and before anything changes;
| `deploy:census --compare` counts again after `migrate` and fails, naming each table, if any holds fewer rows
| or is gone — and `deploy.ps1` then leaves the site down (`D144`). The user (2026-10-16): "always make sure that
| the staging has data, do not remove data". Counted through the privileged connection, as the deploy counts
| them: a superuser, so row security hides nothing. Rows written here go through that connection too — the
| test's own transaction is invisible to it — and are removed afterwards.
*/

function censusFile(): string
{
    return storage_path('framework/testing/census-'.Str::random(8).'.json');
}

function probeRow(): string
{
    $id = (string) Str::uuid();
    DB::connection('pgsql_privileged')->table('skeleton_probes')->insert(['id' => $id, 'note' => 'census', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

afterEach(function (): void {
    DB::connection('pgsql_privileged')->table('skeleton_probes')->where('note', 'census')->delete();
    foreach (glob(storage_path('framework/testing/census-*.json')) ?: [] as $file) {
        @unlink($file);
    }
});

it('passes when every table holds at least as many rows as before', function (): void {
    $file = censusFile();
    probeRow();

    $this->artisan('deploy:census', ['--write' => $file])->assertSuccessful();
    probeRow(); // growth is fine

    $this->artisan('deploy:census', ['--compare' => $file])
        ->expectsOutputToContain('Every table holds at least as many rows as before')
        ->assertSuccessful();
});

it('fails, naming the table and both counts, when a table holds fewer rows than before', function (): void {
    $file = censusFile();
    $first = probeRow();
    probeRow();

    $this->artisan('deploy:census', ['--write' => $file])->assertSuccessful();
    DB::connection('pgsql_privileged')->table('skeleton_probes')->where('id', $first)->delete();

    $this->artisan('deploy:census', ['--compare' => $file])
        ->expectsOutputToContain('skeleton_probes: 2 rows before, 1 after')
        ->assertFailed();
});

it('fails when a table counted before is gone', function (): void {
    $file = censusFile();
    $this->artisan('deploy:census', ['--write' => $file])->assertSuccessful();

    $census = json_decode((string) file_get_contents($file), true);
    $census['tables']['ghost_table'] = 3;
    file_put_contents($file, json_encode($census));

    $this->artisan('deploy:census', ['--compare' => $file])
        ->expectsOutputToContain('ghost_table: 3 rows before, and the table is gone')
        ->assertFailed();
});

it('ignores a table that empties itself by design, such as the job queue', function (): void {
    $file = censusFile();
    $this->artisan('deploy:census', ['--write' => $file])->assertSuccessful();

    $census = json_decode((string) file_get_contents($file), true);
    $census['tables']['jobs'] = ($census['tables']['jobs'] ?? 0) + 5; // a job finished during the window
    file_put_contents($file, json_encode($census));

    $this->artisan('deploy:census', ['--compare' => $file])->assertSuccessful();
});

it('counts every base table, so a new table is counted without anyone listing it', function (): void {
    $file = censusFile();
    $this->artisan('deploy:census', ['--write' => $file])->assertSuccessful();

    $census = json_decode((string) file_get_contents($file), true);
    expect($census['tables'])->toHaveKeys(['tenants', 'users', 'forms', 'form_versions', 'submissions', 'attachments', 'feedback_reports', 'skeleton_probes']);
});

it('refuses to run without exactly one of --write or --compare, and fails on a census it cannot read', function (): void {
    $this->artisan('deploy:census')->assertFailed();
    $this->artisan('deploy:census', ['--write' => censusFile(), '--compare' => censusFile()])->assertFailed();
    $this->artisan('deploy:census', ['--compare' => storage_path('framework/testing/census-missing.json')])
        ->expectsOutputToContain('cannot be read')
        ->assertFailed();
});
