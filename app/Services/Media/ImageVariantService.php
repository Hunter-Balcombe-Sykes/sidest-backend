<?php

namespace App\Services\Media;

use App\Models\Core\ImageVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generates universal WebP image variants from an uploaded original and
 * persists them to the configured media disk (S3-compatible / R2).
 *
 * Every image (gallery or content) gets the same responsive variant set
 * (thumb → hero). The frontend picks the appropriate size for each use
 * case (icon, headshot, banner, etc.).
 *
 * Requires the GD extension with WebP support (ships with PHP 8.2+).
 */
class ImageVariantService
{
    /* ------------------------------------------------------------------ */
    /*  Public API                                                         */
    /* ------------------------------------------------------------------ */

    /**
     * Process an original image into all configured variants, store them
     * on the media disk, and persist ImageVariant rows for the given image.
     *
     * @param  string  $originalTmpPath  Absolute path to the temp original.
     * @param  string  $imageId          UUID of the SiteImage row.
     * @param  string  $basePath         e.g. "images/<proId>/<imageId>"
     * @return array<string, ImageVariant>  keyed by variant name
     */
    public function processVariants(
        string $originalTmpPath,
        string $imageId,
        string $basePath,
    ): array {
        // Ensure GD extension is available with WebP support
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('GD extension is not loaded. Cannot process image variants.');
        }
        
        if (!function_exists('imagewebp')) {
            throw new \RuntimeException('GD WebP support is not available. Cannot generate WebP variants.');
        }

        \Illuminate\Support\Facades\Log::info('Starting image variant processing', [
            'image_id' => $imageId,
            'base_path' => $basePath,
        ]);

        $disk        = $this->disk();
        $definitions = $this->variantDefinitions();

        $sourceImage = $this->loadImage($originalTmpPath);

        if (!$sourceImage) {
            throw new \RuntimeException('Failed to create GD image from the uploaded file.');
        }

        $sourceWidth  = imagesx($sourceImage);
        $sourceHeight = imagesy($sourceImage);

        $created = [];

