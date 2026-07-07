<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validation for publishing a draft version through the API (Increment E). Authorization is the route's
 * `ability:write:forms` + `can:publish,form` gates; this only validates the optional publisher note that
 * is appended to the auto-generated change summary.
 */
final class PublishVersionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
