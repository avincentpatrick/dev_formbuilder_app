<?php

declare(strict_types=1);

use App\Enums\FormBotChallenge;
use App\Enums\FormScheduleState;
use App\Enums\FormThemePreset;
use App\Models\Audit;
use App\Models\Form;
use App\Models\FormFolder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Every form-settings save locks the form row first, and audits and writes the copy it locked (M199,
| `R-5041cc72`, `D190` A) — the order `setDataSharing()` and `archive()` already followed.
|--------------------------------------------------------------------------
| Before M199 ten setters built their audit `$old` from the caller's `$form` and wrote on it. That copy is
| whatever route binding loaded, and anything committed since — the schedule sweeper's flip of
| `schedule_state` (`D190`'s named race), a second editor's save — is invisible to it. Two failures follow:
|  - **the audit records an old value the row did not hold** — the hazard `archive()`'s comment names;
|  - ⛔ **the save is LOST when the value set equals the stale copy's.** `save()` writes only what is dirty
|    against the model's original, so nothing is dirty, no UPDATE runs, the row keeps the concurrent value,
|    and the audit still records a change. This is the half the row never named; measured by `M199`.
|
| Driven through the service, never HTTP: route binding reloads the model on every request, so a stale copy
| cannot be staged over a route without a second connection (BuilderDraftGuardTest says the same). The
| concurrent commit is a direct write behind the copy the caller holds.
|
| RefreshDatabase runs each test inside one transaction on one connection, so lock CONTENTION is
| unobservable; what the argument rests on is the order of the statements, which is deterministic. Helpers
| are prefixed `formSettingsLocking*`: Pest loads the whole suite into one process, and a shared name is a
| fatal redeclaration.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    (new RolePermissionSeeder)->run();

    $this->tenant = Tenant::create(['name' => 'Alpha', 'slug' => 'alpha', 'default_locale' => 'en']);
    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
    $this->actingAs($this->admin);
});

afterEach(fn () => app(PermissionRegistrar::class)->setPermissionsTeamId(null));

/**
 * Every public method of FormService that writes an existing `forms` row. `create()` is the one writer left
 * out, correctly: it creates the row, so there is nothing to lock (§3.4).
 *
 * @return list<string>
 */
function formSettingsLockingLockers(): array
{
    return [
        'updateMetadata', 'assignScope', 'assignFolder', 'setTheme', 'setSaveAndResume', 'setSinglePageMode',
        'setOcrScanning', 'setShareSettings', 'setConfirmationMessage', 'setSchedule', 'setDataSharing', 'archive',
    ];
}

/**
 * One setter staged against a stale copy: the form is put in a known state through the service, then loaded
 * — `form` is the copy the caller holds. `behind` is the concurrent commit, `call` saves the value the stale
 * copy ALREADY holds (the lost-write shape), `read` reads the saved value back, and the audit's `old` must
 * name `auditOld` under `auditKey`: the value the save actually replaced.
 *
 * @return array{form: Form, behind: array<string, mixed>, call: Closure(Form): Form, read: Closure(Form): mixed, expected: mixed, auditKey: string, auditOld: mixed}
 */
