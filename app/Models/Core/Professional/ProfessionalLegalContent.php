<?php

namespace App\Models\Core\Professional;

use App\Models\BaseModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfessionalLegalContent extends BaseModel
{
    public const SOURCE_TEMPLATED = 'templated';
    public const SOURCE_MANUAL = 'manual';

    protected $table = 'core.professional_legal_contents';
    protected $primaryKey = 'professional_id';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'professional_id',
        'generated_privacy_policy',
        'manual_privacy_policy',
        'active_privacy_source',
        'generated_terms_and_conditions',
        'manual_terms_and_conditions',
        'active_terms_source',
        'template_variables',
        'generated_at',
    ];

    protected $casts = [
        'template_variables' => 'array',
        'generated_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'professional_id');
    }

    public function resolveActivePrivacyPolicy(): string
    {
        if ($this->active_privacy_source === self::SOURCE_MANUAL) {
            $manual = $this->trimOrNull($this->manual_privacy_policy);
            if ($manual !== null) {
                return $manual;
            }
        }

        return (string) $this->generated_privacy_policy;
    }

    public function resolveActiveTermsAndConditions(): string
    {
        if ($this->active_terms_source === self::SOURCE_MANUAL) {
            $manual = $this->trimOrNull($this->manual_terms_and_conditions);
            if ($manual !== null) {
                return $manual;
            }
        }

        return (string) $this->generated_terms_and_conditions;
    }

    private function trimOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
