<?php

use Illuminate\Support\Facades\Route;
use Nosh\OmniConnect\Http\Controllers\PluginController;
use Nosh\OmniConnect\Http\Middleware\VerifyMiddlewareJwt;

/*
 * Inbound Plugin API. Mounted by OmniConnectServiceProvider under the
 * configured prefix + middleware; each route additionally passes through the
 * Middleware-JWT verification.
 */
Route::middleware(VerifyMiddlewareJwt::class)->group(function () {
    Route::post('order/{remoteId}', [PluginController::class, 'dispatchOrder'])
        ->name('panda.order.dispatch');

    Route::put('remoteId/{remoteId}/remoteOrder/{remoteOrderId}/posOrderStatus', [PluginController::class, 'orderStatus'])
        ->name('panda.order.status');

    Route::put('remoteId/{remoteId}/availability', [PluginController::class, 'vendorAvailability'])
        ->name('panda.vendor.availability');

    Route::get('menuimport/{remoteId}', [PluginController::class, 'menuImport'])
        ->name('panda.menu.import');

    Route::post('catalog-import-callback', [PluginController::class, 'catalogImportCallback'])
        ->name('panda.catalog.import-status');
});