function formSettingsLockingStage(string $setter, Tenant $tenant, User $actor): array
{
    $service = app(FormService::class);
    $form = $service->create($tenant, $actor, 'Before');

    $stage = match ($setter) {
        'updateMetadata' => [
            'behind' => ['title' => 'Concurrent'],
            'call' => fn (Form $stale): Form => $service->updateMetadata($stale, 'Before', null, $actor),
            'read' => fn (Form $f): mixed => $f->title,
            'expected' => 'Before',
            'auditKey' => 'title',
            'auditOld' => 'Concurrent',
        ],
        'assignScope' => (function () use ($service, $form, $actor): array {
            $mine = makeScopeNode(null, 'Region I');
            $theirs = makeScopeNode(null, 'Region II');
            $service->assignScope($form, $mine->id, $actor);

            return [
                'behind' => ['scope_node_id' => $theirs->id],
                'call' => fn (Form $stale): Form => $service->assignScope($stale, $mine->id, $actor),
                'read' => fn (Form $f): mixed => $f->scope_node_id,
                'expected' => $mine->id,
                'auditKey' => 'scope_node_id',
                'auditOld' => $theirs->id,
            ];
        })(),
        'assignFolder' => (function () use ($service, $form, $actor): array {
            $mine = new FormFolder;
            $mine->name = 'Clinics';
            $mine->save();
            $theirs = new FormFolder;
            $theirs->name = 'Schools';
            $theirs->save();
            $service->assignFolder($form, $mine->id, $actor);

            return [
                'behind' => ['folder_id' => $theirs->id],
                'call' => fn (Form $stale): Form => $service->assignFolder($stale, $mine->id, $actor),
                'read' => fn (Form $f): mixed => $f->folder_id,
                'expected' => $mine->id,
                'auditKey' => 'folder_name',
                'auditOld' => 'Schools',
            ];
        })(),
        'setTheme' => (function () use ($service, $form, $actor): array {
            $service->setTheme($form, FormThemePreset::Forest, $actor);

            return [
                'behind' => ['theme' => json_encode(['preset' => FormThemePreset::Lagoon->value])],
                'call' => fn (Form $stale): Form => $service->setTheme($stale, FormThemePreset::Forest, $actor),
                'read' => fn (Form $f): mixed => FormThemePreset::fromTheme($f->theme)?->value,
                'expected' => FormThemePreset::Forest->value,
                'auditKey' => 'theme_preset',
                'auditOld' => FormThemePreset::Lagoon->value,
            ];
        })(),
        'setSaveAndResume' => (function () use ($service, $form, $actor): array {
            $service->setSaveAndResume($form, true, $actor);

            return [
                'behind' => ['save_and_resume' => false],
                'call' => fn (Form $stale): Form => $service->setSaveAndResume($stale, true, $actor),
                'read' => fn (Form $f): mixed => $f->save_and_resume,
                'expected' => true,
                'auditKey' => 'save_and_resume',
                'auditOld' => false,
            ];
        })(),
        'setSinglePageMode' => [
            'behind' => ['single_page_mode' => true],
            'call' => fn (Form $stale): Form => $service->setSinglePageMode($stale, false, $actor),
            'read' => fn (Form $f): mixed => $f->single_page_mode,
            'expected' => false,
            'auditKey' => 'single_page_mode',
            'auditOld' => true,
        ],
        'setOcrScanning' => [
            'behind' => ['allow_ocr_single' => true],
            'call' => fn (Form $stale): Form => $service->setOcrScanning($stale, false, $actor),
            'read' => fn (Form $f): mixed => $f->allow_ocr_single,
            'expected' => false,
            'auditKey' => 'allow_ocr_single',
            'auditOld' => true,
        ],
        'setShareSettings' => (function () use ($service, $form, $actor): array {
            $share = fn (Form $f): Form => $service->setShareSettings(
                $f,
                slug: 'clinic-intake',
                allowGuests: true,
                botChallenge: FormBotChallenge::Off,
                guestRateLimitPerMinute: null,
                actor: $actor,
            );
            $share($form);

            return [
                'behind' => ['allow_guest_submissions' => false],
                'call' => $share,
                'read' => fn (Form $f): mixed => $f->allow_guest_submissions,
                'expected' => true,
                'auditKey' => 'allow_guest_submissions',
                'auditOld' => false,
            ];
        })(),
        'setConfirmationMessage' => (function () use ($service, $form, $actor): array {
            $service->setConfirmationMessage($form, 'Thank you.', null, null, $actor);

            return [
                'behind' => ['confirmation_message' => 'Concurrent copy.'],
                'call' => fn (Form $stale): Form => $service->setConfirmationMessage($stale, 'Thank you.', null, null, $actor),
                'read' => fn (Form $f): mixed => $f->confirmation_message,
                'expected' => 'Thank you.',
                'auditKey' => 'confirmation_message',
                'auditOld' => 'Concurrent copy.',
            ];
        })(),
        'setSchedule' => (function () use ($service, $form, $actor): array {
            $service->setSchedule($form, null, null, 'Asia/Manila', 10, $actor);

            return [
                'behind' => ['max_responses' => 20],
                'call' => fn (Form $stale): Form => $service->setSchedule($stale, null, null, 'Asia/Manila', 10, $actor),
                'read' => fn (Form $f): mixed => $f->max_responses,
                'expected' => 10,
                'auditKey' => 'max_responses',
                'auditOld' => 20,
            ];
        })(),
        'setDataSharing' => [
            'behind' => ['data_sharing_enabled' => true],
            'call' => fn (Form $stale): Form => $service->setDataSharing($stale, false, null, $actor),
            'read' => fn (Form $f): mixed => $f->data_sharing_enabled,
            'expected' => false,
            'auditKey' => 'data_sharing_enabled',
            'auditOld' => true,
        ],
        'archive' => [
            'behind' => [],
            'call' => fn (Form $stale): Form => $service->archive($stale, $actor),
            'read' => fn (Form $f): mixed => $f->status->value,
            'expected' => 'archived',
            'auditKey' => 'status',
            'auditOld' => 'draft',
        ],
    };

    return ['form' => Form::query()->whereKey($form->id)->firstOrFail()] + $stage;
}

