<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\MediaVariant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

// V2: An uploaded image or video belonging to a site. Tracks processing state (pending/processing/ready/failed) and owns MediaVariant children.
class SiteMedia extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site.site_media';

    public $incrementing = false;
    protected $keyType = 'string';

    public const POOL_GALLERY  = 'gallery';
    public const POOL_CONTENT  = 'content';
    public const POOL_PRODUCT  = 'product';
    public const POOL_BRAND_GALLERY = 'brand_gallery';

    public const MEDIA_TYPE_IMAGE = 'image';
    public const MEDIA_TYPE_VIDEO = 'video';

    public const PROCESSING_STATE_PENDING    = 'pending';
    public const PROCESSING_STATE_PROCESSING = 'processing';
    public const PROCESSING_STATE_READY      = 'ready';
    public const PROCESSING_STATE_FAILED     = 'failed';

    protected $attributes = [
        'is_active'        => true,
        'pool'             => self::POOL_GALLERY,
        'media_type'       => self::MEDIA_TYPE_IMAGE,
        'processing_state' => self::PROCESSING_STATE_PENDING,
    ];

    protected $fillable = [
        'site_id',
        'pool',
        'path',
        'alt_text',
        'sort_order',
        'is_active',
        'media_type',
        'processing_state',
        'processing_error',
        'original_mime',
        'original_size_bytes',
        'duration_ms',
        'poster_path',
    ];

    protected $casts = [
        'sort_order'          => 'integer',
        'is_active'           => 'boolean',
        'original_size_bytes' => 'integer',
        'duration_ms'         => 'integer',
        'created_at'          => 'datetime',
        'updated_at'          => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function mediaVariants(): HasMany
    {
        return $this->hasMany(MediaVariant::class, 'media_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Return [variant_key => public_url] map for image media items.
     * Filters to artifact_type='webp' from the already-loaded mediaVariants relation.
     *
     * @return array<string, string>
     */
    public function variantUrls(): array
    {
        return $this->mediaVariants
            ->filter(fn (MediaVariant $v) => $v->artifact_type === 'webp')
            ->mapWithKeys(fn (MediaVariant $v) => [$v->variant_key => $v->url])
            ->all();
    }
}
