<?php

declare(strict_types=1);

namespace App\Support\Explorer;

use App\Enums\ExplorerFieldType;

/**
 * One field of a data source, as the data explorer's catalogue declares it (ADR-0025 §D2).
 *
 * `expression` is SQL, and it is written HERE, by the application, never taken from a request. A chart names
 * a field by `key`; the catalogue turns the key into this. That is the whole of the explorer's defence
 * against a reader asking for a column they were not offered.
 *
 * The expression carries no bound parameters, on purpose: PostgreSQL will not accept a `GROUP BY` that
 * repeats a parameterised expression (two placeholders are two different values to it), and an answer's key
 * is the one runtime value an expression ever holds. Sources quote that key as a literal after checking its
 * characters — see `AnswersSource`.
 */
final readonly class ExplorerField
{
    /**
     * @param  string  $group  the heading this field sits under in the picker
     * @param  array<string, string>|null  $options  value → label, for a field with a fixed vocabulary
     * @param  string|null  $lookup  the directory that names this field's ids: `forms`, `users` or `folders`
     * @param  bool  $headline  print a raw value such as `form_editor` as "Form Editor"
     * @param  string|null  $listExpression  for a multi-choice field, SQL yielding the jsonb array of choices
     */
    public function __construct(
        public string $key,
        public string $label,
        public ExplorerFieldType $type,
        public string $expression,
        public string $group = '',
        public ?array $options = null,
        public ?string $lookup = null,
        public bool $headline = false,
        public ?string $listExpression = null,
    ) {}

    /**
     * What the page is told about the field. The expression stays on the server.
     *
     * @param  array<array-key, string>|null  $directory  the names of an id field's values, where the page asked for them
     * @return array{key: string, label: string, type: string, group: string, options: list<array{value: string, label: string}>|null}
     */
    public function toArray(?array $directory = null): array
    {
        $options = null;

        if (($directory ?? $this->options) !== null) {
            $options = [];
            foreach ($directory ?? $this->options as $value => $label) {
                $options[] = ['value' => (string) $value, 'label' => $label];
            }
        }

        return [
            'key' => $this->key,
            'label' => $this->label,
            'type' => $this->type->value,
            'group' => $this->group,
            'options' => $options,
        ];
    }
}
