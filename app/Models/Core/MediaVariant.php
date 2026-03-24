<?php

namespace App\Models\Core;

use App\Models\BaseModel;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A single processed variant or artifact row in core.media_variants.
 *
 * Images (artifact_type='webp'):
 *   - variant_key='optimized'  + artifact_type='webp'         → optimised WebP
 *   - variant_key='maximized'  + artifact_type='webp'         → full-quality WebP
 *
 * Videos:
 *   - variant_key='optimized'  + artifact_type='mp4'          → 720p MP4
 *   - variant_key='maximized'  + artifact_type='mp4'          → 1080p MP4
 *   - variant_key='optimized'  + artifact_type='hls_playlist' → 720p HLS playlist
 *   - variant_key='maximized'  + artifact_type='hls_playlist' → 1080p HLS playlist
 *   - variant_key='adaptive'   + artifact_type='hls_playlist' → master HLS playlist
 *   - variant_key='poster'     + artifact_type='poster'       → poster JPEG
 *
 * @property string      $id
 * @property string      $media_id          FK → site_media.id
 * @property string      $variant_key       Logical tier: optimized|maximized|adaptive|poster
 * @property string      $artifact_type     Physical format: mp4|hls_playlist|poster
 * @property string      $disk
 * @property string      $path              Storage path (not a public URL)
 * @property string|null $mime
 * @property int|null    $width
 * @property int|null    $height
 * @property int|null    $bitrate_kbps
 * @property int|null    $file_size_bytes
 * @property int|null    $duration_ms
 * @property array|null  $metadata
 */
class MediaVariant extends BaseModel
{
    use HasUuids;

    protected $table = 'media_variants';

    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'media_id',
        'variant_key',
        'artifact_type',
        'disk',
        'path',
        'mime',
        'width',
        'height',
        'bitrate_kbps',
        'file_size_bytes',
        'duration_ms',
        'metadata',
        'content_hash',
    ];

    protected $casts = [
        'width'          => 'integer',
        'height'         => 'integer',
        'bitrate_kbps'   => 'integer',
        'file_size_bytes' => 'integer',
        'duration_ms'    => 'integer',
        'metadata'       => 'array',
    ];

    /* ------------------------------------------------------------------ */
    /*  Relationships                                                      */
    /* ------------------------------------------------------------------ */

    public function media(): BelongsTo
    {
        return $this->belongsTo(SiteMedia::class, 'media_id');
    }

    /* ------------------------------------------------------------------ */
    /*  Accessors                                                          */
    /* ------------------------------------------------------------------ */

    /**
     * Public URL for this artifact (CDN-friendly).
     */
    public function getUrlAttribute(): string
    {
        /** @var \Illuminate\Filesystem\FilesystemAdapter $disk */
        $disk = Storage::disk($this->disk);

        return $disk->url($this->path);
    }
}
