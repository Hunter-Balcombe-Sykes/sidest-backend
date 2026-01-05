<?php

namespace App\Http\Controllers\Api\Professional\Uploads;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Uploads\PrepareUploadRequest;
use App\Models\Core\Site\SiteImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class ProfessionalUploadController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function prepare(PrepareUploadRequest $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);
        $pro->loadMissing('site');
        $site = $this->currentSite($pro);

        $bucket = (string) config('comet.media_bucket', 'media');

        $type = $request->validated('type');
        $contentType = $request->validated('content_type');

        $ext = match ($contentType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => 'jpg',
        };

        // Prevent wasted uploads (we also enforce this again when inserting the DB row)
        if ($type === 'gallery') {
            $activeCount = SiteImage::query()
                ->where('site_id', $site->id)
                ->where('is_active', true)
                ->count();

            if ($activeCount >= 6) {
                return $this->error('Gallery limit reached (max 6 images).', 422);
            }
        }

        [$path, $upsert] = match ($type) {
            'icon'     => ["professionals/{$pro->id}/icon.{$ext}", true],
            'headshot' => ["professionals/{$pro->id}/headshot.{$ext}", true],
            'banner'   => ["sites/{$site->id}/banner.{$ext}", true],
            'gallery'  => ["sites/{$site->id}/gallery/" . Str::uuid() . ".{$ext}", false],
        };

        return $this->success([
            'bucket' => $bucket,
            'path' => $path,
            'upsert' => $upsert,
        ]);
    }
}
