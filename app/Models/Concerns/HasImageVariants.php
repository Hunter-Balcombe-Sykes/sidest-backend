<?php

namespace App\Models\Concerns;

use App\Models\Core\ImageVariant;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * Adds image-variant relationships to any model that stores
 * processed image variants (Professional, Site, SiteImage).
 *
 * Usage:
 *   $pro->imageVariants             // all variants
 *   $pro->iconVariants()            // only icon variants
 *   $pro->variantUrlsFor('icon')    // ['thumb' => '…', 'medium' => '…', …]
 */
trait HasImageVariants
{
    public function imageVariants(): MorphMany
    {
        return $this->morphMany(ImageVariant::class, 'imageable');
    }

    /**
     * Get variants for a specific upload type.
     */
    public function variantsFor(string $uploadType): MorphMany
    {
        return $this->imageVariants()->where('upload_type', $uploadType);
    }

    /**
     * Helper: return [variant_name => public_url] map.
     *
     * @return array<string, string>
     */
    public function variantUrlsFor(string $uploadType): array
    {
        return $this->variantsFor($uploadType)
            ->get()
            ->mapWithKeys(fn (ImageVariant $v) => [$v->variant => $v->url])
            ->all();
    }
}
