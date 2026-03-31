<?php

namespace App\Models\Retail;

use App\Models\BaseModel;
use App\Models\Core\Professional\Professional;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandPromotion extends BaseModel
{
    use HasUuids;

    protected $table = 'retail.brand_promotions';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'brand_professional_id',
        'name',
        'description',
        'starts_at',
        'ends_at',
        'commission_rate',
        'discount_rate',
        'affiliate_scope',
        'affiliate_segment_ids',
        'affiliate_ids',
        'product_scope',
        'product_ids',
        'priority',
        'is_active',
        'notification_sent_at',
        'end_notification_sent_at',
    ];

    protected $casts = [
        'commission_rate' => 'float',
        'discount_rate' => 'float',
        'priority' => 'integer',
        'is_active' => 'boolean',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'notification_sent_at' => 'datetime',
        'end_notification_sent_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function brandProfessional(): BelongsTo
    {
        return $this->belongsTo(Professional::class, 'brand_professional_id');
    }

    /**
     * Parse a Postgres UUID[] string like "{uuid1,uuid2}" into a PHP array.
     */
    private function parseUuidArray(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, static fn ($v): bool => is_string($v) && $v !== ''));
        }

        if (! is_string($value) || $value === '' || $value === '{}') {
            return [];
        }

        $inner = trim($value, '{}');

        if ($inner === '') {
            return [];
        }

        return array_values(array_filter(
            explode(',', $inner),
            static fn (string $v): bool => $v !== ''
        ));
    }

    /**
     * Serialize a PHP array to a Postgres UUID[] literal like "{uuid1,uuid2}".
     */
    private function serializeUuidArray(mixed $value): string
    {
        if (! is_array($value) || $value === []) {
            return '{}';
        }

        $clean = array_values(array_unique(array_filter(array_map(
            static fn ($v): string => trim((string) $v),
            $value
        ), static fn (string $v): bool => $v !== '')));

        return '{' . implode(',', $clean) . '}';
    }

    public function getAffiliateSegmentIdsAttribute(mixed $value): array
    {
        return $this->parseUuidArray($value);
    }

    public function setAffiliateSegmentIdsAttribute(mixed $value): void
    {
        $this->attributes['affiliate_segment_ids'] = $this->serializeUuidArray($value);
    }

    public function getAffiliateIdsAttribute(mixed $value): array
    {
        return $this->parseUuidArray($value);
    }

    public function setAffiliateIdsAttribute(mixed $value): void
    {
        $this->attributes['affiliate_ids'] = $this->serializeUuidArray($value);
    }

    public function getProductIdsAttribute(mixed $value): array
    {
        return $this->parseUuidArray($value);
    }

    public function setProductIdsAttribute(mixed $value): void
    {
        $this->attributes['product_ids'] = $this->serializeUuidArray($value);
    }
}
