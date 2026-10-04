<?php

declare(strict_types=1);

namespace App\Services\Forms;

use App\Exceptions\Forms\PublishValidationException;
use App\Models\Form;
use App\Models\FormField;
use App\Models\FormVersion;
use Illuminate\Support\Collection;

/**
 * The publish gate for a choice question that takes its choices from another form (M133, `R-5da4a30f` — Connect
 * project v1). Called from {@see StructuralValidationGate::collect()}, whose refusals it joins, so an author sees
 * every broken link beside every other structural fault in one publish attempt.
 *
 * ⚠️ IT IS ITS OWN CLASS, IN THE GATE'S OWN NAMESPACE, FOR A REASON OUTSIDE IT: `StructuralValidationGate.php` is
 * cited by line from the ledger, and a dependency there would cost a `use` line above every cited line. A class in
 * the same namespace needs none.
 *
 * What it refuses, each with its own code:
 *   - a link on a question that is not a single choice or dropdown (`linked_choices_wrong_type`);
 *   - a link naming no form or no question (`linked_choices_incomplete`);
 *   - typed choices beside a link (`linked_choices_with_typed_options`) — both engines check an answer against the
 *     snapshot's typed list whenever it is non-empty, so a mix would refuse every linked answer;
 *   - and whatever {@see LinkedChoiceService::refusal()} finds: the form itself, a missing source, a question the
 *     source does not share, or an owner who cannot read the source's responses.
 */
final class LinkedChoiceGate
{
    public function __construct(private readonly LinkedChoiceService $links) {}

    /**
     * @param  Collection<int, FormField>  $fields
     * @return list<PublishValidationException>
     */
    public function collect(FormVersion $version, Collection $fields): array
    {
        $destination = null;
        $violations = [];

        foreach ($fields as $field) {
            $config = $field->config;
            if (! LinkedChoiceService::declaresLink($config)) {
                continue;
            }

            $code = $this->codeFor($field, $config, $destination ??= Form::query()->whereKey($version->form_id)->first());
            if ($code !== null) {
                $violations[] = PublishValidationException::linkedChoicesInvalid($field->key, $code);
            }
        }

        return $violations;
    }

    /** @param array<string, mixed>|null $config */
    private function codeFor(FormField $field, ?array $config, ?Form $destination): ?string
    {
        if (! in_array($field->field_type, LinkedChoiceService::LINKABLE_TYPES, true)) {
            return 'linked_choices_wrong_type';
        }

        $link = LinkedChoiceService::linkOf($config);
        if ($link === null) {
            return 'linked_choices_incomplete';
        }

        $typed = $config['options'] ?? [];
        if (is_array($typed) && $typed !== []) {
            return 'linked_choices_with_typed_options';
        }

        return $destination === null ? 'linked_choices_source_missing' : $this->links->refusal($destination, $link);
    }
}
