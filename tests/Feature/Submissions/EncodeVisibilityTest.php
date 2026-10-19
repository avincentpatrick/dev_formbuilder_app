<?php

declare(strict_types=1);

use App\Enums\FieldType;
use App\Models\User;
use App\Services\Forms\FormService;
use App\Services\Forms\PublishService;
use App\Services\Submissions\EncodeFormPresenter;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| M177 — Show to respondents on the manual-entry page (`R-f44e9d00`).
|--------------------------------------------------------------------------
| A keyer types what is on the paper. A question set to Never is on no paper (the printed form leaves it off), so the
| page leaves it out too; the Hidden type keeps its keyer reference row (H7), whatever its visibility says. A calculation
| set to Always is shown, read-only, worked out as the keyer types; one left at its default stays off the page.
*/

beforeEach(function (): void {
    TenantContext::flush();
    $this->seed(RolePermissionSeeder::class);
    $this->tenant = inboxTenant('acme');
    $this->user = User::factory()->create();
    enterTenant($this->tenant->id, $this->user->id);
    makeActiveMember($this->user, 'owner');
});

/** @return list<string> the keys of every presented field, in page order */
function encodeVisibilityKeys(array $page): array
{
    $keys = [];
    foreach ($page['blocks'] as $block) {
        foreach ($block['fields'] as $field) {
            $keys[] = $field['key'];
        }
    }

    return $keys;
}

it('leaves out a question set to Never and shows a calculation set to Always', function (): void {
    $form = app(FormService::class)->create($this->tenant, $this->user, 'Visibility');
    $version = $form->draftVersion;
    addFormField($version, $this->user, 'age', FieldType::Integer, 1);
    addFormField($version, $this->user, 'ref', FieldType::ShortText, 2, ['config' => ['visibility' => 'hidden']]);
    addFormField($version, $this->user, 'doubled', FieldType::Calculated, 3, ['config' => ['calculated_formula' => '${age} * 2', 'visibility' => 'shown']]);
    addFormField($version, $this->user, 'plain_calc', FieldType::Calculated, 4, ['config' => ['calculated_formula' => '${age} + 1']]);
    addFormField($version, $this->user, 'case_ref', FieldType::Hidden, 5, ['config' => ['prefill_source' => 'url', 'visibility' => 'hidden']]);
    app(PublishService::class)->publish($form->refresh(), $this->user);
    $form->refresh();

    $page = app(EncodeFormPresenter::class)->present($form, $form->currentPublishedVersion);

    expect(encodeVisibilityKeys($page))->toBe(['age', 'doubled', 'case_ref']);
});
