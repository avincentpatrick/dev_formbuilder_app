<?php

declare(strict_types=1);

use App\Enums\FeedbackSource;
use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Models\FeedbackReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M179 (`R-db9dec62`, `D150`) — a report says where it came from, and a relayed one carries its reference.
|--------------------------------------------------------------------------
| Every report written before these columns existed — and every one the Feedback button writes since — is a
| `button` report: the default is a constant, so PostgreSQL fills existing rows without rewriting them, and
| nothing on staging is touched (`D137`). A relayed report carries the reference the testers know it by
| (`R1-7a`, `U4`); the support API creates or updates by that reference, so it is unique within a workspace —
| and only within one, because two workspaces may well number their comments alike.
|
| Each refusal runs inside its own `DB::transaction()` — a savepoint inside `RefreshDatabase`'s transaction —
| so the failed statement rolls back alone and the test can go on asserting.
*/

beforeEach(function (): void {
    TenantContext::flush();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme-columns']);
    $this->reporter = User::factory()->create();
    enterTenant($this->tenant->id, $this->reporter->id);
});

/** A report written the way the Feedback button writes one, then given the relay's columns. */
function columnsReport(User $reporter, array $relay = []): FeedbackReport
{
    $report = FeedbackReport::create([
        'user_id' => $reporter->id,
        'type' => FeedbackType::Bug,
        'route' => '/dashboard',
        'remarks' => 'A report.',
        'status' => FeedbackStatus::New,
    ]);

    if ($relay !== []) {
        $report->forceFill($relay)->save();
    }

    return $report->refresh();
}

/** Run one statement in a savepoint and return the SQLSTATE it failed with, or null. */
function columnsRefusal(Closure $statement): ?string
{
    try {
        DB::transaction($statement);
    } catch (QueryException $e) {
        return (string) ($e->errorInfo[0] ?? $e->getCode());
    }

    return null;
}

it('reads a report sent with the button as a button report, with nothing relayed or confirmed on it', function (): void {
    $report = columnsReport($this->reporter);

    expect($report->source)->toBe(FeedbackSource::Button)
        ->and($report->reference)->toBeNull()
        ->and($report->title)->toBeNull()
        ->and($report->source_label)->toBeNull()
        ->and($report->confirmed_at)->toBeNull()
        ->and($report->confirmed_by)->toBeNull()
        ->and($report->last_comment_at)->toBeNull();
});

it('refuses a source outside the two the enum declares', function (): void {
    $report = columnsReport($this->reporter);

    expect(columnsRefusal(fn () => DB::table('feedback_reports')->where('id', $report->id)->update(['source' => 'email'])))
        ->toBe('23514');
});

it('keeps a reference unique within one workspace, and only within one', function (): void {
    $first = columnsReport($this->reporter, ['source' => FeedbackSource::Relayed, 'reference' => 'R1-7a', 'title' => 'Grid']);

    expect($first->source)->toBe(FeedbackSource::Relayed)
        ->and(columnsRefusal(fn () => columnsReport($this->reporter, ['source' => FeedbackSource::Relayed, 'reference' => 'R1-7a'])))
        ->toBe('23505');

    $other = Tenant::create(['name' => 'Globex', 'slug' => 'globex-columns']);
    $stranger = User::factory()->create();
    enterTenant($other->id, $stranger->id);

    expect(columnsRefusal(fn () => columnsReport($stranger, ['source' => FeedbackSource::Relayed, 'reference' => 'R1-7a'])))
        ->toBeNull();
});

it('lets any number of reports carry no reference at all', function (): void {
    columnsReport($this->reporter);
    columnsReport($this->reporter);

    expect(FeedbackReport::query()->whereNull('reference')->count())->toBe(2);
});
