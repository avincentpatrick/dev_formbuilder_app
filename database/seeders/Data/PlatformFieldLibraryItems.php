<?php

declare(strict_types=1);

namespace Database\Seeders\Data;

use App\Enums\FieldType;
use Illuminate\Support\Str;

/**
 * Hand-authored platform (system) question-library content (Increment G9b / onboarding-content-plan §3): the
 * NULL-tenant `field_library` rows every tenant sees in the builder's Library picker. Kept as compact
 * definitions (not built-then-snapshotted) — the same rationale as {@see PlatformTemplateBlueprints}: no
 * throwaway tenant/RLS context needed, and a validating drift test (PlatformFieldLibrarySeedTest) closes the
 * hand-authored risk. English-only; every `default_validations` is `[]` (a portable single question carries no
 * cross-field rule).
 */
final class PlatformFieldLibraryItems
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::item('Full name', 'A respondent’s full name.', 'Identity', FieldType::ShortText, 'Full name'),
            self::item('Age', 'Age in whole years.', 'Demographics', FieldType::Integer, 'Age (years)'),
            self::item('Sex', 'Self-reported sex.', 'Demographics', FieldType::SingleSelect, 'Sex', [
                'options' => ['Female', 'Male', 'Other', 'Prefer not to say'],
            ]),
            self::item('Phone number', 'A contact phone number.', 'Contact', FieldType::Phone, 'Phone number'),
            self::item('Email address', 'A contact email address.', 'Contact', FieldType::Email, 'Email address'),
            self::item('Consent', 'Consent to take part.', 'Consent', FieldType::YesNo, 'Do you consent to take part?'),
        ];
    }

    /**
     * @param  array{options?: list<string>}  $opts
     * @return array<string, mixed>
     */
    private static function item(string $name, string $description, string $category, FieldType $type, string $label, array $opts = []): array
    {
        return [
            'name' => $name,
            'description' => $description,
            'category' => $category,
            'field_type' => $type->value,
            'default_label' => $label,
            'default_config' => isset($opts['options']) ? self::options($opts['options']) : [],
            'default_validations' => [],
        ];
    }

    /**
     * The choice-field config shape (data-dictionary §4a): `config.options = [{value, label}]`.
     *
     * @param  list<string>  $labels
     * @return array<string, mixed>
     */
    private static function options(array $labels): array
    {
        return [
            'options' => array_map(
                static fn (string $label): array => ['value' => Str::slug($label, '_'), 'label' => $label],
                $labels,
            ),
        ];
    }
}
