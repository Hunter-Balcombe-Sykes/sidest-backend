<?php

namespace App\Services\Branding;

use App\Models\Core\Site\BrandFont;
use App\Services\Cache\CacheKeyGenerator;
use Illuminate\Support\Facades\Cache;

class BrandFontResolver
{
    private const MISS_SENTINEL = '__MISS__';

    /**
     * @return array{id:string,file_name:?string,file_path:string,file_url:string,format:string,file_hash:string,size_bytes:int}|null
     */
    public function getActiveFont(string $brandProfessionalId): ?array
    {
        $brandProfessionalId = trim($brandProfessionalId);
        if ($brandProfessionalId === '') {
            return null;
        }

        $cacheKey = CacheKeyGenerator::brandFontActive($brandProfessionalId);
        $cached = Cache::get($cacheKey);

        if ($cached === self::MISS_SENTINEL) {
            return null;
        }

        if (is_array($cached)) {
            return $cached;
        }

        $font = BrandFont::query()
            ->where('brand_professional_id', $brandProfessionalId)
            ->where('slot', BrandFont::SLOT_PRIMARY)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first();

        if (! $font) {
            Cache::put($cacheKey, self::MISS_SENTINEL, now()->addMinutes(10));
            return null;
        }

        $payload = [
            'id' => (string) $font->id,
            'file_name' => is_string($font->file_name) ? $font->file_name : null,
            'file_path' => (string) $font->file_path,
            'file_url' => (string) $font->file_url,
            'format' => strtolower((string) $font->format),
            'file_hash' => (string) $font->file_hash,
            'size_bytes' => (int) $font->size_bytes,
        ];

        Cache::put($cacheKey, $payload, now()->addMinutes(15));

        return $payload;
    }

    public function activeFontUrl(string $brandProfessionalId): ?string
    {
        $font = $this->getActiveFont($brandProfessionalId);

        if (! is_array($font)) {
            return null;
        }

        $url = trim((string) ($font['file_url'] ?? ''));

        return $url !== '' ? $url : null;
    }

    /**
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    public function hydrateTypographySettings(array $settings, string $brandProfessionalId): array
    {
        $font = $this->getActiveFont($brandProfessionalId);
        if (! is_array($font)) {
            return $settings;
        }

        $design = is_array($settings['design'] ?? null) ? $settings['design'] : [];
        $typography = is_array($design['typography'] ?? null) ? $design['typography'] : [];

        $typography['font_file_name'] = $font['file_name'];
        $typography['font_file_path'] = $font['file_path'];
        $typography['font_file_url'] = $font['file_url'];

        $design['typography'] = $typography;
        $settings['design'] = $design;

        return $settings;
    }

    public function forget(string $brandProfessionalId): void
    {
        $brandProfessionalId = trim($brandProfessionalId);
        if ($brandProfessionalId === '') {
            return;
        }

        Cache::forget(CacheKeyGenerator::brandFontActive($brandProfessionalId));
    }
}
