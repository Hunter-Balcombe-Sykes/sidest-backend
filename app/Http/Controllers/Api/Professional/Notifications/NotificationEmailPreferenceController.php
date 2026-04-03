<?php

namespace App\Http\Controllers\Api\Professional\Notifications;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Http\Requests\Api\Professional\Notifications\UpdateNotificationEmailPreferencesRequest;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// V2: Per-category email notification opt-in/out with staff policy overrides support.
class NotificationEmailPreferenceController extends ApiController
{
    use ResolveCurrentProfessional;

    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $prefs = DB::table('notifications.notification_email_preferences')
            ->where('professional_id', $pro->id)
            ->get(['category_key', 'enabled'])
            ->keyBy('category_key');

        $perProPolicies = DB::table('core.notification_email_policies')
            ->where('professional_id', $pro->id)
            ->get(['category_key', 'mode'])
            ->keyBy('category_key');

        $globalPolicies = DB::table('core.notification_email_policies')
            ->whereNull('professional_id')
            ->get(['category_key', 'mode'])
            ->keyBy('category_key');

        $result = array_map(function (string $category) use ($prefs, $perProPolicies, $globalPolicies): array {
            $perProMode = $perProPolicies->get($category)?->mode;
            $globalMode = $globalPolicies->get($category)?->mode;
            $prefRow    = $prefs->get($category);
            $prefValue  = $prefRow !== null ? (bool) $prefRow->enabled : null;

            if ($perProMode === 'force_on') {
                $effective = true;
            } elseif ($perProMode === 'force_off') {
                $effective = false;
            } elseif ($globalMode === 'force_on') {
                $effective = true;
            } elseif ($globalMode === 'force_off') {
                $effective = false;
            } elseif ($prefValue !== null) {
                $effective = $prefValue;
            } else {
                $effective = true;
            }

            return [
                'category'             => $category,
                'enabled'              => $effective,
                'preference_set'       => $prefValue !== null,
                'overridden_by_policy' => in_array($perProMode, ['force_on', 'force_off'], true)
                    || in_array($globalMode, ['force_on', 'force_off'], true),
            ];
        }, NotificationPublisher::CATEGORIES);

        return $this->success(['preferences' => array_values($result)]);
    }

    public function update(UpdateNotificationEmailPreferencesRequest $request): JsonResponse
    {
        $pro     = $this->currentProfessional($request);
        $updates = $request->validated()['preferences'];

        foreach ($updates as $update) {
            DB::statement(
                'INSERT INTO notifications.notification_email_preferences (id, professional_id, category_key, enabled, created_at, updated_at)
                 VALUES (?, ?, ?, ?, NOW(), NOW())
                 ON CONFLICT (professional_id, category_key)
                 DO UPDATE SET enabled = EXCLUDED.enabled, updated_at = NOW()',
                [(string) Str::uuid(), $pro->id, $update['category'], $update['enabled']]
            );
        }

        return $this->success(['ok' => true]);
    }
}
