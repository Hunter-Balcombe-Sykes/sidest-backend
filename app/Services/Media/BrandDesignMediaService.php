<?php

namespace App\Services\Media;

use App\Jobs\ProcessImageVariantsJob;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

// V2: Single seam for brand design media — logo (full + square) and
// placeholders. Both ProfessionalUploadController (multipart) and
// SyncShopifyBrandDesignJob (Shopify-fetched bytes) call into this service so
// every brand design asset goes through the same persistence + variant pipeline.
class BrandDesignMediaService
{
    public const PLACEHOLDER_MAX = 5;

    private const ALLOWED_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    public function __construct(
        private readonly ImageVariantService $images,
    ) {}

    /**
     * Upload a logo from a multipart UploadedFile. Variant is 'full' or 'square'.
     * Soft-deletes any prior active row with the same purpose so the singleton
     * index holds.
     */
    public function upsertLogoFromUploadedFile(Site $site, string $proId, UploadedFile $file, string $variant): SiteMedia
    {
        $purpose = $this->purposeForLogoVariant($variant);

        // finfo against actual bytes — getMimeType() trusts the client Content-Type header.
        $this->assertMimeAllowed((new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()));

        $media = $this->createDesignRow($site, $purpose, $file->getMimeType(), $file->getSize(), 0);

        $basePath = "images/{$proId}/{$media->id}";

        try {
            $originalPath = $this->images->storeOriginal($file, $basePath);
        } catch (Throwable $e) {
            Log::error('BrandDesignMediaService: failed to store logo original.', [
                'site_id' => $site->id,
                'purpose' => $purpose,
                'error' => $e->getMessage(),
            ]);
            $media->delete();
            throw $e;
        }

        $media->update(['path' => $originalPath]);
        $this->dispatchVariantJob($media->id, $originalPath, $basePath);
        $this->invalidateSiteCache($site);

        return $media->refresh();
    }

    /**
     * Upload a logo from raw bytes already in memory (Shopify CDN download path).
     * Same singleton-replace semantics as upsertLogoFromUploadedFile.
     */
    public function upsertLogoFromBytes(Site $site, string $proId, string $bytes, string $mime, string $variant): SiteMedia
    {
        $purpose = $this->purposeForLogoVariant($variant);

        // finfo against actual bytes — $mime comes from Shopify CDN headers, not verified.
        $this->assertMimeAllowed((new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes));

        $media = $this->createDesignRow($site, $purpose, $mime, strlen($bytes), 0);

        $basePath = "images/{$proId}/{$media->id}";
        $ext = $this->extensionFromMime($mime);
        $hash = substr(hash('sha256', $bytes), 0, 16);
        $originalPath = "{$basePath}/original_{$hash}.{$ext}";

        try {
            Storage::disk($this->images->resolvedDiskName())->put($originalPath, $bytes, 'public');
        } catch (Throwable $e) {
            Log::error('BrandDesignMediaService: failed to store logo bytes.', [
                'site_id' => $site->id,
                'purpose' => $purpose,
                'error' => $e->getMessage(),
            ]);
            $media->delete();
            throw $e;
        }

        $media->update(['path' => $originalPath]);
        $this->dispatchVariantJob($media->id, $originalPath, $basePath);
        $this->invalidateSiteCache($site);

        return $media->refresh();
    }

