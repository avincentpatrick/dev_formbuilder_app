<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Enums\FormBotChallenge;
use App\Enums\OcrScanSource;
use App\Enums\OcrScanStatus;
use App\Enums\PlanTier;
use App\Enums\UsageMetric;
use App\Jobs\ReadOcrScanJob;
use App\Jobs\ScanAttachmentJob;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\OcrScan;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Entitlements\EntitlementService;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Settings\TenantSettingRegistry;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Submissions\SubmissionReference;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Ramsey\Uuid\Uuid;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M165 — a filled paper copy from the public link (`R-7173c02c`, `D106`).
|--------------------------------------------------------------------------
| `POST /api/v1/public/f/{shareToken}/scans`: photos or a scan of the printed form, read by the same job as a staff
| scan and saved as a response only by a staff reviewer. The user's answers (2026-10-12) are each a case here: the
| same staff list marks the scan "From the public link"; the guest is told only a reference; and the spend guard is
| a spam check on every upload plus two limits the form's owner sets — a per-device window and a daily cap.
|
| Every refusal runs after the same request has been admitted, so it can only come from the boundary under test.
| Each upload comes from its own address unless a case is about the window, so no case spends another's slots.
|
| ⚠️ Helpers are prefixed `paperCopy*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake('local');
    Queue::fake();

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'admin');

    $this->form = paperCopyForm(guestForm($this->tenant, $this->owner));
    $this->token = shareTokenFor($this->form);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** Open a guest form to paper copies: scanning on, and the public link taking them. */
function paperCopyForm(Form $form): Form
{
    $form->forceFill(['allow_ocr_single' => true, 'allow_guest_ocr' => true])->save();

    return $form->refresh();
}

function paperCopyPng(string $name = 'page.png'): UploadedFile
{
    return UploadedFile::fake()->createWithContent($name, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
}

/** A fresh address per upload, so no case spends another upload's slot in its device window. */
function paperCopyAddress(): string
{
    static $n = 0;

    return '198.51.100.'.(++$n % 250 + 1);
}

/** Mint and solve a spam check for `$token` exactly as the browser does, and return the header it sends. */
function paperCopySolution(TestCase $test, string $token, string $ip): string
{
    $challenge = $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson("http://acme.meridian.test/api/v1/public/f/{$token}/challenge")
        ->assertOk()
        ->json('data');

    for ($n = 0; $n <= $challenge['maxnumber']; $n++) {
        if (hash('sha256', $challenge['salt'].$n) === $challenge['challenge']) {
            return base64_encode((string) json_encode([
                'algorithm' => $challenge['algorithm'],
                'challenge' => $challenge['challenge'],
                'salt' => $challenge['salt'],
                'number' => $n,
                'signature' => $challenge['signature'],
            ]));
        }
    }

    throw new RuntimeException('Unsolvable challenge.');
}

/**
 * Upload `$pages` to `$token`'s form from `$ip`. `$challenge` is `solve` (a real solution), `none` (no header) or
 * any other string, sent as the header verbatim.
 *
 * @param  list<UploadedFile>|null  $pages
 */
function paperCopyPost(TestCase $test, string $token, ?array $pages = null, string $challenge = 'solve', ?string $ip = null): TestResponse
{
    $ip ??= paperCopyAddress();
    $headers = ['Accept' => 'application/json'];
    if ($challenge !== 'none') {
        $headers['X-Meridian-Challenge'] = $challenge === 'solve' ? paperCopySolution($test, $token, $ip) : $challenge;
    }

    return $test->withServerVariables(['REMOTE_ADDR' => $ip])
        ->post("http://acme.meridian.test/api/v1/public/f/{$token}/scans", ['pages' => $pages ?? [paperCopyPng()]], $headers);
}

function paperCopyScans(string $tenantId, string $userId): int
{
    enterTenant($tenantId, $userId);

    return OcrScan::query()->count();
}

// ── The happy path ─────────────────────────────────────────────────────────────────────────────

it('takes a filled paper copy with no uploader, marks it as from the public link, and answers a reference and a paper token for its sender', function (): void {
    $response = paperCopyPost($this, $this->token, [paperCopyPng('one.png'), paperCopyPng('two.png')])->assertStatus(202);

    // Nothing that was read, and not the scan's id in the clear: a reference to quote, in the format a response's has, and
    // (M166, `D116`) the signed paper token with which only this page can wait for the reading and check it.
    $reference = $response->json('data.reference');
    $paper = $response->json('data.paper_token');
    expect(array_keys($response->json('data')))->toBe(['reference', 'paper_token'])
        ->and($reference)->toMatch('/^[0-9A-Z]{4}-[0-9A-Z]{4}$/');

    enterTenant($this->tenant->id, $this->owner->id);
    $scan = OcrScan::query()->sole();
    $pages = Attachment::query()->whereIn('id', array_column($scan->pages, 'attachment_id'))->get();

    expect(app(GuestShareTokenService::class)->verifyPaper((string) $paper)->scanId)->toBe($scan->id)
        ->and($scan->source)->toBe(OcrScanSource::Guest)
        ->and($scan->uploaded_by)->toBeNull()
        ->and($scan->reference)->toBe(SubmissionReference::normalize($reference))
        ->and($scan->status)->toBe(OcrScanStatus::Queued)
        ->and($pages)->toHaveCount(2);

    foreach ($pages as $page) {
        expect($page->uploaded_by)->toBeNull()
            ->and($page->is_pii)->toBeTrue()
            ->and($page->attachable_id)->toBe($scan->id);
    }

    Queue::assertPushed(ReadOcrScanJob::class, fn (ReadOcrScanJob $job): bool => $job->scanId === $scan->id);
    Queue::assertPushed(ScanAttachmentJob::class, 2);
});

it('still records a staff upload as staff, with no reference', function (): void {
    $this->actingAs($this->owner)
        ->post("http://acme.meridian.test/forms/{$this->form->id}/ocr/scans", ['pages' => [paperCopyPng()]], ['Accept' => 'application/json'])
        ->assertStatus(202);

    enterTenant($this->tenant->id, $this->owner->id);
    $scan = OcrScan::query()->sole();
    expect($scan->source)->toBe(OcrScanSource::Staff)
        ->and($scan->reference)->toBeNull()
        ->and($scan->uploaded_by)->toBe($this->owner->id);
});

// ── The spam check: required whatever the form's own setting ───────────────────────────────────

it('requires a solved spam check even on a form whose own spam check is off', function (): void {
    expect($this->form->bot_challenge)->toBe(FormBotChallenge::Off);

    paperCopyPost($this, $this->token)->assertStatus(202);

    paperCopyPost($this, $this->token, challenge: 'none')
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'challenge_required');
    paperCopyPost($this, $this->token, challenge: base64_encode('{"number":1}'))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'challenge_failed');

    expect(paperCopyScans($this->tenant->id, $this->owner->id))->toBe(1);
});

// ── The form must say yes ──────────────────────────────────────────────────────────────────────

it('refuses with one neutral answer when the guest-upload switch, scanning or guest access is off', function (): void {
    paperCopyPost($this, $this->token)->assertStatus(202);

    foreach (['allow_guest_ocr', 'allow_ocr_single', 'allow_guest_submissions'] as $switch) {
        enterTenant($this->tenant->id, $this->owner->id);
        $this->form->refresh()->forceFill([$switch => false])->save();

        paperCopyPost($this, $this->token)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'paper_upload_unavailable');

        enterTenant($this->tenant->id, $this->owner->id);
        $this->form->refresh()->forceFill([$switch => true])->save();
    }

    expect(paperCopyScans($this->tenant->id, $this->owner->id))->toBe(1);
});

it('refuses a form whose current version paper cannot carry', function (): void {
    paperCopyPost($this, $this->token)->assertStatus(202);

    enterTenant($this->tenant->id, $this->owner->id);
    $located = app(FormService::class)->create($this->tenant, $this->owner, 'With a location');
    addFormField($located->draftVersion, $this->owner, 'where', FieldType::Geopoint, 0);
    app(PublishService::class)->publish($located->refresh(), $this->owner);
    $located->refresh()->forceFill(['public_slug' => 'located', 'allow_guest_submissions' => true])->save();
    $located = paperCopyForm($located);

    paperCopyPost($this, shareTokenFor($located))
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'paper_upload_unavailable');
});

it('refuses a form that is closed, as a submission is refused', function (): void {
    paperCopyPost($this, $this->token)->assertStatus(202);

    enterTenant($this->tenant->id, $this->owner->id);
    $this->form->forceFill(['closes_at' => now()->subHour()])->save();

    paperCopyPost($this, $this->token)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'form_closed');

    expect(paperCopyScans($this->tenant->id, $this->owner->id))->toBe(1);
});

it('says the workspace switched scanning off before it ever mentions the plan', function (): void {
    paperCopyPost($this, $this->token)->assertStatus(202);

    enterTenant($this->tenant->id, $this->owner->id);
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.ocr_single' => false], $this->owner);
    paperCopyPost($this, $this->token)->assertStatus(403);

    enterTenant($this->tenant->id, $this->owner->id);
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.ocr_single' => true], $this->owner);
    assignPlanTier(PlanTier::Starter);
    paperCopyPost($this, $this->token)->assertStatus(402);

    expect(paperCopyScans($this->tenant->id, $this->owner->id))->toBe(1);
});

it('refuses a full storage quota with the neutral answer, never an upgrade prompt, and stores nothing', function (): void {
    paperCopyPost($this, $this->token)->assertStatus(202);

    enterTenant($this->tenant->id, $this->owner->id);
    $plan = Plan::factory()->tier(PlanTier::Professional)
        ->withFeatures(['ocr_single' => true])
        ->withQuotas([UsageMetric::StorageBytes->value => 10])
        ->create();
    Subscription::factory()->forPlan($plan)->create();
    app(EntitlementService::class)->forget();
    $pagesBefore = Attachment::query()->count();

    paperCopyPost($this, $this->token)
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'paper_upload_unavailable');

    expect(paperCopyScans($this->tenant->id, $this->owner->id))->toBe(1)
        ->and(Attachment::query()->count())->toBe($pagesBefore);
});

// ── The limits the form's owner sets ───────────────────────────────────────────────────────────

it('takes three uploads per device per the form\'s window, then again once the window has passed', function (): void {
    enterTenant($this->tenant->id, $this->owner->id);
    $this->form->forceFill(['guest_ocr_window_minutes' => 2])->save();

    $device = '203.0.113.7';
    foreach (range(1, 3) as $upload) {
        paperCopyPost($this, $this->token, ip: $device)->assertStatus(202);
    }

    $refused = paperCopyPost($this, $this->token, ip: $device)->assertStatus(429);
    expect((int) $refused->headers->get('Retry-After'))->toBeGreaterThan(60)->toBeLessThanOrEqual(120);

    // Another device is not held by this one's window.
    paperCopyPost($this, $this->token, ip: '203.0.113.8')->assertStatus(202);

    $this->travel(121)->seconds();
    paperCopyPost($this, $this->token, ip: $device)->assertStatus(202);
});

it('keeps a device\'s paper copies and its responses in separate buckets', function (): void {
    enterTenant($this->tenant->id, $this->owner->id);
    $this->form->forceFill(['guest_rate_limit_per_minute' => 1])->save();

    $device = '203.0.113.20';
    paperCopyPost($this, $this->token, ip: $device)->assertStatus(202);
    paperCopyPost($this, $this->token, ip: $device)->assertStatus(202);

    $this->withServerVariables(['REMOTE_ADDR' => $device])
        ->postJson("http://acme.meridian.test/api/v1/public/f/{$this->token}/submissions", ['answers' => ['full_name' => 'Ada']])
        ->assertCreated();
});

it('stops at the form\'s daily cap, counting only guest uploads of the last 24 hours', function (): void {
    enterTenant($this->tenant->id, $this->owner->id);
    $this->form->forceFill(['guest_ocr_daily_cap' => 2])->save();

    // Neither of these counts: a staff scan, and a guest scan from 25 hours ago.
    foreach ([['staff', now()], ['guest', now()->subHours(25)]] as [$source, $at]) {
        $scan = new OcrScan(['tenant_id' => $this->tenant->id, 'form_id' => $this->form->id, 'provider' => 'google_vision', 'pages' => []]);
        $scan->source = OcrScanSource::from($source);
        $scan->save();
        $scan->forceFill(['created_at' => $at])->save();
    }

    paperCopyPost($this, $this->token)->assertStatus(202);
    paperCopyPost($this, $this->token)->assertStatus(202);
    paperCopyPost($this, $this->token)
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'paper_upload_limit_reached');

    expect(paperCopyScans($this->tenant->id, $this->owner->id))->toBe(4);
});

// ── The staff list ─────────────────────────────────────────────────────────────────────────────

it('lists a guest scan as from the public link with its reference, and finds it by that reference however it is typed', function (): void {
    $reference = paperCopyPost($this, $this->token)->assertStatus(202)->json('data.reference');
    $this->actingAs($this->owner)
        ->post("http://acme.meridian.test/forms/{$this->form->id}/ocr/scans", ['pages' => [paperCopyPng()]], ['Accept' => 'application/json'])
        ->assertStatus(202);

    // Both in the same second, as CI produced by accident: newest-first must still put the later upload first.
    enterTenant($this->tenant->id, $this->owner->id);
    OcrScan::query()->update(['created_at' => now()->startOfSecond()]);

    $page = fn (string $query = '') => $this->actingAs($this->owner)->withoutVite()
        ->get("http://acme.meridian.test/forms/{$this->form->id}/ocr/scans{$query}")
        ->assertOk();

    $page()->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->component('ocr/Scans', false)
        ->has('scans', 2)
        ->where('scans.0.from_public_link', false)
        ->where('scans.0.reference', null)
        ->where('scans.1.from_public_link', true)
        ->where('scans.1.reference', $reference)
        ->where('scans.1.uploaded_by', null));

    $typed = strtolower(str_replace('-', ' ', $reference));
    $page('?reference='.urlencode($typed))->assertInertia(fn (AssertableInertia $inertia) => $inertia
        ->has('scans', 1)
        ->where('scans.0.reference', $reference)
        ->where('search.reference', $typed));

    // Something that cannot be a reference finds nothing, rather than listing every scan.
    $page('?reference=nope')->assertInertia(fn (AssertableInertia $inertia) => $inertia->has('scans', 0));
});

// ── The public page's hint ─────────────────────────────────────────────────────────────────────

it('offers the upload on the public page exactly where the upload route would take one', function (): void {
    $schema = fn () => $this->getJson("http://acme.meridian.test/api/v1/public/f/{$this->token}")->assertOk();

    expect($schema()->json('data.form.paper_upload'))->toBe([
        'max_pages' => 5,
        'accepted_types' => ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'],
        'max_bytes_per_file' => 7_000_000,
        'max_bytes_per_scan' => 25_000_000,
    ]);

    enterTenant($this->tenant->id, $this->owner->id);
    $this->form->forceFill(['allow_guest_ocr' => false])->save();
    expect($schema()->json('data.form.paper_upload'))->toBeNull();

    enterTenant($this->tenant->id, $this->owner->id);
    $this->form->forceFill(['allow_guest_ocr' => true])->save();
    app(TenantSettingRegistry::class)->put($this->tenant, ['modules.ocr_single' => false], $this->owner);
    expect($schema()->json('data.form.paper_upload'))->toBeNull();
});

it('keeps the list newest first when two scans share a second, whatever order they were stored in', function (): void {
    // CI met this by accident: `created_at` is kept to the second, so a tie came back in either order. Here the later
    // scan (the higher uuid7) is stored FIRST, so only the id tie-break can put it on top.
    $earlier = Uuid::uuid7()->toString();
    $later = Uuid::uuid7()->toString();
    expect(strcmp($later, $earlier))->toBeGreaterThan(0);

    enterTenant($this->tenant->id, $this->owner->id);
    foreach ([$later, $earlier] as $id) {
        $scan = new OcrScan(['tenant_id' => $this->tenant->id, 'form_id' => $this->form->id, 'provider' => 'google_vision', 'pages' => []]);
        $scan->id = $id;
        $scan->save();
    }
    OcrScan::query()->update(['created_at' => now()->startOfSecond()]);

    $this->actingAs($this->owner)->withoutVite()
        ->get("http://acme.meridian.test/forms/{$this->form->id}/ocr/scans")
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $inertia) => $inertia
            ->where('scans.0.id', $later)
            ->where('scans.1.id', $earlier));
});
