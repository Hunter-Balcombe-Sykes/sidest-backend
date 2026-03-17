<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Professional\Professional;
use App\Models\Core\Site\Site;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BrandPartnerController extends ApiController
{
    use ResolveCurrentProfessional;

    public function index(): JsonResponse
    {
        $brands = Professional::query()
            ->where('professional_type', 'brand')
            ->where('status', 'active')
            ->with('site')
            ->orderByRaw('COALESCE(display_name, handle) asc')
            ->get()
            ->map(function (Professional $professional): array {
                return [
                    'id' => $professional->id,
                    'display_name' => $professional->display_name,
                    'handle' => $professional->handle,
                    'subdomain' => $professional->site?->subdomain,
                ];
            })
            ->values()
            ->all();

        return $this->success([
            'brands' => $brands,
        ]);
    }

    public function promote(Request $request, string $brandProfessionalId): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
            return $this->error('Brand accounts cannot manage brand partner connections.', 403);
        }

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        $settings = is_array($site->settings ?? null) ? $site->settings : [];
        $additionalPartners = is_array($settings['additional_brand_partners'] ?? null)
            ? $settings['additional_brand_partners']
            : [];

        // Find the partner in additional list
        $foundIndex = null;
        foreach ($additionalPartners as $index => $partner) {
            if (($partner['professional_id'] ?? null) === $brandProfessionalId) {
                $foundIndex = $index;
                break;
            }
        }

        if ($foundIndex === null) {
            return $this->error('Brand partner not found in your additional partners.', 404);
        }

        // Swap: move current primary to additional, promote target to primary
        $currentPrimary = $settings['brand_partner'] ?? null;
        $promotedPartner = $additionalPartners[$foundIndex];

        // Remove promoted partner from additional list
        array_splice($additionalPartners, $foundIndex, 1);

        // Add old primary to additional list (if it existed)
        if (is_array($currentPrimary) && ! empty($currentPrimary['professional_id'])) {
            $additionalPartners[] = ['professional_id' => $currentPrimary['professional_id']];
        }

        $settings['brand_partner'] = ['professional_id' => $promotedPartner['professional_id']];
        $settings['additional_brand_partners'] = $additionalPartners;
        $site->settings = $settings;
        $site->save();

        return $this->success([
            'promoted' => true,
            'primary_professional_id' => $promotedPartner['professional_id'],
        ]);
    }

    public function disconnect(Request $request, string $brandProfessionalId): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        if (mb_strtolower(trim((string) $professional->professional_type)) === 'brand') {
            return $this->error('Brand accounts cannot manage brand partner connections.', 403);
        }

        $site = Site::query()->where('professional_id', $professional->id)->first();
        if (! $site) {
            return $this->error('Site not found.', 404);
        }

        $settings = is_array($site->settings ?? null) ? $site->settings : [];
        $currentPrimaryId = $settings['brand_partner']['professional_id'] ?? null;
        $additionalPartners = is_array($settings['additional_brand_partners'] ?? null)
            ? $settings['additional_brand_partners']
            : [];

        $disconnected = false;

        if ($currentPrimaryId === $brandProfessionalId) {
            // Removing primary: promote first additional to primary, or clear
            unset($settings['brand_partner'], $settings['brandPartner']);

            if (count($additionalPartners) > 0) {
                $newPrimary = array_shift($additionalPartners);
                $settings['brand_partner'] = ['professional_id' => $newPrimary['professional_id']];
                $settings['additional_brand_partners'] = array_values($additionalPartners);
            } else {
                $settings['additional_brand_partners'] = [];
            }

            $disconnected = true;
        } else {
            // Check additional partners
            $newAdditional = [];
            foreach ($additionalPartners as $partner) {
                if (($partner['professional_id'] ?? null) === $brandProfessionalId) {
                    $disconnected = true;
                    continue;
                }
                $newAdditional[] = $partner;
            }
            $settings['additional_brand_partners'] = $newAdditional;
        }

        if (! $disconnected) {
            return $this->error('Brand partner not found in your connections.', 404);
        }

        $site->settings = $settings;
        $site->save();

        return $this->success([
            'disconnected' => true,
            'brand_professional_id' => $brandProfessionalId,
        ]);
    }
}
