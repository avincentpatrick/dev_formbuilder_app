<?php

declare(strict_types=1);

namespace App\Http\Requests\Tenant;

use App\Exceptions\Explorer\InvalidExplorerQueryException;
use App\Models\User;
use App\Services\Explorer\ExplorerQueryRunner;
use App\Support\Explorer\ExplorerQuery;
use Illuminate\Validation\Validator;

/**
 * A saved-chart write from `/explore` (M235, ADR-0025 §D8).
 *
 * A chart is checked against the catalogue BEFORE it is stored, as the member saving it: a declaration that
 * names a field of another source, or a form they cannot reach, is a field error on `definition` while they
 * can still fix it, never a row that is broken from birth.
 *
 * On a PATCH every key is optional and an absent one is left alone — {@see queryOrNull()} — so renaming a
 * chart or sharing it cannot rewrite what it charts.
 */
final class SaveExplorerChartRequest extends ExplorerQueryRequest
{
    private ?ExplorerQuery $declaration = null;

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'max:150'],
            'definition' => [$required, 'array'],
            'is_shared' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return list<callable(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->isNotEmpty() || ! $this->has('definition')) {
                    return;
                }

                /** @var User $user */
                $user = $this->user();

                try {
                    $this->declaration = $this->toQuery();
                    app(ExplorerQueryRunner::class)->assertRunnable($this->declaration, $user);
                } catch (InvalidExplorerQueryException $e) {
                    $validator->errors()->add('definition', $e->getMessage());
                }
            },
        ];
    }

    /** The declaration to store, or null to leave the stored one untouched. */
    public function queryOrNull(): ?ExplorerQuery
    {
        return $this->declaration;
    }

    public function nameOrNull(): ?string
    {
        return $this->has('name') ? $this->string('name')->trim()->toString() : null;
    }

    public function sharedOrNull(): ?bool
    {
        return $this->has('is_shared') ? $this->boolean('is_shared') : null;
    }
}
