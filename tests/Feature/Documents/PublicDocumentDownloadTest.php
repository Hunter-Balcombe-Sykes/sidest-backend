<?php

use Illuminate\Support\Facades\Route;

it('route GET api/public/documents/{document}/download is registered', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('GET', $r->methods()) && $r->uri() === 'api/public/documents/{document}/download');

    expect($route)->not->toBeNull();
    expect($route->getActionName())->toContain('PublicDocumentDownloadController');
});

it('route GET api/public/documents/{document}/download has public-site throttle', function () {
    $route = collect(Route::getRoutes()->getRoutes())
        ->first(fn ($r) => in_array('GET', $r->methods()) && $r->uri() === 'api/public/documents/{document}/download');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:public-site');
});
