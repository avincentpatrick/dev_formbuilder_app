<?php

declare(strict_types=1);

use App\Enums\AuditEvent;
use App\Enums\FieldType;
use App\Enums\FormBotChallenge;
use App\Enums\RequiredMode;
use App\Models\Audit;
use App\Models\Form;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Forms\FormSlug;
use App\Support\Guest\GuestShareTokenService;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Increment I1 — the share surface (PRD Feature #3's missing half).
|--------------------------------------------------------------------------
| Before I1 `forms.public_slug` and `forms.allow_guest_submissions` had NO route and NO UI writer: only the
| XLSForm importer and the e2e seeder ever set them, so a real tenant could build and publish a form and
| still had no way to hand it to a respondent. These tests pin the write path that closes that.
|
| Five properties carry more weight than the rest, and each exists because the obvious implementation gets
| it wrong:
|   1. Uniqueness is PER TENANT and counts SOFT-DELETED forms. The index is (tenant_id, public_slug) with no
|      deleted_at predicate, so a model-scoped check reports a trashed row's slug free and the write then
|      dies on a 23505 nobody catches.
|   2. Guest-on with a null slug is REFUSED. It is not half-configured, it is unreachable: the runtime
|      resolves the form BY that column and every visitor gets the same 404 as an unknown form.
|   3. The write is AUDITED — the first `auditable_type = 'form'` row in the system. Turning guest access on
|      is the difference between a private draft and an open collection endpoint.
|   4. A slug change invalidates NOTHING except the old URL. Share tokens are HMACs over
|      (tenant, form, version) and resume links carry a token, not a slug — so a guest mid-form and a saved
|      draft both survive a rename. That is asserted here rather than assumed, because the whole shape of
|      the modal's rename warning depends on it being true.
|   5. The stored value is LOWERCASE, and M61 made that a load-bearing guarantee rather than a convention.
|      The runtime lookup is case-insensitive now, and `forms_tenant_id_public_slug_unique` is not — so a
|      mixed-case row would be a pair the lookup resolves to both. The regex refuses uppercase on the way in
|      and FormService lowers whatever it is handed; both halves are pinned below.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme']);
    $this->tenant->domains()->create(['domain' => 'acme']);

    $this->admin = User::factory()->create();
    enterTenant($this->tenant->id, $this->admin->id);
    makeActiveMember($this->admin, 'admin');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function shareUrl(Form $form): string
{
    return "http://acme.meridian.test/forms/{$form->id}/share";
}

/** A published form owned by the acting admin — the state in which a link can actually go live. */
function shareableForm(User $owner, string $title = 'Clinic Intake'): Form
{
    /** @var Tenant $tenant */
    $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();

    $form = app(FormService::class)->create($tenant, $owner, $title);
    addFormField($form->draftVersion, $owner, 'full_name', FieldType::ShortText, 0, [
        'is_required' => RequiredMode::Required,
    ]);
    app(PublishService::class)->publish($form->refresh(), $owner);

    return $form->refresh();
}

// ── The happy path ───────────────────────────────────────────────────────────────────────────

it('sets a public slug and opens guest access', function (): void {
    $form = shareableForm($this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), ['public_slug' => 'clinic-intake', 'allow_guest_submissions' => true, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = Form::query()->whereKey($form->id)->firstOrFail();

    expect($fresh->public_slug)->toBe('clinic-intake')
        ->and($fresh->allow_guest_submissions)->toBeTrue();

    // The point of the whole increment: the link now actually resolves.
    $this->getJson('http://acme.meridian.test/f/clinic-intake')->assertOk();
});

it('closes guest access without discarding the slug', function (): void {
    $form = shareableForm($this->admin);
    app(FormService::class)->setShareSettings($form, 'clinic-intake', true, FormBotChallenge::Off, null, $this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), ['public_slug' => 'clinic-intake', 'allow_guest_submissions' => false, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = Form::query()->whereKey($form->id)->firstOrFail();

    expect($fresh->public_slug)->toBe('clinic-intake')
        ->and($fresh->allow_guest_submissions)->toBeFalse();

    // Closing the toggle is the coarse revocation lever: the link stops working immediately, keeping the
    // name reserved so re-opening later does not need a new address on the flyers already printed.
    $this->getJson('http://acme.meridian.test/f/clinic-intake')->assertNotFound();
});

it('clears the link entirely when the slug is nulled', function (): void {
    $form = shareableForm($this->admin);
    app(FormService::class)->setShareSettings($form, 'clinic-intake', false, FormBotChallenge::Off, null, $this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), ['public_slug' => null, 'allow_guest_submissions' => false, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::query()->whereKey($form->id)->firstOrFail()->public_slug)->toBeNull();
});

// ── Validation ───────────────────────────────────────────────────────────────────────────────

// ⚠️ M61 MADE THE RUNTIME LOOKUP CASE-INSENSITIVE AND THIS CASE IS STILL CORRECT — do not delete the
// `uppercase` entry as obsolete. The reason it is refused changed rather than expired: the unique index is
// case-SENSITIVE, so accepting `Clinic-Intake` beside `clinic-intake` would let two authors believe they
// hold distinct link names while Rule::unique passes both, and the now-forgiving lookup would resolve the
// pair to both rows. Refusing on the way in is what keeps one canonical spelling per form.
it('rejects slugs that are not lowercase hyphenated', function (string $slug): void {
    $form = shareableForm($this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), ['public_slug' => $slug, 'allow_guest_submissions' => false, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null])
        ->assertSessionHasErrors('public_slug');
})->with([
    'uppercase' => 'Clinic-Intake',
    'spaces' => 'clinic intake',
    'leading hyphen' => '-clinic',
    'trailing hyphen' => 'clinic-',
    'doubled hyphen' => 'clinic--intake',
    'underscore' => 'clinic_intake',
    'slash' => 'clinic/intake',
    'query char' => 'clinic?intake',
    'too short' => 'ab',
]);

it('rejects the reserved `resume` slug', function (): void {
    // `/f/resume/{token}` is a sibling route. A form living at `/f/resume` is not a routing conflict, but it
    // is an address no author can explain, so it is refused by name.
    $form = shareableForm($this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), ['public_slug' => 'resume', 'allow_guest_submissions' => false, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null])
        ->assertSessionHasErrors('public_slug');
});

it('refuses to open guest access without a slug', function (): void {
    $form = shareableForm($this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), ['public_slug' => null, 'allow_guest_submissions' => true, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null])
        ->assertSessionHasErrors('public_slug');

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::query()->whereKey($form->id)->firstOrFail()->allow_guest_submissions)->toBeFalse();
});

