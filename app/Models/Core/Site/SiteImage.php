<?php

namespace App\Models\Core\Site;

use App\Models\BaseModel;
use App\Models\Core\ImageVariant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class SiteImage extends BaseModel
{
    use HasUuids, SoftDeletes;

    protected $table = 'site_images';

    public $incrementing = false;
    protected $keyType = 'string';

    public const POOL_GALLERY = 'gallery';
    public const POOL_CONTENT = 'content';

    protected $attributes = [
        'is_active' => true,
        'pool'      => self::POOL_GALLERY,
    ];

    protected $fillable = [
        'site_id',
        'pool',
        'bucket',
        'path',
        'alt_text',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active'  => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class, 'site_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(ImageVariant::class, 'image_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Helpers                                                            */
    /* ------------------------------------------------------------------ */

    /**
     * Return [variant_name => public_url] map for this image.
     *
     * @return array<string, string>
     */
    public function variantUrls(): array
    {
        return $this->variants
            ->mapWithKeys(fn (ImageVariant $v) => [$v->variant => $v->url])
            ->all();
    }
}
