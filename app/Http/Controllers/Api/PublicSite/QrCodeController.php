<?php

namespace App\Http\Controllers\Api\PublicSite;

use App\Http\Controllers\Api\ApiController;
use App\Http\Controllers\Concerns\BuildsQrCodeUrls;
use App\Models\Core\Professional\Professional;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// V2: QR code generation (SVG) and short-link redirection using the professional's qr_slug.
class QrCodeController extends ApiController
{
    use BuildsQrCodeUrls;

    public function redirect(string $qr_slug, Request $request): Response
    {
        $professional = Professional::query()
            ->with('site')
            ->where('qr_slug', $qr_slug)
            ->first();

        if (! $professional) {
            abort(404);
        }

        $site = $professional->site;

        if (! $site || ! is_string($site->subdomain) || $site->subdomain === '') {
            abort(404);
        }

        $publicDomain = (string) config('sidest.public_domain', '');
        if ($publicDomain === '') {
            abort(500, 'Public domain not configured.');
        }

        $scheme = $this->baseScheme($request);
        $url = $scheme.'://'.$site->subdomain.'.'.$publicDomain;

        return redirect()->to($url, 302);
    }

    public function svg(string $qr_slug, Request $request): Response
    {
        $professional = Professional::query()
            ->where('qr_slug', $qr_slug)
            ->first();

        if (! $professional) {
            abort(404);
        }

        $qrUrl = $this->qrUrl($qr_slug, $request);

        $qrCode = QrCode::create($qrUrl)
            ->setSize(320)
            ->setMargin(10);

        $writer = new SvgWriter;
        $result = $writer->write($qrCode);

        return response($result->getString(), 200, [
            'Content-Type' => $result->getMimeType(),
            'Cache-Control' => 'public, max-age=31536000, immutable',
        ]);
    }
}