it('requires the guest flag to be present', function (): void {
    $form = shareableForm($this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), ['public_slug' => 'clinic-intake'])
        ->assertSessionHasErrors('allow_guest_submissions');
});

// ── Uniqueness ───────────────────────────────────────────────────────────────────────────────

it('refuses a slug another form in the tenant already holds', function (): void {
    $taken = shareableForm($this->admin, 'First');
    app(FormService::class)->setShareSettings($taken, 'clinic-intake', false, FormBotChallenge::Off, null, $this->admin);
    $other = shareableForm($this->admin, 'Second');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($other), ['public_slug' => 'clinic-intake', 'allow_guest_submissions' => false, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null])
        ->assertSessionHasErrors('public_slug');
});

it('lets a form keep its own slug on an unrelated save', function (): void {
    // The `->ignore($form->id)` half: without it, toggling guest access on a form that already has a link
    // would 422 against itself.
    $form = shareableForm($this->admin);
    app(FormService::class)->setShareSettings($form, 'clinic-intake', false, FormBotChallenge::Off, null, $this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), ['public_slug' => 'clinic-intake', 'allow_guest_submissions' => true, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null])
        ->assertSessionHasNoErrors();
});

it('refuses a slug held by a SOFT-DELETED form', function (): void {
    // The DB index has no deleted_at predicate, so a trashed row keeps its slug reserved. A validator that
    // respected the SoftDeletes scope would pass this and then eat a 23505 in the service.
    $trashed = shareableForm($this->admin, 'Archived');
    app(FormService::class)->setShareSettings($trashed, 'clinic-intake', false, FormBotChallenge::Off, null, $this->admin);
    $trashed->delete();

    $other = shareableForm($this->admin, 'Live');

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($other), ['public_slug' => 'clinic-intake', 'allow_guest_submissions' => false, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null])
        ->assertSessionHasErrors('public_slug');
});

