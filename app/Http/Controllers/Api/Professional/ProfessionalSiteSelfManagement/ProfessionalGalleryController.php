<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\ImageGallery\ReorderGalleryImageRequest;
use App\Http\Requests\Api\Professional\ImageGallery\StoreGalleryImageRequest;
use App\Models\Core\Site\SiteImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class ProfessionalGalleryController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function index(): JsonResponse
    {
        $pro = $this->currentProfessional(request());
        $site = $this->currentSite($pro);
        $images = SiteImage::query()
            ->where('site_id', $site->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('created_at')
            ->get();

        return $this->success(['images' => $images]);
    }

    public function store(StoreGalleryImageRequest $request): JsonResponse
    {

        $pro = $this->currentProfessional(request());
        $site = $this->currentSite($pro);
        $data = $request->validated();

        $image = DB::transaction(function () use ($site, $data) {
            DB::select('select pg_advisory_xact_lock(hashtext(?))', ["gallery:{$site->id}"]);
            $activeCount = SiteImage::query()
                ->where('site_id', $site->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->count();

            if ($activeCount >= 6){
                abort(422, 'Gallery limit reached (max 6 images)');
            }

            $maxSort = SiteImage::query()
                ->where('site_id', $site->id)
                ->where('is_active', true)
                ->max('sort_order');

            $maxSort = is_null($maxSort) ? -1 : (int) $maxSort;

            $image = new SiteImage([
                'site_id' => $site->id,
                'bucket' => $data['bucket'],
                'path' => $data['path'],
                'alt_text' => $data['alt_text'] ?? null,
                'sort_order' => $maxSort + 1,
                'is_active' => true,
            ]);

            $image->save();

            return $image->fresh();
        });
        return $this->success(['image' => $image], 201);
    }

    public function reorder(ReorderGalleryImageRequest $request): JsonResponse
    {

        $pro = $this->currentProfessional(request());
        $site = $this->currentSite($pro);

        $ids = array_values(array_unique($request->validated()['ids'] ?? []));
        DB::transaction(function () use ($site, $ids) {
            $allIds = SiteImage::query()
                ->where('site_id', $site->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->orderBy('sort_order')
                ->orderBy('created_at')
                ->pluck('id')
                ->all();

            $allSet = array_flip($allIds);
            foreach ($ids as $id) {
                if (!isset($allSet[$id])) {
                    abort(403, 'One or more images do not belong to your site.');
                }
        }
            $remaining = array_values(array_diff($allIds, $ids));
            $newOrder = array_merge($ids, $remaining);

            foreach ($newOrder as $i => $id) {
                SiteImage::query()
                    ->where('site_id', $site->id)
                    ->where('id', $id)
                    ->update(['sort_order' => $i]);
            }
        });

        return $this->success(['ok' => true]);

    }

    // Soft Delete
    public function destroy(SiteImage $image): JsonResponse
    {
        $pro = $this->currentProfessional(request());
        $site = $this->currentSite($pro);
        abort_unless($image->site_id === $site->id, 404);

        $image->delete();

        return $this->success(['deleted' => true]);
    }

}
