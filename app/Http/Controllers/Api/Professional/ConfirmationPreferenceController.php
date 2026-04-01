<?php

namespace App\Http\Controllers\Api\Professional;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Services\Professional\ConfirmationPreferenceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ConfirmationPreferenceController extends ApiController
{
    use ResolveCurrentProfessional;

    public function __construct(
        private readonly ConfirmationPreferenceService $preferences
    ) {}

    public function show(Request $request): JsonResponse
    {
        $professional = $this->currentProfessional($request);

        return $this->success([
            'confirmation_preferences' => $this->preferences->getForProfessional((string) $professional->id),
        ]);
    }

    public function update(\App\Http\Requests\Api\Professional\UpdateConfirmationPreferenceRequest $request): JsonResponse
    {
        $validated = $request->validated();
        if ($validated === []) {
            return $this->error(
                'No confirmation preference fields were provided.',
                422
            );
        }

        $professional = $this->currentProfessional($request);
        $updated = $this->preferences->updateForProfessional((string) $professional->id, $validated);

        return $this->success([
            'confirmation_preferences' => $updated,
        ]);
    }
}