    /**
     * Append a placeholder image. Throws PlaceholderLimitExceededException if
     * 5 active placeholders already exist for the site.
     */
    public function addPlaceholder(Site $site, string $proId, UploadedFile $file): SiteMedia
    {
        $this->assertMimeAllowed((new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()));

        $media = DB::transaction(function () use ($site, $file) {
            $activeCount = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->count();

            if ($activeCount >= self::PLACEHOLDER_MAX) {
                throw new PlaceholderLimitExceededException(self::PLACEHOLDER_MAX);
            }

            $maxSort = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->max('sort_order');

            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => SiteMedia::POOL_DESIGN,
                'purpose' => SiteMedia::PURPOSE_PLACEHOLDER,
                'path' => '',
                'alt_text' => $file->getClientOriginalName(),
                'sort_order' => is_null($maxSort) ? 0 : ((int) $maxSort + 1),
                'is_active' => true,
                'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
                'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
                'original_mime' => $file->getMimeType(),
                'original_size_bytes' => $file->getSize(),
            ]);
        });

        $basePath = "images/{$proId}/{$media->id}";

        try {
            $originalPath = $this->images->storeOriginal($file, $basePath);
        } catch (Throwable $e) {
            Log::error('BrandDesignMediaService: failed to store placeholder original.', [
                'site_id' => $site->id,
                'media_id' => $media->id,
                'error' => $e->getMessage(),
            ]);
            $media->delete();
            throw $e;
        }

        $media->update(['path' => $originalPath]);
        $this->dispatchVariantJob($media->id, $originalPath, $basePath);
        $this->invalidateSiteCache($site);

        return $media->refresh();
    }

    /**
     * Soft-delete a placeholder and repack the remaining sort_order so the list
     * has no gaps. Throws if the media id doesn't belong to this site / isn't a
     * placeholder.
     */
    public function deletePlaceholder(Site $site, string $mediaId): void
    {
        DB::transaction(function () use ($site, $mediaId) {
            $row = SiteMedia::query()
                ->where('id', $mediaId)
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->first();

            if (! $row) {
                abort(404, 'Placeholder not found.');
            }

            $row->delete();

            // Re-pack remaining placeholders to (0, 1, 2, ...) — first push to
            // a high offset to avoid colliding with the partial unique index,
            // then assign final values.
            $remaining = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->orderBy('sort_order')
                ->lockForUpdate()
                ->get();

            $offset = self::PLACEHOLDER_MAX + 1000;
            foreach ($remaining as $idx => $r) {
                SiteMedia::query()->where('id', $r->id)->update(['sort_order' => $offset + $idx]);
            }
            foreach ($remaining as $idx => $r) {
                SiteMedia::query()->where('id', $r->id)->update(['sort_order' => $idx]);
            }
        });

        $this->invalidateSiteCache($site);
    }

    /**
     * Replace the existing sort_order of placeholders with the supplied ordering.
     * The id list must contain exactly the active placeholder ids for this site
     * (no extras, no missing rows). Two-pass update to avoid index collisions.
     */
    public function reorderPlaceholders(Site $site, array $orderedIds): void
    {
        DB::transaction(function () use ($site, $orderedIds) {
            $existing = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', SiteMedia::PURPOSE_PLACEHOLDER)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->pluck('id')
                ->all();

            $existingSet = array_flip($existing);
            $orderedSet = array_flip($orderedIds);

            if (count($orderedIds) !== count($existing) || array_diff_key($existingSet, $orderedSet) !== []) {
                abort(422, 'Reorder ids must match active placeholders exactly.');
            }

            $offset = self::PLACEHOLDER_MAX + 1000;
            foreach ($orderedIds as $idx => $id) {
                SiteMedia::query()->where('id', $id)->update(['sort_order' => $offset + $idx]);
            }
            foreach ($orderedIds as $idx => $id) {
                SiteMedia::query()->where('id', $id)->update(['sort_order' => $idx]);
            }
        });

        $this->invalidateSiteCache($site);
    }

    /**
     * Soft-delete a logo row by variant (full or square). No-ops if no active
     * row exists for the given purpose.
     */
    public function deleteLogo(Site $site, string $variant): void
    {
        $purpose = $this->purposeForLogoVariant($variant);

        $row = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->where('purpose', $purpose)
            ->whereNull('deleted_at')
            ->first();

        if (! $row) {
            abort(404, 'Logo not found.');
        }

        $row->delete();

        $this->invalidateSiteCache($site);
    }

    /**
     * Resolve all brand design media for a site into the shape that every reader
     * (HydrogenBrandDesignController, BrandDesignController, SiteCacheService)
     * consumes. Only ready rows are returned — pending/failed rows are skipped.
     *
     * @return array{
     *     logo: array{full_url: ?string, square_url: ?string},
     *     placeholders: array<int, array{id: string, alt_text: ?string, url: string, sort_order: int}>
     * }
     */
    public function listDesignMedia(string $siteId): array
    {
        $rows = SiteMedia::query()
            ->where('site_id', $siteId)
            ->where('pool', SiteMedia::POOL_DESIGN)
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->where('processing_state', SiteMedia::PROCESSING_STATE_READY)
            ->with('mediaVariants')
            ->orderBy('sort_order')
            ->get();

        $logo = ['full_url' => null, 'square_url' => null];
        $placeholders = [];

        foreach ($rows as $row) {
            $url = $row->variantUrls()['optimized'] ?? null;
            if ($url === null) {
                continue;
            }

            if ($row->purpose === SiteMedia::PURPOSE_LOGO_FULL) {
                $logo['full_url'] = $url;
            } elseif ($row->purpose === SiteMedia::PURPOSE_LOGO_SQUARE) {
                $logo['square_url'] = $url;
            } elseif ($row->purpose === SiteMedia::PURPOSE_PLACEHOLDER) {
                $placeholders[] = [
                    'id' => $row->id,
                    'alt_text' => $row->alt_text,
                    'url' => $url,
                    'sort_order' => (int) $row->sort_order,
                ];
            }
        }

        return ['logo' => $logo, 'placeholders' => $placeholders];
    }

    /* ------------------------------------------------------------------ */
    /*  Internal helpers */
    /* ------------------------------------------------------------------ */

    private function assertMimeAllowed(string $actual): void
    {
        if (! in_array($actual, self::ALLOWED_IMAGE_MIMES, true)) {
            throw new UnprocessableImageException(
                "Rejected: MIME type '{$actual}' is not an accepted image format."
            );
        }
    }

    private function purposeForLogoVariant(string $variant): string
    {
        return match ($variant) {
            'full' => SiteMedia::PURPOSE_LOGO_FULL,
            'square' => SiteMedia::PURPOSE_LOGO_SQUARE,
            default => throw new \InvalidArgumentException("Unknown logo variant: {$variant}"),
        };
    }

    private function createDesignRow(Site $site, string $purpose, ?string $mime, ?int $size, int $sortOrder): SiteMedia
    {
        return DB::transaction(function () use ($site, $purpose, $mime, $size, $sortOrder) {
            // Singleton-replace: soft-delete any prior active row with this purpose.
            SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DESIGN)
                ->where('purpose', $purpose)
                ->whereNull('deleted_at')
                ->lockForUpdate()
                ->get()
                ->each(fn (SiteMedia $row) => $row->delete());

            return SiteMedia::create([
                'site_id' => $site->id,
                'pool' => SiteMedia::POOL_DESIGN,
                'purpose' => $purpose,
                'path' => '',
                'sort_order' => $sortOrder,
                'is_active' => true,
                'media_type' => SiteMedia::MEDIA_TYPE_IMAGE,
                'processing_state' => SiteMedia::PROCESSING_STATE_PENDING,
                'original_mime' => $mime,
                'original_size_bytes' => $size,
            ]);
        });
    }

    private function dispatchVariantJob(string $imageId, string $originalPath, string $basePath): void
    {
        $queueDefault = (string) config('queue.default', 'sync');
        $processInline = in_array(app()->environment(), ['local', 'testing'], true)
            || $queueDefault === 'sync';

        if ($processInline) {
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } catch (Throwable $e) {
                Log::error('BrandDesignMediaService: inline variant processing failed.', [
                    'image_id' => $imageId,
                    'error' => $e->getMessage(),
                ]);
            }

            return;
        }

        try {
            ProcessImageVariantsJob::dispatch(
                originalPath: $originalPath,
                imageId: $imageId,
                basePath: $basePath,
            );
        } catch (Throwable $e) {
            Log::error('BrandDesignMediaService: queue dispatch failed; falling back to sync.', [
                'image_id' => $imageId,
                'error' => $e->getMessage(),
            ]);
            try {
                ProcessImageVariantsJob::dispatchSync(
                    originalPath: $originalPath,
                    imageId: $imageId,
                    basePath: $basePath,
                );
            } catch (Throwable $sync) {
                Log::error('BrandDesignMediaService: sync fallback also failed.', [
                    'image_id' => $imageId,
                    'error' => $sync->getMessage(),
                ]);
            }
        }
    }

    private function invalidateSiteCache(Site $site): void
    {
        try {
            $siteCache = app(SiteCacheService::class);
            $siteCache->invalidateSite($site);
            // Also bust the Hydrogen brand-design cache so dashboard saves
            // (logo, placeholder upload/delete/reorder) surface on Hydrogen
            // inside its 5s staleWhileRevalidate window.
            $siteCache->forgetBrandDesign((string) $site->id);
        } catch (Throwable $e) {
            Log::warning('BrandDesignMediaService: cache invalidation failed.', [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extensionFromMime(string $mime): string
    {
        return match (strtolower(trim(explode(';', $mime)[0] ?? ''))) {
            'image/png' => 'png',
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => 'png',
        };
    }
}
