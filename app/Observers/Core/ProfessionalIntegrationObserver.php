<?php

namespace App\Observers\Core;

use App\Models\Core\Professional\Professional;
use App\Models\Core\Professional\ProfessionalIntegration;
use App\Observers\Concerns\LogsWithRequestContext;
use App\Services\Notifications\NotificationPublisher;
use App\Services\Professional\SectionVisibilityService;
use Illuminate\Support\Facades\Log;

// V2: Publishes integration connect/disconnect notifications and re-evaluates booking section visibility.
class ProfessionalIntegrationObserver
{
    use LogsWithRequestContext;

    public bool $afterCommit = true;

    public function __construct(
        private readonly NotificationPublisher $publisher,
        private readonly SectionVisibilityService $visibilityService,
    ) {}

    public function created(ProfessionalIntegration $integration): void
    {
        try {
            $professionalId = trim((string) ($integration->professional_id ?? ''));
            if ($professionalId === '') {
                return;
            }

            $provider = ucfirst(strtolower(trim((string) ($integration->provider ?? 'Integration'))));

            $this->publisher->publish(
                professionalId: $professionalId,
                frontendType: 'Success',
                category: 'integrations',
                title: "{$provider} connected",
                body: "Your {$provider} integration has been connected successfully.",
                dedupeKey: "integration.connected.{$integration->id}",
                ctaUrl: '/account/integrations',
                retentionConfigKey: 'integration',
            );
        } catch (\Throwable $e) {
            Log::warning('ProfessionalIntegration created notification failed', $this->logContext(__METHOD__, [
                'integration_id' => $integration->id,
                'professional_id' => $integration->professional_id,
                'message' => $e->getMessage(),
            ]));
        }

        $this->reevaluateBooking($integration);
    }

    public function deleted(ProfessionalIntegration $integration): void
    {
        try {
            $professionalId = trim((string) ($integration->professional_id ?? ''));
            if ($professionalId === '') {
                return;
            }

            $provider = ucfirst(strtolower(trim((string) ($integration->provider ?? 'Integration'))));

            $this->publisher->publish(
                professionalId: $professionalId,
                frontendType: 'Warning',
                category: 'integrations',
                title: "{$provider} disconnected",
                body: "Your {$provider} integration has been disconnected.",
                dedupeKey: "integration.disconnected.{$integration->id}",
                ctaUrl: '/account/integrations',
                retentionConfigKey: 'integration',
            );
        } catch (\Throwable $e) {
            Log::warning('ProfessionalIntegration deleted notification failed', $this->logContext(__METHOD__, [
                'integration_id' => $integration->id,
                'professional_id' => $integration->professional_id,
                'message' => $e->getMessage(),
            ]));
        }

        $this->reevaluateBooking($integration);
    }

    private function reevaluateBooking(ProfessionalIntegration $integration): void
    {
        try {
            $pro = Professional::query()->with('site')->find($integration->professional_id);
            $site = $pro?->site;
            if (! $pro || ! $site) {
                return;
            }

            $this->visibilityService->reevaluateEnabled(
                (string) $integration->professional_id,
                (string) $site->id,
                'booking'
            );
        } catch (\Throwable $e) {
            Log::warning('Booking section visibility reevaluation failed on integration change', $this->logContext(__METHOD__, [
                'integration_id' => $integration->id,
                'professional_id' => $integration->professional_id,
                'message' => $e->getMessage(),
            ]));
        }
    }
}
