<?php

namespace App\Services\Media;

use App\Models\Core\MediaVariant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generates universal WebP image variants from an uploaded original and
 * persists them to the configured media disk (S3-compatible / R2).
 *
 * Every image (gallery or content) gets the same configured variant set.
 * Current default produces two full-resolution variants:
 * - optimized (target-size adaptive quality)
 * - maximized (highest quality)
 *
 * Requires the GD extension with WebP support (ships with PHP 8.2+).
 */
// V2: Generates WebP image variants from uploads via GD. Content-hashed storage on Cloudflare R2 with adaptive quality targeting.
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
     * @param  string  $imageId          UUID of the SiteMedia row.
     * @param  string  $basePath         e.g. "images/<proId>/<imageId>"
     * @return array<string, MediaVariant>  keyed by variant name
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
                $quality    = max(1, min(100, (int) ($def['quality'] ?? 92)));
                $minQuality = max(1, min($quality, (int) ($def['min_quality'] ?? 60)));
                $targetKb   = max(0, (int) ($def['target_kb'] ?? 0));
                $targetBytes = $targetKb > 0 ? ($targetKb * 1024) : null;
                $fit        = (string) ($def['fit'] ?? 'inside');

                $preserveResolution = filter_var(
                    $def['preserve_resolution'] ?? true,
                    FILTER_VALIDATE_BOOLEAN,
                    FILTER_NULL_ON_FAILURE,
                );
                $preserveResolution = $preserveResolution ?? true;

                if ($preserveResolution) {
                    [$cropX, $cropY, $cropW, $cropH, $dstW, $dstH] = [0, 0, $sourceWidth, $sourceHeight, $sourceWidth, $sourceHeight];
                } else {
                    $targetW = max(1, (int) ($def['width'] ?? $sourceWidth));
                    $targetH = max(1, (int) ($def['height'] ?? $sourceHeight));

                    // Backward-compatible path for capped variants.
                    [$cropX, $cropY, $cropW, $cropH, $dstW, $dstH] = $this->calculateDimensions(
                        $sourceWidth, $sourceHeight, $targetW, $targetH, $fit,
                    );
                }

                $canvas = imagecreatetruecolor($dstW, $dstH);
                imagealphablending($canvas, false);
                imagesavealpha($canvas, true);

                imagecopyresampled(
                    $canvas, $sourceImage,
                    0, 0, $cropX, $cropY,
                    $dstW, $dstH, $cropW, $cropH,
                );

                // --- Encode to WebP ---
                $tmpFile = tempnam(sys_get_temp_dir(), 'sidest_img_');
                if (!is_string($tmpFile) || $tmpFile === '') {
                    throw new \RuntimeException('Failed to create temp file for WebP encoding.');
                }

                try {
                    $encodedQuality = $quality;
                    if ($targetBytes !== null) {
                        $encodedQuality = $this->encodeWebpToTargetSize(
                            image: $canvas,
                            tmpFile: $tmpFile,
                            maxQuality: $quality,
                            minQuality: $minQuality,
                            targetBytes: $targetBytes,
                        );
                    } else {
                        $this->encodeWebp($canvas, $tmpFile, $quality);
                    }

                    $fileBytes = filesize($tmpFile);
                    if ($fileBytes === false) {
                        throw new \RuntimeException('Failed to determine encoded WebP file size.');
                    }

                    if ($targetBytes !== null && $fileBytes > $targetBytes) {
                        Log::warning('Image variant exceeded target size at min quality.', [
                            'image_id' => $imageId,
                            'variant' => $variantName,
                            'target_kb' => $targetKb,
                            'actual_kb' => (int) round($fileBytes / 1024),
                            'quality_used' => $encodedQuality,
                        ]);
                    }

                    $hash = hash_file('sha256', $tmpFile);
                    if (!is_string($hash)) {
                        throw new \RuntimeException('Failed to hash encoded WebP variant.');
                    }
                    $hash = substr($hash, 0, 16);

                    // Content-hashed filename: optimized_abc123def456.webp
                    $storagePath = "{$basePath}/{$variantName}_{$hash}.webp";

                    // --- Upload to bucket ---
                    $payload = file_get_contents($tmpFile);
                    if ($payload === false) {
                        throw new \RuntimeException('Failed to read encoded WebP for upload.');
                    }
                    $disk->put($storagePath, $payload, 'public');

                    // --- Upsert DB row ---
                    $variant = MediaVariant::updateOrCreate(
                        [
                            'media_id'      => $imageId,
                            'variant_key'   => $variantName,
                            'artifact_type' => 'webp',
                        ],
                        [
                            'disk'            => $this->diskName(),
                            'path'            => $storagePath,
                            'mime'            => 'image/webp',
                            'width'           => $dstW,
                            'height'          => $dstH,
                            'file_size_bytes' => $fileBytes,
                            'content_hash'    => $hash,
                        ],
                    );

                    $created[$variantName] = $variant;
                } finally {
                    @unlink($tmpFile);
                    unset($canvas);
                }
            }
        } finally {
            unset($sourceImage);
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
     * Delete all image variant files (and DB rows) for a given SiteMedia item,
     * plus the original file stored at the image's path.
     */
    public function deleteVariants(string $imageId, ?string $originalPath = null): void
    {
        $variants = MediaVariant::where('media_id', $imageId)
            ->where('artifact_type', 'webp')
            ->get();
        $disk = $this->disk();

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
     * Build a variant map (variant_key → public URL) for a SiteMedia image item.
     *
     * @return array<string, string>
     */
    public function variantUrls(string $imageId): array
    {
        return MediaVariant::where('media_id', $imageId)
            ->where('artifact_type', 'webp')
            ->get()
            ->mapWithKeys(fn (MediaVariant $v) => [$v->variant_key => $v->url])
            ->all();
    }

    /**
     * Resolve the effective media disk name used for all reads/writes.
     */
    public function resolvedDiskName(): string
    {
        return $this->diskName();
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers                                                    */
    /* ------------------------------------------------------------------ */

    private function diskName(): string
    {
        $configured = (string) config('sidest.media_disk', 'media');

        // If SIDEST_MEDIA_DISK is explicitly set, always honour it.
        $explicit = $_ENV['SIDEST_MEDIA_DISK'] ?? $_SERVER['SIDEST_MEDIA_DISK'] ?? null;
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
                Log::warning('SIDEST_MEDIA_DISK not set; using filesystems.default disk for media operations.', [
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
        $definitions = (array) config('sidest.image_variants', []);

        if ($definitions !== []) {
            return $definitions;
        }

        return [
            'optimized' => [
                'format' => 'webp',
                'preserve_resolution' => true,
                'quality' => 92,
                'min_quality' => 60,
                'target_kb' => 500,
            ],
            'maximized' => [
                'format' => 'webp',
                'preserve_resolution' => true,
                'quality' => 100,
            ],
        ];
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
     * Encode an image as WebP at the given quality and return file size.
     */
    private function encodeWebp(\GdImage $image, string $tmpFile, int $quality): int
    {
        if (@imagewebp($image, $tmpFile, $quality) === false) {
            throw new \RuntimeException("Failed to encode WebP at quality {$quality}.");
        }

        clearstatcache(true, $tmpFile);
        $size = filesize($tmpFile);
        if ($size === false) {
            throw new \RuntimeException('Failed to read encoded WebP size.');
        }

        return $size;
    }

    /**
     * Binary-search the highest quality that stays under target bytes.
     *
     * Returns the quality used for the final file in $tmpFile.
     */
    private function encodeWebpToTargetSize(
        \GdImage $image,
        string $tmpFile,
        int $maxQuality,
        int $minQuality,
        int $targetBytes,
    ): int {
        $lower = max(1, min(100, $minQuality));
        $upper = max($lower, min(100, $maxQuality));

        $bestQuality = null;

        while ($lower <= $upper) {
            $mid = intdiv($lower + $upper, 2);
            $size = $this->encodeWebp($image, $tmpFile, $mid);

            if ($size <= $targetBytes) {
                $bestQuality = $mid;
                $lower = $mid + 1;
                continue;
            }

            $upper = $mid - 1;
        }

        if ($bestQuality !== null) {
            $this->encodeWebp($image, $tmpFile, $bestQuality);
            return $bestQuality;
        }

        // Could not satisfy target even at min quality.
        $this->encodeWebp($image, $tmpFile, max(1, min(100, $minQuality)));

        return max(1, min(100, $minQuality));
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
