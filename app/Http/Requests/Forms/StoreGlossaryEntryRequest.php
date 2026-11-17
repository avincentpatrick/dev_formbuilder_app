<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use App\Services\Forms\FormGlossaryService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Add a term in a form's draft glossary (M245, `R-d0bbb7bb`). The cheap early check only: {@see FormGlossaryService}
 * cleans the spacing and is the authority on a term's length, its characters and whether the glossary already has it,
 * because a term is measured after its spacing is collapsed. Ungated by plan; the route stacks `can:update,form`.
 */
final class StoreGlossaryEntryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // route middleware (can:update,form) owns authorization
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'term' => ['required', 'string', 'max:255'],
            'definition' => ['required', 'string', 'max:4000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'term.required' => 'Write the term.',
            'definition.required' => 'Write what the term means.',
        ];
    }

    public function term(): string
    {
        return $this->string('term')->toString();
    }

    public function definition(): string
    {
        return $this->string('definition')->toString();
    }
}
