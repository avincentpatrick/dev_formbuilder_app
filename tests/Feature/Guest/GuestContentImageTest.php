<?php

declare(strict_types=1);

use App\Enums\AttachmentKind;
use App\Enums\FieldType;
use App\Enums\RequiredMode;
use App\Enums\ScanStatus;
use App\Models\Attachment;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M130 — a note's image, read by a respondent (R-c9f50df2): `GET /api/v1/public/content-images/{shareToken}/{image}`.
|--------------------------------------------------------------------------
| The guest page draws a note's image blocks from this route, so it is the one unauthenticated read of a stored
| file a form owns. It serves an image only when the token's own published version shows it, and every other case
| is the same enveloped 404 — the route says nothing about what exists.
|
| ⚠️ EVERY REFUSAL RUNS ITS POSITIVE CONTROL FIRST, in the same test, and then changes ONE fact: a route that does
| not exist answers 404 too (M122), so a 404 alone proves nothing about the boundary under test.
|
| ⚠️ Helpers are prefixed `guestContentImage*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Storage::fake(config('filesystems.default'));

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function guestContentImageUrl(string $token, string $image): string
{
    return "http://acme.meridian.test/api/v1/public/content-images/{$token}/{$image}";
}

/** A form's own clean image, written to the table and the disk. */
function guestContentImageStored(Form $form, array $state = []): Attachment
{
    $attachment = Attachment::factory()->create([
        'attachable_type' => 'form',
        'attachable_id' => $form->id,
        'kind' => AttachmentKind::FormContentImage,
        'mime_type' => 'image/png',
        'original_filename' => 'entrance.png',
        'virus_scan_status' => ScanStatus::Clean,
        ...$state,
    ]);
    Storage::disk($attachment->disk)->put($attachment->path, 'PNGBYTES');

    return $attachment;
}

/**
 * A guest-enabled form whose published note shows one image, and the image.
 *
 * @return array{0: Form, 1: Attachment}
 */
function guestContentImageForm(Tenant $tenant, User $owner, string $slug = 'intake'): array
{
    $form = app(FormService::class)->create($tenant, $owner, 'Clinic Intake');
    $image = guestContentImageStored($form);
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0, ['is_required' => RequiredMode::Required]);
    addFormField($form->draftVersion, $owner, 'welcome', FieldType::Note, 1, [
        'label' => 'Welcome (for the team)',
        'config' => ['content' => [
            ['type' => 'paragraph', 'spans' => [['text' => 'Find us here:']]],
            ['type' => 'image', 'attachment_id' => $image->id, 'alt' => 'The clinic entrance'],
        ]],
    ]);
    app(PublishService::class)->publish($form->refresh(), $owner);
    $form->refresh()->update(['public_slug' => $slug, 'allow_guest_submissions' => true]);

    return [$form->refresh(), $image];
}

it('serves an image the token’s version shows, inline and never sniffed', function (): void {
    [$form, $image] = guestContentImageForm($this->tenant, $this->owner);

    $response = $this->get(guestContentImageUrl(shareTokenFor($form), $image->id));

    $response->assertOk()
        ->assertHeader('Content-Type', 'image/png')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->streamedContent())->toBe('PNGBYTES')
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        // An id never names different bytes; the browser may keep it a day, and only for this respondent.
        ->and($response->headers->getCacheControlDirective('private'))->toBeTrue()
        ->and($response->headers->getCacheControlDirective('max-age'))->toBe('86400');
});

it('refuses with one enveloped 404 each image the token’s version does not show, after serving the one it does', function (string $case): void {
    [$form, $image] = guestContentImageForm($this->tenant, $this->owner);
    $token = shareTokenFor($form);

    // The positive control: this exact request is served before the one fact under test changes.
    $this->get(guestContentImageUrl($token, $image->id))->assertOk();
    enterTenant($this->tenant->id, $this->owner->id);

    $requested = match ($case) {
        'an image the form owns that no note in the version names' => guestContentImageStored($form)->id,
        'an image of another kind' => tap($image)->update(['kind' => AttachmentKind::FieldMediaSample])->id,
        'an image held by something other than a form' => tap($image)->update(['attachable_type' => 'form_field'])->id,
        'an image another form owns' => tap($image)->update([
            'attachable_id' => app(FormService::class)->create($this->tenant, $this->owner, 'Another form')->id,
        ])->id,
        'an image still being checked' => tap($image)->update(['virus_scan_status' => ScanStatus::Pending])->id,
        'an image the check refused' => tap($image)->update(['virus_scan_status' => ScanStatus::Infected])->id,
        'a form whose guest access is off' => tap($image, fn () => $form->update(['allow_guest_submissions' => false]))->id,
        'an id that is not a uuid' => 'not-a-uuid',
    };

    $this->getJson(guestContentImageUrl($token, $requested))
        ->assertNotFound()
        ->assertExactJson(['error' => ['code' => 'image_not_found', 'message' => 'This image is not available.']]);
})->with([
    'an image the form owns that no note in the version names',
    'an image of another kind',
    'an image held by something other than a form',
    'an image another form owns',
    'an image still being checked',
    'an image the check refused',
    'a form whose guest access is off',
    'an id that is not a uuid',
]);

it('reads the version the token is pinned to, so a republish that drops an image keeps it for a respondent already filling', function (): void {
    [$form, $image] = guestContentImageForm($this->tenant, $this->owner);
    $pinnedToFirst = shareTokenFor($form);

    // The author removes the image and republishes; the next visitor's token is pinned to the new version.
    FormField::query()
        ->where('form_version_id', $form->draft_version_id)
        ->where('key', 'welcome')
        ->firstOrFail()
        ->update(['config' => ['content' => [['type' => 'paragraph', 'spans' => [['text' => 'Find us here.']]]]]]);
    app(PublishService::class)->publish($form->refresh(), $this->owner);
    $pinnedToSecond = shareTokenFor($form->refresh());

    $this->get(guestContentImageUrl($pinnedToFirst, $image->id))->assertOk();
    $this->getJson(guestContentImageUrl($pinnedToSecond, $image->id))->assertNotFound();
});

it('never serves another workspace’s image, even one its own form shows', function (): void {
    [$form, $image] = guestContentImageForm($this->tenant, $this->owner);
    $this->get(guestContentImageUrl(shareTokenFor($form), $image->id))->assertOk();

    $beta = guestTenant('beta');
    $betaOwner = User::factory()->create();
    enterTenant($beta->id, $betaOwner->id);
    [$betaForm, $betaImage] = guestContentImageForm($beta, $betaOwner);
    $this->get(guestContentImageUrl(shareTokenFor($betaForm), $betaImage->id))->assertOk();

    // Acme's token, beta's image id: row-level security hides the row, and the answer is the same 404.
    $this->getJson(guestContentImageUrl(shareTokenFor($form), $betaImage->id))
        ->assertNotFound()
        ->assertJsonPath('error.code', 'image_not_found');
});

it('refuses an expired token before the image is looked at, with the token middleware’s own answer', function (): void {
    [$form, $image] = guestContentImageForm($this->tenant, $this->owner);
    $this->get(guestContentImageUrl(shareTokenFor($form), $image->id))->assertOk();

    $this->getJson(guestContentImageUrl(shareTokenFor($form, now: time() - 90_000), $image->id))
        ->assertUnauthorized()
        ->assertJsonPath('error.code', 'share_token_expired');
});

it('spends its own budget, never the per-token budget a respondent submits with', function (): void {
    [$form, $image] = guestContentImageForm($this->tenant, $this->owner);
    $token = shareTokenFor($form);
    config(['guest.rate_limit.submit_per_token' => 1, 'guest.rate_limit.content_image_per_token' => 2]);

    // Two images fit the image budget, and are past what a submit budget of one would have allowed.
    $this->get(guestContentImageUrl($token, $image->id))->assertOk();
    $this->get(guestContentImageUrl($token, $image->id))->assertOk();
    $this->getJson(guestContentImageUrl($token, $image->id))->assertTooManyRequests();

    // And the schema read on the same token still has its whole budget: no image was counted against it.
    $this->getJson("http://acme.meridian.test/api/v1/public/f/{$token}")->assertOk();
});
