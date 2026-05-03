<?php

namespace App\Services\Hydrogen;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

// Dispatches the GitHub Actions workflow in sidest-storefront that deploys the
// Hydrogen bundle to a brand's Oxygen instance. Called from both the embedded
// wizard and the dashboard wizard when a brand saves Oxygen credentials.
//
// The workflow already handles the deploy-everyone-on-push case; this service
// adds the single-brand deploy for new brands that just completed setup.
class HydrogenDeploymentService
{
    /**
     * Trigger a single-brand Oxygen deployment via GitHub Actions workflow_dispatch.
     *
     * Best-effort — failures are logged but never thrown, so a missing token or
     * GitHub outage doesn't block the wizard. The next push to sidest-storefront
     * will deploy to all brands including this one.
     */
    public function dispatchDeployment(string $professionalId): void
    {
        $token = config('sidest.hydrogen.github_token');

        if (empty($token)) {
            Log::info('HydrogenDeployment: skipping dispatch — SIDEST_HYDROGEN_GITHUB_TOKEN not set.', [
                'professional_id' => $professionalId,
            ]);

            return;
        }

        $repo = config('sidest.hydrogen.github_repo', 'hunterbalcombesykes/sidest-storefront');
        $ref = config('sidest.hydrogen.github_ref', 'main');
        $url = "https://api.github.com/repos/{$repo}/actions/workflows/oxygen-deployment.yml/dispatches";

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->post($url, [
                    'ref' => $ref,
                    'inputs' => [
                        'professional_id' => $professionalId,
                    ],
                ]);

            if ($response->successful()) {
                Log::info('HydrogenDeployment: workflow dispatched.', [
                    'professional_id' => $professionalId,
                    'repo' => $repo,
                    'ref' => $ref,
                ]);
            } else {
                Log::warning('HydrogenDeployment: GitHub API returned non-2xx.', [
                    'professional_id' => $professionalId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('HydrogenDeployment: failed to reach GitHub API.', [
                'professional_id' => $professionalId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
