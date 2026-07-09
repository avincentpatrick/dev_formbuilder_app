<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComparisonOperator;
use App\Enums\LogicOperator;
use App\Enums\ValidationRuleType;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasUuidv7;
use App\Models\Concerns\TenantScoped;
use Database\Factories\FormFieldValidationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A structured rule or a free-text expression validating one field (data-dictionary §6). Content child
 * of form_versions (draft_child RLS shape). A DB CHECK enforces exactly one of expression / rule_type.
 *
 * @property string $id
 * @property string $tenant_id
 * @property string $form_version_id
 * @property string $form_field_id
 * @property ?string $related_form_field_id
 * @property ?ValidationRuleType $rule_type
 * @property ?ComparisonOperator $operator
 * @property ?string $rule_value
 * @property ?string $expression
 * @property ?string $error_message
 * @property array<string, mixed>|null $error_message_translations
 * @property ?string $logic_group
 * @property ?LogicOperator $logic_operator
 * @property int $sequence
 */
class FormFieldValidation extends Model implements TenantScoped
{
    use BelongsToTenant;

    /** @use HasFactory<FormFieldValidationFactory> */
    use HasFactory;

    use HasUuidv7;

    protected $fillable = [
        'tenant_id',
        'form_version_id',
        'form_field_id',
        'related_form_field_id',
        'rule_type',
        'operator',
        'rule_value',
        'expression',
        'error_message',
        'error_message_translations',
        'logic_group',
        'logic_operator',
        'sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule_type' => ValidationRuleType::class,
            'operator' => ComparisonOperator::class,
            'logic_operator' => LogicOperator::class,
            'error_message_translations' => 'array',
            'sequence' => 'integer',
        ];
    }

    /** @return BelongsTo<FormVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(FormVersion::class, 'form_version_id');
    }

    /** @return BelongsTo<FormField, $this> */
    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    /** @return BelongsTo<FormField, $this> */
    public function relatedField(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'related_form_field_id');
    }
}
