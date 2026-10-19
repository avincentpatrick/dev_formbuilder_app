<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Exceptions\Forms\BuilderConflictException;
use App\Exceptions\Forms\FormException;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureBuilderDraftCurrent;
use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\FormBuilderService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "Other, please specify" on and off (M177, `R-71509975`): `store` adds the `other` choice and the Please specify question
 * in one transaction and answers both rows; `destroy` removes both and answers the choice question.
 *
 * ⛔ ITS OWN CONTROLLER, FOR {@see FormFieldConversionController}'s REASON: {@see FormBuilderController} is a census hub,
 * and the routes already cost the increment its one hub edit (`routes/tenant.php`). The JSON contract is the builder's
 * own — the same 409 `{message, current}` and 422 `{message}` — so {@see self::respond()} is that controller's copy.
 */
final class FormFieldOtherSpecifyController extends Controller
{
    public function __construct(
        private readonly FormBuilderService $builder,
        private readonly BuilderPresenter $presenter,
    ) {}

    public function store(Request $request, Form $form, FormField $field): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond(function () use ($form, $field, $user): array {
            $pair = $this->builder->addOtherSpecify($form, $user, $field);

            return ['parent' => $this->presenter->field($pair['parent']), 'child' => $this->presenter->field($pair['child'])];
        });
    }

    public function destroy(Request $request, Form $form, FormField $field): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond(fn (): array => ['parent' => $this->presenter->field($this->builder->removeOtherSpecify($form, $user, $field))]);
    }

    /**
     * @param  Closure(): array<string, mixed>  $op
     */
    private function respond(Closure $op): JsonResponse
    {
        try {
            return response()->json($op());
        } catch (BuilderConflictException $e) {
            $current = $e->current instanceof FormField
                ? $this->presenter->field($e->current)
                : $this->presenter->section($e->current);

            return response()->json(['message' => $e->getMessage(), 'current' => $current], 409);
        } catch (FormException $e) {
            $form = request()->route('form');
            if ($e->isChildSuperseded() && $form instanceof Form) {
                return EnsureBuilderDraftCurrent::superseded($form);
            }

            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
