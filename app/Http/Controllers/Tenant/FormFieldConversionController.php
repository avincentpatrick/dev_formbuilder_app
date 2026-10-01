<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Exceptions\Forms\BuilderConflictException;
use App\Exceptions\Forms\FormException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Forms\ConvertFieldRequest;
use App\Models\Form;
use App\Models\FormField;
use App\Models\User;
use App\Services\Forms\BuilderPresenter;
use App\Services\Forms\ConversionCensus;
use App\Services\Forms\FormBuilderService;
use App\Support\Forms\ConversionPlan;
use Closure;
use Illuminate\Http\JsonResponse;

/**
 * Change a question's type in place (Increment M122) — the HTTP half of `M121`'s engine. `index` reads every
 * conversion the engine offers, each with the cross-field census of what it would do to the rest of the form;
 * `store` applies one.
 *
 * ⛔ A SEPARATE CONTROLLER, NOT TWO MORE METHODS ON {@see FormBuilderController}. That file is a census hub,
 * and the routes already cost this increment its one hub edit (`routes/tenant.php`). The JSON contract is the
 * builder's own — the same 409 `{message, current}` body the client already reads — so {@see self::respond()}
 * copies `FormBuilderController::respond()` rather than sharing it. `FormException` has an API render arm and
 * no web one (`bootstrap/app.php`), so without this mapping a refused conversion would be a 500.
 *
 * ⚠️ WHAT THE CLIENT (`B5b`) MUST KNOW:
 *   - Show the confirmation whenever a plan's `census` is non-empty, even when `requires_confirmation` is
 *     false. A lossless plan can still change what another question's condition means.
 *   - The census is advisory and outside the fingerprint, which covers the converted field alone — another
 *     tab editing a question that names it is not caught at the write. Publish stays the gate.
 *   - A stale token and a fingerprint that no longer matches are the SAME 409, by design: the remedy for
 *     both is to read the plans again.
 *   - The token has one-second resolution, so cancel and flush any pending autosave before the POST.
 */
final class FormFieldConversionController extends Controller
{
    public function __construct(
        private readonly FormBuilderService $builder,
        private readonly BuilderPresenter $presenter,
        private readonly ConversionCensus $census,
    ) {}

    /** Every plan, in the order the dialog lists them, each with its census. Writes nothing. */
    public function index(Form $form, FormField $field): JsonResponse
    {
        return $this->respond(function () use ($form, $field): array {
            // The plans first: they run the draft guard, so a field outside this draft is refused before
            // the census reads anything.
            $plans = $this->builder->conversionPlans($form, $field);
            $uses = $this->census->referencesTo($form, $field);

            return ['plans' => array_map(
                fn (ConversionPlan $plan): array => [...$plan->toArray(), 'census' => $this->census->judge($uses, $plan->from, $plan->to)],
                $plans,
            )];
        });
    }

    public function store(ConvertFieldRequest $request, Form $form, FormField $field): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return $this->respond(fn (): array => $this->presenter->field($this->builder->convertField(
            $form,
            $field,
            $user,
            $request->target(),
            $request->string('version')->toString(),
            $request->fingerprint(),
        )));
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
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
