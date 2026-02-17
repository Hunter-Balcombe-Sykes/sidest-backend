<?php

namespace App\Http\Controllers\Api\Staff\StaffSite;

use App\Http\Controllers\Api\ApiController;
use App\Models\Core\Notifications\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Jobs\Notifications\SendStaffBroadcastEmailsJob;

class StaffNotificationController extends ApiController
{
    /** POST /staff/notifications */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'professional_id' => ['nullable', 'uuid'],
            'type' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:255'],
            'body' => ['required', 'string', 'max:5000'],
            'cta_url' => ['nullable', 'string', 'max:2048'],
            'severity' => ['required', 'string', 'in:info,warning,critical'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'send_email' => ['nullable', 'boolean'],
            'email_list_key' => ['nullable', 'string', 'max:50'],
        ]);

        if (empty($data['ends_at'])) {
            $map = config('comet.notification_retention_days', []);
            $days = $map[$data['type']] ?? ($map['default'] ?? 30);

            // null means "keep until manually ended/dismissed"
            if ($days !== null) {
                $data['ends_at'] = now()->addDays((int) $days);
            }
        }

        $notification = Notification::query()->create($data);

        $sendEmail = (bool)($data['send_email'] ?? false);
        $emailListKey = $data['email_list_key'] ?? 'comet_updates';

        if ($sendEmail) {
            // Only broadcast emails for global notifications (professional_id null)
            // If you want to allow targeted emails too, remove this guard.
            if ($notification->professional_id === null) {
                SendStaffBroadcastEmailsJob::dispatch($notification->id, $emailListKey);
            }
        }


        return $this->success(['notification' => $notification], 201);
    }
}
