<?php

namespace App\Observers\Core;

use App\Models\Core\Professional\ProfessionalIntegration;
use App\Services\Notifications\NotificationPublisher;
use Illuminate\Support\Facades\Log;

class ProfessionalIntegrationObserver
{
    public bool $afterCommit = true;

    public function __construct(private readonly NotificationPublisher $publisher) {}

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
            Log::warning('ProfessionalIntegration created notification failed', [
                'integration_id' => $integration->id,
                'message'        => $e->getMessage(),
            ]);
        }
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
            Log::warning('ProfessionalIntegration deleted notification failed', [
                'integration_id' => $integration->id,
                'message'        => $e->getMessage(),
            ]);
        }
    }
}