        try {
            foreach ($definitions as $variantName => $def) {
                $targetW = $def['width'];
                $targetH = $def['height'];
                $quality = $def['quality'] ?? 80;
                $fit     = $def['fit'] ?? 'cover';

                // --- Resize ---
                [$cropX, $cropY, $cropW, $cropH, $dstW, $dstH] = $this->calculateDimensions(
                    $sourceWidth, $sourceHeight, $targetW, $targetH, $fit,
                );

                $canvas = imagecreatetruecolor($dstW, $dstH);
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);

                imagecopyresampled(
                    $canvas, $sourceImage,
                    0, 0, $cropX, $cropY,
                    $dstW, $dstH, $cropW, $cropH,
                );

                // --- Encode to WebP ---
                $tmpFile = tempnam(sys_get_temp_dir(), 'comet_img_');
                imagewebp($canvas, $tmpFile, $quality);
                imagedestroy($canvas);

                $fileBytes = filesize($tmpFile);
                $hash      = substr(hash_file('sha256', $tmpFile), 0, 16);

                // Content-hashed filename: thumb_abc123def456.webp
                $storagePath = "{$basePath}/{$variantName}_{$hash}.webp";

                // --- Upload to bucket ---
                $disk->put($storagePath, file_get_contents($tmpFile), 'public');
                @unlink($tmpFile);

                // --- Upsert DB row ---
                $variant = ImageVariant::updateOrCreate(
                    [
                        'image_id' => $imageId,
                        'variant'  => $variantName,
                    ],
                    [
                        'disk'         => $this->diskName(),
                        'path'         => $storagePath,
                        'format'       => 'webp',
                        'width'        => $dstW,
                        'height'       => $dstH,
                        'file_size'    => $fileBytes,
                        'content_hash' => $hash,
                    ],
                );

                $created[$variantName] = $variant;
            }
        } finally {
            imagedestroy($sourceImage);
        }

        \Illuminate\Support\Facades\Log::info('Image variant processing completed', [
            'image_id' => $imageId,
            'variant_count' => count($created),
        ]);

        return $created;
    }

    /**
     * Store the original upload to a location on the media disk
     * (kept for disaster-recovery / re-processing).
     *
     * Returns the storage path of the original.
     */
    public function storeOriginal(UploadedFile $file, string $basePath): string
    {
        $ext  = $file->getClientOriginalExtension() ?: 'jpg';
        $hash = substr(hash_file('sha256', $file->getRealPath()), 0, 16);
        $path = "{$basePath}/original_{$hash}.{$ext}";

        $this->disk()->put($path, file_get_contents($file->getRealPath()), 'public');

        return $path;
    }

    /**
     * Delete all variant files (and DB rows) for a given SiteImage,
     * plus the original file stored at the image's path.
     */
    public function deleteVariants(string $imageId, ?string $originalPath = null): void
    {
        $variants = ImageVariant::where('image_id', $imageId)->get();
        $disk     = $this->disk();

        foreach ($variants as $variant) {
            $disk->delete($variant->path);
            $variant->delete();
        }

        // Also remove the original if a path was provided
        if ($originalPath && $disk->exists($originalPath)) {
            $disk->delete($originalPath);
        }
    }

    /**
     * Build a variant map (variant_name → public URL) for a SiteImage.
     *
     * @return array<string, string>
     */
    public function variantUrls(string $imageId): array
    {
        return ImageVariant::where('image_id', $imageId)
            ->get()
            ->mapWithKeys(fn (ImageVariant $v) => [$v->variant => $v->url])
            ->all();
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function diskName(): string
    {
        $configured = (string) config('comet.media_disk', 'media');

        // If COMET_MEDIA_DISK is explicitly set, always honour it.
        $explicit = $_ENV['COMET_MEDIA_DISK'] ?? $_SERVER['COMET_MEDIA_DISK'] ?? null;
        if (is_string($explicit) && trim($explicit) !== '') {
            return $configured;
        }

        // Laravel Cloud injects disks dynamically and may set a non-"media"
        // filesystems.default disk. If media disk is only a fallback value,
        // prefer the Cloud default to avoid missing/empty media credentials.
        if ($configured === 'media') {
            $default = (string) config('filesystems.default', 'local');
            $defaultConfig = config("filesystems.disks.{$default}");

            if (
                $default !== '' &&
                $default !== 'local' &&
                $default !== 'media' &&
                is_array($defaultConfig) &&
                (($defaultConfig['driver'] ?? null) === 's3')
            ) {
                Log::warning('COMET_MEDIA_DISK not set; using filesystems.default disk for media operations.', [
                    'configured_media_disk' => $configured,
                    'fallback_disk' => $default,
                ]);

                return $default;
            }
        }

        return $configured;
    }

    private function disk(): \Illuminate\Contracts\Filesystem\Filesystem
    {
        return Storage::disk($this->diskName());
    }

    /**
     * Universal variant definitions (same for all images).
     */
    private function variantDefinitions(): array
    {
        return (array) config('comet.image_variants', []);
    }

    /**
     * Load an image file into a GD resource regardless of source format.
     */
    private function loadImage(string $path): \GdImage|false
    {
        $info = @getimagesize($path);
        if (!$info) {
            return false;
        }

        return match ($info[2]) {
            IMAGETYPE_JPEG => @imagecreatefromjpeg($path),
            IMAGETYPE_PNG  => @imagecreatefrompng($path),
            IMAGETYPE_WEBP => @imagecreatefromwebp($path),
            default        => false,
        };
    }

    /**
     * Calculate crop / resize geometry.
     *
     * @return array [cropX, cropY, cropW, cropH, dstW, dstH]
     */
    private function calculateDimensions(
        int $srcW, int $srcH,
        int $maxW, int $maxH,
        string $fit,
    ): array {
        if ($fit === 'cover') {
            $srcRatio = $srcW / $srcH;
            $dstRatio = $maxW / $maxH;

            if ($srcRatio > $dstRatio) {
                $cropH = $srcH;
                $cropW = (int) round($srcH * $dstRatio);
                $cropX = (int) round(($srcW - $cropW) / 2);
                $cropY = 0;
            } else {
                $cropW = $srcW;
                $cropH = (int) round($srcW / $dstRatio);
                $cropX = 0;
                $cropY = (int) round(($srcH - $cropH) / 2);
            }

            return [$cropX, $cropY, $cropW, $cropH, $maxW, $maxH];
        }

        // "inside" – fit within bounds, no crop, never upscale
        $ratio = min($maxW / $srcW, $maxH / $srcH, 1);
        $dstW  = (int) round($srcW * $ratio);
        $dstH  = (int) round($srcH * $ratio);

        return [0, 0, $srcW, $srcH, $dstW, $dstH];
    }
}
