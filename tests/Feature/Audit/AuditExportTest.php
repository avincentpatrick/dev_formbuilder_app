<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\UsageMetric;
use App\Models\Audit;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditRedactor;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| The audit-log export (Increment I2, audit-compliance-logging-spec §3).
|
| ⚠️ ASSERT ON PARSED ROWS, NEVER ON STATUS. The stream closure fires during `Response::send()`, AFTER
| `EstablishTenantDatabaseContext::terminate()` has torn the tenant GUC down — so a missing
| `TenantContext::applyLocal()` produces an EMPTY FILE AT HTTP 200, which every status-only test passes.
| Mutation-verified: deleting that call reddens the first test below with a header-only file.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->actingAs($this->owner);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

function auditExportUrl(string $query = ''): string
{
    return 'http://acme.meridian.test/audit-log/export'.($query === '' ? '' : '?'.$query);
}

/** Parse an openspout CSV body, stripping its UTF-8 BOM. @return list<list<string>> */
function auditExportRows(string $csv): array
{
    $csv = (string) preg_replace('/^\xEF\xBB\xBF/', '', $csv);

    return array_values(array_filter(array_map(
        static fn (string $line): array => str_getcsv($line, escape: ''),
        explode("\n", trim($csv))
    )));
}

it('streams a non-empty file with the header and one row per audit entry', function (): void {
    app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');

    $response = $this->get(auditExportUrl())->assertOk();
    $rows = auditExportRows($response->streamedContent());

    expect($rows[0])->toBe(['Timestamp', 'Event', 'Type', 'Target ID', 'Actor', 'IP address', 'Changes', 'Redacted fields']);
    // THE assertion that catches a missing applyLocal(): with the GUC torn down, RLS hides every row and
    // this file is a header and nothing else, at HTTP 200.
    expect(count($rows))->toBeGreaterThan(1);

    $flat = array_map(static fn (array $r): string => implode('|', $r), $rows);
    expect($flat)->toContain(implode('|', array_slice($rows[1], 0)));
    expect(implode("\n", $flat))->toContain('Created')->toContain('Form');
});

it('names the actor in every row rather than exporting an unattributed ledger', function (): void {
    app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');

    $response = $this->get(auditExportUrl())->assertOk();
    $rows = auditExportRows($response->streamedContent());

    // Plainly: this asserts the Actor column carries real names rather than blanks or "Unknown user" —
    // the fact a compliance export exists to record. Two things it does NOT guard, both checked by
    // mutation rather than assumed, and both worth knowing before someone "strengthens" this test:
    //   • the second argument of applyLocal() — the `users` policy resolves an active co-tenant through
    //     its membership branch with no user GUC at all, so dropping it changes nothing here;
    //   • the `->with('user:id,name')` eager load — `data_get()` lazy-loads the relation, so removing it
    //     silently degrades one query into N+1 and every assertion still passes.
    $actors = array_column(array_slice($rows, 1), 4);

    expect($actors)->not->toBeEmpty();
    expect($actors)->toContain($this->owner->name);
    expect($actors)->not->toContain('Unknown user');
    expect($actors)->not->toContain('');
});

it('writes ONE self-referential `exported` row, keyed on the tenant, carrying the filters', function (): void {
    app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');

    $this->get(auditExportUrl('event=created&from=2026-01-01&format=csv'))->assertOk();

    // The request tore tenant context down; re-entering is the established web-test idiom.
    enterTenant($this->tenant->id, $this->owner->id);

    $audit = Audit::query()->where('event', AuditEvent::Exported->value)->sole();

    expect($audit->auditable_type)->toBe('audit_log');
    // uuid NOT NULL with no audit_log row to point at — spec §1's role-grant device, keyed on the owner.
    expect($audit->auditable_id)->toBe($this->tenant->id);
    expect((string) $audit->user_id)->toBe((string) $this->owner->getKey());
    expect($audit->old_values)->toBeNull();
    expect($audit->new_values['format'])->toBe('csv');
    expect($audit->new_values['event'])->toBe('created');
    expect($audit->new_values['from'])->toBe('2026-01-01');
    expect($audit->new_values['to'])->toBeNull();
    // NO row count, deliberately: it needs a count(*) over an unbounded table before the stream starts,
    // and the client can abandon the download mid-body — so an intended count they never received would
    // be a false entry in the one table that exists to not contain those.
    expect($audit->new_values)->not->toHaveKey('count');
    expect($audit->new_values)->not->toHaveKey('rows');
});

it('records the export BEFORE the stream, where the actor and request still exist', function (): void {
    app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');

    // Deliberately NOT touching streamedContent(): the row must already be committed by the time the
    // response is returned, because the closure runs after context teardown with no Auth guard and no
    // request to read an IP from. Asserting without draining the stream is what proves the ordering.
    $this->get(auditExportUrl())->assertOk();

    enterTenant($this->tenant->id, $this->owner->id);

    $audit = Audit::query()->where('event', AuditEvent::Exported->value)->sole();

    expect($audit->user_id)->not->toBeNull();
    expect($audit->ip_address)->not->toBeNull();
});

