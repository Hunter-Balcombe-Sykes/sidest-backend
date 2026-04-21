<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Documents\UploadDocumentRequest;
use App\Models\Core\Site\SiteMedia;
use App\Services\Cache\SiteCacheService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

// V2: Per-site document CRUD (PDF/JPG/PNG, 1 per site). Flat-replace
// semantics — a second upload soft-deletes the existing row and deletes
// its R2 bytes synchronously before creating the new row.
class ProfessionalDocumentController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $media = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DOCUMENTS)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->first();

        return $this->success([
            'document' => $media ? $this->buildDocumentPayload($media) : null,
        ]);
    }

    public function store(UploadDocumentRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // Brand accounts are excluded per product spec — they have Shopify
        // for catalogue assets and don't get the generic document slot.
        if ($pro->professional_type === 'brand') {
            return $this->error('Documents section not available for brand accounts.', 403);
        }

        // Double MIME-check via finfo — prevents Content-Type header spoofing
        // on top of the mimes: validation rule which trusts the client header.
        $file = $request->file('file');
        $actualMime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $allowed = ['application/pdf', 'image/jpeg', 'image/png'];
        if (! in_array($actualMime, $allowed, true)) {
            return $this->error('Document bytes do not match an accepted file type.', 415);
        }

        $title = trim((string) $request->validated('title'));
        $caption = $this->normaliseOptionalString($request->validated('caption'));
        $originalFilename = substr((string) $file->getClientOriginalName(), 0, 255);

        // Flat-replace: inside one transaction, remove the existing document
        // row + R2 bytes (if any), then create the new row. Advisory lock
        // prevents two parallel uploads from racing.
        $media = DB::transaction(function () use ($site, $pro, $file, $actualMime, $title, $caption, $originalFilename) {
            if (DB::getDriverName() === 'pgsql') {
                DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-documents:{$site->id}"]);
            }

            $existing = SiteMedia::query()
                ->where('site_id', $site->id)
                ->where('pool', SiteMedia::POOL_DOCUMENTS)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->first();

            if ($existing) {
                try {
                    Storage::disk(config('sidest.media_disk'))->delete((string) $existing->path);
                } catch (\Throwable $e) {
                    Log::warning('Failed to delete previous document R2 object', [
                        'media_id' => $existing->id,
                        'path' => $existing->path,
                        'error' => $e->getMessage(),
                    ]);
                }
                $existing->delete();
            }

            // Extension is derived from the actual MIME — never from the
            // client-supplied filename (spoofable).
            $ext = match ($actualMime) {
                'application/pdf' => 'pdf',
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
            };

            $media = SiteMedia::create([
                'site_id' => $site->id,
                'pool' => SiteMedia::POOL_DOCUMENTS,
                'path' => '',
                'alt_text' => $title,
                'caption' => $caption,
                'sort_order' => 0,
                'is_active' => true,
                'media_type' => SiteMedia::MEDIA_TYPE_DOCUMENT,
                'processing_state' => SiteMedia::PROCESSING_STATE_READY,
                'original_mime' => $actualMime,
                'original_filename' => $originalFilename,
                'original_size_bytes' => $file->getSize(),
            ]);

            // Stream to R2 (not in-memory) — matches the video upload path
            // so 10 MB PDFs don't peg worker memory.
            $mediaDisk = config('sidest.media_disk');
            $path = "documents/{$pro->id}/{$media->id}/original.{$ext}";
            $stream = fopen($file->getRealPath(), 'rb');
            Storage::disk($mediaDisk)->put($path, $stream, 'public');
            if (is_resource($stream)) {
                fclose($stream);
            }

            $media->update(['path' => $path]);

            return $media;
        });

        app(SiteCacheService::class)->invalidateSite($site);

        return $this->success(['document' => $this->buildDocumentPayload($media)], 201);
    }

    /**
     * @return array{id: string, title: string|null, caption: string|null, original_mime: string|null, original_size_bytes: int|null, original_filename: string|null, preview_url: string, download_url: string, created_at: mixed, updated_at: mixed}
     */
    private function buildDocumentPayload(SiteMedia $media): array
    {
        $mediaDisk = config('sidest.media_disk');
        $previewUrl = Storage::disk($mediaDisk)->url((string) $media->path);

        return [
            'id' => $media->id,
            'title' => $media->alt_text,
            'caption' => $media->caption,
            'original_mime' => $media->original_mime,
            'original_size_bytes' => $media->original_size_bytes,
            'original_filename' => $media->original_filename,
            'preview_url' => $previewUrl,
            'download_url' => '/api/public/documents/'.$media->id.'/download',
            'created_at' => $media->created_at,
            'updated_at' => $media->updated_at,
        ];
    }

    /**
     * Trim; coerce empty/whitespace-only strings to NULL so NULL and ""
     * mean the same thing at rest.
     */
    private function normaliseOptionalString(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }

        $trimmed = trim($raw);

        return $trimmed === '' ? null : $trimmed;
    }
}
