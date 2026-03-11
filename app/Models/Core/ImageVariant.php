<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use App\Models\Core\Site\SiteImage;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A single processed WebP variant of a SiteImage
 * (e.g. optimized_abc123.webp or maximized_def456.webp).
 *
 * @property string $id
 * @property string $image_id       FK → site_images.id
 * @property string $variant        configured variant key (e.g. optimized|maximized)
 * @property string $disk
 * @property string $path
 * @property string $format
 * @property int    $width
 * @property int    $height
 * @property int    $file_size
 * @property string $content_hash
 */
class ImageVariant extends BaseModel
{
    use HasUuids;

    protected $table = 'image_variants';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'image_id',
        'variant',
        'disk',
        'path',
        'format',
        'width',
        'height',
        'file_size',
        'content_hash',
    ];

    protected $casts = [
        'width'     => 'integer',
        'height'    => 'integer',
        'file_size' => 'integer',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function image(): BelongsTo
    {
        return $this->belongsTo(SiteImage::class, 'image_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Public URL for this variant (CDN-friendly, immutable path).
     */
    public function getUrlAttribute(): string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($this->disk);

        return $disk->url($this->path);
    }
}
