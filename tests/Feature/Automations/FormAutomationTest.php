<?php

declare(strict_types=1);

use App\Enums\FormAutomationAction;
use App\Enums\PlanTier;
use App\Enums\ResourceCapacity;
use App\Models\Audit;
use App\Models\Form;
use App\Models\FormAutomation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Automations\FormAutomationPresenter;
use App\Services\Automations\FormAutomationService;
use App\Services\Forms\FormService;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\WebhookSigner;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M132 — a form's automations, configured (R-b7bc5149, D82, D83).
|--------------------------------------------------------------------------
| Who may add what, what each row shows to whom, and the secret that is shown once. Every refusal runs its positive
| control first (M122). The web addresses are literal public IPs, so the address guard needs no DNS.
|
| ⚠️ Helpers are prefixed `automation*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = Tenant::create(['name' => 'Acme', 'slug' => 'acme', 'default_locale' => 'en']);
    $this->tenant->domains()->create(['domain' => 'acme']);
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'owner');
    $this->form = app(FormService::class)->create($this->tenant, $this->owner, 'Clinic Intake');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

function automationUrl(Form $form, ?string $id = null, string $suffix = ''): string
{
    return "http://acme.meridian.test/forms/{$form->id}/automations".($id === null ? '' : "/{$id}").$suffix;
}

/** A form editor who may edit THIS form, and who does not hold `webhooks.manage`. */
function automationEditor(Tenant $tenant, Form $form): User
{
    $editor = User::factory()->create();
    enterTenant($tenant->id, $editor->id);
    makeActiveMember($editor, 'form_editor');
    makeCollaborator($form, $editor, ResourceCapacity::Editor);

    return $editor;
}

/** @return array<string, mixed> */
function automationEmail(array $over = []): array
{
    return ['name' => 'Tell the team', 'action' => 'email', 'recipients' => ['nurse@example.org', 'lead@example.org'], ...$over];
}

/** @return array<string, mixed> */
function automationWebhook(array $over = []): array
{
    return ['name' => 'To the registry', 'action' => 'webhook', 'url' => 'https://8.8.8.8/hook', ...$over];
}

it('lets an editor add an email automation, which carries no secret and no address', function (): void {
    $editor = automationEditor($this->tenant, $this->form);

    $response = $this->actingAs($editor)->postJson(automationUrl($this->form), automationEmail())->assertCreated();

    expect($response->json('secret'))->toBeNull()
        ->and($response->json('data.action'))->toBe('email')
        ->and($response->json('data.recipients'))->toBe(['nurse@example.org', 'lead@example.org'])
        ->and($response->json('data.enabled'))->toBeTrue()
        ->and($response->json('data.manageable'))->toBeTrue()
        ->and($response->json('data.runs'))->toBe([]);

    enterTenant($this->tenant->id, $this->owner->id);
    $row = FormAutomation::query()->sole();
    expect($row->secret)->toBeNull()->and($row->url)->toBeNull()->and($row->created_by)->toBe($editor->id);
});

it('refuses addresses an email automation cannot use, after five good ones are accepted', function (array $recipients, string $field, string $message): void {
    $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationEmail([
        'recipients' => ['a@example.org', 'b@example.org', 'c@example.org', 'd@example.org', 'e@example.org'],
    ]))->assertCreated();

    $this->actingAs($this->owner)
        ->postJson(automationUrl($this->form), automationEmail(['recipients' => $recipients]))
        ->assertStatus(422)
        ->assertJsonValidationErrors([$field => $message]);

    enterTenant($this->tenant->id, $this->owner->id);
    expect(FormAutomation::query()->count())->toBe(1);
})->with([
    'six addresses' => [['a@example.org', 'b@example.org', 'c@example.org', 'd@example.org', 'e@example.org', 'f@example.org'], 'recipients', 'An automation can email at most five addresses.'],
    'a malformed one' => [['not an address'], 'recipients.0', 'Each address must be a valid email address.'],
    'the same one twice' => [['a@example.org', 'A@example.org'], 'recipients.1', 'Each address may appear only once.'],
    'none' => [[], 'recipients', 'Add at least one email address.'],
]);

it('lets an Owner add a web-address automation, and returns its secret exactly once', function (): void {
    $response = $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationWebhook())->assertCreated();

    $secret = (string) $response->json('secret');
    expect($secret)->toStartWith('whsec_')
        ->and($response->json('data.url'))->toBe('https://8.8.8.8/hook')
        ->and($response->json('data'))->not->toHaveKey('secret');

    enterTenant($this->tenant->id, $this->owner->id);
    $row = FormAutomation::query()->sole();
    // Stored encrypted, decrypted only through the model, and never serialized.
    expect($row->secret)->toBe($secret)
        ->and(DB::table('form_automations')->value('secret'))->not->toBe($secret)
        ->and($row->toArray())->not->toHaveKey('secret')
        ->and(app(FormAutomationPresenter::class)->forForm($this->form, $this->owner)['items'][0])->not->toHaveKey('secret');
});

it('refuses a web-address automation to an editor who may not manage webhooks, after an Owner’s is accepted', function (): void {
    $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationWebhook())->assertCreated();
    $editor = automationEditor($this->tenant, $this->form);

    $this->actingAs($editor)->postJson(automationUrl($this->form), automationWebhook(['name' => 'Mine']))->assertForbidden();

    enterTenant($this->tenant->id, $this->owner->id);
    expect(FormAutomation::query()->count())->toBe(1);
});

it('refuses a web address on a plan without webhooks with the plan gate, after an email one is accepted', function (): void {
    assignPlanTier(PlanTier::Free);

    $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationEmail())->assertCreated();
    $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationWebhook())->assertStatus(402);

    enterTenant($this->tenant->id, $this->owner->id);
    expect(FormAutomation::query()->pluck('action')->map(fn ($a) => $a->value)->all())->toBe(['email']);
});

it('refuses a web address inside a private network, after a public one is accepted', function (): void {
    $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationWebhook())->assertCreated();

    $this->actingAs($this->owner)
        ->postJson(automationUrl($this->form), automationWebhook(['url' => 'https://10.1.2.3/hook']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['url']);
});

it('caps a form at ten automations', function (): void {
    foreach (range(1, 10) as $n) {
        $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationEmail(['name' => "Notice {$n}"]))->assertCreated();
    }

    $this->actingAs($this->owner)
        ->postJson(automationUrl($this->form), automationEmail(['name' => 'Eleventh']))
        ->assertStatus(422)
        ->assertJsonValidationErrors(['name' => 'A form can have at most 10 automations. Delete one to add another.']);
});

it('shows a web address whole only to whoever may manage webhooks, and its host to everyone else', function (): void {
    $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationWebhook())->assertCreated();
    $editor = automationEditor($this->tenant, $this->form);

    $presenter = app(FormAutomationPresenter::class);
    $forOwner = $presenter->forForm($this->form, $this->owner);
    $forEditor = $presenter->forForm($this->form, $editor);

    expect($forOwner['can_webhook'])->toBeTrue()
        ->and($forOwner['items'][0]['url'])->toBe('https://8.8.8.8/hook')
        ->and($forOwner['items'][0]['manageable'])->toBeTrue()
        ->and($forEditor['can_webhook'])->toBeFalse()
        ->and($forEditor['items'][0]['url'])->toBeNull()
        ->and($forEditor['items'][0]['host'])->toBe('8.8.8.8')
        ->and($forEditor['items'][0]['manageable'])->toBeFalse();
});

it('lets an editor change an email automation but not a web-address one, which only its managers may change', function (): void {
    $email = $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationEmail())->json('data.id');
    $hook = $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationWebhook())->json('data.id');
    $editor = automationEditor($this->tenant, $this->form);

    $this->actingAs($editor)->patchJson(automationUrl($this->form, $email), ['enabled' => false])->assertOk()->assertJsonPath('data.enabled', false);
    $this->actingAs($editor)->patchJson(automationUrl($this->form, $hook), ['enabled' => false])->assertForbidden();
    $this->actingAs($editor)->deleteJson(automationUrl($this->form, $hook))->assertForbidden();
    $this->actingAs($this->owner)->patchJson(automationUrl($this->form, $hook), ['name' => 'Registry'])->assertOk();
    $this->actingAs($this->owner)->deleteJson(automationUrl($this->form, $hook))->assertNoContent();

    enterTenant($this->tenant->id, $this->owner->id);
    expect(FormAutomation::query()->pluck('name')->all())->toBe(['Tell the team']);
});

it('answers 404 for another form’s automation, after the same request works on its own form', function (): void {
    $id = $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationEmail())->json('data.id');
    enterTenant($this->tenant->id, $this->owner->id);
    $other = app(FormService::class)->create($this->tenant, $this->owner, 'Another form');

    $this->actingAs($this->owner)->patchJson(automationUrl($this->form, $id), ['name' => 'Renamed'])->assertOk();
    $this->actingAs($this->owner)->patchJson(automationUrl($other, $id), ['name' => 'Hijacked'])->assertNotFound();
});

it('audits every change without the secret, the full address or the addresses in clear', function (): void {
    $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationWebhook())->assertCreated();
    $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationEmail())->assertCreated();

    enterTenant($this->tenant->id, $this->owner->id);
    $audits = Audit::query()->where('auditable_type', 'form_automation')->get();
    // Unescaped slashes: json_encode writes `/` as `\/` by default, and a search for the address would then pass blind.
    $json = $audits->map(fn (Audit $audit) => json_encode([$audit->old_values, $audit->new_values], JSON_UNESCAPED_SLASHES))->implode(' ');

    expect($audits)->toHaveCount(2)
        ->and($json)->toContain('8.8.8.8')
        ->and(str_contains($json, 'https://8.8.8.8/hook'))->toBeFalse()
        ->and(str_contains($json, 'whsec_'))->toBeFalse()
        ->and(str_contains($json, 'nurse@example.org'))->toBeFalse();
});

it('sends a signed test to a web address, with no answers in it', function (): void {
    Http::preventStrayRequests();
    Http::fake(['https://8.8.8.8/*' => Http::response('', 204)]);
    $response = $this->actingAs($this->owner)->postJson(automationUrl($this->form), automationWebhook())->assertCreated();
    $secret = (string) $response->json('secret');

    $this->actingAs($this->owner)
        ->postJson(automationUrl($this->form, (string) $response->json('data.id'), '/test'))
        ->assertOk()
        ->assertJsonPath('data.ok', true);

    Http::assertSent(function (ClientRequest $request) use ($secret): bool {
        $timestamp = $request->header(WebhookSigner::TIMESTAMP_HEADER)[0];
        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->body(), $secret);

        return $request->header(WebhookSigner::SIGNATURE_HEADER)[0] === $expected
            && $request['test'] === true
            && $request['answers'] === [];
    });
});

it('gates every route on editing the form, the changes on the policy, and the test on the webhooks plan', function (): void {
    $stack = static fn (string $name): array => Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];

    expect($stack('forms.automations.store'))->toContain('can:update,form')
        ->and(in_array('throttle:30,1', $stack('forms.automations.store'), true))->toBeTrue()
        ->and($stack('forms.automations.update'))->toContain('can:manage,automation')
        ->and($stack('forms.automations.destroy'))->toContain('can:manage,automation')
        ->and($stack('forms.automations.test'))->toContain('feature:webhooks')
        ->and(in_array('throttle:6,1', $stack('forms.automations.test'), true))->toBeTrue();
});

it('names a web-address automation’s action for what it sends', function (): void {
    expect(FormAutomationAction::Webhook->label())->toBe('Send to a web address')
        ->and(FormAutomationService::MAX_PER_FORM)->toBe(10);
});