it('suggests past a SOFT-DELETED form that still holds the slug', function (): void {
    // The other half of the same defect, and the half that actually bites. FormSlug::suggest de-duplicates
    // with `-2`, `-3`, … and the check behind that loop must count trashed rows, because the DB index does.
    // Without `withTrashed()` this returns `clinic-intake`, which the validator then refuses — leaving the
    // modal pre-filled with a value that cannot be saved and no way for the author to know why.
    $trashed = shareableForm($this->admin, 'Clinic Intake');
    app(FormService::class)->setShareSettings($trashed, 'clinic-intake', false, FormBotChallenge::Off, null, $this->admin);
    $trashed->delete();

    $fresh = shareableForm($this->admin, 'Clinic Intake');

    expect(FormSlug::suggest($fresh))->toBe('clinic-intake-2');
});

it('lets a DIFFERENT tenant use the same slug', function (): void {
    // public_slug is unique per tenant, which is exactly why the guest runtime resolves tenant context from
    // the HOST before it ever looks at the slug.
    $mine = shareableForm($this->admin);
    app(FormService::class)->setShareSettings($mine, 'clinic-intake', true, FormBotChallenge::Off, null, $this->admin);

    $beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta']);
    $beta->domains()->create(['domain' => 'beta']);
    $betaAdmin = User::factory()->create();
    enterTenant($beta->id, $betaAdmin->id);
    makeActiveMember($betaAdmin, 'admin');

    $theirs = app(FormService::class)->create($beta, $betaAdmin, 'Their Intake');
    addFormField($theirs->draftVersion, $betaAdmin, 'full_name', FieldType::ShortText, 0);
    app(PublishService::class)->publish($theirs->refresh(), $betaAdmin);

    $this->actingAs($betaAdmin)->withoutVite()
        ->patch("http://beta.meridian.test/forms/{$theirs->id}/share", [
            'public_slug' => 'clinic-intake',
            'allow_guest_submissions' => true,
            'bot_challenge' => 'off',
            'guest_rate_limit_per_minute' => null,
        ])
        ->assertSessionHasNoErrors();

    enterTenant($beta->id, $betaAdmin->id);
    expect(Form::query()->whereKey($theirs->id)->firstOrFail()->public_slug)->toBe('clinic-intake');
});

// ── Authorization ────────────────────────────────────────────────────────────────────────────

it('refuses a member with no edit rights on the form', function (): void {
    $form = shareableForm($this->admin);

    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->withoutVite()
        ->patch(shareUrl($form), ['public_slug' => 'clinic-intake', 'allow_guest_submissions' => true, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null])
        ->assertForbidden();

    enterTenant($this->tenant->id, $this->admin->id);
    expect(Form::query()->whereKey($form->id)->firstOrFail()->public_slug)->toBeNull();
});

// ── Audit ────────────────────────────────────────────────────────────────────────────────────

it('audits the share write as an `updated` event on the form', function (): void {
    $form = shareableForm($this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), ['public_slug' => 'clinic-intake', 'allow_guest_submissions' => true, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null])
        ->assertRedirect();

    enterTenant($this->tenant->id, $this->admin->id);
    $audit = Audit::query()
        ->where('auditable_type', 'form')
        ->where('auditable_id', $form->id)
        ->latest('id')
        ->firstOrFail();

    expect($audit->event)->toBe(AuditEvent::Updated)
        ->and($audit->user_id)->toBe($this->admin->id)
        ->and($audit->old_values['public_slug'])->toBeNull()
        ->and($audit->old_values['allow_guest_submissions'])->toBeFalse()
        ->and($audit->new_values['public_slug'])->toBe('clinic-intake')
        ->and($audit->new_values['allow_guest_submissions'])->toBeTrue();
});

// ── What a slug change does and does not invalidate ───────────────────────────────────────────

