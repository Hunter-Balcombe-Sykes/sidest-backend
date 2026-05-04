<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Documents\UpdateDocumentRequest;
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

        // Return the document regardless of is_active so draft docs surface in
        // the dashboard — the frontend publish toggle flips is_active directly.
        $media = SiteMedia::query()
            ->where('site_id', $site->id)
            ->where('pool', SiteMedia::POOL_DOCUMENTS)
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
        // basename() removes path traversal components; control-char strip (incl. CRLF)
        // prevents header injection if this value ever appears in Content-Disposition.
        $rawFilename = basename((string) $file->getClientOriginalName());
        $rawFilename = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $rawFilename);
        $originalFilename = substr($rawFilename, 0, 255);

        // Flat-replace: inside one transaction, soft-delete the existing row
        // (if any), create the new row, stream the file to R2, and set the path.
        // The old row's R2 bytes are deleted AFTER commit so a transaction
        // rollback doesn't leave an active row pointing at missing bytes.
        //
        // If the transaction closure throws AFTER we've streamed the new file
        // to R2, the uploaded bytes would be orphaned (row gone, file remains).
        // Track the new path and clean it up in the catch branch below.
        $previousPath = null;
        $newUploadedPath = null;
        try {
            $media = DB::transaction(function () use ($site, $pro, $file, $actualMime, $title, $caption, $originalFilename, &$previousPath, &$newUploadedPath) {
                if (DB::getDriverName() === 'pgsql') {
                    DB::select('select pg_advisory_xact_lock(hashtext(?))', ["site-documents:{$site->id}"]);
                }

                // Flat-replace targets any non-deleted doc (including drafts)
                // so uploading a new file always takes over the single slot.
                $existing = SiteMedia::query()
                    ->where('site_id', $site->id)
                    ->where('pool', SiteMedia::POOL_DOCUMENTS)
                    ->whereNull('deleted_at')
                    ->first();

                if ($existing) {
                    // Capture the path for post-commit R2 cleanup.
                    $previousPath = (string) $existing->path;
                    // Suppress the old row's `deleted` observer event during
                    // flat-replace — the new row's `saved` event a few lines
                    // below will trigger section-visibility reevaluation once.
                    // Without this, both events fire post-commit and do the
                    // same DB read + check in sequence (wasted work).
                    SiteMedia::withoutEvents(function () use ($existing): void {
                        $existing->delete();
                    });
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
                $newUploadedPath = $path;

                $media->update(['path' => $path]);

                return $media;
            });
        } catch (\Throwable $e) {
            // Transaction failed after the R2 put succeeded — clean up the
            // orphaned bytes so we don't leak storage on retries.
            if ($newUploadedPath !== null) {
                try {
                    Storage::disk(config('sidest.media_disk'))->delete($newUploadedPath);
                } catch (\Throwable $cleanupError) {
                    Log::warning('Failed to clean up orphaned document R2 object after transaction failure', [
                        'path' => $newUploadedPath,
                        'error' => $cleanupError->getMessage(),
                    ]);
                }
            }
            throw $e;
        }

        // Post-commit: delete old R2 bytes. Safe to run outside the txn because
        // the old row is already soft-deleted (so no reader will try to fetch
        // the old path), and if this delete fails we just leak bytes — not a
        // correctness issue. If the transaction had rolled back, $previousPath
        // would stay null and this is a no-op.
        if ($previousPath !== null && $previousPath !== '') {
            try {
                Storage::disk(config('sidest.media_disk'))->delete($previousPath);
            } catch (\Throwable $e) {
                Log::warning('Failed to delete previous document R2 object after commit', [
                    'path' => $previousPath,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        app(SiteCacheService::class)->invalidateSite($site);

        return $this->success(['document' => $this->buildDocumentPayload($media)], 201);
    }

    /**
     * Edit document title and/or caption. isDirty-guarded so no-op PATCHes
     * don't churn the public-site cache.
     */
    public function update(UpdateDocumentRequest $request, SiteMedia $document): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // Ownership check. Route model binding already 404s on soft-deleted rows
        // (SoftDeletes trait). We intentionally allow is_active = false so the
        // publish toggle can flip a draft document back to live.
        abort_unless(
            $document->site_id === $site->id
            && $document->pool === SiteMedia::POOL_DOCUMENTS,
            404
        );

        $data = $request->validated();
        $update = [];

        if (array_key_exists('title', $data)) {
            $update['alt_text'] = $this->normaliseOptionalString($data['title']);
        }

        if (array_key_exists('caption', $data)) {
            $update['caption'] = $this->normaliseOptionalString($data['caption']);
        }

        // is_enabled maps to is_active — the publish toggle flips this directly.
        if (array_key_exists('is_enabled', $data)) {
            $update['is_active'] = (bool) $data['is_enabled'];
        }

        $changed = false;
        if (! empty($update)) {
            $document->fill($update);
            if ($document->isDirty(['alt_text', 'caption', 'is_active'])) {
                $document->save();
                $changed = true;
            }
        }

        if ($changed) {
            app(SiteCacheService::class)->invalidateSite($site);
        }

        return $this->success(['document' => $this->buildDocumentPayload($document->fresh())]);
    }

    /**
     * Soft-delete the row and synchronously delete the R2 bytes (no
     * versioning, so there's no archival value in keeping bytes around).
     */
    public function destroy(Request $request, SiteMedia $document): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        // Ownership check — allows deleting draft (is_active = false) docs too.
        // Route model binding already 404s on soft-deleted rows.
        abort_unless(
            $document->site_id === $site->id
            && $document->pool === SiteMedia::POOL_DOCUMENTS,
            404
        );

        try {
            Storage::disk(config('sidest.media_disk'))->delete((string) $document->path);
        } catch (\Throwable $e) {
            Log::warning('Failed to delete document R2 object on destroy', [
                'media_id' => $document->id,
                'path' => $document->path,
                'error' => $e->getMessage(),
            ]);
        }

        $document->delete();

        app(SiteCacheService::class)->invalidateSite($site);

        return $this->success(['deleted' => true]);
    }

    /**
     * @return array{id: string, title: string|null, caption: string|null, is_enabled: bool, original_mime: string|null, original_size_bytes: int|null, original_filename: string|null, preview_url: string, download_url: string, created_at: mixed, updated_at: mixed}
     */
    private function buildDocumentPayload(SiteMedia $media): array
    {
        $mediaDisk = config('sidest.media_disk');
        $previewUrl = Storage::disk($mediaDisk)->url((string) $media->path);

        return [
            'id' => $media->id,
            'title' => $media->alt_text,
            'caption' => $media->caption,
            // is_enabled maps to is_active — the publish toggle reads and writes this.
            'is_enabled' => (bool) $media->is_active,
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
