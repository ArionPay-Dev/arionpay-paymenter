<?php

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;
use Paymenter\Extensions\Gateways\ArionPay\ArionPay;

// Server-to-server call signed by ArionPay: no CSRF token.
Route::post('/extensions/gateways/arionpay/webhook', [ArionPay::class, 'webhook'])
    ->withoutMiddleware([VerifyCsrfToken::class])
    ->name('extensions.gateways.arionpay.webhook');
