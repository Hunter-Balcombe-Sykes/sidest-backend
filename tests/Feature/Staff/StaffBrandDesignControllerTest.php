<?php

use App\Http\Controllers\Api\Staff\StaffSite\StaffBrandDesignController;
use App\Models\Core\Professional\Professional;
use App\Services\Media\BrandDesignMediaService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    setupProfessionalsTable();
    setupSitesTable();
    setupProfessionalIntegrationsTable();
});

function makeStaffDesignProfessional(array $siteSettings = []): Professional
{
    $id = (string) Str::uuid();
    DB::connection('pgsql')->table('core.professionals')->insert([
        'id' => $id,
        'handle' => 'des-'.substr($id, 0, 8),
        'handle_lc' => 'des-'.substr($id, 0, 8),
        'display_name' => 'Design Brand',
        'primary_email' => 'des-'.substr($id, 0, 8).'@example.com',
        'professional_type' => 'brand',
        'status' => 'active',
    ]);

    DB::connection('pgsql')->table('site.sites')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $id,
        'subdomain' => 'des-'.substr($id, 0, 8),
        'settings' => json_encode($siteSettings),
        'is_published' => 0,
        'created_at' => now()->toDateTimeString(),
        'updated_at' => now()->toDateTimeString(),
    ]);

    return Professional::query()->find($id);
}

it('returns resolved design defaults when the brand has not customised anything', function () {
    $pro = makeStaffDesignProfessional();

    $brandDesign = Mockery::mock(BrandDesignMediaService::class);
    $brandDesign->shouldReceive('listDesignMedia')
        ->once()
        ->andReturn(['logo' => ['full_url' => null, 'square_url' => null], 'placeholders' => []]);

    $controller = new StaffBrandDesignController($brandDesign);
    $response = $controller->show($pro);
    $body = $response->getData(true);

    expect($response->getStatusCode())->toBe(200)
        ->and($body['theme_mode'])->toBe('light')
        ->and($body['corner_radius'])->toBe('default')
        ->and($body['border_thickness'])->toBe('default')
        ->and($body['section_spacing'])->toBe('default')
        ->and($body['font_family'])->toBe('helvetica_neue')
        ->and($body['shopify_connected'])->toBeFalse();
});

it('reflects explicit theme_mode + accent colour set in site.settings.design', function () {
    $pro = makeStaffDesignProfessional([
        'design' => [
            'theme_mode' => 'dark',
            'colors' => ['accent' => '#FF00AA'],
            'font_family' => 'inter',
            'slogan' => 'For the bold',
        ],
    ]);

    $brandDesign = Mockery::mock(BrandDesignMediaService::class);
    $brandDesign->shouldReceive('listDesignMedia')
        ->once()
        ->andReturn(['logo' => ['full_url' => null, 'square_url' => null], 'placeholders' => []]);

    $controller = new StaffBrandDesignController($brandDesign);
    $response = $controller->show($pro);
    $body = $response->getData(true);

    expect($body['theme_mode'])->toBe('dark')
        ->and($body['colors']['accent'])->toBe('#FF00AA')
        ->and($body['font_family'])->toBe('inter')
        ->and($body['slogan'])->toBe('For the bold');
});

it('reports shopify_connected=true when an integration row exists', function () {
    $pro = makeStaffDesignProfessional();

    DB::connection('pgsql')->table('core.professional_integrations')->insert([
        'id' => (string) Str::uuid(),
        'professional_id' => $pro->id,
        'provider' => 'shopify',
        'access_token' => 'shpat_test',
        'created_at' => now()->toIso8601String(),
        'updated_at' => now()->toIso8601String(),
    ]);

    $brandDesign = Mockery::mock(BrandDesignMediaService::class);
    $brandDesign->shouldReceive('listDesignMedia')
        ->once()
        ->andReturn(['logo' => ['full_url' => null, 'square_url' => null], 'placeholders' => []]);

    $controller = new StaffBrandDesignController($brandDesign);
    $response = $controller->show($pro);
    $body = $response->getData(true);

    expect($body['shopify_connected'])->toBeTrue();
});
