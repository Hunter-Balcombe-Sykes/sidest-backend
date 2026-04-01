<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Professional\BrandAffiliateInvite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AffiliateInviteController extends ApiController
{
    use ResolveCurrentProfessional;

    public function index(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
            return $this->error('Brand accounts cannot view affiliate invites.', 403);
        }

        $emails = array_filter([
            mb_strtolower(trim((string) ($professional->primary_email ?? ''))),
            mb_strtolower(trim((string) ($professional->public_contact_email ?? ''))),
        ], fn (string $email): bool => $email !== '');

        if ($emails === []) {
            return $this->success(['invites' => []]);
        }

        $invites = BrandAffiliateInvite::query()
            ->with('brandProfessional.site')
            ->whereIn('email_lc', array_unique($emails))
            ->where('status', 'pending')
            ->where(function ($query) {
                $query->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->orderByDesc('created_at')
            ->get()
            ->map(function (BrandAffiliateInvite $invite): array {
                $brand = $invite->brandProfessional;
                $siteSettings = is_array($brand?->site?->settings ?? null) ? $brand->site->settings : [];
                $designSettings = is_array($siteSettings['design'] ?? null) ? $siteSettings['design'] : [];
                $mediaSettings = is_array($designSettings['media'] ?? null) ? $designSettings['media'] : [];

                return [
                    'id' => $invite->id,
                    'token' => $invite->token,
                    'status' => $invite->status,
                    'message' => $invite->message,
                    'expires_at' => optional($invite->expires_at)->toIso8601String(),
                    'created_at' => optional($invite->created_at)->toIso8601String(),
                    'brand' => [
                        'id' => $brand?->id,
                        'display_name' => $brand?->display_name,
                        'handle' => $brand?->handle,
                        'brand_logo_url' => is_string($mediaSettings['brand_logo_url'] ?? $mediaSettings['brandLogoUrl'] ?? null)
                            ? ($mediaSettings['brand_logo_url'] ?? $mediaSettings['brandLogoUrl'])
                            : null,
                        'brand_color' => is_string($designSettings['dark_color'] ?? $designSettings['darkColor'] ?? null)
                            ? ($designSettings['dark_color'] ?? $designSettings['darkColor'])
                            : null,
                    ],
                ];
            })
            ->values()
            ->all();

        return $this->success(['invites' => $invites]);
    }
}