it('leaves an in-flight share token valid across a slug rename', function (): void {
    // The pinned semantics behind the modal's rename warning. A share token is an HMAC over
    // (tenant, form, version) — the slug is not in it, so a guest who is mid-form keeps going.
    $form = shareableForm($this->admin);
    app(FormService::class)->setShareSettings($form, 'clinic-intake', true, FormBotChallenge::Off, null, $this->admin);

    $token = app(GuestShareTokenService::class)->mint(
        $form->tenant_id,
        $form->id,
        (string) $form->current_published_version_id,
    )->token;

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), ['public_slug' => 'clinic-intake-2026', 'allow_guest_submissions' => true, 'bot_challenge' => 'off', 'guest_rate_limit_per_minute' => null])
        ->assertRedirect();

    // Still resolves — the token pins a version id, not an address.
    $this->getJson("http://acme.meridian.test/api/v1/public/f/{$token}")->assertOk();

    // The new address works and the old one is gone. THIS is the consequence worth warning an author about.
    $this->getJson('http://acme.meridian.test/f/clinic-intake-2026')->assertOk();
    $this->getJson('http://acme.meridian.test/f/clinic-intake')->assertNotFound();
});

// ── The QR endpoint ──────────────────────────────────────────────────────────────────────────

it('renders the public link as an SVG QR code', function (): void {
    $form = shareableForm($this->admin);
    app(FormService::class)->setShareSettings($form, 'clinic-intake', true, FormBotChallenge::Off, null, $this->admin);

    $response = $this->actingAs($this->admin)->withoutVite()
        ->get("http://acme.meridian.test/forms/{$form->id}/share/qr.svg")
        ->assertOk()
        ->assertHeader('Content-Type', 'image/svg+xml')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect($response->getContent())->toContain('<svg');
});

it('404s the QR when the form has no link yet', function (): void {
    $form = shareableForm($this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->get("http://acme.meridian.test/forms/{$form->id}/share/qr.svg")
        ->assertNotFound();
});

it('refuses the QR to a member with no edit rights', function (): void {
    $form = shareableForm($this->admin);
    app(FormService::class)->setShareSettings($form, 'clinic-intake', true, FormBotChallenge::Off, null, $this->admin);

    $viewer = User::factory()->create();
    enterTenant($this->tenant->id, $viewer->id);
    makeActiveMember($viewer, 'viewer');

    $this->actingAs($viewer)->withoutVite()
        ->get("http://acme.meridian.test/forms/{$form->id}/share/qr.svg")
        ->assertForbidden();
});

// ── Spam protection (Increment I8b, PRD Feature #3) ───────────────────────────────────────────

it('saves spam protection in the SAME audit row as the rest of the share settings', function (): void {
    // ⚠️ THE REASON setShareSettings() GREW RATHER THAN GAINING A SIBLING. Spam protection is saved by the
    // same button in the same modal as the slug and the guest toggle, and "who turned the spam check off
    // on the form that then got flooded" is the same question, about the same act, as the one this audit
    // row exists to answer. Two service calls would make it two rows to correlate by timestamp.
    $form = shareableForm($this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), [
            'public_slug' => 'clinic-intake',
            'allow_guest_submissions' => true,
            'bot_challenge' => 'proof_of_work',
            'guest_rate_limit_per_minute' => 20,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->admin->id);

    $fresh = Form::query()->whereKey($form->id)->firstOrFail();
    expect($fresh->bot_challenge)->toBe(FormBotChallenge::ProofOfWork)
        ->and($fresh->guest_rate_limit_per_minute)->toBe(20);

    $audits = Audit::query()
        ->where('auditable_type', 'form')
        ->where('auditable_id', $form->id)
        ->where('event', AuditEvent::Updated->value)
        ->get();

    // ONE row for the whole save, carrying all four keys on both sides.
    expect($audits)->toHaveCount(1);
    expect($audits->first()->new_values)->toMatchArray([
        'public_slug' => 'clinic-intake',
        'allow_guest_submissions' => true,
        'bot_challenge' => 'proof_of_work',
        'guest_rate_limit_per_minute' => 20,
    ]);
    expect($audits->first()->old_values)->toMatchArray([
        'bot_challenge' => 'off',
        'guest_rate_limit_per_minute' => null,
    ]);
});

it('accepts a null rate limit as "no per-form ceiling"', function (): void {
    // `present` + `nullable`, not `required` — null is a meaningful value and `required` would make the
    // field unclearable once set, the same trap public_slug's rule avoids.
    $form = shareableForm($this->admin);
    app(FormService::class)->setShareSettings($form, 'clinic-intake', true, FormBotChallenge::ProofOfWork, 5, $this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), [
            'public_slug' => 'clinic-intake',
            'allow_guest_submissions' => true,
            'bot_challenge' => 'off',
            'guest_rate_limit_per_minute' => null,
        ])
        ->assertSessionHasNoErrors();

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = Form::query()->whereKey($form->id)->firstOrFail();

    expect($fresh->guest_rate_limit_per_minute)->toBeNull()
        ->and($fresh->bot_challenge)->toBe(FormBotChallenge::Off);
});

