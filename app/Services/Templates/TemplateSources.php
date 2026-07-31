<?php

declare(strict_types=1);

namespace App\Services\Templates;

use App\Enums\FieldType;
use App\Models\FormField;
use App\Services\Forms\CapabilityFlags;
use App\Services\Submissions\EncodeFormPresenter;
use App\Services\Submissions\SubmissionExporter;
use App\Services\Submissions\SubmissionInboxPresenter;
use Illuminate\Support\Collection;

/**
 * Builds {@see TemplateRenderer}'s `$sources` map — field key ⇒ its type and config — from either of the
 * two shapes a version's structure is available in (Increment H6a).
 *
 * Both shapes exist and neither is avoidable: {@see SubmissionInboxPresenter} and
 * {@see EncodeFormPresenter} walk live Eloquent rows, while
 * {@see SubmissionExporter} walks a version's immutable `schema_snapshot`. One
 * normalization step here keeps the renderer itself from having to know the difference, and keeps a piped
 * `single_select` resolving its option labels identically on both paths — `displayValue()` reads
 * `config.options`, so a source map that forgets to thread `config` through silently renders raw choice
 * codes instead of labels.
 *
 * Fail-closed like {@see CapabilityFlags}: a snapshot entry whose `field_type` is
 * unknown to this deployment (a snapshot written by a newer schema) is SKIPPED rather than thrown on, so a
 * hole naming it renders empty per Doc #26 §3.4 instead of 500ing a respondent's page.
 *
 * @phpstan-type RenderSource array{type: FieldType, config: array<string, mixed>}
 */
final class TemplateSources
{
    /**
     * @param  Collection<int, FormField>  $fields
     * @return array<string, RenderSource>
     */
    public static function fromFields(Collection $fields): array
    {
        $sources = [];

        foreach ($fields as $field) {
            $sources[$field->key] = [
                'type' => $field->field_type,
                'config' => $field->config ?? [],
            ];
        }

        return $sources;
    }

    /**
     * @param  array<mixed>  $snapshotFields  the `fields` list of a frozen `schema_snapshot`
     * @return array<string, RenderSource>
     */
    public static function fromSnapshot(array $snapshotFields): array
    {
        $sources = [];

        foreach ($snapshotFields as $field) {
            if (! is_array($field) || ! isset($field['key'])) {
                continue;
            }

            $type = FieldType::tryFrom((string) ($field['field_type'] ?? ''));

            if ($type === null) {
                continue;
            }

            $sources[(string) $field['key']] = [
                'type' => $type,
                'config' => is_array($field['config'] ?? null) ? $field['config'] : [],
            ];
        }

        return $sources;
    }
}
