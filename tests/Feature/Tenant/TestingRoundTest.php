<?php

declare(strict_types=1);

use App\Enums\FeedbackCommentAction;
use App\Enums\FeedbackParty;
use App\Enums\FeedbackSource;
use App\Enums\FeedbackStatus;
use App\Enums\FeedbackType;
use App\Models\FeedbackComment;
use App\Models\FeedbackReport;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M179 (`R-db9dec62`, `R-129c44c3`, `D150`, `D151`) — what each member's Feedback page holds.
|--------------------------------------------------------------------------
| Two lists, never one with an `OR`: **My reports** is the member's own button reports (`user_id = me AND
| source = button`, paginated, unchanged in shape), and **Testing-round comments** is every relayed report of
| the workspace. An `OR` on one list would show the Owner who relayed them all 31 twice — and the relayed list's
| predicate is the whole boundary that lets every member see them while a colleague's button report stays
| hidden (RLS on this table is tenant-wide).
|
| Every row says what this viewer may do with it — `actions` from the member graph, `can_comment` from the
| policy — so the page never has to know the lifecycle. The Owner/Admin list at `/feedback` finally carries the
| support team's note, the measured gap in `R-129c44c3`.
*/

beforeEach(function (): void {
    $this->withoutVite();
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->tenant->domains()->create(['domain' => 'acme']);
    $this->owner = User::factory()->create(['name' => 'Olivia Owner']);
    $this->tester = User::factory()->create(['name' => 'Tess Tester']);
    $this->colleague = User::factory()->create(['name' => 'Cole Colleague']);
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    makeActiveMember($this->tester, 'viewer');
    makeActiveMember($this->colleague, 'form_editor');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function roundReport(User $reporter, string $remarks, FeedbackStatus $status = FeedbackStatus::New, ?array $relay = null): FeedbackReport
{
    $report = FeedbackReport::create([
        'user_id' => $reporter->id,
        'type' => FeedbackType::Bug,
        'route' => '/forms',
        'remarks' => $remarks,
        'status' => $status,
    ]);

    if ($relay !== null) {
        $report->forceFill(['source' => FeedbackSource::Relayed, ...$relay])->save();
    }

    return $report->refresh();
}

function roundComment(FeedbackReport $report, ?User $author, FeedbackParty $side, string $body, ?FeedbackCommentAction $action = null): void
{
    $comment = new FeedbackComment;
    $comment->forceFill([
        'id' => Uuid::uuid7()->toString(),
        'feedback_report_id' => $report->id,
        'user_id' => $author?->id,
        'author_kind' => $side,
        'action' => $action,
        'body' => $body,
    ])->save();
}

it('shows a member their own button reports and every relayed report of the workspace — never a colleague\'s', function (): void {
    roundReport($this->tester, 'Mine.');
    roundReport($this->colleague, 'A colleague\'s.');
    roundReport($this->owner, 'Relayed, fixed.', FeedbackStatus::Resolved, ['reference' => 'U1', 'title' => 'Allow negative', 'source_label' => 'Your items, Oct 16']);
    roundReport($this->owner, 'Relayed, confirmed.', FeedbackStatus::Confirmed, ['reference' => 'R1-4', 'title' => 'The key has already been taken']);
    roundReport($this->owner, 'Relayed, in hand.', FeedbackStatus::Ongoing, ['reference' => 'U8', 'title' => 'Calculations by clicking']);

    $other = Tenant::create(['name' => 'Globex', 'slug' => 'globex']);
    $stranger = User::factory()->create();
    enterTenant($other->id, $stranger->id);
    makeActiveMember($stranger, 'owner');
    roundReport($stranger, 'Another workspace, relayed.', FeedbackStatus::Resolved, ['reference' => 'U1']);
    enterTenant($this->tenant->id, $this->owner->id);

    $this->actingAs($this->tester)->get('http://acme.meridian.test/feedback/mine')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('feedback/Mine', false)
            ->has('data', 1)
            ->where('data.0.remarks', 'Mine.')
            ->has('testing_round.data', 3)
            ->where('testing_round.open', 2) // Resolved and Ongoing; Confirmed is done
            ->where('testing_round.data', fn ($rows): bool => collect($rows)->pluck('reference')->sort()->values()->all() === ['R1-4', 'U1', 'U8']));
});

it('keeps the Owner\'s relayed reports out of their own My reports', function (): void {
    roundReport($this->owner, 'The Owner\'s own.');
    roundReport($this->owner, 'Relayed by the Owner.', FeedbackStatus::Resolved, ['reference' => 'U2']);

    $this->actingAs($this->owner)->get('http://acme.meridian.test/feedback/mine')
        ->assertInertia(fn (Assert $page) => $page
            ->has('data', 1)
            ->where('data.0.remarks', 'The Owner\'s own.')
            ->has('testing_round.data', 1));
});

it('says on every row what this member may do, and shows the thread with who wrote each message', function (): void {
    $fixed = roundReport($this->owner, 'Relayed, fixed.', FeedbackStatus::Resolved, ['reference' => 'U3', 'title' => 'Larger label box', 'source_label' => 'Your items, Oct 16']);
    $fixed->forceFill(['resolution_note' => 'Fixed in version a5b718b, live on staging.'])->save();
    roundComment($fixed, null, FeedbackParty::Support, 'Please check on a long question.');
    roundComment($fixed, $this->colleague, FeedbackParty::Member, 'Looks bigger to me.');
    $confirmed = roundReport($this->owner, 'Relayed, confirmed.', FeedbackStatus::Confirmed, ['reference' => 'P3']);
    $confirmed->forceFill(['confirmed_by' => $this->colleague->id, 'confirmed_at' => now()])->save();
    roundReport($this->owner, 'Relayed, in hand.', FeedbackStatus::Ongoing, ['reference' => 'U9']);

    $this->actingAs($this->tester)->get('http://acme.meridian.test/feedback/mine')
        ->assertInertia(fn (Assert $page) => $page->where('testing_round.data', function ($rows): bool {
            $rows = collect($rows)->keyBy('reference');

            return $rows['U3']['title'] === 'Larger label box'
                && $rows['U3']['source_label'] === 'Your items, Oct 16'
                && $rows['U3']['resolution_note'] === 'Fixed in version a5b718b, live on staging.'
                && array_column($rows['U3']['actions'], 'verb') === ['confirm', 'reopen']
                && array_column($rows['U3']['actions'], 'label') === ['It works', 'Still a problem']
                && $rows['U3']['can_comment'] === true
                && array_column($rows['U3']['comments'], 'author') === ['Support team', 'Cole Colleague']
                && array_column($rows['P3']['actions'], 'verb') === ['reopen']
                && $rows['P3']['confirmer'] === 'Cole Colleague'
                && $rows['U9']['actions'] === [];
        }));
});

it('opens on the testing round when asked, or when the member has sent nothing themselves', function (): void {
    roundReport($this->owner, 'Relayed, fixed.', FeedbackStatus::Resolved, ['reference' => 'U6']);

    $this->actingAs($this->tester)->get('http://acme.meridian.test/feedback/mine')
        ->assertInertia(fn (Assert $page) => $page->where('view', 'testing'));

    enterTenant($this->tenant->id, $this->owner->id);
    roundReport($this->tester, 'Mine.');

    $this->actingAs($this->tester)->get('http://acme.meridian.test/feedback/mine')
        ->assertInertia(fn (Assert $page) => $page->where('view', 'mine'));
    $this->actingAs($this->tester)->get('http://acme.meridian.test/feedback/mine?view=testing')
        ->assertInertia(fn (Assert $page) => $page->where('view', 'testing'));
});

it('shows Owner/Admin the support team\'s note and the thread at /feedback, and filters by where a report came from', function (): void {
    $relayed = roundReport($this->owner, 'Relayed.', FeedbackStatus::Resolved, ['reference' => 'U7', 'title' => 'Remove Duration']);
    $relayed->forceFill(['resolution_note' => 'Fixed in version a5b718b, live on staging.'])->save();
    roundComment($relayed, $this->tester, FeedbackParty::Member, 'Gone from the palette, thanks.');
    roundReport($this->colleague, 'A button report.');

    $this->actingAs($this->owner)->get('http://acme.meridian.test/feedback')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('data', 2)
            ->has('filters.sources', 2)
            ->where('data', fn ($rows): bool => collect($rows)->firstWhere('reference', 'U7')['resolution_note'] === 'Fixed in version a5b718b, live on staging.'
                && collect($rows)->firstWhere('reference', 'U7')['comments'][0]['author'] === 'Tess Tester'
                && array_column(collect($rows)->firstWhere('reference', 'U7')['actions'], 'verb') === ['confirm', 'reopen']));

    $this->actingAs($this->owner)->get('http://acme.meridian.test/feedback?source=relayed')
        ->assertInertia(fn (Assert $page) => $page->has('data', 1)->where('data.0.reference', 'U7'));
});
