<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Site\Site;
use App\Models\Core\Site\SiteMedia;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;

// V2: Public document download. 302-redirects to an R2 presigned URL with
// a response-content-disposition=attachment override so the browser forces
// a download with the original filename instead of rendering inline.
class PublicDocumentDownloadController extends ApiController
{
    public function __invoke(SiteMedia $document): RedirectResponse
    {
        // Only serve rows that are active, not soft-deleted, and actually
        // part of the documents pool — otherwise 404 without leaking details.
        abort_unless(
            $document->pool === SiteMedia::POOL_DOCUMENTS
            && $document->is_active
            && $document->deleted_at === null,
            404
        );

        $site = Site::query()->find($document->site_id);
        abort_unless($site && $site->is_published, 404);

        $mediaDisk = config('sidest.media_disk');
        $filename = $document->original_filename ?: 'document';

        $presignedUrl = Storage::disk($mediaDisk)->temporaryUrl(
            (string) $document->path,
            now()->addMinutes(5),
            [
                'ResponseContentDisposition' => 'attachment; filename="'.$this->sanitiseFilename($filename).'"',
            ]
        );

        return redirect()->away($presignedUrl);
    }

    /**
     * Strip quote / newline / special characters that would break the
     * Content-Disposition header. Keeps alphanumerics, dots, dashes,
     * underscores, and spaces.
     */
    private function sanitiseFilename(string $name): string
    {
        $cleaned = preg_replace('/[^A-Za-z0-9._\- ]/', '', $name);

        return $cleaned !== '' ? $cleaned : 'document';
    }
}
