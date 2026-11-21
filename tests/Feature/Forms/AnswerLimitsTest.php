<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Exceptions\Forms\PublishValidationException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Support\Forms\FieldTypeConversion;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M243 (`R-1da063f4`) — answer limits: words on a text question, choices and "only answer" choices on a multi-select.
|--------------------------------------------------------------------------
| Field settings in `config` (`min_words`/`max_words`, `min_choices`/`max_choices`, `options[].exclusive`), checked by both
| engines (the golden vectors in `tests/golden/validation/answer_rules.json` pin them to each other) and enforced on the
| respondent's screen. This file holds the server's doors: the builder's save, publish, a type conversion, the builder's
| enums, and a guest's submission refused or accepted on each rule by its rule id.
|
| ⚠️ Helpers are prefixed `limits*`: Pest loads every test file into one process.
*/

beforeEach(function (): void {
    TenantContext::flush();
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    (new RolePermissionSeeder)->run();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $this->tenant = guestTenant();
    $this->owner = User::factory()->create();
    enterTenant($this->tenant->id, $this->owner->id);
    makeActiveMember($this->owner, 'admin');

    $this->form = app(FormService::class)->create($this->tenant, $this->owner, 'Review');
});

afterEach(function (): void {
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
});

/** @param  array<string, mixed>  $config */
function limitsField(object $test, string $key, FieldType $type, array $config, int $sequence = 1): FormField
{
    return addFormField($test->form->refresh()->draftVersion, $test->owner, $key, $type, $sequence, ['config' => $config]);
}

/** @return list<array<string, mixed>> */
function limitsOptions(): array
{
    return [
        ['value' => 'access', 'label' => 'Access'],
        ['value' => 'results', 'label' => 'Results'],
        ['value' => 'use', 'label' => 'Use'],
        ['value' => 'noview', 'label' => 'No view or opinion', 'exclusive' => true],
    ];
}

function limitsPublishError(object $test): string
{
    try {
        app(PublishService::class)->publish($test->form->refresh(), $test->owner);
    } catch (PublishValidationException $e) {
        return $e->getMessage();
    }

    return 'published';
}

function limitsGuestForm(object $test): Form
{
    $test->form->refresh()->update(['public_slug' => 'review', 'allow_guest_submissions' => true]);

    return $test->form->refresh();
}

/** @param  array<string, mixed>  $answers  @return array{0: int, 1: list<string>} the status and each refusal's `field:rule` */
function limitsSubmit(object $test, Form $form, array $answers): array
{
    $response = $test->postJson('http://acme.meridian.test/api/v1/public/f/'.shareTokenFor($form).'/submissions', ['answers' => $answers]);
    $refusals = array_map(static fn (array $e): string => "{$e['field']}:{$e['rule']}", $response->json('error.details.fields') ?? []);

    return [$response->status(), $refusals];
}

it('saves the limits through the builder, and refuses a value that is not one', function (): void {
    test()->withHeaders(builderHeaders($this->form));
    $add = fn (string $type) => $this->actingAs($this->owner)
        ->postJson("http://acme.meridian.test/forms/{$this->form->id}/fields", ['field_type' => $type])->assertOk();
    $patch = fn ($added, array $config) => $this->actingAs($this->owner)
        ->patchJson("http://acme.meridian.test/forms/{$this->form->id}/fields/{$added->json('id')}", [
            'key' => $added->json('key'), 'label' => 'Question', 'is_required' => 'optional',
            'config' => $config, 'validations' => [], 'version' => currentFieldToken($this->form, $this->owner, $added->json('id')),
        ]);

    $text = $add('long_text');
    $patch($text, ['min_words' => 10, 'max_words' => 1500])->assertOk()->assertJsonPath('config.max_words', 1500);
    $patch($text, ['min_words' => null, 'max_words' => null])->assertOk();
    $patch($text, ['max_words' => 0])->assertUnprocessable();
    $patch($text, ['max_words' => 'ten'])->assertUnprocessable();
    $patch($text, ['max_words' => FieldType::ANSWER_LIMIT_CEILING + 1])->assertUnprocessable();

    $multi = $add('multi_select');
    $patch($multi, ['max_choices' => 2, 'options' => limitsOptions()])->assertOk()->assertJsonPath('config.options.3.exclusive', true);
    $patch($multi, ['options' => [['value' => 'a', 'label' => 'A', 'exclusive' => 'yes']]])->assertUnprocessable();
});