it('contains its own export row — an accepted property, pinned so nobody hides it', function (): void {
    app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');

    $response = $this->get(auditExportUrl())->assertOk();
    $body = implode("\n", array_map(
        static fn (array $r): string => implode('|', $r),
        auditExportRows($response->streamedContent()),
    ));

    // The audit row commits in request scope; the stream query runs later. So the file honestly records
    // that it was produced. Excluding `audit_log` rows from the query to tidy this away would make the
    // export lie about the ledger's contents, which is a worse property than a surprising one.
    expect($body)->toContain('Exported')->toContain('Audit log');
});

it('meters the export', function (): void {
    app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');

    $this->get(auditExportUrl())->assertOk();

    enterTenant($this->tenant->id, $this->owner->id);

    expect(DB::table('usage_counters')->where('metric', UsageMetric::ExportsCount->value)->sum('value'))
        ->toBeGreaterThan(0);
});

it('honours the filters it recorded', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');
    app(FormService::class)->archive($form, $this->owner);

    $response = $this->get(auditExportUrl('event=archived'))->assertOk();
    $rows = auditExportRows($response->streamedContent());

    // Header + exactly the one archived row. ("I exported what I was looking at" is a compliance
    // guarantee, not a convenience.)
    expect($rows)->toHaveCount(2);
    expect($rows[1][1])->toBe('Archived');
});

it('renders a redacted value as the placeholder in the cell, never the raw value', function (): void {
    app(AuditLogger::class)->record(
        AuditEvent::Updated,
        'submission',
        (string) Str::uuid7(),
        old: ['remarks' => 'called the mother, 555-0101'],
        new: ['remarks' => 'verified'],
        actorId: (string) $this->owner->getKey(),
    );

    $response = $this->get(auditExportUrl('auditable_type=submission'))->assertOk();
    $body = $response->streamedContent();

    expect($body)->toContain(AuditRedactor::PLACEHOLDER);
    expect($body)->not->toContain('555-0101');
    expect($body)->toContain('remarks');
});

it('names the file by wall clock and serves the right content type per format', function (): void {
    app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');

    $csv = $this->get(auditExportUrl())->assertOk();
    expect($csv->headers->get('content-disposition'))->toMatch('/attachment; filename=audit-log-\d{8}-\d{6}\.csv/');
    expect($csv->headers->get('content-type'))->toContain('text/csv');

    $xlsx = $this->get(auditExportUrl('format=xlsx'))->assertOk();
    expect($xlsx->headers->get('content-type'))
        ->toBe('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('refuses a Viewer', function (): void {
    $viewer = User::factory()->create();
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->get(auditExportUrl())->assertForbidden();
});

/*
| Spreadsheet formula injection (Increment I8c) â€” the audit export is a third path to a reviewer's Excel,
| and I2 flagged it explicitly so all the exporters would be fixed as one set rather than half of them.
|
| âš ï¸ THE VECTOR HERE IS THE **ACTOR** COLUMN, NOT `Changes`, AND THAT SURPRISED ME. `Changes` renders as
| `key: old â†’ new`, so a tenant-authored VALUE can never sit at the start of that cell â€” a cell beginning
| `title: ` is inert whatever follows. The genuinely exposed column is `Actor`, which is a user's own
| display name, written by that user, landing unprefixed at position 0 of a cell. Recorded because the
| obvious reading of I2's note ("the Changes column carries tenant-authored strings") points at the wrong
| column, and a fix aimed only there would have left the real one open.
*/

it('neutralises a formula-injection payload in the Actor column', function (): void {
    $attacker = User::factory()->create(['name' => '=cmd|\' /C calc\'!A0']);
    enterTenant($this->tenant->id, $attacker->id);
    makeActiveMember($attacker, 'admin');

    app(FormService::class)->create($this->tenant, $attacker, 'Clinic Intake');

    enterTenant($this->tenant->id, $this->owner->id);
    $rows = auditExportRows($this->actingAs($this->owner)->get(auditExportUrl())->assertOk()->streamedContent());

    $actors = array_map(static fn (array $r): string => $r[4] ?? '', $rows);
    expect($actors)->toContain('\'=cmd|\' /C calc\'!A0');

    // And nothing anywhere in the file opens a formula. Asserted across EVERY cell rather than one
    // column, because the point of putting the wrapper at the writer is that no column is exempt.
    foreach ($rows as $row) {
        foreach ($row as $cell) {
            expect(str_starts_with($cell, '='))->toBeFalse();
            expect(str_starts_with($cell, '@'))->toBeFalse();
        }
    }
});