/** The newest `form`/`updated` audit row for one form. */
function formSettingsLockingAudit(string $formId): Audit
{
    return Audit::query()
        ->where('auditable_type', 'form')
        ->where('auditable_id', $formId)
        ->where('event', 'updated')
        ->orderByDesc('id')
        ->firstOrFail();
}

/**
 * @return list<string> every SQL statement issued while $work ran, in order
 */
function formSettingsLockingSqlDuring(Closure $work): array
{
    $log = [];
    DB::listen(function (QueryExecuted $q) use (&$log): void {
        $log[] = strtolower($q->sql);
    });

    $work();

    return $log;
}

/**
 * Index of the first statement containing every one of $needles, or null.
 *
 * @param  list<string>  $log
 */
function formSettingsLockingFirstIndex(array $log, string ...$needles): ?int
{
    foreach ($log as $i => $sql) {
        $hit = true;
        foreach ($needles as $needle) {
            $hit = $hit && str_contains($sql, $needle);
        }

        if ($hit) {
            return $i;
        }
    }

    return null;
}

it('saves the value and audits the one it replaced, when the caller holds a stale copy', function (string $setter): void {
    $stage = formSettingsLockingStage($setter, $this->tenant, $this->admin);
    $stale = $stage['form'];

    // The concurrent commit, staged behind the copy the caller holds.
    DB::table('forms')->where('id', $stale->id)->update($stage['behind']);
    expect(($stage['read'])($stale))->toBe($stage['expected'], 'the stale copy should hold the value about to be saved')
        ->and(($stage['read'])(Form::query()->whereKey($stale->id)->firstOrFail()))
        ->not->toBe($stage['expected'], 'the concurrent commit did not land, so the case proves nothing');

    ($stage['call'])($stale);

    expect(($stage['read'])(Form::query()->whereKey($stale->id)->firstOrFail()))
        ->toBe($stage['expected'], 'the save was lost: nothing was dirty against the stale copy, so no UPDATE ran');
    expect(formSettingsLockingAudit($stale->id)->old_values[$stage['auditKey']] ?? null)
        ->toBe($stage['auditOld'], 'the audit recorded the stale copy, not the value the save replaced');
})->with(array_values(array_diff(formSettingsLockingLockers(), ['archive'])));

it('locks the form row before it writes it', function (string $setter): void {
    $stage = formSettingsLockingStage($setter, $this->tenant, $this->admin);
    if ($stage['behind'] !== []) {
        DB::table('forms')->where('id', $stage['form']->id)->update($stage['behind']);
    }

    $log = formSettingsLockingSqlDuring(fn () => ($stage['call'])($stage['form']));

    $lock = formSettingsLockingFirstIndex($log, 'from "forms"', 'for update');
    $write = formSettingsLockingFirstIndex($log, 'update "forms"');

    // Not-null first: PHP orders null below every integer, so a missing lock would pass the comparison.
    expect($lock)->not->toBeNull('no locking select on `forms` was issued');
    expect($write)->not->toBeNull('the save issued no UPDATE on `forms`, so the order proves nothing');
    expect($lock)->toBeLessThan($write, 'the row was written before it was locked');
})->with(formSettingsLockingLockers());

it('audits the schedule state the sweeper left, not the one the editor loaded (D190)', function (): void {
    $service = app(FormService::class);
    $form = $service->create($this->tenant, $this->admin, 'Before');
    $opensAt = CarbonImmutable::now()->subHour()->startOfSecond();
    $service->setSchedule($form, $opensAt, null, 'Asia/Manila', null, $this->admin);

    // The editor loaded the form before the sweep ran: it still says `scheduled`.
    DB::table('forms')->where('id', $form->id)->update(['schedule_state' => FormScheduleState::Scheduled->value]);
    $stale = Form::query()->whereKey($form->id)->firstOrFail();

    // The sweeper's open pass commits behind that copy.
    DB::table('forms')->where('id', $form->id)->update(['schedule_state' => FormScheduleState::Open->value]);

    $service->setSchedule($stale, $opensAt, null, 'Asia/Manila', 50, $this->admin);

    expect(formSettingsLockingAudit($form->id)->old_values['schedule_state'] ?? null)
        ->toBe(FormScheduleState::Open->value, 'the audit recorded the pre-sweep state');
});

it('leaves no public writer on FormService outside the lock list', function (): void {
    $public = array_map(
        static fn (ReflectionMethod $method): string => $method->getName(),
        (new ReflectionClass(FormService::class))->getMethods(ReflectionMethod::IS_PUBLIC),
    );
    $writers = array_values(array_diff($public, ['__construct', 'create']));
    $lockers = formSettingsLockingLockers();
    sort($writers);
    sort($lockers);

    expect($writers)->toBe($lockers, 'a public FormService method is missing from formSettingsLockingLockers() — lock it, then list it');
});
