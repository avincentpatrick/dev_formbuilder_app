<?php

declare(strict_types=1);

namespace App\Http\Requests\Forms;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A forms-list folder's name (M131, `D78`). {@see UpdateFormFolderRequest} renames with the same rules.
 *
 * Uniqueness is NOT a rule here: two tabs creating "Clinics" at once would both pass a check-then-write, so
 * the case-insensitive unique index decides and `FormFolderService` turns its violation into this field's
 * error. What IS here is the two names the list itself uses for its own options — a folder called "Unfiled"
 * would sit in the menu beside the real Unfiled and nobody could tell them apart.
 */
class StoreFormFolderRequest extends FormRequest
{
    /** The forms list's own option labels, compared case-insensitively. */
    private const array RESERVED = ['unfiled', 'all folders'];

    public function authorize(): bool
    {
        return true; // the `can:` route middleware owns authorization
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:80',
                static function (string $attribute, mixed $value, Closure $fail): void {
                    if (is_string($value) && in_array(mb_strtolower(trim($value)), self::RESERVED, true)) {
                        $fail('That name is used by the list itself. Choose another.');
                    }
                },
            ],
        ];
    }

    public function folderName(): string
    {
        return trim((string) $this->validated('name'));
    }
}
