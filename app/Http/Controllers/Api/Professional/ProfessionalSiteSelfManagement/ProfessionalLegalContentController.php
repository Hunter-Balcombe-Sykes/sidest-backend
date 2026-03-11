<?php

namespace App\Http\Controllers\Api\Professional\ProfessionalSiteSelfManagement;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Controllers\Concerns\ResolveCurrentSite;
use App\Http\Requests\Api\Professional\Site\UpsertLegalContentRequest;
use App\Models\Core\Professional\ProfessionalLegalContent;
use App\Services\Legal\ProfessionalLegalContentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfessionalLegalContentController extends ApiController
{
    use ResolveCurrentProfessional;
    use ResolveCurrentSite;

    public function show(Request $request, ProfessionalLegalContentService $legalService): JsonResponse
    {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);

        $legal = $legalService->getOrCreate($professional, $site);

        return $this->success([
            'legal_content' => $legalService->toApiPayload($legal),
        ]);
    }

    public function upsert(
        UpsertLegalContentRequest $request,
        ProfessionalLegalContentService $legalService
    ): JsonResponse {
        $professional = $this->currentProfessional($request);
        $site = $this->currentSite($professional);
        $data = $request->validated();

        $legal = !empty($data['regenerate_templated'])
            ? $legalService->refreshGenerated($professional, $site)
            : $legalService->getOrCreate($professional, $site);

        $manualPrivacy = array_key_exists('manual_privacy_policy', $data)
            ? $this->trimOrNull($data['manual_privacy_policy'])
            : $this->trimOrNull($legal->manual_privacy_policy);

        $manualTerms = array_key_exists('manual_terms_and_conditions', $data)
            ? $this->trimOrNull($data['manual_terms_and_conditions'])
            : $this->trimOrNull($legal->manual_terms_and_conditions);

        if (array_key_exists('manual_privacy_policy', $data)) {
            $legal->manual_privacy_policy = $manualPrivacy;
        }

        if (array_key_exists('manual_terms_and_conditions', $data)) {
            $legal->manual_terms_and_conditions = $manualTerms;
        }

        if (array_key_exists('active_privacy_source', $data)) {
            $legal->active_privacy_source = $data['active_privacy_source'];
        }

        if (array_key_exists('active_terms_source', $data)) {
            $legal->active_terms_source = $data['active_terms_source'];
        }

        // Never allow blank active output: fallback to templated when manual text is empty.
        if (
            $legal->active_privacy_source === ProfessionalLegalContent::SOURCE_MANUAL
            && $manualPrivacy === null
        ) {
            $legal->active_privacy_source = ProfessionalLegalContent::SOURCE_TEMPLATED;
        }

        if (
            $legal->active_terms_source === ProfessionalLegalContent::SOURCE_MANUAL
            && $manualTerms === null
        ) {
            $legal->active_terms_source = ProfessionalLegalContent::SOURCE_TEMPLATED;
        }

        $legal->save();

        return $this->success([
            'legal_content' => $legalService->toApiPayload($legal->fresh()),
        ]);
    }

    private function trimOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
