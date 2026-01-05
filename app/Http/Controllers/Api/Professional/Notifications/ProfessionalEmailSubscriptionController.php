<?php

namespace App\Http\Controllers\Api\Professional\Notifications;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\ResolveCurrentProfessional;
use App\Models\Core\Notifications\EmailSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfessionalEmailSubscriptionController extends ApiController
{
    use ResolveCurrentProfessional;

    // GET /api/email-subscribers?list_key=marketing&status=subscribed&search=...
    public function index(Request $request): JsonResponse
    {
        $pro = $this->currentProfessional($request);

        $listKey = $request->query('list_key', 'marketing');
        $listKey = is_string($listKey) ? trim($listKey) : 'marketing';
        if ($listKey === '') $listKey = 'marketing';

        $status = $request->query('status', 'subscribed'); // subscribed|unsubscribed|all
        $status = is_string($status) ? trim($status) : 'subscribed';

        $perPage = (int) $request->query('per_page', 50);
        $perPage = max(1, min(200, $perPage));

        $search = $request->query('search');
        $search = is_string($search) ? trim($search) : null;
        $like = $search ? '%' . str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $search) . '%' : null;

        $query = EmailSubscription::query()
            ->where('professional_id', $pro->id)
            ->where('list_key', $listKey)
            ->orderByDesc('subscribed_at')
            ->orderByDesc('created_at');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        if ($like) {
            $query->where(function ($q) use ($like) {
                $q->where('email', 'ilike', $like)
                    ->orWhere('full_name', 'ilike', $like);
            });
        }

        $page = $query->paginate($perPage)->appends($request->query());

        return $this->success([
            'subscriptions' => $page->items(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'last_page' => $page->lastPage(),
            ],
            'filters' => [
                'list_key' => $listKey,
                'status' => $status,
                'search' => $search,
            ],
        ]);
    }

    // GET /api/email-subscribers/export?list_key=marketing&status=subscribed
    public function export(Request $request): StreamedResponse
    {
        $pro = $this->currentProfessional($request);

        $listKey = $request->query('list_key', 'marketing');
        $listKey = is_string($listKey) ? trim($listKey) : 'marketing';
        if ($listKey === '') $listKey = 'marketing';

        $status = $request->query('status', 'subscribed');
        $status = is_string($status) ? trim($status) : 'subscribed';

        $query = EmailSubscription::query()
            ->where('professional_id', $pro->id)
            ->where('list_key', $listKey)
            ->orderBy('email');

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $filename = "email-subscribers-{$listKey}-{$status}.csv";

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'full_name', 'status', 'subscribed_at', 'unsubscribed_at']);

            foreach ($query->cursor() as $row) {
                fputcsv($out, [
                    $row->email,
                    $row->full_name,
                    $row->status,
                    optional($row->subscribed_at)->toISOString(),
                    optional($row->unsubscribed_at)->toISOString(),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
