<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Core\Notifications\EmailSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PublicEmailUnsubscribeController extends Controller
{
    public function unsubscribe(Request $request, string $token): JsonResponse
    {
        $sub = EmailSubscription::query()
            ->where('unsubscribe_token', $token)
            ->first();

        if (!$sub) {
            return response()->json(['message' => 'Invalid or expired unsubscribe link.'], 404);
        }

        if ($sub->status !== 'unsubscribed') {
            $sub->markUnsubscribed();
            $sub->save();
        }

        return response()->json(['ok' => true, 'unsubscribed' => true]);
    }
}
