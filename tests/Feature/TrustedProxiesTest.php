<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    Route::get('/_proxy-check', fn (Request $request): array => ['secure' => $request->isSecure(), 'ip' => $request->ip()]);
});

test('a trusted proxy (TRUSTED_PROXIES) passes on the visitor\'s scheme and address', function () {
    config(['trustedproxy.proxies' => '*']);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
        ->getJson('/_proxy-check')
        ->assertExactJson(['secure' => true, 'ip' => '203.0.113.9']);
});

test('without trusted proxies the forwarded headers are ignored', function () {
    config(['trustedproxy.proxies' => null]);

    $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.5'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-For' => '203.0.113.9'])
        ->getJson('/_proxy-check')
        ->assertExactJson(['secure' => false, 'ip' => '10.0.0.5']);
});