it('rejects an unknown challenge mechanism', function (): void {
    $form = shareableForm($this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), [
            'public_slug' => 'clinic-intake',
            'allow_guest_submissions' => false,
            'bot_challenge' => 'recaptcha',
            'guest_rate_limit_per_minute' => null,
        ])
        ->assertSessionHasErrors('bot_challenge');
});

it('rejects a rate limit outside 1..600', function (mixed $value): void {
    // 0 would refuse every response — a footgun disguised as a limit — and the upper bound keeps the
    // smallInteger column honest.
    $form = shareableForm($this->admin);

    $this->actingAs($this->admin)->withoutVite()
        ->patch(shareUrl($form), [
            'public_slug' => 'clinic-intake',
            'allow_guest_submissions' => false,
            'bot_challenge' => 'off',
            'guest_rate_limit_per_minute' => $value,
        ])
        ->assertSessionHasErrors('guest_rate_limit_per_minute');
})->with(['zero' => 0, 'negative' => -1, 'too large' => 601, 'not a number' => 'many']);

it('never lets the two new columns be mass-assigned', function (): void {
    // Neither is in Form::$fillable, deliberately. The two OLDER share columns are — for historical
    // reasons UpdateFormShareRequest's docblock apologises for — and adding two more would hand every
    // form editor the ability to switch a public form's spam protection off as a side effect of an
    // unrelated update().
    $form = shareableForm($this->admin);

    $form->update(['bot_challenge' => 'proof_of_work', 'guest_rate_limit_per_minute' => 1]);

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = Form::query()->whereKey($form->id)->firstOrFail();

    expect($fresh->bot_challenge)->toBe(FormBotChallenge::Off)
        ->and($fresh->guest_rate_limit_per_minute)->toBeNull();
});

// ── M61: the storage invariant the runtime lookup now depends on ──────────────────────────────

it('stores a slug lowercased even when the service is called directly, and audits the stored value', function (): void {
    $form = shareableForm($this->admin);

    // Unreachable through HTTP by design — the FormRequest's regex refuses uppercase before it gets here,
    // and the dataset case above pins that. This asserts the SERVICE's own guarantee, which matters
    // because the service is callable without a request (the XLSForm importer normalizes for the same
    // reason) and because FormSlug::forLookup() is only deterministic while it holds:
    // forms_tenant_id_public_slug_unique is case-SENSITIVE, so `Intake` beside `intake` would be a pair
    // the case-insensitive lookup resolves to both, with ->first() choosing arbitrarily.
    app(FormService::class)->setShareSettings($form, 'Clinic-Intake', true, FormBotChallenge::Off, null, $this->admin);

    enterTenant($this->tenant->id, $this->admin->id);
    $fresh = Form::query()->whereKey($form->id)->firstOrFail();

    expect($fresh->public_slug)->toBe('clinic-intake');

    // ⛔ THE AUDIT HALF IS WHY THIS TEST IS WORTH WRITING. Lower the value at forceFill() time instead of
    // above the $old/$new arrays and the column assertion above still passes — while the ledger records a
    // value the database does not hold, in the one method whose docblock argues the audit row is the whole
    // reason it grew. The column assertion cannot see that; this one can.
    $audit = Audit::query()
        ->where('auditable_type', 'form')
        ->where('auditable_id', $form->id)
        ->latest('id')
        ->firstOrFail();

    expect($audit->new_values['public_slug'])->toBe('clinic-intake');

    // And the point of the increment: the link resolves at both casings, from the canonical one directly.
    $this->getJson('http://acme.meridian.test/f/clinic-intake')->assertOk();
    $this->getJson('http://acme.meridian.test/f/Clinic-Intake')->assertStatus(301);
});
