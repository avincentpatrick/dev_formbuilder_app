<?php

declare(strict_types=1);

namespace App\Services\Forms;

/**
 * Where a choice question's choices come from (M184, T3): typed here, a list file of its version (`options_list`, the
 * file's name, written into `options` at publish by {@see ChoiceListMaterializer}), or another form's answers
 * (`options_source`, M133, served live by {@see LinkedChoiceService}).
 *
 * ⚠️ A DECLARED SOURCE COUNTS WHILE IT IS STILL BEING PICKED — a key present and not null, as
 * {@see LinkedChoiceService::declaresLink()} has always read it. The builder holds `{form_id: null, field_key: null}`
 * or `options_list: ''` until the author chooses; publish names what is unfinished. The builder's `choiceSourceOf()`
 * (`resources/js/components/builder/choice-source.ts`) reads the same keys the same way.
 */
enum ChoiceSource: string
{
    case Typed = 'typed';
    case List = 'list';
    case Form = 'form';

    public static function of(mixed $config): self
    {
        if (! is_array($config)) {
            return self::Typed;
        }
        if (LinkedChoiceService::declaresLink($config)) {
            return self::Form;
        }

        return ($config['options_list'] ?? null) !== null ? self::List : self::Typed;
    }

    public function isTyped(): bool
    {
        return $this === self::Typed;
    }
}