it('refuses at publish the limits no answer can meet, and names the numbers', function (Closure $build, string $reason): void {
    $build($this);

    expect(limitsPublishError($this))->toContain($reason);
})->with([
    'fewest words above most' => [fn (object $t) => limitsField($t, 'why', FieldType::LongText, ['min_words' => 20, 'max_words' => 10]), 'at least 20 words is more than at most 10'],
    'fewest choices above most' => [fn (object $t) => limitsField($t, 'pick', FieldType::MultiSelect, ['min_choices' => 3, 'max_choices' => 2, 'options' => limitsOptions()]), 'at least 3 choices is more than at most 2'],
    'more choices asked for than can be chosen together' => [fn (object $t) => limitsField($t, 'pick', FieldType::MultiSelect, ['min_choices' => 4, 'options' => limitsOptions()]), 'it asks for at least 4 choices and has 3 that can be chosen together'],
]);

it('publishes limits an answer can meet, including a minimum of one on a list of only answers', function (): void {
    limitsField($this, 'why', FieldType::LongText, ['min_words' => 10, 'max_words' => 10]);
    limitsField($this, 'pick', FieldType::MultiSelect, ['min_choices' => 3, 'max_choices' => 3, 'options' => limitsOptions()], 2);
    limitsField($this, 'alone', FieldType::MultiSelect, ['min_choices' => 1, 'options' => [['value' => 'x', 'label' => 'X', 'exclusive' => true]]], 3);

    expect(limitsPublishError($this))->toBe('published');
});

it('refuses a guest answer on each limit by its rule, and accepts one inside them', function (): void {
    limitsField($this, 'why', FieldType::LongText, ['min_words' => 3, 'max_words' => 5]);
    limitsField($this, 'pick', FieldType::MultiSelect, ['min_choices' => 2, 'max_choices' => 2, 'options' => limitsOptions()], 2);
    app(PublishService::class)->publish($this->form->refresh(), $this->owner);
    $form = limitsGuestForm($this);
    $good = ['why' => 'three good words', 'pick' => ['access', 'results']];

    expect(limitsSubmit($this, $form, [...$good, 'why' => 'one two three four five six']))->toBe([422, ['why:words_too_many']])
        ->and(limitsSubmit($this, $form, [...$good, 'why' => 'two words']))->toBe([422, ['why:words_too_few']])
        ->and(limitsSubmit($this, $form, [...$good, 'pick' => ['access', 'results', 'use']]))->toBe([422, ['pick:choices_too_many']])
        ->and(limitsSubmit($this, $form, [...$good, 'pick' => ['access']]))->toBe([422, ['pick:choices_too_few']])
        ->and(limitsSubmit($this, $form, [...$good, 'pick' => ['noview', 'access']]))->toBe([422, ['pick:choice_exclusive']])
        ->and(limitsSubmit($this, $form, $good)[0])->toBe(201)
        ->and(limitsSubmit($this, $form, [...$good, 'pick' => ['noview']])[0])->toBe(201);
});

it('drops word and choice limits a new type cannot take, and says so', function (): void {
    $text = limitsField($this, 'why', FieldType::ShortText, ['max_words' => 50]);
    $multi = limitsField($this, 'pick', FieldType::MultiSelect, ['max_choices' => 2, 'options' => limitsOptions()], 2);

    $toLong = FieldTypeConversion::plan($text, [], FieldType::LongText, [], false);
    $toEmail = FieldTypeConversion::plan($text, [], FieldType::Email, [], false);
    $toSingle = FieldTypeConversion::plan($multi, [], FieldType::SingleSelect, [], false);

    expect($toLong->config['max_words'] ?? null)->toBe(50)
        ->and(array_key_exists('max_words', $toEmail->config))->toBeFalse()
        ->and($toEmail->configDropped)->toContain('max_words')
        ->and(array_key_exists('max_choices', $toSingle->config))->toBeFalse()
        ->and($toSingle->configDropped)->toBe(['max_choices'])
        // An "only answer" stays on the options: removing it would report the whole list dropped, and nothing reads it here.
        ->and($toSingle->config['options'][3]['exclusive'] ?? null)->toBeTrue();
});

it('reads each limit by the engines\' whole-number rule, for the screen and the gate alike', function (): void {
    $odd = limitsField($this, 'odd', FieldType::ShortText, ['min_words' => '10', 'max_words' => 2.5]);
    $none = limitsField($this, 'none', FieldType::Email, ['max_words' => 3], 2);
    $multi = limitsField($this, 'pick', FieldType::MultiSelect, ['max_choices' => 2.0, 'options' => limitsOptions()], 3);

    expect($odd->answerLimits())->toBe(['words' => ['min' => 10, 'max' => null], 'choices' => null, 'exclusive' => []])
        ->and($none->answerLimits())->toBeNull()
        ->and($multi->answerLimits())->toBe(['words' => null, 'choices' => ['min' => null, 'max' => 2], 'exclusive' => ['noview']]);
});

it('sends the builder the types that take each limit, from FieldType alone', function (): void {
    $enums = app(BuilderPresenter::class)->present($this->form->refresh())['enums'];

    expect($enums['word_limit_types'])->toBe(['short_text', 'long_text'])
        ->and($enums['choice_limit_types'])->toBe(['multi_select']);
});
