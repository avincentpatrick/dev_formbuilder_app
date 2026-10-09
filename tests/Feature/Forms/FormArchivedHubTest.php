<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\SubmissionStatus;
use App\Models\Audit;
use App\Models\Form;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| An archived form's own page (M155, `R-aaf36122`, `D107` A).
|--------------------------------------------------------------------------
| `M154` made an archived form's row on the Forms list read-only; the page its title opens still offered Edit
| form, Share, the Builder and Settings tabs and — on its Responses tab — "Scan paper forms", and its tile read
| "Accepting". The user's question that started it was "how can i retrieve the responses from that archived
| form?", so every case that hides something sits beside one proving the way to the responses is still there.
|
| ⛔ A PAGE MASK, NOT A PERMISSION. `D107` A: `SubmissionPolicy::create()` still admits an archived form, because
| the same check gates the offline sync API, staff drafts and attachment uploads, and a tablet's responses queued
| before the archive must still arrive. Nothing here may be "fixed" by refusing there.
|--------------------------------------------------------------------------
*/

beforeEach(function (): void {
    // Whole pages render here, and CI builds no Vite manifest (measured, M129).
    $this->withoutVite();
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = inboxTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');

    $this->form = publishedInboxForm($this->tenant, $this->owner, 'Clinic Retired');
    $this->form->forceFill(['allow_ocr_single' => true])->save();
    seedInboxSubmission($this->form, $this->owner, SubmissionStatus::Submitted, [
        'full_name' => 'Ada Lovelace', 'color' => 'r', 'hobbies' => ['read'], 'subscribe' => true,
    ]);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function archivedHubUrl(Form $form, string $tail = ''): string
{
    return 'http://acme.meridian.test/forms/'.$form->id.$tail;
}

/** @return list<string> */
function archivedHubTabKeys(array $props): array
{
    return array_map(static fn (array $tab): string => (string) $tab['key'], $props['tabs']);
}

function archiveForTest(Form $form, User $actor): Form
{
    enterTenant($form->tenant_id, $actor->id);

    return app(FormService::class)->archive($form, $actor);
}

it('offers editing on the page of a form that is not archived — the control for every case below', function (): void {
    $props = $this->actingAs($this->owner)->get(archivedHubUrl($this->form))->assertOk()->viewData('page')['props'];

    expect(archivedHubTabKeys($props))->toBe(['overview', 'submissions', 'builder', 'analytics', 'settings'])
        ->and($props['can']['edit'])->toBeTrue()
        ->and($props['can']['encode'])->toBeTrue()
        ->and($props)->toHaveKey('share')
        ->and($props['schedule']['acceptance'])->toBe('open');
});

it('offers nothing that edits or adds to an archived form on its own page', function (): void {
    archiveForTest($this->form, $this->owner);

    $this->actingAs($this->owner)->get(archivedHubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('form.status', 'archived')
            ->where('can.edit', false)
            ->where('can.publish', false)
            ->where('can.encode', false)
            // Print blank on the published version stays: the paper is a read of the form, not a change to it.
            ->where('can.template', true)
            ->missing('share')
            ->etc());
});

it('drops the Builder and Settings tabs on every page of an archived form, and keeps Responses and Analytics', function (): void {
    archiveForTest($this->form, $this->owner);

    foreach (['', '/submissions', '/analytics'] as $tail) {
        $props = $this->actingAs($this->owner)->get(archivedHubUrl($this->form, $tail))->assertOk()->viewData('page')['props'];

        expect(archivedHubTabKeys($props))->toBe(['overview', 'submissions', 'analytics'], "the strip on '{$tail}'");
    }
});

it('says an archived form is closed, whatever its schedule says', function (): void {
    // A window still open for another week: the schedule alone would read "open".
    $this->form->forceFill(['closes_at' => CarbonImmutable::now()->addWeek()])->save();
    archiveForTest($this->form, $this->owner);

    $this->actingAs($this->owner)->get(archivedHubUrl($this->form))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('schedule.acceptance', 'closed')->etc());
});

it('keeps the responses of an archived form listed and exportable, with no "Scan paper forms" entry', function (): void {
    archiveForTest($this->form, $this->owner);

    $this->actingAs($this->owner)->get(archivedHubUrl($this->form, '/submissions'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('scan_url', null)
            ->has('data', 1)
            ->etc());

    $csv = $this->actingAs($this->owner)
        ->get(archivedHubUrl($this->form, '/submissions/export?format=csv'))
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Ada Lovelace');
});

it('offers "Scan paper forms" on the same Responses tab while the form is not archived', function (): void {
    $this->actingAs($this->owner)->get(archivedHubUrl($this->form, '/submissions'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('scan_url', "/forms/{$this->form->id}/ocr/scans")->etc());
});

it('leaves an archived form untouched when it is archived again: no new date, no second audit entry', function (): void {
    $first = archiveForTest($this->form, $this->owner);
    $stamped = $first->archived_at?->toIso8601String();

    $this->travel(5)->minutes();
    $again = archiveForTest($first, $this->owner);

    $entries = Audit::query()
        ->where('auditable_type', 'form')
        ->where('auditable_id', $this->form->id)
        ->where('event', AuditEvent::Archived->value)
        ->count();

    expect($stamped)->not->toBeNull()
        ->and($again->archived_at?->toIso8601String())->toBe($stamped)
        ->and($this->form->refresh()->archived_at?->toIso8601String())->toBe($stamped)
        ->and($entries)->toBe(1);
});
